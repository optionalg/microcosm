<?php

require_once("config.php");
require_once("osmtypes.php");
require_once('dbutils.php');

function FactoryBboxDatabase()
{
	return new BboxDatabaseSqlite();
}

class BboxDatabaseSqlite
{
	var $dbh;
	var $tables;
	var $transactionOpen;
	var $bboxindex;

	function __construct()
	{
		chdir(dirname(realpath (__FILE__)));
		$this->dbh = new PDO('sqlite:sqlite/bbox.db');
		$this->UpdateTablesList();
		$this->transactionOpen = 0;
		$this->bboxindex = new BboxIndex();
		$this->modifyBuffer = array();
	}

	function __destruct()
	{
		$this->EndTransaction();
	}

	function SanitizeKey($key)
	{
		$key = rawurlencode($key);
		$key = str_replace("_","%95",$key);//Encode underscores

		//Make case all lower, R*Tree seems to be case insensitive.
		//Without this fix, duplicate tables create errors on SQL CREATE.
		$key = strtolower($key);

		return $key;
	}

	function SanitizeTableName($tableName)
	{
		$tableName = rawurlencode($tableName);
		$tableName = str_replace("_","%95",$tableName);//Encode underscores	
		return $tableName;
	}

	function InitialiseSchemaForKey($key)
	{		
		$posTableName = $key."_pos";
		$dataTableName = $key."_data";
		$tablesChanged = 0;

		if(!in_array($posTableName,$this->tables))
		{
		//echo "Creating table ".$posTableName."\n";
		$sql="CREATE VIRTUAL TABLE [".$this->SanitizeTableName($posTableName);
		$sql.="] USING rtree(rowid,minLat,maxLat,minLon,maxLon);";
		SqliteCheckTableExistsOtherwiseCreate($this->dbh,$this->SanitizeTableName($posTableName),$sql);
		//echo "Done.\n";
		$tablesChanged = 1;
		}
		if(!in_array($dataTableName,$this->tables))
		{
		//echo "Creating table ".$dataTableName."\n";
		$sql="CREATE TABLE [".$this->SanitizeTableName($dataTableName);
		$sql.="] (rowid INTEGER PRIMARY KEY,elementid STRING UNIQUE, value STRING, ";
		$sql.="type INTEGER, hasNodes INTEGER, hasWays INTEGER);";
		SqliteCheckTableExistsOtherwiseCreate($this->dbh,$this->SanitizeTableName($dataTableName),$sql);
		//echo "Done.\n";
		$tablesChanged = 1;
		}
		if($tablesChanged)
			$this->UpdateTablesList();
	}

	function UpdateTablesList()
	{
		//Remember to ignore the ghost tables used by R*Tree
		$sql = "SELECT * FROM sqlite_master WHERE type='table';";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$this->tables = array();
		foreach($ret as $row)
		{
			if(strlen($row['name'])>5 and substr($row['name'],-5) == "_node") 
				continue;
			if(strlen($row['name'])>6 and substr($row['name'],-6) == "_rowid") 
				continue;
			if(strlen($row['name'])>7 and substr($row['name'],-7) == "_parent") 
				continue;
			array_push($this->tables,$row['name']);
		}
	}

	function GetStoredKeys()
	{
		$out = array();
		foreach($this->tables as $table)
		{
			if(substr($table,-7) == "%95data") continue;
			array_push($out, substr($table,0,-6));
		}
		return $out;
	}
	
	function Purge()
	{	
		//echo "Purging bbox db\n";
		//print_r($this->tables);
		//exit(0);
		foreach($this->tables as $table)
			SqliteDropTableIfExists($this->dbh,$table);
		$this->bboxindex->Purge();
		$this->UpdateTablesList();
	}

	function GetTableSizes()
	{	
		$out = array();
		foreach($this->tables as $table)
		{
			$query = "SELECT count(*) FROM [".$this->SanitizeTableName($table)."];";
			$ret = $this->dbh->query($query);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

			foreach($ret as $row)
			{
				$out[$table]=$row[0];
			}
		}	
		return $out;
	}

	function BeginTransactionIfNotAlready()
	{
		if(!$this->transactionOpen)
		{
			$sql = "BEGIN;";
			$ret = $this->dbh->exec($sql);//Begin transaction
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 1;
		}
	}

	function EndTransaction()
	{
		if($this->transactionOpen)
		{
			$sql = "END;";
			$ret = $this->dbh->exec($sql);//End transaction	
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 0;
		}
	}

