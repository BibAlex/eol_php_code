<?php
namespace php_active_record;

include_once(dirname(__FILE__) . "/../config/environment.php");

$collections = Collection::all();

foreach ($collections as $collection)
{
	$indexer = new CollectionItemIndexer();
    $indexer->index_collection($collection->id);
}


?>