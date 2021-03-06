<?php

class DataObjectAncestriesIndexer
{
    private $mysqli;
    private $mysqli_slave;
    private $solr;
    
    public function __construct()
    {
        $this->mysqli =& $GLOBALS['db_connection'];
        if($GLOBALS['ENV_NAME'] == 'production' && environment_defined('slave')) $this->mysqli_slave = load_mysql_environment('slave');
        else $this->mysqli_slave =& $this->mysqli;
    }
    
    public function index()
    {
        if(!defined('SOLR_SERVER') || !SolrAPI::ping(SOLR_SERVER, 'data_objects_swap')) return false;
        $this->solr = new SolrAPI(SOLR_SERVER, 'data_objects_swap');
        
        $this->solr->delete_all_documents();
        
        $start = 0;
        $max_id = 0;
        $limit = 50000;
        
        $result = $this->mysqli_slave->query("SELECT MIN(data_object_id) min, MAX(data_object_id) max FROM data_objects_taxon_concepts");
        if($result && $row=$result->fetch_assoc())
        {
            $start = $row["min"];
            $max_id = $row["max"];
        }
        
        for($i=$start ; $i<=$max_id ; $i+=$limit)
        {
            unset($this->objects);
            
            $this->lookup_objects($i, $limit);
            $this->lookup_ancestries($i, $limit);
            $this->lookup_resources($i, $limit);
            
            if(isset($this->objects)) $this->solr->send_attributes($this->objects);
            //break;
        }
        $this->solr->optimize();
        
        $results = $this->solr->get_results('ancestor_id:1');
        if($results) $this->solr->swap('data_objects_swap', 'data_objects');
    }
    
    public function index_objects(&$data_object_ids = array(), $optimize = true)
    {
        $this->solr = new SolrAPI(SOLR_SERVER, 'data_objects');
        
        $batches = array_chunk($data_object_ids, 10000);
        foreach($batches as $batch)
        {
            unset($this->objects);
            
            $this->lookup_objects(null, null, $batch);
            $this->lookup_ancestries(null, null, $batch);
            $this->lookup_resources(null, null, $batch);
            
            if(isset($this->objects)) $this->solr->send_attributes($this->objects);
        }
        
        $this->solr->commit();
        if($optimize) $this->solr->optimize();
    }
    
    
    private function lookup_objects($start, $limit, &$data_object_ids = array())
    {
        echo "\nquerying objects ($start, $limit)\n";
        $last_data_object_id = 0;
        $query = "SELECT id, guid, data_type_id, vetted_id, visibility_id, published, data_rating, UNIX_TIMESTAMP(created_at) FROM data_objects WHERE (published=1 OR visibility_id!=".Visibility::find('visible').") AND id ";
        if($data_object_ids) $query .= "IN (". implode(",", $data_object_ids) .")";
        else $query .= "BETWEEN $start AND ". ($start+$limit);
        
        foreach($this->mysqli_slave->iterate_file($query) as $row_num => $row)
        {
            if(isset($row[7]) && preg_match("/^[0-9a-z]{32}$/i", $row[1]))
            {
                $id = $row[0];
                $guid = $row[1];
                $data_type_id = $row[2];
                $vetted_id = $row[3];
                $visibility_id = $row[4];
                $published = $row[5];
                $data_rating = $row[6];
                $created_at = $row[7];
                //$description = str_replace("|", " ", SolrApi::text_filter($parts[9]));
                
                $this->objects[$id]['guid'] = $guid;
                $this->objects[$id]['data_type_id'] = $data_type_id;
                $this->objects[$id]['vetted_id'] = $vetted_id;
                $this->objects[$id]['visibility_id'] = $visibility_id;
                $this->objects[$id]['published'] = $published;
                $this->objects[$id]['data_rating'] = $data_rating;
                $this->objects[$id]['created_at'] = date('Y-m-d', $created_at) . "T". date('h:i:s', $created_at) ."Z";
                //$this->objects[$id]['description'] = str_replace("|", " ", $description);
                
                $last_data_object_id = $id;
            }
            
            // // this would be a partial line. DataObjects can contain newlines and MySQL SELECT INTO OUTFILE
            // // does not escape them so one object can span many lines
            // elseif($last_data_object_id && !preg_match("/^([0-9]+)\t([0-9a-z]{32})\t/", $line))
            // {
            //     //echo $last_data_object_id."\n";
            //     //echo "$line\n\n";
            //     //$this->objects[$last_data_object_id]['description'] .= SolrApi::text_filter($line);
            // }
        }
    }
    
    private function lookup_ancestries($start, $limit, &$data_object_ids = array())
    {
        echo "\nquerying ancestries ($start, $limit)\n";
        $query = "SELECT do.id, dotc.taxon_concept_id, tcf.ancestor_id FROM data_objects do LEFT JOIN (data_objects_taxon_concepts dotc LEFT JOIN taxon_concepts_flattened tcf ON (dotc.taxon_concept_id=tcf.taxon_concept_id)) ON (do.id=dotc.data_object_id) WHERE (do.published=1 OR do.visibility_id!=".Visibility::find('visible').") AND do.id ";
        if($data_object_ids) $query .= "IN (". implode(",", $data_object_ids) .")";
        else $query .= "BETWEEN $start AND ". ($start+$limit);
        
        foreach($this->mysqli_slave->iterate_file($query) as $row_num => $row)
        {
            $id = $row[0];
            $taxon_concept_id = $row[1];
            $ancestor_id = $row[2];
            
            if($taxon_concept_id) $this->objects[$id]['ancestor_id'][$taxon_concept_id] = 1;
            if($ancestor_id) $this->objects[$id]['ancestor_id'][$ancestor_id] = 1;
        }
    }
    
    private function lookup_resources($start, $limit, &$data_object_ids = array())
    {
        echo "\nquerying resources ($start, $limit)\n";
        $query = "SELECT dohe.data_object_id, he.resource_id FROM data_objects do JOIN data_objects_harvest_events dohe ON (do.id=dohe.data_object_id) JOIN harvest_events he ON (dohe.harvest_event_id=he.id) WHERE (do.published=1 OR do.visibility_id!=".Visibility::find('visible').") AND do.id ";
        if($data_object_ids) $query .= "IN (". implode(",", $data_object_ids) .")";
        else $query .= "BETWEEN $start AND ". ($start+$limit);
        
        foreach($this->mysqli_slave->iterate_file($query) as $row_num => $row)
        {
            $id = $row[0];
            $resource_id = $row[1];
            
            $this->objects[$id]['resource_id'] = $resource_id;
        }
    }
}

?>