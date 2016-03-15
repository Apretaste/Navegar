<?php

/**
 * Apretaste
 * 
 * NAVEGAR service
 * 
 * @author kuma (kumarajiva2015@gmail.com)
 * @version 1.0
 */
use Goutte\Client;

class Navegar extends Service
{

    private $mailto = null;

    private $request = null;

    private $config = null;

    private $wwwroot = null;

    /**
     * Function executed when the service is called
     *
     * @param Request $request            
     * @return Response
     */
    public function _main (Request $request, $agent = 'default')
    {
        // Save request
        $this->request = $request;
        
        // Load configuration
        $this->loadServiceConfig();
        
        // Get path to the www folder
        $di = \Phalcon\DI\FactoryDefault::getDefault();
        $this->wwwroot = $di->get('path')['root'];
        
        // Load libs
        require_once "{$this->pathToService}/lib/Emogrifier.php";
        require_once "{$this->pathToService}/lib/php_ftp/ftp.class.php";
        require_once "{$this->pathToService}/lib/php_ftp/ftp.api.php";
        
        $request->query = trim($request->query);
        
        // Welcome message when query is empty
        if ($request->query == '') {
            $response = new Response();
            $response->setResponseSubject("Navegar en Internet");
            
            $db = new Connection();
            
            $sql = "SELECT * FROM _navegar_visits ORDER BY usage_count DESC LIMIT 10;";
            
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
            $response->setResponseSubject("No se puedo acceder");
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
        $response->createFromTemplate("{$responseContent['type']}.tpl", $responseContent);
        
        return $response;
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
        
        // Create http client
        $http_client = new GuzzleHttp\Client(array(
                'cookies' => true
        ));
        
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
            
            // Sending cookies
        $cookies = $this->loadCookies($this->request->email, parse_url($url, PHP_URL_HOST));
        
        if ($cookies !== false) $options['cookies'] = $cookies;
        
        // Allow redireections
        $options['allow_redirects'] = true;
        
        // Set user agent
        $options['headers'] = array(
                'user-agent' => $this->config[$agent . '_user_agent']
        );
        
        // Sending POST/GET data
        if ($method == 'POST') $options['body'] = $post;
        
        // Build request
        $http_request = $http_client->createRequest($method, $url, $options);
        
        // Send request
        try {
            $http_response = $http_client->send($http_request);
        } catch (Exception $e) {
            return false;
        }
        
        // Gedt HTTP headers
        $http_headers = $http_response->getHeaders();
        
        if (isset($http_headers['Content-Type'])) {
            $ct = $http_headers['Content-Type'][0];
            
            // show image
            if (substr($ct, 0, 6) == 'image/') {
                // save image file
                $filePath = $this->getTempDir() . "/files/image-" . md5($url);
                file_put_contents($filePath, $http_response->getBody());
                
                // optimize the image
                $this->utils->optimizeImage($filePath, 400);
                
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
        
        $tidy = new tidy();
        $body = $tidy->repairString($body, array(
                'output-xhtml' => true
        ), 'utf8');
        
        $doc = new DOMDocument();
        @$doc->loadHTML($body);
        
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
                $resources[$src] = $src;
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
                    
                    // phase 1: trying href as full css path
                    $href = $this->getFullHref($style->getAttribute('href'), $url);
                    
                    $r = file_get_contents($href);
                    
                    if ($r === false) {
                        // phase 2: trying href with url host
                        $parts = parse_url($url);
                        $temp_url = $parts['scheme'] . '://' . $parts['host'] . '/';
                        $href = trim($style->getAttribute('href'));
                        if ($href[0] == '/') $href = substr($href, 1);
                        $r = @file_get_contents($temp_url . '/' . $href);
                        
                        if ($r == false) {
                            // phase 3: trying href with url host and each part
                            // of url path
                            $parts = explode("/", $parts['path']);
                            foreach ($parts as $part) {
                                $temp_url .= '/' . $part;
                                $r = @file_get_contents($temp_url . '/' . $href);
                                if ($r !== false) break;
                            }
                        }
                    }
                    
                    if ($r !== false) {
                        $css .= $r;
                        $resources[$href] = $href;
                    }
                }
            }
        }
        
        // Convert image tags to NAVEGAR links
        
        $images = $doc->getElementsByTagName('img');
        
        if ($images->length > 0) {
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
                
                $imgname = explode("/", $src);
                $imgname = $imgname[count($imgname) - 1];
                
                $imgname = str_replace(array(
                        ' ',
                        '-'
                ), '_', $imgname);
                
                $node = $doc->createElement('a', "IMAGEN");
                $node->setAttribute('style', 'text-align: center;margin:10px; background:#eeeeee;color:navy;padding:5px;width:100px;height:100px;border: 1px solid navy;line-height: 2;');
                $node->setAttribute('href', $this->convertToMailTo($src, $url));
                
                $replace[] = array(
                        'parent' => $image->parentNode,
                        'oldnode' => $image,
                        'newnode' => $node
                );
            }
        }
        
