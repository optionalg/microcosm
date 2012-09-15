<?php
include_once ('model-fs.php');
include_once ('model-filetree.php');
include_once ('model-ft-sqlite.php');

//The backend database is implemented using a strategy software pattern. This makes
//them nice and modular. The specific strategy to use is determined in this factory.

function OsmDatabase()
{
	return new OsmDatabaseByOsmXml();
	//return new OsmDatabaseByFileTree();
	//return new OsmDatabaseByFTSqlite();
}

?>