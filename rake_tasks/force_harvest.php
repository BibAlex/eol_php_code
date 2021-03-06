<?php

$attr = @$argv[1];
$id = @$argv[2];
$opt1 = @$argv[3];
$opt2 = @$argv[4];

$options = array("-download", "-now");

if($attr != "-id" || !$id || !is_numeric($id))
{
    echo "\n\n\tforce_harvest.php -id [resource_id] [-download] [-now]\n\n";
    exit;
}



include_once(dirname(__FILE__) . "/../config/environment.php");

$mysqli =& $GLOBALS['mysqli_connection'];

$resource = new Resource($id);
if($resource)
{
    if($opt1 == "-download" || $opt2 == "-download")
    {
        if($resource->accesspoint_url && $resource->service_type_id == ServiceType::insert('EOL Transfer Schema'))
        {
            echo "\nDownloading $resource->title ($id)\n";
            $manager = new ContentManager();
            $new_resource_path = $manager->grab_file($resource->accesspoint_url, $resource->id, "resource");
            if(!$new_resource_path)
            {
                $mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Upload Failed")." WHERE id=$resource->id");
                echo "\n$resource->title ($id) resource download failed\n\n";
                exit;
            }
        }
    }
    
    
    if(!file_exists($resource->resource_file_path()))
    {
        echo "\n$resource->title ($id) does not have a resource file\n\n";
        exit;
    }
    
    if($opt1 == "-now" || $opt2 == "-now")
    {
        $log = HarvestProcessLog::create('Force Harvest');
        echo "Harvesting $resource->title ($id)\n";
        $resource->harvest();
        $log->finished();
    }else
    {
        echo "Setting status of $resource->title ($id) to force harvest\n";
        $mysqli->update("UPDATE resources SET resource_status_id = ". ResourceStatus::insert("Force Harvest")." where id=$resource->id");
    }
}else echo "\nNo resource with id $id\n\n";


?>