        // Replace/remove childs
        
        foreach ($replace as $rep) {
            if (is_null($rep['newnode']))
                $rep['parent']->removeChild($rep['oldnode']);
            else
                $rep['parent']->replaceChild($rep['newnode'], $rep['oldnode']);
        }
        
        $body = $doc->saveHTML();
        
        // Set style to each element in DOM, based on CSS stylesheets
        
        $emo = new Pelago\Emogrifier($body, $css);
        $body = $emo->emogrify();
        
        // Get only the body
        $body = $tidy->repairString($body, array(
                'output-xhtml' => true,
                'show-body-only' => true
        ), 'utf8');
        
        // Cleanning the text to look better in the email
        $body = str_replace("<br>", "<br>\n", $body);
        $body = str_replace("<br/>", "<br/>\n", $body);
        $body = str_replace("</p>", "</p>\n", $body);
        $body = str_replace("</h2>", "</h2>\n", $body);
        $body = str_replace("</span>", "</span>\n", $body);
        $body = str_replace("/>", "/>\n", $body);
        $body = str_replace("<p", "<p style=\"text-align:justify;\" align=\"justify\"", $body);
        $body = wordwrap($body, 200, "\n");
        
        // strip unnecessary, dangerous tags
        $body = strip_tags($body, 
                '<input><button><a><abbr><acronym><address><area><article><aside><audio><b><base><basefont><bdi><bdo><big><blockquote><br><canvas><caption><center><cite><code><col><colgroup><command><datalist><dd><del><details><dfn><dialog><dir><div><dl><dt><em><embed><fieldset><figcaption><figure><font><footer><form><frame><frameset><head><header><h1> - <h6><hr><i><ins><kbd><keygen><label><legend><li><link><map><mark><menu><meta><meter><nav><noframes><noscript><object><ol><optgroup><option><output><p><param><pre><progress><q><rp><rt><ruby><s><samp><section><select><small><source><span><strike><strong><style><sub><summary><sup><table><tbody><td><textarea><tfoot><th><thead><time><title><tr><track><tt><u><ul><var><video><wbr><h2><h3>');
        
        // Compress the returning code
        $body = preg_replace('/\s+/S', " ", $body);
        
        // Cut large pages
        $limit = 1024 * 400; // 400KB
        $body_length = strlen($body);
        if ($body_length > $limit) $body = substr($body, 0, $limit);
        
        // Return results
        return array(
                'title' => $title,
                'body' => $body,
                'body_length' => number_format($body_length / 1024, 2),
                'url' => $url,
                'resources' => $resources
        );
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
        
        if (file_exists($cacheFile) /*&& time() - filemtime($cacheFile) > $this->config['cache_life_time']*/) {
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
            return $result->results;
        }
        
