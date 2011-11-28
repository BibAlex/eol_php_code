<?php
namespace php_active_record;
/* connector for DiscoverLife Maps
estimated execution time:
*/
set_time_limit(0);
include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
require_library('connectors/DiscoverLifeAPIv2');
$resource_id = 223;

if(isset($argv[1])) $call_multiple_instance = false;
else $call_multiple_instance = true;

DiscoverLifeAPIv2::start_process($resource_id, $call_multiple_instance);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
echo "elapsed time = " . $elapsed_time_sec/60/60/24 . " days \n";
exit("\n\n Done processing.");
?>