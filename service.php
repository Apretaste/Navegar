<?php

class Navegar extends Service
{
	/**
	 * Function called once this service is called
	 * 
	 * @param Request
	 * @return Response
	 * */
	
	public function _main(Request $request)
		{
		 // change blank query to default http://guije.com
	   	$url="" ;
	   	$isNoimage = false;
	   	$url = "www.guije.com" ;

		 if(!empty($request->query)) {
            		$url = $request->query;
            		$url = str_ireplace("http://", "", $url);
            		$url = str_ireplace("https://", "", $url);
            		$url = str_ireplace("/index.html", "", $url);
            		$url = str_ireplace("/index.htm", "", $url);
            		$url = rtrim($url, '/');
            		//$url = urlencode($url) ;
	    		}

	   	if (preg_match('/--noimage/',$url)) {
            		$isNoimage = true;
            		$url = str_ireplace(" --noimage","",$url) ; 
            		}
	   	$url_dec=urldecode($url);
        
	   	// find the right query in navegar
           	$correctedQuery = $this->checkurl($url);
           	if(empty($correctedQuery))
	    		{
	    		$response = new Response();
	    		$response->setResponseSubject("Su busqueda no produjo resultados");
	    		$response->createFromText("Su b&uacute;squeda <b>{$url_dec}</b> no fue encontrada en Navegar. Por favor modifique el texto e intente nuevamente.");
	    		return $response;
	    		}
		
	     	// get the HTML code for the page
	     	$page = $this->get($correctedQuery,$isNoimage);

	     	// get the home image
	     	$imageName = empty($page['images']) ? false : basename($page['images'][0]);

	     	// create a json object to send to the template
	     	$responseContent = array(
			"title" => $page['title'],
			"body" => $page['body'],
			"image" => $imageName,
			"isLarge" => $page['isLarge']
	     		);

	     	// send the response to the template 
	     	$response = new Response();
	     	$response->setResponseSubject("Navegar: {$page['title']}");
	     	$response->createFromTemplate("navegar.tpl", $responseContent, $page['images']);
	     	return $response;
	    	}


	/**
	 * check url in Navegar
	 */
	private function checkurl($query){
		
		// need to make it work for http and https
		$encodedQuery = "http://$query/";
		// get the results part as an array 
		$page = file_get_contents($encodedQuery);
		// return corrected query or false
		if (!empty($page)) return $encodedQuery;
		else {
			$encodedQuery = "https://$query/";
		  	$page = file_get_contents($encodedQuery);
		  	if (!empty($page)) return $encodedQuery;
		  	else return false;
			} 
		}
	
	/**
	 * Get an article from navegar
	 * 
	 */

	private function get($query,$noimage) {
		$utils = new Utils();

		// get path to the www folder
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];

		// get the url
		$page = file_get_contents($query);

		##Modified page reducing size and elements
		// removing the brackets []
		$page = preg_replace('/\[([^\[\]]++|(?R))*+\]/', '', $page);

		// remove the table of contents
		$mark = '<div id="toc" class="toc">';
		$p1 = strpos($page, $mark);
		if ($p1 !== false)
		  {
		   $p2 = strpos($page, '</div>', $p1);
		   if ($p2 !== false)
			{
			$p2 = strpos($page, '</div>', $p2 + 1);
			$page = substr($page, 0, $p1) . substr($page, $p2 + 6);
			}
	          }
		
		//remove <!--   -->
		$page = preg_replace('/<!--.*?-->/','',$page);

		// clean the page
		$page = str_ireplace('>?</span>', '></span>', $page);
		$page = trim($page);
		
        	// remove a lot of tags   this only for future change
        	$page = str_ireplace(array(''),"", $page) ;