        return array();
    }

    /**
     * Return full HREF
     */
    private function getFullHref ($href, $url)
    {
        $base = '';
        
        if (strtolower(substr($href, 0, 2) == '//')) return 'http:' . $href;
        
        if (! $this->isHttpURL($href)) {
            $port = parse_url($url, PHP_URL_PORT);
            $port = ($port == '' ? "" : ":$port");
            if (substr($href, 0, 1) == '/') {
                if (! $this->isHttpURL($url)) $url = 'http://' . $url;
                $base = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST) . $port . "/";
            } else {
                $base = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST) . $port . "/" . parse_url($url, PHP_URL_PATH);
            }
            
        }
        if (substr($base, strlen($base) - strlen($href)) == $href) $href = '';
        return (empty($base) ? $href : $base . (empty($href) ? '' : "/" . $href));
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
        // create direct link for the sandbox
        $di = \Phalcon\DI\FactoryDefault::getDefault();
        
        if ($di->get('environment') == "sandbox" && ! $ignoreSandbox) {
            $wwwhttp = $di->get('path')['http'];
            return "$wwwhttp/run/display?subject=NAVEGAR " . $this->getFullHref($href, $url) . ($body == '' ? '' : "&amp;body=$body");
        } else {
            
            $newhref = 'mailto:' . $this->getMailTo() . '?subject=NAVEGAR ' . $this->getFullHref($href, $url);
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
            
            $db = new Connection();
            $r = $db->deepQuery("SELECT * FROM _navegar_visits WHERE site = '$site';");
            
            if (empty($r)) {
                $sql = "INSERT INTO _navegar_visits (site) VALUES ('$site');";
            } else {
                $sql = "UPDATE _navegar_visits SET usage_count = usage_count + 1, last_usage = CURRENT_TIMESTAMP WHERE site = '$site';";
            }
            
            $db->deepQuery($sql);
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
        
        $ftp = ftp_connect($host, $port);
        
        $login_result = ftp_login($ftp, $user, $pass);
        
        if ($login_result) {
            $r = @ftp_chdir($ftp, $path);
            
            if ($r === false) {
                $size = ftp_size($ftp, $path);
                
                if ($size >= 0) {
                    if ($size <= $this->config['max_attachment_size']) {
                        $local_file = $this->getTempDir() . "/files/" . md5($url);
                        
                        $r = ftp_get($ftp, $local_file, $path, FTP_BINARY);
                        
                        if ($r === true) {
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
                }
            } else {
                
                $contents = ftp_nlist($ftp, ".");
                foreach ($contents as $k => $v) {
                    $contents[$k] = str_replace("./", "", $v);
                }
                var_dump($contents);
                die('test');
                return array(
                        "type" => "dir",
                        "contents" => $contents
                );
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
                if (isset($rss->entry->title)) $result['items'][0]['title'] = $rss->entry->title . '';
                if (isset($rss->entry->updated)) $result['items'][0]['pubDate'] = $rss->entry->updated . '';
                if (isset($rss->entry->summary)) $result['items'][0]['description'] = $rss->entry->summary . '';
            }
            
            return $result;
        } else 
            if ($root_element_name == 'rss') {
                // is rss feed
                $result = array(
                        'title' => 'Canal de noticias',
                        'items' => array()
                );
                
                if (isset($rss->channel->title)) $result['title'] = $rss->channel->title . '';
                
                if (isset($rss->channel->item)) if (is_array($rss->channel->item)) foreach ($rss->channel->item as $item) {
                    $data = array(
                            'link' => '',
                            'title' => '',
                            'pubDate' => date('Y-m-d') . '',
                            'description' => ''
                    );
                    
                    if (isset($item->link)) $data['link'] = $item->link;
                    if (isset($item->title)) $data['title'] = $item->title;
                    if (isset($item->pubDate)) $data['pubDate'] = $item->pubDate;
                    if (isset($item->description)) $data['description'] = $item->description;
                    
                    $result['items'][] = $data;
                }
                
                return $result;
            }
        return false;
    }
}