	function CountMemberNodesAndWays($el)
	{
		$nodes = 0; $ways = 0;
		foreach($el->members as $el)
		{
			if($el[0] == "node") $nodes ++;
			if($el[0] == "way") $ways ++;
		}
		return array($nodes, $ways);
	}

	function InsertRecord($key,$el,$bbox,$value)
	{
		$type = $el->GetType();
		$elementidStr = $type.'-'.$el->attr['id'];
		$posTableName = $key."_pos";
		$dataTableName = $key."_data";
		//Bbox input order: min_lon,min_lat,max_lon,max_lat
		
		$typeInt = null;
		if($type=="node") $typeInt = 1;
		if($type=="way") $typeInt = 2;
		if($type=="relation") $typeInt = 3;
		if(is_null($typeInt)) throw new Exception("Unknown type, can't convert to int");

		//print_r($el->members);
		list($countNodes, $countWays) = $this->CountMemberNodesAndWays($el);
		$hasNodes = ($countNodes > 0);
		$hasWays = ($countWays > 0);

		$sql = "INSERT INTO [".$this->SanitizeTableName($dataTableName)."] ";
		$sql .= "(rowid,elementid,value,type,hasNodes,hasWays) VALUES (null,?,?,?";
		$sql .= ",?";//hasNodes
		$sql .= ",?";//hasWays
		$sql.= ");";
		$sqlVals = array($elementidStr, $value, $typeInt, $hasNodes, $hasWays);

		$sth = $this->dbh->prepare($sql);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$rowid = $this->dbh->lastInsertId();

		$sql = "INSERT INTO [".$this->SanitizeTableName($posTableName)."] (rowid,minLat,maxLat,minLon,maxLon) VALUES (";
		$sql .= $rowid.",".$bbox[1].','.$bbox[3].','.$bbox[0].','.$bbox[2].");";
		$ret = $this->dbh->exec($sql);
		//echo $sql."\n";
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function GetRowIdOfTable($table,$elementid)
	{
		$query = "SELECT rowid FROM [".$table."] WHERE elementid=?;";
		$sqlVals = array($elementid);

		$sth = $this->dbh->prepare($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		foreach($sth->fetchAll() as $row)
		{
			return $row[0];
		}
		return null;
	}

	function RemoveElement($el)
	{
		//Check if element already exists, if so, return
		$elementidStr = $el->GetType().'-'.$el->attr['id'];
		//echo $elementidStr." ".isset($this->bboxindex[$elementidStr])."\n";
		$index = $this->bboxindex[$elementidStr];
		if(is_null($index)) return;
		$keys = $index['tags']; //Clear from tables as specified in the bbox index
		//$keys = $this->GetStoredKeys(); //Clear from all tables - SLOW
		//print_r($keys);

		//Go through tables and remove element
		foreach($keys as $key)
		{
			$this->InitialiseSchemaForKey($key);

			$posTableName = $this->SanitizeTableName($key."_pos");
			$dataTableName = $this->SanitizeTableName($key."_data");

			$row = $this->GetRowIdOfTable($dataTableName, $elementidStr);
			if(is_null($row)) continue;

			$sql = "DELETE FROM [".$posTableName."] WHERE rowid=".$row.";";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

			$sql = "DELETE FROM [".$dataTableName."] WHERE rowid=".$row.";";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}

		//Remove element from index
		unset($this->bboxindex[$elementidStr]);
	}

	function Update($bboxModifiedEls,$verbose=0)
	{
		if($verbose>=1) echo "Updating bboxes...\n";
		$startTime = microtime(1);
		//Begin transaction
		$this->BeginTransactionIfNotAlready();
		$countInserts = 0;
		if(!is_array($bboxModifiedEls)) throw new Exception("Updated elements should be in array");

		foreach($bboxModifiedEls as $el)
		{
			$type = $el->GetType();
			$id = $el->attr['id'];

			//Remove previous entry in database
			$this->RemoveElement($el);

			//Skip object if no tags
			if(count($el->tags)==0) continue;

			//Get bbox
			if($type!="node")
				$bbox = CallFuncByMessage(Message::GET_ELEMENT_BBOX, array($type,$id));
			else
			{
				//Don't bother computing for node's, its the same as the node position
				//format: min_lon,min_lat,max_lon,max_lat
				$bbox = array($el->attr['lon'],$el->attr['lat'],$el->attr['lon'],$el->attr['lat']);
			}
			if(!is_array($bbox))
				throw new Exception("Bounding box should be array for ".$type." ".$id);

			//echo $type." ".$id;
			//print_r($bbox);
			//echo "\n";

			//Store in database
			//Record which keys are set in index table
			$eleInKeyTables = array();
			foreach($el->tags as $k => $v)
			{
				$k = $this->SanitizeKey($k);
				array_push($eleInKeyTables,$k);
			}
			$data = array();
			$data['tags'] = $eleInKeyTables;
			$elementidStr = $el->GetType().'-'.$el->attr['id'];
			$this->bboxindex[$elementidStr] = $data;

			//Add element to individual key tables
			foreach($el->tags as $k => $v)
			{
				$k = $this->SanitizeKey($k);

				//echo $k."\n";
				$this->InitialiseSchemaForKey($k);				
				$this->InsertRecord($k,$el,$bbox,$v);
				$countInserts ++;
			}

		}
		$timeDuration = microtime(1) - $startTime;
		if($verbose>=1) echo "done in ".$timeDuration." sec. Inserts=".$countInserts;
		if($verbose>=1 and $timeDuration > 0.0) echo " (".(float)$countInserts/(float)$timeDuration." /sec)";
		if($verbose>=1) echo "\n";
	}

	function QueryXapi($type=null, $bbox=null, $key=null, $value=null, $maxRecords=MAX_XAPI_ELEMENTS)
	{
		if($key===Null) throw new Exception("Empty key not allowed");
		$keyExp = explode("|",$key);
		$out = array();
		foreach($keyExp as $keySingle)
		{
			$ret = $this->QueryXapiSingle($type,$bbox,$keySingle,$value,$maxRecords);
			foreach($ret as $i)
				if(!in_array($i,$out)) array_push($out,$i);
			if(count($out)>$maxRecords)
				return $out;
		}
		return $out;
	}

	function QueryXapiSingle($type=null, $bbox=null, $key=null, $value=null, $maxRecords=MAX_XAPI_ELEMENTS)
	{
		if($key===Null) throw new Exception("Empty key not allowed");
		$keys = $this->GetStoredKeys();
		//print_r($keys);
		$keySanitized = $this->SanitizeKey($key);

		//Check this table exists
		if(!in_array($keySanitized,$keys))
		{
			return array(); //Empty result
		}
		
		//Query table
		$posTableName = $keySanitized."_pos";
		$dataTableName = $keySanitized."_data";
		if(is_null($bbox)) $bbox = array(-180,-90,180,90);

		$query = "SELECT elementid,value FROM [".$this->SanitizeTableName($posTableName);
		$query .= "] INNER JOIN [".$this->SanitizeTableName($dataTableName);
		$query .= "] ON [".$this->SanitizeTableName($posTableName);
		$query .= "].rowid=[".$this->SanitizeTableName($dataTableName)."].rowid";
		$query .= " WHERE minLat > ?";
		$query .= " and maxLat < ?";
		$query .= " and maxLon < ? and minLon > ?";
		$sqlVals = array((float)$bbox[1], (float)$bbox[3], (float)$bbox[2], (float)$bbox[0]);

		if(!is_null($value))
		{
			$valueExp = explode("|",$value);
			$query .= " and (value=?";
			array_push($sqlVals, $valueExp[0]);
			for($i=1;$i<count($valueExp);$i++)
			{
				$query .= " or value=?";
				array_push($sqlVals, $valueExp[$i]);
			}
			$query .= ")";
		}

		if($type=="node") $query .= " and type=1";
		if($type=="way") $query .= " and type=2";
		if($type=="relation") $query .= " and type=3";
		if(!is_null($maxRecords))
		{	
			$query .= " LIMIT 0,?";
			array_push($sqlVals, (int)$maxRecords);
		}
		$query .= ";";

		$sth = $this->dbh->prepare($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		$out = array();

		foreach($sth->fetchAll() as $row)
		{
			//$expId = explode("-",$row['elementid']);
			//$type = $expId[0];
			//$id = (int)$expId[1];
			//$value = $row['value'];
			//array_push($out,array($type,$id,$value));
			array_push($out,$row['elementid']);
		}
		//print_r($out);
		return $out;
	}

	function AddElementsToBuffer($elArray)
	{
		//echo count($elArray)."\n";
		$this->modifyBuffer = array_merge($this->modifyBuffer, $elArray);

		//Flush buffer to prevent buffer getting too large
		if(count($this->modifyBuffer) > MAX_XAPI_ELEMENTS)
		{
			$this->FlushModifyBuffer();
		}
	}

	function FlushModifyBuffer()
	{
		$this->Update($this->modifyBuffer, 0);
		$this->modifyBuffer = array();
	}
	

}

class BboxIndex extends GenericSqliteTable
{
	var $keys=array('id'=>'STRING');
	var $dbname='sqlite/bboxindex.db';
	var $tablename="bboxindex";

}

function ModelBboxEventHandler($eventType, $content, $listenVars)
{
	global $xapiGlobal;
	if($xapiGlobal === Null)
		$xapiGlobal = new BboxDatabaseSqlite();

	if($eventType === Message::XAPI_QUERY)
	{
		return $xapiGlobal->QueryXapi($content[0], $content[1], $content[2], $content[3]);
	}

	if($eventType === Message::PURGE_MAP)
	{
		return $xapiGlobal->Purge();
	}

	if($eventType === Message::ELEMENT_UPDATE_PARENTS)
	{
		list($type, $eid, $obj, $parents) = $content;
		$xapiGlobal->AddElementsToBuffer(array($obj));
		$xapiGlobal->AddElementsToBuffer($parents);
	}

	if($eventType === Message::SCRIPT_END)
	{
		$xapiGlobal->FlushModifyBuffer();
		unset($xapiGlobal);
		$xapiGlobal = Null;
	}

}

//************************************************************
//Functions to determine parents affected by edits

class RichEditProcessor
{
	function __construct()
	{

	}

	function __destruct()
	{

	}

	function HandleEvent($eventType, $content, $listenVars)
	{
		if($eventType === Message::ELEMENT_UPDATE_PRE_APPLY)
		{
			$type = $content[0];
			$eid = (int)$content[1];
			$obj = $content[2];
			if(!is_object($obj)) throw new Exception("Input object of wrong type");

			#Get existing version of element
			$oldobj = CallFuncByMessage(Message::GET_OBJECT_BY_ID, array($type, $eid, Null));

			#Get parents of modified element
			$parents = CallFuncByMessage(Message::GET_ELEMENT_FULL_PARENT_DATA, $obj);
			$fi=fopen("test.xml","wt");
			fwrite($fi,$type." ".$eid." ".print_r($oldobj,True)." ".print_r($parents,True));
			fflush($fi);
			
			#Get full children details related to these parents
			$children = array();
			//if(is_object($oldobj)) $parentsAndSelf = array_merge(array($oldobj), $parents);
			$parentsAndSelf = array_merge(array($obj), $parents);
			foreach($parentsAndSelf as $el)
			{
				if(!is_object($el)) throw new Exception("Cannot get full details of non-object");
				$elchildren = CallFuncByMessage(Message::GET_ELEMENT_FULL_DATA, $el);
				$children = array_merge($children, $elchildren);
			}

			#Send data to message pump
			CallFuncByMessage(Message::ELEMENT_UPDATE_PRE_APPLY_RICH_DATA, array($type,$eid,$oldobj,$obj,$parents,$children));

		}

		if($eventType === Message::ELEMENT_UPDATE_DONE)
		{
			//Check if any listeners are waiting for this data
			//if not, skip this
			global $messagePump;
			$num = $messagePump->CountListeners(Message::ELEMENT_UPDATE_PARENTS);
			if($num>0)
			{
			$type = $content[0];
			$eid = (int)$content[1];
			$obj = $content[2];

			$parents = CallFuncByMessage(Message::GET_ELEMENT_FULL_PARENT_DATA, $obj);

			CallFuncByMessage(Message::ELEMENT_UPDATE_PARENTS, array($type, $eid, $obj, $parents));
			}
		}

	}

}

$richGlobal=Null;
function RichEditEventHandler($eventType, $content, $listenVars)
{
	global $richGlobal;
	if($richGlobal === Null)
		$richGlobal = new RichEditProcessor();

	$richGlobal->HandleEvent($eventType, $content, $listenVars);

	if($eventType === Message::SCRIPT_END)
	{
		unset($richGlobal);
		$richGlobal = Null;
	}
}

//***********************************************

class ElementSet //Adding elements to a set automatically removes duplicates
{
	function __construct()
	{
		$this->Clear();
	}

	function __destruct()
	{

	}

	function Clear()
	{
		$this->members = array();
		$this->cnt = 0;
		$this->addCalls = 0;
	}

	function Add($el)
	{
		$this->addCalls ++;
		$ty = $el->GetType();
		if(!isset($this->members[$ty])) $this->members[$ty] = array();
		$id = $el->attr['id'];
		if(!isset($this->members[$ty][$id])) $this->members[$ty][$id] = array();
		$version = $el->attr['version'];
		if(!isset($this->members[$ty][$id][$version]))
		{	
			$this->cnt ++;
		}
		$this->members[$ty][$id][$version] = $el;
	}

	function GetElements()
	{
		$out = array();
		foreach($this->members as $type=>$ids)
			foreach($ids as $id=>$vers)
				foreach($vers as $ver=>$obj)
					array_push($out, $obj);
		return $out;
	}

	function ElementIsSet($ty,$id,$version)
	{
		return isset($this->members[$ty][$id][$version]);
	}
}

//***********************************************

class RichEditLogger
{
	function __construct()
	{
		$this->newSet = new ElementSet();
		$this->oldSet = new ElementSet();
		$this->parentsSet = new ElementSet();
		$this->childrenSet = new ElementSet();
		$this->test = 0;
	}

	function __destruct()
	{
		
	}

	function Flush()
	{
		$fi = fopen("diff.xml","wt");
		fwrite($fi,"<richosm>\n");
		fwrite($fi,"<new>\n");

		foreach($this->newSet->GetElements() as $el)
			fwrite($fi, $el->ToXmlString());
		fwrite($fi,"</new>\n");

		fwrite($fi,"<old>\n");
		foreach($this->oldSet->GetElements() as $el)
			fwrite($fi, $el->ToXmlString());
		fwrite($fi,"</old>\n");

		fwrite($fi,"<parents>\n");
		foreach($this->parentsSet->GetElements() as $el)
			if(!$this->newSet->ElementIsSet($el->GetType(),$el->attr['id'],$el->attr['version']))
				fwrite($fi, $el->ToXmlString());
		fwrite($fi,"</parents>\n");

		fwrite($fi,"<children>\n");
		foreach($this->childrenSet->GetElements() as $el)
			if(!$this->newSet->ElementIsSet($el->GetType(),$el->attr['id'],$el->attr['version'])
				and !$this->parentsSet->ElementIsSet($el->GetType(),$el->attr['id'],$el->attr['version']))
					fwrite($fi, $el->ToXmlString());
		fwrite($fi,"</children>\n");
		fwrite($fi,"</richosm>\n");
		fflush($fi);

		$this->newSet->Clear();
		$this->oldSet->Clear();
		$this->parentsSet->Clear();
		$this->childrenSet->Clear();

	}

	function HandleEvent($eventType, $content, $listenVars)
	{
		if($eventType==Message::ELEMENT_UPDATE_PRE_APPLY_RICH_DATA)
		{
			$this->test ++;
			$type = $content[0];
			$eid = $content[1];
			$oldobj = $content[2];
			$obj = $content[3];
			$parents = $content[4];
			$children = $content[5];

			$this->newSet->Add($obj);
			if(is_object($oldobj)) $this->oldSet->Add($oldobj);
			foreach($parents as $el)
				$this->parentsSet->Add($el);
			foreach($children as $el)
				$this->childrenSet->Add($el);

			/*$fi = fopen("diff2.xml","wt");
			fwrite($fi,"<richosm>\n");
			fwrite($fi,"<new>\n");
			fwrite($fi, $obj->ToXmlString());
			fwrite($fi,"</new>\n");
			if(is_object($oldobj))
			{
				fwrite($fi,"<old>\n");
				fwrite($fi, $oldobj->ToXmlString());
				fwrite($fi,"</old>\n");
			}
			fwrite($fi,"<parents>\n");
			foreach($parents as $el)
				fwrite($fi, $el->ToXmlString());
			fwrite($fi,"</parents>\n");
			fwrite($fi,"<children>\n");
			foreach($children as $el)
				fwrite($fi, $el->ToXmlString());
			fwrite($fi,"</children>\n");
			fwrite($fi,"</richosm>\n");
			fflush($fi);*/

			$numEls = $this->newSet->cnt + $this->oldSet->cnt + $this->parentsSet->cnt + $this->childrenSet->cnt;
			if($numEls > 1000)
				$this->Flush(); //Prevent memory getting filled with changes
		}
	
		if($eventType === Message::SCRIPT_END)
		{
			if($this->newSet->cnt>0) $this->Flush(); //Don't bother writing empty data
		}
	}

}

$richLogGlobal = Null;
function RichEditLoggerEventHandler($eventType, $content, $listenVars)
{
	global $richLogGlobal;
	if($richLogGlobal === Null)
	{
		/*$fi = fopen("test.txt","at");
		fwrite($fi,"c".time()."\n");
		fflush($fi);
		fclose($fi);*/
		$richLogGlobal = new RichEditLogger();
	}

	$richLogGlobal->HandleEvent($eventType, $content, $listenVars);

	if($eventType === Message::SCRIPT_END)
	{
		/*$fi = fopen("test.txt","at");
		fwrite($fi,"d".time()."\n");
		fflush($fi);
		fclose($fi);*/
		unset($richLogGlobal);
		$richLogGlobal = Null;
	}
}


?>
