<?php

include_once(dirname(__FILE__) . "/../config/environment.php");
//$GLOBALS['ENV_DEBUG'] = false;
require_library('SiteStatistics');

$log = HarvestProcessLog::create('Site Statistics');

$stats = new SiteStatistics();
$stats->insert_taxa_stats();
$stats->insert_data_object_stats();

$log->finished();

?>