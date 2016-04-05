<?php

/**
 * Apretaste
 * 
 * NAVEGAR service
 * 
 * @author kuma (kumahavana@gmail.com)
 * @version 1.0
 */
use Goutte\Client;
use ForceUTF8\Encoding;

class Navegar extends Service
{

    private $mailto = null;

    private $request = null;

    private $config = null;

    private $wwwroot = null;

    private $base = null;

    /**
     * Function executed when the service is called
     *
     * @param Request $request            
     * @return Response
     */
    public function _main (Request $request, $agent = 'default')
    {
        set_time_limit(600);
        
        $this->prepare($request);
        
        $request->query = trim($request->query);
        
        // Welcome message when query is empty
        if ($request->query == '') {
            $response = new Response();
            $response->setResponseSubject("Navegar en Internet");
            
            $db = new Connection();
            
            $sql = "SELECT * FROM _navegar_visits WHERE site is not null and site <> '' ORDER BY usage_count DESC LIMIT 10;";
            
            $result = $db->deepQuery($sql);
            if (! isset($result[0])) $result = false;
            
            $response->createFromTemplate("welcome.tpl", array(
                    'max_attachment_size' => $this->config['max_attachment_size'],
                    'visits' => $result
            ));
            
            return $response;
        }
        
        // If $argument is not an URL, then search on the web
        if (! $this->isUrl($request->query)) {
            return $this->searchResponse($request, 'web');
        }
        
        // Force HTTP in malformed URLs
        if (substr($request->query, 0, 2) == '//') {
            $request->query = 'http:' . $request->query;
        } else 
            if (substr($request->query, 0, 1) == '/') {
                $request->query = 'http:/' . $request->query;
            }
        
        // Detecting FTP access
        $scheme = strtolower(parse_url($request->query, PHP_URL_SCHEME));
        
        if ($scheme == 'ftp') {
            $ftp_result = $this->getFTP($request->query);
            
            if ($ftp_result == false) {
                $response = new Response();
                $response->setResponseSubject("No se pudo acceder al servidor FTP");
                $response->createFromTemplate("ftp_error.tpl", array(
                        "url" => $request->query
                ));
                return $response;
            }
            
            switch ($ftp_result['type']) {
                case 'dir':
                    $response = new Response();
                    $response->setResponseSubject("Accediendo al servidor de archivos");
                    $response->createFromTemplate("ftp.tpl", 
                            array(
                                    "url" => $request->query,
                                    "contents" => $ftp_result['contents'],
                                    "base_url" => $request->query
                            ));
                    return $response;
                
                case 'file':
                    $response = new Response();
                    $response->setResponseSubject("Archivo descargado del servidor FTP");
                    $response->createFromTemplate("ftp_file.tpl", 
                            array(
                                    "url" => $request->query,
                                    "size" => $ftp_result['size'],
                                    "zipped" => $ftp_result['zipped']
                            ), array(), array(
                                    $ftp_result['filepath']
                            ));
                    return $response;
                
                case 'file_fail':
                    $response = new Response();
                    $response->setResponseSubject("No se pudo descargar el archivo del FTP");
                    $response->createFromTemplate("ftp_file_fail.tpl", array(
                            "url" => $request->query,
                            "size" => $ftp_result['size']
                    ));
                    return $response;
                
                case 'bigfile':
                    $response = new Response();
                    $response->setResponseSubject("Archivo demasiado grande");
                    $response->createFromTemplate("ftp_bigfile.tpl", array(
                            "url" => $request->query,
                            "size" => number_format($ftp_result['size'] / 1024, 0, '.', '')
                    ));
                    return $response;
            }
        }
        
        // Asume HTTP access
        
        // Preparing POST data
        $paramsbody = trim($request->body);
        $p = strpos($paramsbody, "\n");
        
        if ($p !== false) $paramsbody = substr($paramsbody, $p);
        
        if (strpos($paramsbody, '=') === false)
            $paramsbody = false;
        else
            $paramsbody = trim($paramsbody);
            
            // Default method is GET
        $method = 'GET';
        
        $argument = $request->query;
        
        // Analyzing params in body
        if ($paramsbody !== false) {
            if (stripos($paramsbody, 'apretaste-form-method=post') != false) {
                $method = 'POST';
            } else
                $argument = $request->query . '?' . $paramsbody;
        }
        
        // Retrieve the page/image/file
        $url = $argument;
        $page = $this->getHTTP($argument, $method, $paramsbody, $agent);
        
        if ($page === false) {
            // Return invalid page
            $response = new Response();
            $response->setResponseSubject("No se pudo acceder");
            $response->createFromTemplate('http_error.tpl', array(
                    'url' => $url
            ));
            return $response;
        }
        
        // Save stats
        
        $this->saveVisit($argument);
        
        // Create response
        $responseContent = $page;
        $responseContent['url'] = $argument;
        
        if (! isset($responseContent['type'])) $responseContent['type'] = 'basic';
        
        $response = new Response();
        $response->setResponseSubject(empty($responseContent['title']) ? "Navegando con Apretaste" : $responseContent['title']);
        $response->createFromTemplate("{$responseContent['type']}.tpl", $responseContent, (isset($responseContent['images']) ? $responseContent['images'] : array()), (isset($responseContent['attachments']) ? $responseContent['attachments'] : array()));
        
        return $response;
    }

    /**
     * Prepare service
     *
     * @param Request $request            
     */
    private function prepare ($request)
    {
        // Save request
        $this->request = $request;
        
        // Load configuration
        $this->loadServiceConfig();
        
        // Get path to the www folder
        $di = \Phalcon\DI\FactoryDefault::getDefault();
        $this->wwwroot = $di->get('path')['root'];
        
        // Load libs
        
        $mod_sockets = extension_loaded('sockets');
        if (! $mod_sockets && function_exists('dl') && is_callable('dl')) {
            $prefix = (PHP_SHLIB_SUFFIX == 'dll') ? 'php_' : '';
            @dl($prefix . 'sockets.' . PHP_SHLIB_SUFFIX);
            $mod_sockets = extension_loaded('sockets');
        }
        
        require_once $this->pathToService . '/lib/Emogrifier.php';
        require_once $this->pathToService . '/lib/PemFTP/ftp_class.php';
        // require_once $this->pathToService . '/lib/PemFTP/ftp_class_' .
        // ($mod_sockets ? 'sockets' : 'pure') . '.php';
        require_once $this->pathToService . '/lib/PemFTP/ftp_class_pure.php';
        require_once $this->pathToService . "/lib/CSSParser/CSSParser.php";
        require_once $this->pathToService . "/lib/Encoding.php";
    }

