<?php
$GLOBALS['ENV_DEBUG'] = true;
include_once(dirname(__FILE__) . "/../../config/environment.php");

$time_start = time_elapsed();
ini_set('memory_limit', '1500M');
require_library('TaxonPageMetrics');
$stats = new TaxonPageMetrics();
$stats->insert_page_metrics(); //1.5 hrs
       
$time_elapsed_sec = time_elapsed() - $time_start;
echo "\n elapsed time = $time_elapsed_sec sec               ";
echo "\n elapsed time = " . $time_elapsed_sec/60 . " mins   ";
echo "\n elapsed time = " . $time_elapsed_sec/60/60 . " hrs ";
?>