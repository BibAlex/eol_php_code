<?php
/* connector for Learning + Education Group
Partner provides RSS feed.
estimated execution time: just a few seconds
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
require_library('connectors/LearningEducationAPI');
$GLOBALS['ENV_DEBUG'] = false;

$taxa = LearningEducationAPI::get_all_taxa();
$xml = SchemaDocument::get_taxon_xml($taxa);

$resource_path = CONTENT_RESOURCE_LOCAL_PATH . "257.xml";
$OUT = fopen($resource_path, "w+");
fwrite($OUT, $xml);
fclose($OUT);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n";
echo "elapsed time = $elapsed_time_sec seconds              \n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes   \n";
exit("\n\n Done processing.");
?>