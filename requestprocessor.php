<?php
require_once('userdetails.php');

class RequestProcessor
{
	var $methods = array();
	var $userId = Null;
	var $displayName = Null;

	function AddMethod($url, $method, $func, $authReq = 0, $arg = Null)
	{
		array_push($this->methods,array('url'=>$url,
			'method'=>$method,'func'=>$func,
			'authReq'=>$authReq,'arg'=>$arg));
	}

	function DoesUrlMatchPattern($url, $pattern)
	{
		if (strcmp($url, $pattern)==0) return 1;
		$urlExp = explode("/",$url);
		$patternExp = explode("/",$pattern);
		if(count($urlExp)!=count($patternExp)) return 0;

		for($i=0;$i<count($urlExp);$i++)
		{
			$urlTerm = $urlExp[$i];
			$patternTerm = $patternExp[$i];
			
			if($urlTerm == $patternTerm) continue;
			if($patternTerm == "STR" and is_string($urlTerm)) continue;
			if($patternTerm == "NUM" and is_numeric($urlTerm)) continue;
			if($patternTerm == "ELEMENT")
			{
				if($urlTerm=="node") continue;
				if($urlTerm=="way") continue;
				if($urlTerm=="relation") continue;
			}
			if($patternTerm == "ELEMENTS")
			{
				if($urlTerm=="nodes") continue;
				if($urlTerm=="ways") continue;
				if($urlTerm=="relations") continue;
			}

			return 0;
		}
		return 1;
	}

	function Process($url)
	{
		$urlButNotMethodMatched = 0;
		$urlMatchedAllowedMethod = null;

		foreach ($this->methods as $methodEntry)
		{
			$urlMatch = $this->DoesUrlMatchPattern($url, $methodEntry['url']);
			//echo $methodEntry['url'].$urlMatch."\n";
			if(!$urlMatch) continue;
			//echo $methodEntry[0];

			//Get HTTP method is correct
			$methodMatch = (strcmp(GetServerRequestMethod(),$methodEntry['method'])==0);
			//echo $methodEntry['url'].",".$methodEntry['method'].",".GetServerRequestMethod()."\n";
			if(!$methodMatch)
			{
				$urlButNotMethodMatched = 1;
				$urlMatchedAllowedMethod = $methodEntry['method'];
				continue;
			}

			//Do authentication if required
			if($this->userId == null and $methodEntry['authReq'])
				list ($this->displayName, $this->userId) = RequireAuth();
	
			try
			{
				$userInfo = array('userId'=>$this->userId, 'displayName'=>$this->displayName);
				$response = call_user_func($methodEntry['func'],$userInfo,$methodEntry['arg']);
			}
			catch (Exception $e)
			{
				header('HTTP/1.1 500 Internal Server Error');
				header("Content-Type:text/plain");
				echo "Internal server error: ".$e->getMessage()."\n";
				if(DEBUG_MODE) print_r($e->getTrace());
				return 1;
			}

			//Return normal response to client
			if(is_array($response) and $response[0] == 1)
			{
				foreach($response[1] as $headerline) header($headerline);
				echo $response[2];
				return 1;
			}
			
			//Translate error to correct http result
			if(is_array($response) and $response[0] == 0)
			{
				TranslateErrorToHtml($response);
				return 1;
			}

			header('HTTP/1.1 500 Internal Server Error');
			header("Content-Type:text/plain");
			echo "Internal server error: Function needs to return array result starting with 0 or 1\n";
			return 1;
		}

		//Found URL string that matched but wrong http method specified
		if($urlButNotMethodMatched)
		{
			header('HTTP/1.1 405 Method Not Allowed');
			echo "Only method ".$urlMatchedAllowedMethod." is supported on this URI";
			return 1;
		}

	}
}


// Some stuff to translate errors to the correct HTML headers

