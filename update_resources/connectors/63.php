<?php
exit;
/* connector for INOTAXA
estimated execution time: 1.3 hours

Partner provided a non EOL-compliant XML file for all their species.
Connector parses this XML and generates the EOL-compliant XML.

Some dataObject.dc:identifier will be blank
*/
/*
Note:
BCA-coleoptv4p3-t239
this id has identical text descriptions in one <taxon>; one with identifier, the other doesn't.
*/

//http://www.inotaxa.org/jsp/display.jsp?context=TaxonTreatment&taxmlitid=BCA-coleoptv4p3s-t82
//http://www.inotaxa.org/jsp/display.jsp?context=ElementID&taxmlitid=BCA-coleoptv4p3-3313

/* good sample for preview
next 9
http://127.0.0.1:3000/harvest_events/8/taxa/732
http://127.0.0.1:3000/harvest_events/8/taxa/620
http://127.0.0.1:3000/harvest_events/8/taxa/515
*/


include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/InotaxaAPI');
$GLOBALS['ENV_DEBUG'] = false;

$taxa = InotaxaAPI::get_all_taxa();
$xml = SchemaDocument::get_taxon_xml($taxa);

$resource_path = CONTENT_RESOURCE_LOCAL_PATH . "63.xml";

$OUT = fopen($resource_path, "w+");
fwrite($OUT, $xml);
fclose($OUT);

echo "\n time: ". Functions::time_elapsed()."\n";
?>