    /**
     * Common functionality for search
     *
     * @param Request $request            
     * @param string $source            
     * @return Response
     */
    private function searchResponse ($request, $source = 'web')
    {
        $results = array();
        
        if (strlen($request->query) >= $this->config['min_search_query_len']) $results = $this->search($request->query);
        
        if (empty($results)) {
            $response = new Response();
            $response->setResponseSubject("No se encontraron resultados en la web");
            $response->createFromTemplate("no_results.tpl", array(
                    'query' => $request->query
            ));
            return $response;
        }
        
        $response = new Response();
        $response->setResponseSubject("Resultados en la web");
        $response->createFromTemplate('results.tpl', array(
                'results' => $results
        ));
        
        return $response;
    }

    /**
     * Subservice NOTICIAS
     *
     * @param Request $request            
     * @return Response
     */
    public function _noticias ($request)
    {
        $this->prepare($request);
        return $this->searchResponse($request, 'news');
    }

    /**
     * Subservice MOVIL
     *
     * @param Request $request            
     * @return Response
     */
    public function _movil ($request)
    {
        return $this->_main($request, 'mobile');
    }

    /**
     * Load service configuration
     *
     * @return void
     */
    private function loadServiceConfig ()
    {
        $config_file = "{$this->pathToService}/service.ini";
        $this->config = @parse_ini_file($config_file, true, INI_SCANNER_RAW);
        
        $default_config = array(
                'default_user_agent' => 'Mozilla/5.0 (Windows NT 6.2; rv:40.0) Gecko/20100101 Firefox/40.0',
                'mobile_user_agent' => 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 3_0 like Mac OS X; en-us) AppleWebKit/528.18 (KHTML, like Gecko) Version/4.0 Mobile/7A341 Safari/528.16',
                'max_attachment_size' => 400,
                'cache_life_time' => 100000
        );
        
