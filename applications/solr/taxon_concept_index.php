<?php

include_once(dirname(__FILE__) . "/../../config/environment.php");
$GLOBALS['ENV_DEBUG'] = false;

$log = HarvestProcessLog::create('TaxonConceptIndexer');

$indexer = new TaxonConceptIndexer();
$indexer->index();

$log->finished();

?>