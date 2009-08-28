<?php

class HarvestEvent extends MysqlBase
{
    function __construct($param)
    {
        $this->table_name = Functions::class_name(__FILE__);
        parent::initialize($param);
        if(@!$this->id) return;
    }
    
    public function delete()
    {
        $this->mysqli->delete("DELETE do FROM data_objects_harvest_events dohe JOIN data_objects do ON (dohe.data_object_id=do.id) WHERE harvest_event_id=$this->id AND dohe.status_id IN (".Status::insert("Inserted").", ".Status::insert("Updated").")");
        $this->mysqli->delete("DELETE FROM data_objects_harvest_events WHERE harvest_event_id=$this->id");
        $this->mysqli->delete("DELETE t FROM harvest_events_taxa het JOIN taxa t ON (het.taxon_id=t.id) WHERE harvest_event_id=$this->id AND het.status_id IN (".Status::insert("Inserted").", ".Status::insert("Updated").")");
        $this->mysqli->delete("DELETE FROM harvest_events_taxa WHERE harvest_event_id=$this->id");
        $this->mysqli->delete("DELETE FROM harvest_events WHERE id=$this->id");
    }
    
    public function completed()
    {
        //$this->expire_taxa_cache();
        $this->mysqli->update("UPDATE harvest_events SET completed_at=NOW() WHERE id=$this->id");
    }
    
    public function published()
    {
        $this->mysqli->update("UPDATE harvest_events SET published_at=NOW() WHERE id=$this->id");
    }
    
    public function expire_taxa_cache()
    {
        $taxon_ids = $this->modified_taxon_ids();
        
        if(defined("TAXON_CACHE_PREFIX"))
        {
            $response = Functions::curl_post_request(TAXON_CACHE_PREFIX, array("taxa_ids" => implode(",", $taxon_ids)));
            Functions::debug($response);
        }
    }
    
    public function modified_taxon_ids()
    {
        $taxon_ids = array();
        
        $result = $this->mysqli->query("SELECT DISTINCT dot.taxon_id FROM data_objects_harvest_events dohe JOIN data_objects_taxa dot ON (dohe.data_object_id=dot.data_object_id) WHERE dohe.harvest_event_id=$this->id AND dohe.status_id!=".Status::insert("Unchanged"));
        while($result && $row=$result->fetch_assoc())
        {
            $taxon_ids[] = $row["taxon_id"];
        }
        
        return $taxon_ids;
    }
    
    public function add_taxon($taxon, $status)
    {
        if(@!$taxon->id) return false;
        $this->mysqli->insert("INSERT INTO harvest_events_taxa VALUES ($this->id, $taxon->id, '$taxon->guid', ".Status::insert($status).")");
    }
    
    public function add_data_object($data_object, $status)
    {
        if(@!$data_object->id) return false;
        $this->mysqli->insert("INSERT INTO data_objects_harvest_events VALUES ($this->id, $data_object->id, '$data_object->guid', ".Status::insert($status).")");
    }
    
    static function insert($resource_id)
    {
        if(!$resource_id) return 0;
        
        return parent::insert_fields_into(array('resource_id' => $resource_id), Functions::class_name(__FILE__));
    }
    
    static function find($parameters)
    {
        return 0;
    }
}

?>