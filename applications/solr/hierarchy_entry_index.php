<?php

include_once(dirname(__FILE__) . "/../../config/environment.php");
$mysqli =& $GLOBALS['mysqli_connection'];




$indexer = new HierarchyEntryIndexer();
$indexer->index();



?>