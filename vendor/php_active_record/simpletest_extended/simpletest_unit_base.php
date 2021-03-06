<?php
namespace php_active_record;

require_once(DOC_ROOT . 'vendor/simpletest/autorun.php');

class SimpletestUnitBase extends \UnitTestCase
{
    function setUp()
    {
        $this->fixtures = load_fixtures('test');
    }
    
    function tearDown()
    {
        $GLOBALS['db_connection']->truncate_tables('test');
        unset($this->fixtures);
        Cache::flush();
        
        $called_class = get_called_class();
        $called_class_name = $called_class;
        if(preg_match("/\\\([^\\\]*)$/", $called_class, $arr)) $called_class_name = $arr[1];
        echo "UnitTest => ".$called_class_name."<br>\n";
        
        flush();
    }
}

?>