		 if ( ! empty($page))
			{
			// Build our DOMDocument, and load our HTML
			$doc = new DOMDocument();
			@$doc->loadHTML($page);

			// New-up an instance of our DOMXPath class
			$xpath = new DOMXPath($doc);

			// Find all elements whose class attribute has test2
			$elements = $xpath->query("//*[contains(@class,'thumb')]");

			// Cycle over each, remove attribute 'class'
			foreach ($elements as $element)
			{
				// Empty out the class attribute value
				$element->parentNode->removeChild($element);
			}

			// make the suggestion smaller and separate it from the table
			$nodes = $xpath->query("//div[contains(@class, 'rellink')]");
			if ($nodes->length > 0)
				{
				$nodes->item(0)->setAttribute("style", "font-size:small;");
				$nodes->item(0)->appendChild($doc->createElement("br"));
				$nodes->item(0)->appendChild($doc->createElement("br"));
				}

			// make the table centered
			$nodes = $xpath->query("//table[contains(@class, 'infobox')]");
			if ($nodes->length > 0)
				{
				$nodes->item(0)->setAttribute("border", "1");
				$nodes->item(0)->setAttribute("width", "100%");
				$nodes->item(0)->setAttribute('style', 'width:100%;');
				}

			// make the quotes takes the whole screen 
			$nodes = $xpath->query("//table[contains(@class, 'wikitable')]");
			for($i=0; $i<$nodes->length; $i++)
				{
				$nodes->item($i)->setAttribute("width", "100%");
				$nodes->item($i)->setAttribute("style", "table-layout:fixed; width:100%;");
				}

			// remove all the noresize resources that makes the page wider
			$nodes = $xpath->query("//*[contains(@class, 'noresize')]");
			for($i=0; $i<$nodes->length; $i++) $nodes->item($i)->parentNode->removeChild($nodes->item($i));

			// Load images
			$imagestags = $doc->getElementsByTagName("img");

			$images = array();
			if (($imagestags->length > 0)&&($noimage == false))
				{
				foreach ($imagestags as $imgtag)
					{
					// get the full path to the image 
					$imgsrc = $imgtag->getAttribute('src');
					if (substr($imgsrc, 0, 2) == '//') $imgsrc = 'https:' . $imgsrc;

					// save image as a png file
					$filePath = "$wwwroot/temp/" . $utils->generateRandomHash() . ".png";
					$content = file_get_contents($imgsrc);
					imagepng(imagecreatefromstring($content), $filePath);

					// optimize the png image
					$utils->optimizeImage($filePath);

					// save the image in the array for the template
					$images[] = $filePath;

					//change the src at the document for the images
					$imgtag->setAttribute('src', $filePath);

					}
				}

			// Output the HTML of our container
			$page = $doc->saveHTML();

	        	if ($noimage == false)
        			{
        			//chnage source inside of the <img>
        			} else {
        			//remove images tabs.
            			$page = preg_replace("/<img[^>]+\>/i", " ", $page); 
        			}

			// cleanning the text to look better in the email
			$page = str_ireplace("<br>", "<br>\n", $page);
			$page = str_ireplace("<br/>", "<br/>\n", $page);
			$page = str_ireplace("</p>", "</p>\n", $page);
			$page = str_ireplace("</h2>", "</h2>\n", $page);
			$page = str_ireplace("</span>", "</span>\n", $page);
			$page = str_ireplace("/>", "/>\n", $page);
			$page = str_ireplace("<p", "<p style=\"text-align:justify;\" align=\"justify\"", $page);
			$page = wordwrap($page, 200, "\n");

			// convert the links to emails
			$apretasteValidEmailAddress = $utils->getValidEmailAddress();
			# for some sites that don't have / at the begin of path.
			$page = preg_replace('#[href/HREF]=\"(\w+)\/#', 'F="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR '.$query.'\1/\2', $page);
			$page = str_ireplace("href=\"//", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
			$page = str_ireplace("href=\"https://", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
			$page = str_ireplace("href=\"http://", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
			$page = str_ireplace("href=\"/", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR '.$query.'', $page);
		
			// compress the returning code
			$page = preg_replace('/\s+/S', " ", $page);

			// if the result is too big, hide images and shorten text
			$limit = 1024 * 450;
			$isLarge = false;
			$title = $query ;
			if (strlen($page) > $limit)
				{
				$isLarge = true;
				$images = array();
				$page = substr($page, 0, $limit);
				}

			// save content into pages that will go to the view
			return array(
				"title" => $title,
				"body" => $page,
				"images" => $images,
				"isLarge" => $isLarge
				);
			}
			return false;		
		}

	/**
	 * A test for a subservice Navegar
	 * */
	function _test(Request $request)
	{
		$response = new Response();
		$response->setResponseSubject("Just a test for subservice");
		$response->createFromText("A test for the subservice Navegar");
		return $response;
	}
}