        foreach ($default_config as $prop => $value)
            if (! isset($this->config[$prop])) $this->config[$prop] = $default_config[$prop];
    }

    /**
     * Load web page
     *
     * @param string $url            
     * @return array
     */
    private function getHTTP ($url, $method = 'GET', $post = '', $agent = 'default')
    {
        // clear $url
        $url = str_replace("///", "/", $url);
        $url = str_replace("//", "/", $url);
        $url = str_replace("http:/", "http://", $url);
        $url = str_replace("https:/", "https://", $url);
        
        if (substr($url, 0, 2) == '//')
            $url = 'http:' . $url;
        else 
            if (substr($url, 0, 1) == '/') $url = 'http:/' . $url;
        
        try {
            // Create http client
            $http_client = new GuzzleHttp\Client(array(
                    'cookies' => true
            ));
        } catch (Exception $e) {
            return false;
        }
        
        // Build POST
        if ($post != '') {
            $arr = explode("&", $post);
            $post = array();
            foreach ($arr as $v) {
                $arr2 = explode('=', $v);
                if (! isset($arr2[1])) $arr2[1] = '';
                $post[$arr2[0]] = $arr2[1];
            }
        } else
            $post = array();
        
        $cookies = false;
        try {
            // Sending cookies
            $cookies = $this->loadCookies($this->request->email, parse_url($url, PHP_URL_HOST));
        } catch (Exception $e) {}
        
        if ($cookies !== false) $options['cookies'] = $cookies;
        
        // Allow redireections
        $options['allow_redirects'] = true;
        
        // Set user agent
        $options['headers'] = array(
                'user-agent' => $this->config[$agent . '_user_agent']
        );
        
        // Sending POST/GET data
        if ($method == 'POST') $options['body'] = $post;
        
        try {
            // Build request
            $http_request = $http_client->createRequest($method, $url, $options);
        } catch (Exception $e) {
            return false;
        }
        
        // Send request
        try {
            $http_response = $http_client->send($http_request);
        } catch (Exception $e) {
            return false;
        }
        
        $http_headers = array();
        // Gedt HTTP headers
        try {
            $http_headers = $http_response->getHeaders();
        } catch (Exception $e) {}
        if (isset($http_headers['Content-Type'])) {
            $ct = $http_headers['Content-Type'][0];
            
            // show image
            if (substr($ct, 0, 6) == 'image/') {
                // save image file
                $filePath = $this->getTempDir() . "/files/image-" . md5($url) . ".jpg";
                file_put_contents($filePath, $http_response->getBody());
                
                // optimize the image
                $this->utils->optimizeImage($filePath);
                
                // save the image in the array for the template
                $images = array(
                        $filePath
                );
                
                return array(
                        'title' => 'Imagen en la web',
                        'type' => 'image',
                        'images' => $images
                );
            }
            
            // Get RSS
            if (substr($ct, 0, 8) == 'text/xml' || substr($ct, 0, 15) == 'application/xml' || substr($ct, 0, 20) == "application/atom+xml") {
                $result = $this->getRSS($url);
                
                if ($result !== false) {
                    return array(
                            'title' => 'Canal de noticias',
                            'type' => 'rss',
                            'results' => $result
                    );
                }
                
                // else: is a simple XML
            }
            
            // attach other files
            if (substr($ct, 0, 9) != 'text/html' && substr($ct, 0, 10) != 'text/xhtml' && strpos($ct, 'application/xhtml+xml') === false) {
                
                $size = $this->getFileSize($url);
                
                if ($size / 1024 > $this->config['max_attachment_size']) {
                    return array(
                            'title' => 'Archivo demasiado grande',
                            'type' => 'ftp_bigfile',
                            'size' => $size,
                            'images' => array(),
                            'attachments' => array()
                    );
                }
                
                $fname = $this->getTempDir() . "/files/" . md5($url);
                
                if (file_exists($fname))
                    $content = file_get_contents($fname);
                else {
                    $content = $http_response->getBody();
                    
                    file_put_contents($fname, $content);
                }
                
                // Trying to zip file
                $zip = new ZipArchive();
                
                $finalname = $fname;
                $zipped = false;
                $r = $zip->open($fname . ".zip", file_exists($fname . ".zip") ? ZipArchive::OVERWRITE : ZipArchive::CREATE);
                
                if ($r !== false) {
                    
                    $f = explode("/", $url);
                    $f = $f[count($f) - 1];
                    
                    $zip->addFromString($f, file_get_contents($fname));
                    $zip->close();
                    
                    $finalname = $fname . '.zip';
                    $zipped = true;
                }
                
                return array(
                        'title' => 'Archivo descargado de la web',
                        'type' => 'http_file',
                        'size' => number_format(filesize($finalname) / 1024, 0),
                        'zipped' => $zipped,
                        'images' => array(),
                        'attachments' => array(
                                $finalname
                        )
                );
            }
        }
        
        // Getting cookies
        $jar = new \GuzzleHttp\Cookie\CookieJar();
        $jar->extractCookies($http_request, $http_response);
        
        // Save cookies
        $this->saveCookies($this->request->email, parse_url($url, PHP_URL_HOST), $jar);
        
        $resources = array();
        
        // Getting HTML page
        $css = '';
        $body = $http_response->getBody();
        
        // Force to UTF8 encoding
        $body = ForceUTF8\Encoding::toUTF8($body);
        
        $tidy = new tidy();
        $body = $tidy->repairString($body, array(
                'output-xhtml' => true
        ), 'utf8');
        
        $doc = new DOMDocument();
        
        @$doc->loadHTML($body);
        
        // Getting BASE of URLs (base tag)
        $base = $doc->getElementsByTagName('base');
        if ($base->length > 0) $this->base = $base->item(0)->getAttribute('href');
        
        // Get the page's title
        
        $title = $doc->getElementsByTagName('title');
        
        if ($title->length > 0)
            $title = $title->item(0)->nodeValue;
        else
            $title = $url;
            
            // Convert links to mailto
        $links = $doc->getElementsByTagName('a');
        
        if ($links->length > 0) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                
                if ($href == false || empty($href)) $href = $link->getAttribute('data-src');
                
                if (substr($href, 0, 1) == '#') {
                    $link->setAttribute('href', '');
                    continue;
                }
                if (strtolower(substr($href, 0, 7)) == 'mailto:') continue;
                
                $link->setAttribute('href', $this->convertToMailTo($href, $url));
            }
        }
        
        // Array for store replacements of DOM's nodes
        $replace = array();
        $in_the_end = array();
        
        // Parsing forms
        
        $forms = $doc->getElementsByTagName('form');
        if ($forms->length > 0) {
            foreach ($forms as $form) {
                if ($form->hasAttribute('action')) {
                    if (strtolower($form->getAttribute('method')) == 'post') {
                        $newchild = $doc->createElement('input');
                        $newchild->setAttribute('type', 'hidden');
                        $newchild->setAttribute('name', 'apretaste-form-method');
                        $newchild->setAttribute('value', 'post');
                        $form->appendChild($newchild);
                    }
                    $form->setAttribute('method', 'post');
                    $newaction = $form->getAttribute('action');
                    $newaction = $this->convertToMailTo($newaction, $url, '', true);
                    $form->setAttribute('action', $newaction);
                }
            }
        }
        
        // Get scripts
        $scripts = $doc->getElementsByTagName('script');
        
        if ($scripts->length > 0) {
            foreach ($scripts as $script) {
                $src = $this->getFullHref($script->getAttribute('src'), $url);
                
                if ($src != $url) $resources[$src] = $src;
            }
        }
        
        // Get CSS stylesheets
        $styles = $doc->getElementsByTagName('style');
        
        if ($styles->length > 0) {
            foreach ($styles as $style) {
                $css .= $style->nodeValue;
            }
        }
        
        // Remove some tags
        $tags = array(
                'script',
                'style',
                'noscript'
        );
        
        foreach ($tags as $tag) {
            $elements = $doc->getElementsByTagName($tag);
            
            if ($elements->length > 0) {
                foreach ($elements as $element) {
                    
                    $replace[] = array(
                            'oldnode' => $element,
                            'newnode' => null,
                            'parent' => $element->parentNode
                    );
                }
            }
        }
        
        // Getting LINK tags and retrieve CSS
        $styles = $doc->getElementsByTagName('link');
        
        if ($styles->length > 0) {
            foreach ($styles as $style) {
                
                // Is CSS?
                if ($style->getAttribute('rel') == 'stylesheet') {
                    
                    $href = $this->getFullHref($style->getAttribute('href'), $url);
                    
                    $r = @file_get_contents($href);
                    
                    if ($r !== false) {
                        $css .= $r;
                        $resources[$href] = $href;
                    }
                }
            }
        }
        
        // Replace/remove childs
        
        foreach ($replace as $rep) {
            try {
                if (is_null($rep['newnode']))
                    $rep['parent']->removeChild($rep['oldnode']);
                else
                    $rep['parent']->replaceChild($rep['newnode'], $rep['oldnode']);
            } catch (Exception $e) {
                continue;
            }
        }
        
        $replace = array();
        
        $body = $doc->saveHTML();
        
        // Set style to each element in DOM, based on CSS stylesheets
        
        $css = ForceUTF8\Encoding::toUTF8($css);
        
        $emo = new Pelago\Emogrifier($body, $css);
        $emo->disableInvisibleNodeRemoval();
        
        try {
            $body = @$emo->emogrify();
        } catch (Exception $e) {}
        
        @$doc->loadHTML($body);
        
        $nodeBody = $doc->getElementsByTagName('body');
        
        $styleBody = @$nodeBody[0]->getAttribute('style');
        $styleBody = $this->fixStyle($styleBody);
        $styleBody = str_replace('"', "'", $styleBody);
        
        $tags_to_fix = explode(' ', 'a p label div pre h1 h2 h3 h4 h5 button i b u li ol ul fieldset small legend form input span button nav table tr th td thead');
        
        foreach ($tags_to_fix as $tagname) {
            if (array_search($tagname, array(
                    'input'
            )) !== false) continue;
            
            $tags = $doc->getElementsByTagName($tagname);
            if ($tags->length > 0) {
                foreach ($tags as $tag) {
                    if (trim($tag->nodeValue) == '' && $tag->childNodes->length == 0) {
                        $replace[] = array(
                                'parent' => $tag->parentNode,
                                'oldnode' => $tag,
                                'newnode' => null
                        );
                    }
                }
            }
        }
        // Fixing PRE
        
        $pres = $doc->getElementsByTagName('pre');
        
        if ($pres->length > 0) {
            foreach ($pres as $pre) {
                $lines = split('\n', $pre->nodeValue);
                
                $newpre = $doc->createElement('div');
                foreach ($lines as $line) {
                    $line = str_replace(' ', '&nbsp;', $line);
                    $newp = $doc->createElement('p', $line);
                    $newp->setAttribute('style', 'line-height:13px;');
                    $newpre->appendChild($newp);
                }
                
                $newpre->setAttribute('style', $pre->getAttribute('style') . ';font-size: 13px;font-family:Courier;');
                $replace[] = array(
                        'parent' => $pre->parentNode,
                        'oldnode' => $pre,
                        'newnode' => $newpre
                );
            }
        }
        
        // Fixing styles
        
        foreach ($tags_to_fix as $tag) {
            
            $links = $doc->getElementsByTagName($tag);
            
            if ($links->length > 0) {
                foreach ($links as $link) {
                    $sty = $link->getAttribute('style');
                    $sty = $this->fixStyle($sty);
                    $link->setAttribute('style', $sty);
                    $link->setAttribute('class', '');
                }
            }
        }
        
        // Convert image tags to NAVEGAR links
        
        $style_navegar_links = 'margin:10px; background:#5EBB47;color:#FFFFFE;padding:5px;max-width:300px;max-height:300px;border: none; line-height: 2; text-decoration:none;';
        $stroke = '#5dbd00';
        $fill = '#5EBB47';
        $text = '#FFFFFE';
        $width = 150;
        $fontsize = 16;
        $height = 44;
        $style_navegar_links = "background-color:$fill;border:1px solid $stroke;border-radius:3px;color:$text;display:inline-block;font-family:sans-serif;font-size:{$fontsize}px;line-height:{$height}px;text-align:center;text-decoration:none;width:{$width}px;-webkit-text-size-adjust:none;mso-hide:all;";
        $images = $doc->getElementsByTagName('img');
        
        if ($images->length > 0) {
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
                $width = $image->getAttribute('width');
                $height = $image->getAttribute('height');
                $style = $image->getAttribute('style');
                
                if (empty("$width")) $width = null;
                if (empty("$height")) $height = null;
                if (empty("$style")) $style = null;
                
                $imgname = explode("/", $src);
                $imgname = $imgname[count($imgname) - 1];
                
                $imgname = str_replace(array(
                        ' ',
                        '-'
                ), '_', $imgname);
                
                $id = uniqid();
                
                $node = $doc->createElement('a', '{' . $id . '}');
                // $node->setAttribute('style', (! is_null($style) ? $style :
                // "") . $style_navegar_links . (! is_null($width) ?
                // ";width:{$width}px;" : "") . (is_null($height) ?
                // "height:{$height}px;" : ""));
                $node->setAttribute('href', $this->convertToMailTo($src, $url));
                $img_w = $node->getAttribute('width');
                $img_h = $node->getAttribute('height');
                
                if (empty($img_w)) $img_w = 100;
                if (empty($img_h)) $img_h = 100;
                
                $img_style = $node->getAttribute('style');
                $node->setAttribute('style', $img_style);
                
                $replace[] = array(
                        'parent' => $image->parentNode,
                        'oldnode' => $image,
                        'newnode' => $node
                );
                
                $in_the_end[$id] = $this->getHTMLOfNoImage(array(
                        'width' => $img_w,
                        'height' => $img_h,
                        'text' => 'IMAGEN'
                ));
            }
        }
        
        // Convert IFRAMES to NAVEGAR links
        
        $iframes = $doc->getElementsByTagName('iframe');
        
        if ($iframes->length > 0) {
            foreach ($iframes as $iframe) {
                $src = $iframe->getAttribute('src');
                // $button = $this->buildButton($this->convertToMailTo($src,
                // $url), "[ MARCO ]");
                $node = $doc->createElement('a', "P&Aacute;GINA");
                $node->setAttribute('style', $style_navegar_links);
                $node->setAttribute('href', $this->convertToMailTo($src, $url));
                $replace[] = array(
                        'parent' => $iframe->parentNode,
                        'oldnode' => $iframe,
                        'newnode' => $node
                );
                $resources[$src] = $src;
            }
        }
        
        // Replace/remove childs [again]
        
        foreach ($replace as $rep) {
            try {
                if (is_null($rep['newnode']))
                    $rep['parent']->removeChild($rep['oldnode']);
                else
                    $rep['parent']->replaceChild($rep['newnode'], $rep['oldnode']);
            } catch (Exception $e) {
                continue;
            }
        }
        
        $body = $doc->saveHTML();
        
        // Get only the body
        $body = $tidy->repairString($body, array(
                'output-xhtml' => true,
                'show-body-only' => true
        ), 'utf8');
        
        // Cleanning the text to look better in the email
        /*
         * $body = str_ireplace("<br>", "<br>\n", $body);
         * $body = str_ireplace("<br/>", "<br/>\n", $body);
         * $body = str_ireplace("</p>", "</p>\n", $body);
         * $body = str_ireplace("</h1>", "</h1>\n", $body);
         * $body = str_ireplace("</h2>", "</h2>\n", $body);
         * $body = str_ireplace("</span>", "</span>\n", $body);
         * $body = str_ireplace("/>", "/>\n", $body);
         * $body = wordwrap($body, 200, "\n");
         */
        $body = str_ireplace('class=""', '', $body);
        $body = str_ireplace('style=""', '', $body);
        
        // strip unnecessary, dangerous tags
        /*
         * $body = strip_tags($body,
         * '<input><button><a><abbr><acronym><address><area><article><aside><audio><b><base><basefont><bdi><bdo><big><blockquote><br><canvas><caption><center><cite><code><col><colgroup><command><datalist><dd><del><details><dfn><dialog><dir><div><dl><dt><em><embed><fieldset><figcaption><figure><font><footer><form><frame><frameset><head><header><h1>
         * -
         * <h6><hr><i><ins><kbd><keygen><label><legend><li><link><map><mark><menu><meta><meter><nav><noframes><noscript><object><ol><optgroup><option><output><p><param><pre><progress><q><rp><rt><ruby><s><samp><section><select><small><source><span><strike><strong><style><sub><summary><sup><table><tbody><td><textarea><tfoot><th><thead><time><title><tr><track><tt><u><ul><var><video><wbr><h2><h3>');
         */
        // Compress the returning code
        $body = preg_replace('/\s+/S', " ", $body);
        
        // Cut large pages
        $limit = 1024 * 400; // 400KB
        $body_length = strlen($body);
        if ($body_length > $limit) $body = substr($body, 0, $limit);
        
        foreach ($in_the_end as $id => $code) {
            $body = str_replace('{' . $id . '}', $code, $body);
        }
        
        // Return results
        return array(
                'title' => $title,
                'body' => $body,
                'style' => $styleBody,
                'body_length' => number_format($body_length / 1024, 2),
                'url' => $url,
                'resources' => $resources
        );
    }

    private function getHTMLOfNoImage ($params)
    {
        $width = isset($params["width"]) ? $params["width"] : "100";
        $height = isset($params["height"]) ? $params["height"] : "100";
        $text = isset($params["text"]) ? strtoupper($params["text"]) : "NO FOTO";
        
        return "
		<table>
			<tr>
				<td width='$width' height='$height' bgcolor='#F2F2F2' align='center' valign='middle'>
					<div style='width:{$width}px; color:gray;'>
						<small>$text</small>
					</div>
				</td>
			</tr>
		</table>";
    }

    private function saveCookies ($email, $host, $jar)
    {
        $tempdir = $this->getTempDir();
        $fname = $tempdir . "/cookies/$email-" . md5($host);
        file_put_contents($fname, serialize($jar));
    }

    private function loadCookies ($email, $host)
    {
        $tempdir = $this->getTempDir();
        $fname = $tempdir . "/cookies/$email-" . md5($host);
        if (file_exists($fname)) {
            $content = file_get_contents($fname);
            return unserialize($content);
        }
        return false;
    }

    /**
     */
    private function getTempDir ()
    {
        $wwwroot = $this->wwwroot;
        
        if (! file_exists("$wwwroot/temp/navegar")) mkdir("$wwwroot/temp/navegar");
        if (! file_exists("$wwwroot/temp/navegar/cookies")) mkdir("$wwwroot/temp/navegar/cookies");
        if (! file_exists("$wwwroot/temp/navegar/files")) mkdir("$wwwroot/temp/navegar/files");
        if (! file_exists("$wwwroot/temp/navegar/searchcache")) mkdir("$wwwroot/temp/navegar/searchcache");
        
        return "$wwwroot/temp/navegar";
    }

    /**
     * Check URL
     *
     * @param string $text            
     * @return boolean
     */
    private function isUrl ($text)
    {
        $text = strtolower($text);
        if (substr($text, 0, 7) == 'http://') return true;
        if (substr($text, 0, 6) == 'ftp://') return true;
        if (substr($text, 0, 8) == 'https://') return true;
        if (strpos($text, ' ') === false && strpos($text, '.') !== false) return true;
        return false;
    }

    /**
     * Search on the web
     *
     * @param string $query            
     * @return string
     */
    private function search ($query, $source = 'web')
    {
        $cacheFile = $this->getTempDir() . "/searchcache/$source-" . md5($query);
        
        if (file_exists($cacheFile) && time() - filemtime($cacheFile) > $this->config['cache_life_time']) {
            $content = file_get_contents($cacheFile);
        } else {
            $config = $this->config['search-api-faroo'];
            
            // http://www.faroo.com/api?q=cuba&start=1&length=10&l=en&src=web&i=false&f=json&key=G2POOpVSD35690JspEW8SxnI@XI_
            $url = $config['base_url'] . '?' . (empty($query) ? '' : 'q=' . urlencode("$query"));
            $url .= "&start=1";
            $url .= "&length=" . $config['results_length'];
            $url .= "&l=es";
            $url .= "&src=" . $source;
            $url .= "&i=false&f=json&key=" . $config['key'];
            
            $content = @file_get_contents($url);
            if ($content != false)
                file_put_contents($cacheFile, $content);
            else
                $content = '';
        }
        
        $result = json_decode($content);
        
        // Save stats
        $this->saveSearchStat($source, $query);
        
        if (isset($result->results)) if (is_array($result->results)) {
            foreach ($result->results as $k => $v) {
                $v->date = date("d/m/Y", $v->date);
                
                $result->results[$k] = $v;
            }
            return $result->results;
        }
        
        return array();
    }

    /**
     * Check if URL exists
     *
     * @param string $url            
     * @return boolean
     */
    private function urlExists ($url)
    {
        $headers = @get_headers($url, 1);
        if ($headers === false) return false;
        if (isset($headers[0])) {
            if (stripos($headers[0], '200 OK') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return full HREF
     */
    private function getFullHref ($href, $url)
    {
        $href = trim($href);
        if ($href == '' || $href == 'javascript(0);' || $href == 'javascript:void(0);') return $url;
        if (strtolower(substr($href, 0, 2) == '//')) return 'http:' . $href;
        if (strtolower(substr($href, 0, 1) == '?')) {
            if (! is_null($this->base)) return $this->base . $href;
            return $url . $href;
        }
        
        $base = '';
        
        if ($this->isHttpURL($href) || $this->isFtpURL($href)) return $href;
        
        if (! $this->isHttpURL($url) && ! $this->isFtpURL($url)) $url = 'http://' . $url;
        
        $url = trim($url);
        
        if (substr($url, strlen($url) - 1, 1) == "/") $url = substr($url, 0, strlen($url) - 1);
        
        $parts = parse_url($url);
        
        if (! is_null($this->base))
            $base = $this->base;
        else {
            if ($parts === false) return $href;
            
            if (! isset($parts['port'])) $parts['port'] = 80;
            
            $base = $parts['scheme'] . "://" . $parts['host'] . ":" . $parts['port'] . "/";
            
            if (isset($parts['path'])) {
                $p = $parts['path'];
                
                $exts = explode(' ', 'html htm php5 exe php jsp asp aspx jsf py');
                
                foreach ($exts as $ext)
                    if (stripos($p, '.' . $ext) !== false) {
                        $base .= dirname($p) . "/";
                        break;
                    }
            }
        }
        
        return $base . $href;
    }

    /**
     * Return TRUE if url is a HTTP or HTTPS
     *
     * @param string $url            
     * @return boolean
     */
    private function isHttpURL ($url)
    {
        $url = trim(strtolower($url));
        return strtolower(substr($url, 0, 7)) === 'http://' || strtolower(substr($url, 0, 8)) === 'https://';
    }

    /**
     * Return TRUE if url is a FTP
     *
     * @param string $url            
     * @return boolean
     */
    private function isFtpURL ($url)
    {
        $url = trim(strtolower($url));
        return strtolower(substr($url, 0, 6)) === 'ftp://';
    }

    /**
     * Singleton, return valid email address to A!
     *
     * @return String
     */
    private function getMailTo ()
    {
        if (is_null($this->mailto)) $this->mailto = $this->utils->getValidEmailAddress();
        
        return $this->mailto;
    }

    /**
     * Repair HREF/SRC attributes
     *
     * @param string $href            
     * @param string $url            
     * @return string
     */
    private function convertToMailTo ($href, $url, $body = '', $ignoreSandbox = false)
    {
        if (trim($href) == '') return '';
        
        // if ($href[0] == '?') $url = dirname($url);
        
        // create direct link for the sandbox
        $di = \Phalcon\DI\FactoryDefault::getDefault();
        
        $fullhref = $this->getFullHref($href, $url);
        if ($di->get('environment') == "sandbox" && ! $ignoreSandbox) {
            $wwwhttp = $di->get('path')['http'];
            return "$wwwhttp/run/display?subject=NAVEGAR " . $fullhref . ($body == '' ? '' : "&amp;body=$body");
        } else {
            
            $newhref = 'mailto:' . $this->getMailTo() . '?subject=NAVEGAR ' . $fullhref;
            $newhref = str_replace("//", "/", $newhref);
            $newhref = str_replace("//", "/", $newhref);
            $newhref = str_replace("//", "/", $newhref);
            $newhref = str_replace("http:/", "http://", $newhref);
            $newhref = str_replace("http/", "http://", $newhref);
            
            return $newhref;
        }
    }

    /**
     * Returns the size of a file without downloading it, or -1 if the file
     * size could not be determined.
     *
     * @param string $url
     *            The location of the remote file to download. Cannot be null or
     *            empty.
     *            
     * @return The size of the file referenced by $url, or -1 if the size
     *         could not be determined.
     */
    private function getFileSize ($url)
    {
        
        // Assume failure.
        $result = - 1;
        
        if (is_null($url) || empty($url)) return - 1;
        
        $curl = curl_init($url);
        
        // Issue a HEAD request and follow any redirects.
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->config['default_user_agent']);
        
        $data = curl_exec($curl);
        curl_close($curl);
        
        if ($data) {
            $content_length = "unknown";
            $status = "unknown";
            
            if (preg_match("/^HTTP\/1\.[01] (\d\d\d)/", $data, $matches)) {
                $status = (int) $matches[1];
            }
            
            if (preg_match("/Content-Length: (\d+)/", $data, $matches)) {
                $content_length = (int) $matches[1];
            }
            
            // http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
            if ($status == 200 || ($status > 300 && $status <= 308)) {
                $result = $content_length;
            }
        }
        
        return $result;
    }

    /**
     * Save visit stats
     *
     * @param string $url            
     */
    private function saveVisit ($url)
    {
        try {
            $site = parse_url($url, PHP_URL_HOST);
            
            if ($site === false) $site = $url;
            
            if (! empty(trim($site))) return false;
            
            $db = new Connection();
            $r = $db->deepQuery("SELECT * FROM _navegar_visits WHERE site = '$site';");
            
            if (empty($r)) {
                
                $sql = "INSERT INTO _navegar_visits (site) VALUES ('$site');";
            } else {
                $sql = "UPDATE _navegar_visits SET usage_count = usage_count + 1, last_usage = CURRENT_TIMESTAMP WHERE site = '$site';";
            }
            
            $db->deepQuery($sql);
            
            return true;
        } catch (Exception $e) {}
    }

    /**
     * Save stats of searches
     *
     * @param string $source            
     * @param string $query            
     */
    private function saveSearchStat ($source, $query)
    {
        $query = trim(strtolower($query));
        
        while (strpos($query, '  ') !== false)
            $query = str_replace('  ', ' ', $query);
        
        try {
            $db = new Connection();
            $where = "WHERE search_source = '$source' AND search_query = '$query'";
            $r = $db->deepQuery("SELECT * FROM _navegar_searchs $where");
            
            if (empty($r)) {
                $sql = "INSERT INTO _navegar_searchs (search_source, search_query) VALUES ('$source','$query');";
            } else {
                $sql = "UPDATE _navegar_searchs SET usage_count = usage_count + 1, last_usage = CURRENT_TIMESTAMP $where;";
            }
            
            $db->deepQuery($sql);
        } catch (Exception $e) {}
    }

    /**
     * Directory listing
     *
     * @param string $host            
     * @param string $user            
     * @param string $pass            
     * @param string $dir            
     * @return boolean|string|boolean
     */
    private function listFTP ($host, $port = 21, $user = "anonymous", $pass = "123", $dir = "/")
    {
        $ftp = new ftp(false);
        $ftp->Verbose = false;
        $ftp->LocalEcho = false;
        
        if ($ftp->SetServer($host, $port)) {
            if ($ftp->connect()) {
                if ($ftp->login($user, $pass)) {
                    $ftp->chdir($dir);
                    $ftp->nlist("-la");
                    $list = $ftp->rawlist(".", "-lA");
                    if ($list !== false) {
                        foreach ($list as $k => $v) {
                            $list[$k] = $ftp->parselisting($v);
                        }
                        return $list;
                    }
                }
            }
        }
        
        $ftp->quit();
        
        return false;
    }

    /**
     * Get FTP directory list
     *
     * @param string $url            
     * @return array
     */
    private function getFTP ($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH);
        $user = parse_url($url, PHP_URL_USER);
        $pass = parse_url($url, PHP_URL_PASS);
        
        if (empty($port)) $port = 21;
        if (empty($user)) $user = 'anonymous';
        if (empty($pass)) $pass = 'ftp';
        if (empty($path)) $path = "./";
        
        $ftp = new ftp(false);
        $ftp->Verbose = false;
        $ftp->LocalEcho = false;
        
        if ($ftp->SetServer($host, $port)) if ($ftp->connect()) {
            
            if ($ftp->login($user, $pass)) {
                $path = str_replace("//", "", $path);
                $size = $ftp->filesize($path);
                if (empty("$size")) $size = false;
                
                /*
                 * $ftp = ftp_connect($host, $port);
                 *
                 * $login_result = ftp_login($ftp, $user, $pass);
                 *
                 * if ($login_result) {
                 * $r = @ftp_chdir($ftp, $path);
                 *
                 * if ($r === false) {
                 * $size = ftp_size($ftp, $path);
                 */
                if ($size >= 0 && $size !== false) {
                    if ($size <= $this->config['max_attachment_size']) {
                        $local_file = $this->getTempDir() . "/files/" . md5($url);
                        
                        // $r = ftp_get($ftp, $local_file, $path, FTP_BINARY);
                        $r = $ftp->get($path, $local_file);
                        
                        if ($r !== false) {
                            $finalname = $local_file;
                            $zipped = false;
                            // Trying to zip file
                            $zip = new ZipArchive();
                            
                            $r = $zip->open($local_file . ".zip", file_exists($local_file . ".zip") ? ZipArchive::OVERWRITE : ZipArchive::CREATE);
                            
                            if ($r !== false) {
                                
                                $f = explode("/", $url);
                                $f = $f[count($f) - 1];
                                
                                $zip->addFromString($f, file_get_contents($local_file));
                                $zip->close();
                                
                                $finalname = $local_file . '.zip';
                                $zipped = true;
                            }
                            
                            return array(
                                    "type" => "file",
                                    "size" => number_format($size / 1024, 0),
                                    "zipped" => $zipped,
                                    "filepath" => $finalname
                            );
                        }
                        
                        return array(
                                "type" => "file_fail",
                                "size" => $size
                        );
                    } else {
                        return array(
                                "type" => "bigfile",
                                "size" => $size
                        );
                    }
                } else {
                    
                    /*
                     * $contents = ftp_nlist($ftp, ".");
                     *
                     * foreach ($contents as $k => $v) {
                     * $contents[$k] = str_replace("./", "", $v);
                     * }
                     */
                    
                    $list = $this->listFTP($host, $port, $user, $pass, $path);
                    
                    if ($list !== false) {
                        $i = 0;
                        $newlist;
                        foreach ($list as $k => $v) {
                            $s = $v['size'];
                            if ($s > 1025) {
                                $s = $s / 1024;
                                if ($s > 1024) {
                                    $s = $s / 1024;
                                    if ($s > 1024) {
                                        $s = $s / 1024;
                                        $s = number_format($s, 0) . " GB";
                                    } else
                                        $s = number_format($s, 0) . " MB";
                                } else
                                    $s = number_format($s, 0) . " KB";
                            } else
                                $s = number_format($s, 0) . " B";
                            $v['size'] = $s;
                            $newlist[] = $v;
                            $i ++;
                            if ($i > 200) break;
                        }
                        
                        return array(
                                "type" => "dir",
                                "contents" => $newlist
                        );
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Retrieve RSS/Atom feed
     *
     * @param unknown $url            
     * @return NULL[]
     */
    private function getRSS ($url)
    {
        
        // TODO: Check the size of XML?
        $rss = simplexml_load_file($url);
        $root_element_name = $rss->getName();
        
        if ($root_element_name == 'feed') {
            $result = array();
            
            if (isset($rss->title))
                $result['title'] = $rss->title . "";
            else
                $result['title'] = 'Canal ATOM';
            
            if (isset($rss->entry)) {
                $result['items'] = array(
                        array(
                                'link' => '',
                                'title' => '',
                                'pubDate' => date('Y-m-d') . '',
                                'description' => ''
                        )
                );
                if (isset($rss->entry->link)) $result['items'][0]['link'] = $rss->entry->link[0]->attributes('href') . "";
                if (isset($rss->entry->title)) $result['items'][0]['title'] = ForceUTF8\Encoding::toUTF8($rss->entry->title) . '';
                if (isset($rss->entry->updated)) $result['items'][0]['pubDate'] = $rss->entry->updated . '';
                if (isset($rss->entry->summary)) $result['items'][0]['description'] = ForceUTF8\Encoding::toUTF8($rss->entry->summary) . '';
            }
            
            return $result;
        } else 
            if ($root_element_name == 'rss') {
                // is rss feed
                $result = array(
                        'title' => 'Canal de noticias',
                        'items' => array()
                );
                
                if (isset($rss->channel->title)) $result['title'] = ForceUTF8\Encoding::toUTF8($rss->channel->title) . '';
                
                if (isset($rss->channel->item)) foreach ($rss->channel->item as $item) {
                    
                    $data = array(
                            'link' => '',
                            'title' => '',
                            'pubDate' => date('Y-m-d') . '',
                            'description' => ''
                    );
                    
                    if (isset($item->link)) $data['link'] = $item->link;
                    if (isset($item->title)) $data['title'] = ForceUTF8\Encoding::toUTF8($item->title);
                    if (isset($item->pubDate)) $data['pubDate'] = $item->pubDate;
                    if (isset($item->description)) $data['description'] = ForceUTF8\Encoding::toUTF8($item->description);
                    
                    $result['items'][] = $data;
                }
                
                return $result;
            }
        return false;
    }

    /**
     * Fix style for email sites
     *
     * @param string $style            
     * @return string
     */
    private function fixStyle ($style)
    {
        $rules = array();
        $parts = explode(';', $style);
        foreach ($parts as $part) {
            if (trim($part) == '') continue;
            $p = strpos($part, ":");
            if ($p === false) continue;
            $prop = strtolower(trim(substr($part, 0, $p)));
            if (trim($prop) == '') continue;
            $prop = trim(str_replace('!important', '', $prop));
            $rules[$prop] = trim(substr($part, $p + 1));
        }
        
        $valid_rules = array(
                'color',
                'background',
                'background-color',
                'text-align',
                'text-decoration',
                'font',
                'font-weight',
                'font-family',
                'font-size',
                'float',
                'list-style',
                'margin',
                'margin-top',
                'margin-left',
                'margin-right',
                'margin-bottom',
                'padding',
                'padding-top',
                'padding-left',
                'padding-right',
                'padding-bottom',
                'border',
                'border-width',
                'border-color',
                'border-radius',
                'line-height',
                'display',
                'width',
                'height',
                '-webkit-text-size-adjust',
                'mso-hide',
                'position',
                'white-space',
                'list-style-type',
                'font-style'
        );
        
        // $oDoc = $parser->parseString($style);
        $contrast = 'white';
        $new_style = '';
        
        // fixing contrast
        $color = false;
        $background = false;
        
        foreach ($rules as $rule => $value) {
            
            switch ($rule) {
                case 'color':
                    if (strpos($value, 'url') === false && strpos($value, ' ') === false) {
                        $color = $value;
                    }
                    break;
                case 'background-color':
                case 'background':
                case 'background-image':
                    
                    if (strpos($value, 'url') === false && strpos($value, ' ') === false) {
                        $background = $value;
                        break;
                    }
                    
                    break;
            }
        }
        
        // set default contrast as white & black
        if ($background !== false && $color !== false) {
            // calculate as decimal
            $color = new CSSColor($color);
            $background = new CSSColor($background);
            $color_dec = hexdec(substr($color->getHexValue(), 1));
            $back_dec = hexdec(substr($background->getHexValue(), 1));
            
            // calculate the difference
            if (abs($color_dec - $back_dec) <= 255) {
                
                // get the best contrast
                $contrast = $this->getContrastYIQ(substr($color->getHexValue(), 1));
                
                $background->toRGB();
                switch ($contrast) {
                    case 'white':
                        $ss = '#FFFFFF';
                        break;
                    case 'black':
                        $ss = '#000000';
                        break;
                }
                
                $rules['background-color'] = $ss;
                $rules['background'] = $ss;
            }
        }
        
        // fixing other rules
        foreach ($rules as $rn => $value) {
            $ignore_rule = false;
            
            if (array_search($rn, $valid_rules) === false) continue;
            
            switch ($rn) {
                case 'width':
                    if (stripos($value, 'px') !== false) {
                        $w = intval(str_replace('px', '', $value));
                        if ($w > 600) $rules[$rn] = '600px';
                    }
                    break;
                case 'height':
                    if (stripos($value, 'px') !== false) {
                        $h = intval(str_replace('px', '', $value));
                        if ($h > 100) $ignore_rule = true;
                    } else
                        $ignore_rule == true;
                    
                    break;
                case 'position':
                    $rules[$rn] = 'inherit';
                    break;
                case 'display':
                    
                    if ($value == 'none' || $value == 'hidden') $rules[$rn] = 'block';
                    
                    break;
                case 'visibility':
                    if ($value == 'none' || $value == 'hidden') $rules[$rn] = 'inherit';
                    
                    break;
                case 'opacity':
                    $rules[$rn] = '100%';
                    
                    break;
                case 'left':
                    if (stripos($value, 'px') === false) $rules[$rn] = '0px';
                    break;
                
                case 'margin-left':
                case 'margin-top':
                case 'margin-bottom':
                case 'margin-right':
                    if (stripos($value, 'px') === false)
                        $ignore_rule = true;
                    else {
                        $m = intval(str_replace('px', '', $value));
                        if ($m < 0) $rules[$rn] = '5px';
                    }
                    
                    break;
                case 'float':
                    if ($value == 'right') $ignore_rule = true;
                    break;
                case 'white-space':
                    if ($value == 'nowrap') $ignore_rule = true;
                    break;
            }
            
            if (! $ignore_rule) {
                $new_style .= $rn . ":" . $rules[$rn] . ';';
            }
        }
        
        return $new_style;
    }

    /**
     * Build
     *
     * @param unknown $linkto            
     * @param unknown $caption            
     * @return string
     */
    private function buildButton ($linkto, $caption)
    {
        $width = 150;
        $fontsize = 16;
        $height = 44;
        $stroke = '#5dbd00';
        $fill = '#5EBB47';
        $text = '#FFFFFF';
        return "<!--[if mso]>
        <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='$linkto' style='height:{$height}px;v-text-anchor:middle;width:{$width}px;' arcsize='5%' strokecolor='$stroke' fillcolor='$fill'>
        <w:anchorlock/>
        <center style='color:$text;font-family:Helvetica, Arial,sans-serif;font-size:{$fontsize}px;'>$caption</center>
        </v:roundrect>
        <![endif]-->
        <a href='$linkto' style='background-color:$fill;border:1px solid $stroke;border-radius:3px;color:$text;display:inline-block;font-family:sans-serif;font-size:{$fontsize}px;line-height:{$height}px;text-align:center;text-decoration:none;width:{$width}px;-webkit-text-size-adjust:none;mso-hide:all;'>$caption</a>";
    }

    /**
     * Better contrast
     *
     * @param string $hexcolor            
     * @return string
     */
    private function getContrastYIQ ($hexcolor)
    {
        $r = hexdec(substr($hexcolor, 0, 2));
        $g = hexdec(substr($hexcolor, 2, 2));
        $b = hexdec(substr($hexcolor, 4, 2));
        $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
        return ($yiq >= 128) ? 'black' : 'white';
    }
}