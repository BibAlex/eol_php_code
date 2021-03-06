<?php
/*connector for CONABIO
estimated execution time: 11 minutes for 500 taxa
Partner provides a list of URL's for its individual species XML.
The connector loops to this list and compiles each XML to 1 final XML for EOL ingestion.
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
error_reporting(E_ALL ^ E_WARNING);
require_library('connectors/ConabioAPI');
$resource_id = 100;
ConabioAPI::combine_all_xmls($resource_id);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
?>