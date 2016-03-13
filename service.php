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
		$response->setResponseSubject("Navegar: {$page['title']}       {$url_dec}");
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
		
        // remove a lot of tags 
        $page = str_ireplace(array(''),"", $page) ;


        if ($noimage == false)
        {
        	//try to get a copy of image and chnage reference inside of the <img>
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
		$page = preg_replace('#[href/HREF]=\"(\w+)\/#', 'F="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR '.$query.'\2', $page);
		$page = str_ireplace("href=\"//", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
		$page = str_ireplace("href=\"https://", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
		$page = str_ireplace("href=\"http://", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR ', $page);
		$page = str_ireplace("href=\"/", 'href="mailto:'.$apretasteValidEmailAddress.'?subject=NAVEGAR '.$query.'', $page);
		
        
				// compress the returning code
				$page = preg_replace('/\s+/S', " ", $page);


			    // if the result is too big, hide images and shorten text
				$limit = 1024 * 450;
				$isLarge = false;
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
