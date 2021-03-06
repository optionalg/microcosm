<?php

include_once('config.php');

function ValidateValue($in, $type)
{
	if(strcmp($type,"int")==0) return (int)$in;
	if(strcmp($type,"float")==0) return (float)$in;
	if(strcmp($type,"string")==0)
	{
		if(strlen($in)>MAX_VALUE_LEN) throw new InvalidArgumentException("String is too long");
		return html_entity_decode((string)$in);
	}
	if(strcmp($type,"timestamp")==0)
	{
		//Validate timestamp ISO 8601
		$date = strtotime($in);
		return date('c',$date);
	}
	if(strcmp($type,"boolean")==0)
	{
		if(strcasecmp("true",$in)==0) return "true";
		if(strcasecmp("false",$in)==0) return "false";
		throw new InvalidArgumentException("Could not validate boolean");
	}
	return null;
}

class OsmElement
{
	public $attr = array();
	public $tags = array();
	public $members = array();
	
	public function AttributesFromXml($input, $allowed)
	{
		//Copy attributes
		foreach($allowed as $attr)
		{
			$attrname = $attr[0];
			$type = $attr[1];
			if(isset($input[$attrname]))
			{
				$this->attr[$attrname]=ValidateValue($input[$attrname],$type);
			}
		}
	}

	public function TagsFromXml($input)
	{
		foreach($input->tag as $tag)
		{
			$key=(string)$tag['k'];
			if(strlen($key)>MAX_KEY_LEN) throw new InvalidArgumentException("Key is too long");
			$value=(string)$tag['v'];
			if(strlen($value)>MAX_VALUE_LEN) throw new InvalidArgumentException("Value is too long");
			$this->tags[html_entity_decode($key)] = html_entity_decode($value);
		}
	}

	public function NodesFromXml($input)
	{
		foreach($input->nd as $nd)
		{
			$ref=(int)$nd['ref'];
			array_push($this->members,array("node",$ref,null));
		}
	}

	public function MembersFromXml($input)
	{
		foreach($input->member as $m)
		{
			$ref=(int)$m['ref'];
			$type=(string)$m['type'];
			$role=(string)$m['role'];
			if(strlen($role)>MAX_VALUE_LEN) throw new InvalidArgumentException("Role is too long");
			array_push($this->members,array($type,$ref,$role));
		}
	}

	public function ToXmlStringBase($type)
	{
		$out = "<".$type;
		foreach($this->attr as $key => $val)
		{
			$out = $out." ".htmlspecialchars($key,ENT_QUOTES,"UTF-8")."='".htmlspecialchars($val,ENT_QUOTES,"UTF-8")."'";
		}
		$out = $out.">\n";

		foreach($this->tags as $key => $value)
		{
			$out = $out.'<tag k="'.htmlspecialchars($key,ENT_QUOTES,"UTF-8");
			$out = $out.'" v="'.htmlspecialchars($value,ENT_QUOTES,"UTF-8").'"/>'."\n";
			//$out = $out.'<tag k="'.$key;
			//$out = $out.'" v="'.$value.'"/>'."\n";
		}
		foreach($this->members as $member)
		{
			if(strcmp($type,"way")==0)
				$out = $out.'<nd ref="'.$member[1].'"/>'."\n";
			else
			{
				$out = $out.'<member type="'.$member[0].'" ref="'.$member[1].'" role="'.
					htmlspecialchars($member[2],ENT_QUOTES,"UTF-8").'"/>'."\n";

			}
		}

		$out = $out."</".$type.">\n";
		return $out;
	}
}

$allowedNodeAttributes = array(array('id','int'),array('action','string'),
	array('lat','float'),array('lon','float'),array('changeset','int'),
	array('user','string'),array('uid','int'),array('visible','boolean'),
	array('timestamp','timestamp'),array('version','int'));

$allowedWayRelAttributes = array(array('id','int'),array('action','string'),
	array('changeset','int'),
	array('user','string'),array('uid','int'),array('visible','boolean'),
	array('timestamp','timestamp'),array('version','int'));

class OsmNode extends OsmElement
{
	public function FromXml($input)
	{
		//Clear previous data
		$this->attr = array();
		$this->tags = array();
		
		//Copy from xml to internal object
		global $allowedNodeAttributes;
		$this->AttributesFromXml($input,$allowedNodeAttributes);
		$this->TagsFromXml($input);
	}

	public function ToXmlString()
	{
		return $this->ToXmlStringBase("node");
	}

	public function GetType()
	{
		return "node";
	}
}

class OsmWay extends OsmElement
{

	public function FromXml($input)
	{
		//Clear previous data
		$this->attr = array();
		$this->tags = array();
		
		//Copy from xml to internal object
		global $allowedWayRelAttributes;
		$this->AttributesFromXml($input,$allowedWayRelAttributes);
		$this->TagsFromXml($input);
		$this->NodesFromXml($input);
	}

	public function ToXmlString()
	{
		return $this->ToXmlStringBase("way");
	}

	public function GetType()
	{
		return "way";
	}

}

class OsmRelation extends OsmElement
{
	public function FromXml($input)
	{
		//Clear previous data
		$this->attr = array();
		$this->tags = array();
		
		//Copy from xml to internal object
		global $allowedWayRelAttributes;
		$this->AttributesFromXml($input,$allowedWayRelAttributes);
		$this->TagsFromXml($input);
		$this->MembersFromXml($input);
	}

	public function ToXmlString()
	{
		return $this->ToXmlStringBase("relation");
	}

	public function GetType()
	{
		return "relation";
	}

}

class OsmChangeset extends OsmElement
{
	//var $data = array();

	public function FromXml($input)
	{
		//Clear previous data
		$this->attr = array();
		$this->tags = array();
		
		//Copy from xml to internal object
		$this->AttributesFromXml($input,array(array('id','int'),array('open','boolean')));
		$this->TagsFromXml($input);
	}

