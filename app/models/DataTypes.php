<?php

class DataType extends MysqlBase
{
    function __construct($param)
    {
        $this->table_name = Functions::class_name(__FILE__);
        parent::initialize($param);
        if(@!$this->id) return;
        
        $this->label = ucfirst($this->label);
    }
    
    static function insert($string)
    {
        $string = trim($string);
        if(!$string) return 0;
        
        if($result = self::find($string)) return $result;
        return parent::insert_fields_into(array('schema_value' => $string, 'label' => $string), Functions::class_name(__FILE__));
    }
    
    static function find_or_create_by_label($string)
    {
        if($result = parent::find_by("label", $string, Functions::class_name(__FILE__))) return $result;
        return parent::insert_fields_into(array('label' => $string), Functions::class_name(__FILE__));
    }
    
    static function find_by_label($string)
    {
        return parent::find_by("label", $string, Functions::class_name(__FILE__));
    }
    
    static function find($string)
    {
        return parent::find_by("schema_value", $string, Functions::class_name(__FILE__));
    }
}

?>