function TranslateErrorToHtml(&$response)
{
	header("Content-Type:text/plain");

	if(strcmp($response[2],"already-closed")==0)
	{	
		//Example: "The changeset 5960426 was closed at 2010-10-05 11:18:26 UTC"
		header('HTTP/1.1 409 Conflict');
		$closedTime = date("c", GetChangesetClosedTime($changesetId)); //ISO 8601
		$err =  "The changeset ".(int)$changesetId." was closed at ".$closedTime;
		header('Error: '.$err);
		echo $err;
		return;
	}

	if("bbox-too-large" == $response[2])
	{
		$err="The maximum bbox size is ".MAX_QUERY_AREA;
		$err.=", and your request was too large. Either request a smaller area, or use planet.osm";
		header('HTTP/1.1 400 Bad Request');
		header('Error: '.$err);
		//header("Error: Stuff")
		echo $err;
		return;
	}

	if("invalid-bbox" == $response[2])
	{
		$err="The latitudes must be between -90 and 90, longitudes between ";
		$err.="-180 and 180 and the minima must be less than the maxima.";
		header('Error: '.$err);
		header('HTTP/1.1 400 Bad Request');
		echo $err;
		return;
	}

	if(strcmp($response[2],"session-id-mismatch")==0)
	{	
		header('HTTP/1.1 409 Conflict');
		echo "Inconsistent changeset id.";
		return;
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{
		header ('HTTP/1.1 400 Bad Request');
		echo "Invalid XML input";
		return;
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{	
		header ('HTTP/1.1 413 Request Entity Too Large');
		echo "Request Entity Too Large";
		return;
	}

	if(strcmp($response[2],"no-such-changeset")==0)
	{	
		$header('HTTP/1.1 409 Conflict');
		echo "No such changeset.";
		return;
	}

	if(strcmp($response[2],"not-found")==0)
	{
		header ('HTTP/1.1 404 Not Found');
		echo "Object not found.";
		return;
	}

	if(strcmp($response[2],"object-not-found")==0)
	{	
		header ('HTTP/1.1 409 Conflict');
		echo "Modified object not found in database.";
		return;
	}

	if(strcmp($response[2],"bad-request")==0)
	{	
		header ('HTTP/1.1 400 Bad Request');
		echo "Bad request.";
		return;
	}

	if(strcmp($response[2],"gone")==0)
	{	
		header ('HTTP/1.1 410 Gone');
		#echo "The ".$response[3]." with the id ".$response[4]." has already been deleted";
		return;
	}
	
	if(strcmp($response[2],"not-implemented")==0)
	{
		header ('HTTP/1.1 501 Not Implemented');
		echo "This feature has not been implemented.";
		return;
	}

	if(strcmp($response[2],"deleting-would-break")==0)
	{
		//Example: Error: Precondition failed: Node 31567970 is still used by way 4733859.
		header('HTTP/1.1 412 Precondition failed');
		$err = "Precondition failed: Node ".$response[3]." is still used by ".$response[4]." ".$response[5].".";
		header('Error: '.$err);
		echo $err;
		return;
	}

	if(strcmp($response[2],"version-mismatch")==0)
	{	
		$mismatch = explode(",",$data);
		//Example: Version mismatch: Provided 1, server had: 2 of Node 354516541
		header ('HTTP/1.1 409 Conflict');
		$err = "Version mismatch: Provided ".$mismatch[1].", server had: ".$mismatch[2]." of ".ucwords($mismatch[3])." ".$mismatch[4];
		header ('Error: '.$err);
		echo $err;
		return;
	}
	
	//Default error
	header('HTTP/1.1 500 Internal Server Error');
	header("Content-Type:text/plain");
	echo "Internal server error: ".$response[2];
	for($i=3;$i<count($response);$i++) echo ",".$response[$i];
	echo "\n";

}

//*******************
//User Authentication
//*******************

function RequestAuthFromUser()
{
	header('WWW-Authenticate: Basic realm="'.SERVER_NAME.'"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Authentication Cancelled';
	exit;
} 

function RequireAuth()
{
	if (!isset($_SERVER['PHP_AUTH_USER'])) {
		RequestAuthFromUser();
	}

	$login = $_SERVER['PHP_AUTH_USER'];

	$ret = CheckLogin($login, $_SERVER['PHP_AUTH_PW']);
	if($ret===-1) RequestAuthFromUser();
	if($ret===0) RequestAuthFromUser();
	if(is_array($ret)) list($displayName, $userId) = $ret;
	return array($displayName, $userId);
}

?>