	public function ToXmlString()
	{
		return $this->ToXmlStringBase("changeset");
	}

	public function GetType()
	{
		return "changeset";
	}

}

function OsmElementFactory($type)
{
	if(strcasecmp($type,"node")==0) return new OsmNode();
	if(strcasecmp($type,"way")==0) return new OsmWay();
	if(strcasecmp($type,"relation")==0) return new OsmRelation();
	if(strcasecmp($type,"changeset")==0) return new OsmChangeset();
	return null;
}

class OsmChange
{
	public $data = array();
	public $version = null;

	public function FromXmlString($input)
	{
		//Clear old data
		$this->data = array();

		//Parse input
		$xml = simplexml_load_string($input);
		if (!$xml)
		{
			$err = "Failed to parse XML upload diff.";
			foreach(libxml_get_errors() as $error) {
				$err = $err."\t".$error->message;
			}
			throw new InvalidArgumentException($err);
		}

		//Version
		$this->version = (string)$xml['version'];
		
		//For each action in data
		foreach($xml as $action => $elements)
		{
			if($action!="create" and $action != "modify" and $action != "delete")
				throw new InvalidArgumentException("Action must be create, modify or delete, not ".$action);

			$elsInAction = array();
			//For each element in the action
			foreach($elements as $type => $elxml)
			{
				$el = OsmElementFactory($type);
				if(is_null($el)) throw new InvalidArgumentException("Invalid element type ".$type);
				if(!is_null($el)) $el->FromXml($elxml);	
				//echo $el->ToXmlString();
				array_push($elsInAction,$el);
			}
			array_push($this->data, array($action,$elsInAction));
		}

		//print_r($this->data);

		return 1;
	}

	public function ToXmlString()
	{
		$out = '<osmChange version="0.6" generator="'.SERVER_NAME.'">';
		foreach($this->data as $data)
		{
			$action = $data[0];
			$els = $data[1];

			$out = $out."<".$action.">\n";
			foreach($els as $el)
				$out=$out.$el->ToXmlString();
			$out = $out."</".$action.">\n";

		}
		$out = $out."</osmChange>\n";
		return $out;
	}

}

function SingleObjectFromXml($input)
{
	//Parse input
	$xml = simplexml_load_string($input);
	if (!$xml)
	{
		$err = "Failed to parse XML upload diff.";
		foreach(libxml_get_errors() as $error) {
			$err = $err."\t".$error->message;
		}
		throw new InvalidArgumentException($err);
	}

	//Create object
	foreach($xml as $type=>$el)
	{
		//echo $type;
		if(strcmp($type,"bounds")==0) continue;
		$obj = OsmElementFactory($type);
		if (is_null($obj)) throw new Exception("Factory returned null for type ".$type);
		$obj->FromXml($el);
		return $obj;
	}		
	return null;
}

function ParseOsmXml($input)
{
	//Parse input
	$xml = simplexml_load_string($input);
	if (!$xml)
	{
		$err = "Failed to parse XML upload diff.";
		foreach(libxml_get_errors() as $error) {
			$err = $err."\t".$error->message;
		}
		throw new InvalidArgumentException($err);
	}

	$out = array();
	//Create object
	foreach($xml as $type=>$el)
	{
		//echo $type;
		if(strcmp($type,"bounds")==0) continue;
		$obj = OsmElementFactory($type);
		if (is_null($obj)) throw new Exception("Factory returned null for type ".$type);
		$obj->FromXml($el);
		array_push($out,$obj);
	}
	return $out;
}

function ParseOsmXmlChangeset($input)
{
	//Parse input
	$xml = simplexml_load_string($input);
	if (!$xml)
	{
		$err = "Failed to parse XML upload diff.";
		foreach(libxml_get_errors() as $error) {
			$err = $err."\t".$error->message;
		}
		throw new InvalidArgumentException($err);
	}

	//Create object
	foreach($xml as $type=>$el)
	{
		//echo $type;
		$obj = OsmElementFactory($type);
		if (is_null($obj)) throw new Exception("Factory returned null for type ".$type);
		$obj->FromXml($el);

		return $obj;
	}		
	return null;
}

class UserPreferences
{
	public $data = array();

	public function FromXmlString($input)
	{
		//Parse input
		$xml = simplexml_load_string($input);
		if (!$xml)
		{
			$err = "Failed to parse XML upload diff.";
			foreach(libxml_get_errors() as $error) {
				$err = $err."\t".$error->message;
			}
			throw new InvalidArgumentException($err);
		}

		//Copy into internal structure
		$count = 0;
		foreach($xml as $key => $prefs)
		{
			foreach($prefs->preference as $i => $pref)
			{
				$k = (string)$pref['k'];
				$v = (string)$pref['v'];
				if(strlen($k)>MAX_KEY_LEN) throw new InvalidArgumentException("Key too large");
				if(strlen($v)>MAX_VALUE_LEN) throw new InvalidArgumentException("Value too large");
				if(isset($this->data[$k])) throw new InvalidArgumentException("Duplicate key");
				$this->data[html_entity_decode($k)] = html_entity_decode($v);
				$count ++;
			}
		}
		if($count > MAX_USER_PERFS) throw new InvalidArgumentException("Too many prefs");

		return 1;
	}

	public function ToXmlString()
	{
		$out = "<preferences>\n";
		foreach($this->data as $k => $v)
		{
			$out = $out.'<preference k="'.htmlentities($k,ENT_QUOTES,"UTF-8").'" v="'.htmlentities($v,ENT_QUOTES,"UTF-8").'"/>'."\n";
		}
		$out = $out."</preferences>\n";
		return $out;
	}

}


?>
