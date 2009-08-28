<?php

//define("ENVIRONMENT", "integration");
//define("DEBUG", true);
//define("MYSQL_DEBUG", true);
require_once("../../config/start.php");
$mysqli =& $GLOBALS['mysqli_connection'];

set_time_limit(0);


if(@$_FILES['xml_upload']) $_POST['xml_upload'] = $_FILES['xml_upload'];
$parameters =& $_GET;
if(!$parameters) $parameters =& $_POST;
if(@!$parameters["_ctrl"]) $parameters["_ctrl"] = "validator";
$controller = @$parameters["_ctrl"];


if($controller)
{
    $class = $controller . "_controller";
    if(class_exists($class))
    {
        $ctrl = new $class();
    }else echo "Bad controller";
}else
{
    echo "No controller";
}





function __autoload($controller)
{
    $controller = preg_replace("/_controller$/", "", $controller);
    @include_once("controllers/". $controller. ".php");
}

?>