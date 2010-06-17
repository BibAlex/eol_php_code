<?php
/* connector for Hydrothermal Vent Larvae
estimated execution time: 20-25 seconds
Connector screen scrapes the partner website.
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/HydrothermalVentLarvaeAPI');
$GLOBALS['ENV_DEBUG'] = false;

$taxa = HydrothermalVentLarvaeAPI::get_all_eol_photos();
$xml = SchemaDocument::get_taxon_xml($taxa);

$resource_path = CONTENT_RESOURCE_LOCAL_PATH . "121.xml";
$OUT = fopen($resource_path, "w+");
fwrite($OUT, $xml);
fclose($OUT);

echo "time: ". Functions::time_elapsed() ."\n";
?>