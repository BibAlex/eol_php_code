<?php

class Resource extends MysqlBase
{
    public $harvest_event;
    public $last_harvest_event;
    public $resource_path;
    public $start_harvest_time;
    public $end_harvest_time;
    
    function __construct($param)
    {
        $this->table_name = Functions::class_name(__FILE__);
        parent::initialize($param);
        if(@!$this->id) return;
        
        $this->harvest_event = false;
        $this->resource_path = CONTENT_RESOURCE_LOCAL_PATH.$this->id.".xml";
        $this->resource_deletions_path = CONTENT_RESOURCE_LOCAL_PATH.$this->id."_delete.xml";
    }
    
    public static function delete($id)
    {
        if(!$id) return false;
        $resource = new Resource($id);
        if(!$resource->id) return false;
        
        $mysqli =& $GLOBALS['mysqli_connection'];
        
        $mysqli->begin_transaction();
        
        //$mysqli->delete("DELETE do FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects do ON (dohe.data_object_id=do.id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE ado FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN agents_data_objects ado ON (dohe.data_object_id=ado.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE dohent FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects_hierarchy_entries dohent ON (dohe.data_object_id=dohent.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE dor FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects_refs dor ON (dohe.data_object_id=dor.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE ado FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN audiences_data_objects ado ON (dohe.data_object_id=ado.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE doii FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects_info_items doii ON (dohe.data_object_id=doii.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE dotoc FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects_table_of_contents dotoc ON (dohe.data_object_id=dotoc.data_object_id) WHERE he.resource_id=$id");
        $mysqli->delete("DELETE dohe FROM harvest_events he STRAIGHT_JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) WHERE he.resource_id=$id");
        
        // primary hierarchy
        if($resource->hierarchy_id)
        {
            $mysqli->delete("DELETE her FROM hierarchy_entries he JOIN hierarchy_entries_refs her ON (he.id=her.hierarchy_entry_id) WHERE he.hierarchy_id=$resource->hierarchy_id");
            $mysqli->delete("DELETE s FROM hierarchy_entries he JOIN synonyms s ON (he.id=s.hierarchy_entry_id) WHERE he.hierarchy_id=$resource->hierarchy_id AND s.hierarchy_id=$resource->hierarchy_id");
            $mysqli->delete("DELETE he FROM hierarchy_entries he WHERE he.hierarchy_id=$resource->hierarchy_id");
            $mysqli->delete("DELETE hehe FROM harvest_events he STRAIGHT_JOIN harvest_events_hierarchy_entries hehe ON (he.id=hehe.harvest_event_id) WHERE he.resource_id=$id");
        }
        
        // DWC hierarchy
        if($resource->dwc_hierarchy_id)
        {
            $mysqli->delete("DELETE her FROM hierarchy_entries he JOIN hierarchy_entries_refs her ON (he.id=her.hierarchy_entry_id) WHERE he.hierarchy_id=$resource->dwc_hierarchy_id");
            $mysqli->delete("DELETE s FROM hierarchy_entries he JOIN synonyms s ON (he.id=s.hierarchy_entry_id) WHERE he.hierarchy_id=$resource->hierarchy_id AND s.hierarchy_id=$resource->dwc_hierarchy_id");
            $mysqli->delete("DELETE he FROM hierarchy_entries he WHERE he.hierarchy_id=$resource->dwc_hierarchy_id");
        }
        
        $mysqli->delete("DELETE FROM harvest_events WHERE resource_id=$id");
        $mysqli->delete("DELETE FROM resources WHERE id=$id");
        
        $mysqli->end_transaction();
    }
    
    public function auto_publish()
    {
        if($this->auto_publish || $this->content_partner()->auto_publish) return true;
        
        return false;
    }
    
    public function vetted()
    {
        if($this->vetted || $this->content_partner()->vetted) return true;
        
        return false;
    }
    
    public static function wikipedia()
    {
        return new Resource(self::find_by_title('Wikipedia'));
    }
    
    public function resource_file_path()
    {
        return CONTENT_RESOURCE_LOCAL_PATH . $this->id . ".xml";
    }
    
    public function ready_to_update()
    {
        //the resource hasn't been downloaded yet
        if(!file_exists($this->resource_file_path())) return true;
        
        return $this->ready_to_harvest(12);
        
        ////Adding 12 hours to last modified to offset time it takes to update some resources
        //$last_updated = Functions::file_hours_since_modified($this->resource_file_path()) + 12;
        //if($last_updated < $this->refresh_period_hours) return false;
        
        return true;
    }
    
    public function set_autopublish($value)
    {
        $auto_publish = ($value === true) ? 1 : 0;
        $this->mysqli->update("UPDATE resources SET auto_publish=$auto_publish WHERE id=$this->id");
        $this->auto_publish = $auto_publish;
    }
    
    public function set_accesspoint_url($accesspoint_url)
    {
        $this->mysqli->update("UPDATE resources SET accesspoint_url='".$this->mysqli->escape($accesspoint_url)."' WHERE id=$this->id");
        $this->accesspoint_url = $accesspoint_url;
    }
    
    // will return boolean of THIS resource is ready
    public function ready_to_harvest($hours_ahead_of_time = null)
    {
        $mysqli =& $GLOBALS['mysqli_connection'];
        
        $extra_hours_clause = "";
        if($hours_ahead_of_time) $extra_hours_clause = " - $hours_ahead_of_time";
        
        $result = $mysqli->query("SELECT SQL_NO_CACHE id FROM resources WHERE id=$this->id AND (resource_status_id=".ResourceStatus::insert("Force Harvest")." OR (harvested_at IS NULL AND (resource_status_id=".ResourceStatus::insert("Validated")." OR resource_status_id=".ResourceStatus::insert("Validation Failed")." OR resource_status_id=".ResourceStatus::insert("Processing Failed").")) OR (refresh_period_hours!=0 AND DATE_ADD(harvested_at, INTERVAL (refresh_period_hours $extra_hours_clause) HOUR)<=NOW() AND resource_status_id IN (".ResourceStatus::insert("Validated").", ".ResourceStatus::insert("Validation Failed").", ".ResourceStatus::insert("Processed").", ".ResourceStatus::insert("Processing Failed").", ".ResourceStatus::insert("Published").")))");
        
        if($result && $row=$result->fetch_assoc()) return true;
        return false;
    }
    
    // static method to find ALL resources ready
    public static function ready_for_harvesting()
    {
        $mysqli =& $GLOBALS['mysqli_connection'];
        
        $resources = array();
        
        $result = $mysqli->query("SELECT SQL_NO_CACHE id FROM resources WHERE resource_status_id=".ResourceStatus::insert("Force Harvest")." OR (harvested_at IS NULL AND (resource_status_id=".ResourceStatus::insert("Validated")." OR resource_status_id=".ResourceStatus::insert("Validation Failed")." OR resource_status_id=".ResourceStatus::insert("Processing Failed").")) OR (refresh_period_hours!=0 AND DATE_ADD(harvested_at, INTERVAL refresh_period_hours HOUR)<=NOW() AND resource_status_id IN (".ResourceStatus::insert("Upload Failed").", ".ResourceStatus::insert("Validated").", ".ResourceStatus::insert("Validation Failed").", ".ResourceStatus::insert("Processed").", ".ResourceStatus::insert("Processing Failed").", ".ResourceStatus::insert("Published")."))");
        while($result && $row=$result->fetch_assoc())
        {
            $resources[] = $resource = new Resource($row["id"]);
        }
        
        return $resources;
    }
    
    public static function ready_for_publishing()
    {
        $mysqli =& $GLOBALS['mysqli_connection'];
        $resources = array();
        $result = $mysqli->query("SELECT SQL_NO_CACHE id FROM resources WHERE resource_status_id=". ResourceStatus::insert("Publish Pending"));
        while($result && $row=$result->fetch_assoc())
        {
            $resources[] = $resource = new Resource($row["id"]);
        }
        return $resources;
    }
    
    public function content_partner()
    {
        if(@$this->content_partner) return $this->content_partner;
        
        $this->content_partner = new ContentPartner(ContentPartner::find($this->data_supplier()->id));
        return $this->content_partner;
    }
    
    public function last_harvest_event_id()
    {
        if(isset($this->last_harvest_event_id)) return $this->last_harvest_event_id;
        
        $this->last_harvest_event_id = 0;
        if($this->harvest_event) $result = $this->mysqli->query("SELECT MAX(id) as id FROM harvest_events WHERE resource_id=$this->id AND id<".$this->harvest_event->id);
        else $result = $this->mysqli->query("SELECT SQL_NO_CACHE MAX(id) as id FROM harvest_events WHERE resource_id=$this->id");
        if($result && $row=$result->fetch_assoc())
        {
            $this->last_harvest_event_id = $row["id"];
        }
        
        return $this->last_harvest_event_id;
    }
    
    public function most_recent_harvest_event_id()
    {
        $result = $this->mysqli->query("SELECT SQL_NO_CACHE MAX(id) as id FROM harvest_events WHERE resource_id=$this->id");
        if($result && $row=$result->fetch_assoc())
        {
            return $row["id"];
        }
        return 0;
    }
    
    public function most_recent_published_harvest_event_id()
    {
        $result = $this->mysqli->query("SELECT SQL_NO_CACHE MAX(id) as id FROM harvest_events WHERE resource_id=$this->id AND published_at IS NOT NULL");
        if($result && $row=$result->fetch_assoc())
        {
            return $row["id"];
        }
        return 0;
    }
    
    
    public function data_supplier()
    {
        if(@$this->data_supplier) return $this->data_supplier;
        
        $result = $this->mysqli->query("SELECT agent_id FROM agents_resources WHERE resource_id=$this->id AND resource_agent_role_id=".ResourceAgentRole::insert("Data Supplier"));
        if($result && $row=$result->fetch_assoc())
        {
            $this->data_supplier = new Agent($row["agent_id"]);
        }else $this->data_supplier = 0;
        
        return $this->data_supplier;
    }
    
    public function unpublish_data_objects($object_guids_to_keep = null)
    {
        if($last_id = $this->most_recent_published_harvest_event_id())
        {
            $where_clause = '';
            if($object_guids_to_keep) $where_clause = "AND do.guid NOT IN ('". implode($object_guids_to_keep,"','") ."')";
            $this->mysqli->update("UPDATE harvest_events he JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects do ON (dohe.data_object_id=do.id) SET do.published=0 WHERE do.published=1 AND he.id=$last_id $where_clause");
        }
    }
    
    public function unpublish_hierarchy_entries()
    {
        $this->mysqli->update("UPDATE hierarchy_entries he SET he.published=0, he.visibility_id=".Visibility::insert('invisible')." WHERE he.published=1 AND he.hierarchy_id=$this->hierarchy_id");
    }
    
    public function unpublish_taxon_concepts()
    {
        $this->mysqli->update("UPDATE hierarchy_entries he JOIN taxon_concepts tc ON (he.taxon_concept_id=tc.id) SET tc.published=0 WHERE he.hierarchy_id=$this->hierarchy_id");
    }
    
    public function vetted_object_guids()
    {
        $guids = array();
        $result = $this->mysqli->query("SELECT do.guid FROM harvest_events he JOIN data_objects_harvest_events dohe ON (he.id=dohe.harvest_event_id) JOIN data_objects do ON (dohe.data_object_id=do.id) WHERE do.published=1 AND do.visibility_id=".Visibility::insert('Visible')." AND do.vetted_id=".Vetted::insert('Trusted')." AND he.resource_id=$this->id");
        while($result && $row=$result->fetch_assoc())
        {
            $guids[] = $row['guid'];
        }
        return array_unique($guids);
    }
    
    public function force_publish()
    {
        $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Publish Pending")." WHERE id=$this->id");
    }
    
    public function publish()
    {
        if($this->resource_status_id != ResourceStatus::insert("Publish Pending")) return false;
        $this->mysqli->begin_transaction();
        if($harvest_event_id = $this->most_recent_harvest_event_id())
        {
            $harvest_event = new HarvestEvent($harvest_event_id);
            if(!$harvest_event->published_at && $harvest_event->completed_at)
            {
                $object_guids_to_keep = array();
                if($this->title == 'Wikipedia')
                {
                    $object_guids_to_keep = $this->vetted_object_guids();
                }
                
                // make all objects in last harvest visible if they were in preview mode
                $harvest_event->make_objects_visible($object_guids_to_keep);
                
                // preserve visibilities from older versions of same objects
                // if the older versions were curated they may be invible or inappropriate and we don't want to lose that info
                if($last_id = $this->most_recent_published_harvest_event_id())
                {
                    $harvest_event->inherit_visibilities_from($last_id);
                    $this->mysqli->commit();
                }
                
                // set published=0 for ALL objects associated with this resource
                $this->unpublish_data_objects($object_guids_to_keep);
                
                // now only set published=1 for the objects in the latest harvest
                $harvest_event->publish_objects();
                $this->mysqli->commit();
                
                // set the harvest published_at date
                $harvest_event->published();
                $this->mysqli->commit();
                
                if($this->hierarchy_id)
                {
                    // unpublish all concepts associated with this resource
                    $this->unpublish_taxon_concepts();
                    $this->mysqli->commit();
                    $this->unpublish_hierarchy_entries();
                    $this->mysqli->commit();
                    
                    // now set published=1 for all concepts in the latest harvest
                    $harvest_event->publish_hierarchy_entries();
                    $this->mysqli->commit();
                    
                    // make sure all concepts are published
                    Hierarchy::publish_wrongly_unpublished_concepts();
                    $this->mysqli->commit();
                    
                    // Rebuild the Solr index for this hierarchy
                    $indexer = new HierarchyEntryIndexer();
                    $indexer->index($this->hierarchy_id);
                    
                    // Compare this hierarchy to all others and store the results in the hierarchy_entry_relationships table
                    $hierarchy = new Hierarchy($this->hierarchy_id);
                    CompareHierarchies::process_hierarchy($hierarchy, null, true);
                    
                    CompareHierarchies::begin_concept_assignment($this->hierarchy_id);
                }
                
                $harvest_event->insert_top_images();
                $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Published").", notes='harvest published' WHERE id=$this->id");
            }
        }
        $this->mysqli->end_transaction();
    }
    
    public function harvest($validate = true)
    {
        debug("Starting harvest of resource: $this->id");
        debug("Validating resource: $this->id");
        // set valid to true if we don't need validation
        $valid = $validate ? $this->validate($this->resource_path) : true;
        debug("Validated resource: $this->id");
        if($valid)
        {
            $this->mysqli->begin_transaction();
            $this->insert_hierarchy();
            $this->start_harvest();
            
            debug("Parsing resource: $this->id");
            $connection = new SchemaConnection($this);
            SchemaParser::parse($this->resource_path, $connection, false);
            unset($connection);
            debug("Parsed resource: $this->id");
            $this->mysqli->commit();
            
            // if the resource only contains information to update, then check for a 
            // _delete file for the identifiers of the objects to delete
            $this->add_unchanged_data_to_harvest();
            
            $this->end_harvest();
            $this->mysqli->commit();
            
            $this->update_names_of_new_entries();
            $this->mysqli->commit();
            
            // if there are things in preview mode in old harvest which are not in this harvest
            // then set them to be invisible
            $this->make_old_preview_objects_invisible();
            $this->mysqli->commit();
            
            // do the same thing with hierarchy entries
            $this->make_old_preview_entries_invisible();
            $this->mysqli->commit();
            
            if($this->hierarchy_id)
            {
                $hierarchy = new Hierarchy($this->hierarchy_id);
                debug("Assigning nested set values resource: $this->id");
                Tasks::rebuild_nested_set($this->hierarchy_id);
                debug("Finished assigning: $this->id");
                $this->make_new_hierarchy_entries_preview($hierarchy);
                
                if(!$this->auto_publish())
                {
                    // Rebuild the Solr index for this hierarchy
                    $indexer = new HierarchyEntryIndexer();
                    $indexer->index($this->hierarchy_id);
                    
                    // Compare this hierarchy to all others and store the results in the hierarchy_entry_relationships table
                    CompareHierarchies::process_hierarchy($hierarchy, null, true);
                    CompareHierarchies::begin_concept_assignment($this->hierarchy_id);
                }
                
                if($this->vetted())
                {
                    // Vet all taxon concepts associated with this resource
                    $this->mysqli->update("UPDATE hierarchy_entries he JOIN taxon_concepts tc ON (he.taxon_concept_id=tc.id) SET he.vetted_id=".Vetted::insert("Trusted").", tc.vetted_id=".Vetted::insert("Trusted")." WHERE hierarchy_id=$this->hierarchy_id");
                }
                
                // after all the resource hierarchy stuff has been taken care of - import the DWC Archive into a
                // SEPARATE hierarchy, but try to get the same concept IDs
                $this->import_dwc_archive();
            }
            
            if($this->vetted() && $this->harvest_event)
            {
                // set vetted=trusted for all objects in this harvest
                $this->harvest_event->vet_objects();
            }
            
            $this->mysqli->end_transaction();
            
            if($this->auto_publish())
            {
                $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Publish Pending")." WHERE id=$this->id");
                $this->resource_status_id = ResourceStatus::insert("Publish Pending");
                $this->publish();
            }
            
            // // after everything is done, do some denormalizing
            // $this->update_taxon_concepts_solr_index();
            // $this->update_data_objects_solr_index();
        }
    }
    
    public function add_unchanged_data_to_harvest()
    {
        // there is no _delete file so we assume the resource is complete
        if(!file_exists($this->resource_deletions_path)) return false;
        
        if($this->harvest_event)
        {
            $last_harvest_event_id = $this->last_harvest_event_id();
            // if there isn't a previous harvest there's nothing to delete or remain unchanged
            if(!$last_harvest_event_id) return false;
            
            $identifiers_to_delete = array();
            $file = file($this->resource_deletions_path);
            foreach($file as $line)
            {
                $id = trim($line);
                if($id) $identifiers_to_delete[] = trim($line);
            }
            
            // at this point identifiers_to_delete could be empty - meaning we don't want to delete anything and
            // we want to being over all old items not references in the resource file
            $identifiers_to_delete_string = "'". implode("','", $identifiers_to_delete) ."'";
            if($identifiers_to_delete_string == "''") $identifiers_to_delete_string = "'NONSENSE 9832rhjgovih'";
            
            $unchanged_status_id = Status::insert('Unchanged');
            // add the unchanged data objects
            $outfile = $this->mysqli->select_into_outfile("SELECT ".$this->harvest_event->id.", dohe.data_object_id, dohe.guid, $unchanged_status_id FROM data_objects_harvest_events dohe JOIN data_objects do ON (dohe.data_object_id=do.id) LEFT JOIN data_objects_harvest_events dohe_current ON (dohe_current.harvest_event_id=".$this->harvest_event->id." AND dohe_current.guid=dohe.guid) WHERE do.identifier NOT IN ($identifiers_to_delete_string) AND dohe.harvest_event_id=$last_harvest_event_id AND dohe_current.data_object_id IS NULL");
            $GLOBALS['db_connection']->load_data_infile($outfile, 'data_objects_harvest_events');
            unlink($outfile);
            
            
            // add the unchanged taxa
            $outfile = $this->mysqli->select_into_outfile("SELECT ".$this->harvest_event->id.", hehe.hierarchy_entry_id, hehe.guid, $unchanged_status_id FROM data_objects_harvest_events dohe JOIN data_objects_hierarchy_entries dohent ON (dohe.data_object_id=dohent.data_object_id) JOIN harvest_events_hierarchy_entries hehe ON (dohent.hierarchy_entry_id=hehe.hierarchy_entry_id) JOIN data_objects do ON (dohe.data_object_id=do.id) LEFT JOIN harvest_events_hierarchy_entries hehe_current ON (hehe_current.harvest_event_id=".$this->harvest_event->id." AND hehe_current.guid=hehe.guid) WHERE do.identifier NOT IN ($identifiers_to_delete_string) AND dohe.harvest_event_id=$last_harvest_event_id AND hehe.harvest_event_id=$last_harvest_event_id AND hehe_current.hierarchy_entry_id IS NULL");
            $GLOBALS['db_connection']->load_data_infile($outfile, 'harvest_events_hierarchy_entries');
            unlink($outfile);
            
            // at this point everything has been added EXCEPT the things we want to delete
        }
    }
    
    
    public function update_names_of_new_entries()
    {
        if($this->harvest_event)
        {
            $last_harvest_max_he_id = 0;
            if($last_he_id = $this->last_harvest_event_id())
            {
                $result = $this->mysqli->query("SELECT max(hierarchy_entry_id) max FROM harvest_events_hierarchy_entries WHERE harvest_event_id=$last_he_id");
                if($result && $row=$result->fetch_assoc()) $last_harvest_max_he_id = $row['max'];
            }
            if($last_harvest_max_he_id == NULL) $last_harvest_max_he_id = 0;
            $result = $this->mysqli->query("SELECT DISTINCT taxon_concept_id FROM harvest_events_hierarchy_entries hehe JOIN hierarchy_entries he ON (hehe.hierarchy_entry_id=he.id) WHERE hehe.harvest_event_id=".$this->harvest_event->id." AND he.id>$last_harvest_max_he_id");
            
            $this->mysqli->begin_transaction();
            while($result && $row=$result->fetch_assoc())
            {
                static $i=0;
                $i++;
                if($i%50==0) $this->mysqli->commit();
                Tasks::update_taxon_concept_names($row['taxon_concept_id']);
            }
            $this->mysqli->end_transaction();
        }
    }
    
    public function update_taxon_concepts_solr_index()
    {
        if($this->harvest_event)
        {
            $taxon_concept_ids = array();
            $query = "SELECT DISTINCT he.taxon_concept_id FROM harvest_events_hierarchy_entries hehe JOIN hierarchy_entries he ON (hehe.hierarchy_entry_id=he.id) WHERE hehe.harvest_event_id=". $this->harvest_event->id;
            foreach($this->mysqli->iterate_file($query) as $row_number => $row)
            {
                $id = $row[0];
                $taxon_concept_ids[$id] = $id;
            }
            
            if($last_id = $this->last_harvest_event_id())
            {
                $query = "SELECT DISTINCT he.taxon_concept_id FROM harvest_events_hierarchy_entries hehe JOIN hierarchy_entries he ON (hehe.hierarchy_entry_id=he.id) WHERE hehe.harvest_event_id=". $last_id;
                foreach($this->mysqli->iterate_file($query) as $row_number => $row)
                {
                    $id = $row[0];
                    $taxon_concept_ids[$id] = $id;
                }
            }
            
            print_r($taxon_concept_ids);
            echo count($taxon_concept_ids);
            echo " $last_id\n";
            $indexer = new TaxonConceptIndexer();
            $indexer->index_concepts($taxon_concept_ids);
        }
    }
    
    public function update_data_objects_solr_index()
    {
        if($this->harvest_event)
        {
            $data_object_ids = array();
            $query = "SELECT DISTINCT data_object_id FROM data_objects_harvest_events dohe WHERE dohe.harvest_event_id=". $this->harvest_event->id;
            foreach($this->mysqli->iterate_file($query) as $row_number => $row)
            {
                $id = $row[0];
                $data_object_ids[$id] = $id;
            }
            
            if($last_id = $this->last_harvest_event_id())
            {
                $query = "SELECT DISTINCT data_object_id FROM data_objects_harvest_events dohe WHERE dohe.harvest_event_id=". $last_id;
                foreach($this->mysqli->iterate_file($query) as $row_number => $row)
                {
                    $id = $row[0];
                    $data_object_ids[$id] = $id;
                }
            }
            
            print_r($data_object_ids);
            echo count($data_object_ids);
            echo " $last_id\n";
            $indexer = new DataObjectAncestriesIndexer();
            $indexer->index_objects($data_object_ids);
        }
    }
    
    public function make_new_hierarchy_entries_preview($hierarchy)
    {
        if($this->harvest_event)
        {
            $this->mysqli->update("UPDATE harvest_events_hierarchy_entries hehe JOIN hierarchy_entries he ON (hehe.hierarchy_entry_id=he.id) SET he.visibility_id=". Visibility::insert('preview') ." WHERE hehe.harvest_event_id=".$this->harvest_event->id." AND he.visibility_id=". Visibility::insert('invisible'));
            $this->make_new_hierarchy_entries_parents_preview($hierarchy);
        }
    }
    
    public function make_new_hierarchy_entries_parents_preview($hierarchy)
    {
        $result = $this->mysqli->query("SELECT 1 FROM hierarchy_entries he JOIN hierarchy_entries he_parents ON (he.parent_id=he_parents.id) WHERE he.hierarchy_id=$hierarchy->id AND he.visibility_id=". Visibility::insert('preview') ." AND he_parents.visibility_id=". Visibility::insert('invisible') ." LIMIT 1");
        while($result && $row=$result->fetch_assoc())
        {
            $this->mysqli->update("UPDATE hierarchy_entries he JOIN hierarchy_entries he_parents ON (he.parent_id=he_parents.id) SET he_parents.visibility_id=". Visibility::insert('preview') ." WHERE he.hierarchy_id=$hierarchy->id AND he.visibility_id=". Visibility::insert('preview') ." AND he_parents.visibility_id=". Visibility::insert('invisible'));
            
            $result = $this->mysqli->query("SELECT 1 FROM hierarchy_entries he JOIN hierarchy_entries he_parents ON (he.parent_id=he_parents.id) WHERE he.hierarchy_id=$hierarchy->id AND he.visibility_id=". Visibility::insert('preview') ." AND he_parents.visibility_id=". Visibility::insert('invisible') ." LIMIT 1");
        }
    }
    
    public function make_old_preview_objects_invisible()
    {
        if($this->harvest_event)
        {
            $result = $this->mysqli->query("SELECT COUNT(*) as count FROM (harvest_events he1 JOIN data_objects_harvest_events dohe1 ON (he1.id=dohe1.harvest_event_id) JOIN data_objects do1 ON (dohe1.data_object_id=do1.id)) LEFT JOIN data_objects_harvest_events dohe2 ON (do1.id=dohe2.data_object_id AND dohe2.harvest_event_id=".$this->harvest_event->id.") WHERE he1.resource_id=$this->id AND he1.id!=".$this->harvest_event->id." AND do1.visibility_id=". Visibility::insert('preview') ." AND dohe2.data_object_id IS NULL");
            if($result && $row=$result->fetch_assoc())
            {
                if($row["count"]) $this->mysqli->query("UPDATE (harvest_events he1 JOIN data_objects_harvest_events dohe1 ON (he1.id=dohe1.harvest_event_id) JOIN data_objects do1 ON (dohe1.data_object_id=do1.id)) LEFT JOIN data_objects_harvest_events dohe2 ON (do1.id=dohe2.data_object_id AND dohe2.harvest_event_id=".$this->harvest_event->id.") SET do1.visibility_id=". Visibility::insert('invisible') ." WHERE he1.resource_id=$this->id AND he1.id!=".$this->harvest_event->id." AND do1.visibility_id=". Visibility::insert('preview') ." AND dohe2.data_object_id IS NULL");
            }
        }
    }
    
    public function make_old_preview_entries_invisible()
    {
        if($this->harvest_event)
        {
            $result = $this->mysqli->query("SELECT COUNT(*) as count FROM (harvest_events hevt1 JOIN harvest_events_hierarchy_entries hehe1 ON (hevt1.id=hehe1.harvest_event_id) JOIN hierarchy_entries he1 ON (hehe1.hierarchy_entry_id=he1.id)) LEFT JOIN harvest_events_hierarchy_entries hehe2 ON (he1.id=hehe2.hierarchy_entry_id AND hehe2.harvest_event_id=".$this->harvest_event->id.") WHERE hevt1.resource_id=$this->id AND hevt1.id!=".$this->harvest_event->id." AND he1.visibility_id=". Visibility::insert('preview') ." AND hehe2.hierarchy_entry_id IS NULL");
            if($result && $row=$result->fetch_assoc())
            {
                if($row["count"]) $this->mysqli->query("UPDATE (harvest_events hevt1 JOIN harvest_events_hierarchy_entries hehe1 ON (hevt1.id=hehe1.harvest_event_id) JOIN hierarchy_entries he1 ON (hehe1.hierarchy_entry_id=he1.id)) LEFT JOIN harvest_events_hierarchy_entries hehe2 ON (he1.id=hehe2.hierarchy_entry_id AND hehe2.harvest_event_id=".$this->harvest_event->id.") SET he1.visibility_id=". Visibility::insert('invisible') ." WHERE hevt1.resource_id=$this->id AND hevt1.id!=".$this->harvest_event->id." AND he1.visibility_id=". Visibility::insert('preview') ." AND hehe2.hierarchy_entry_id IS NULL");
            }
        }
    }
    
    
    public function start_harvest()
    {
        if(!$this->harvest_event)
        {
            // Set resource as 'Being Processed'
            $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Being Processed")." WHERE id=$this->id");
            
            // Create this harvest event
            $this->harvest_event = new HarvestEvent(HarvestEvent::insert($this->id));
            $this->start_harvest_time  = date('Y m d H');
        }
    }
    
    public function end_harvest()
    {
        if($this->harvest_event)
        {
            $this->harvest_event->completed();
            $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert("Processed").", harvested_at=NOW(), notes='harvest ended' WHERE id=$this->id");
            $this->end_harvest_time  = date('Y m d H');
            
            // Make sure we set a harvest start time
            // Compare the end time to the start time, get the number of hours difference,
            // and sync the content servers for each hour this resource was being processed.
            if($this->start_harvest_time)
            {
                $d1 = explode(" ", $this->start_harvest_time);
                $d2 = explode(" ", $this->end_harvest_time);
                
                debug("Start harvest time: $this->start_harvest_time");
                debug("End harvest time: $this->end_harvest_time");
                
                $time1 = mktime($d1[3], 0, 0, $d1[1], $d1[2], $d1[0]);
                $time2 = mktime($d2[3], 0, 0, $d2[1], $d2[2], $d2[0]);
                
                $harvest_hours = ceil(($time2 - $time1) / 3600);
                
                debug("Harvest hours: $harvest_hours");
                
                $date = explode(" ", date("Y m d H", mktime($d1[3], 0, 0, $d1[1], $d1[2], $d1[0])));
                ContentManager::sync_to_content_servers($date[0], $date[1], $date[2], $date[3]);
                while($harvest_hours)
                {
                    $d1[3]+=1;
                    $date = explode(" ", date("Y m d H", mktime($d1[3], 0, 0, $d1[1], $d1[2], $d1[0])));
                    ContentManager::sync_to_content_servers($date[0], $date[1], $date[2], $date[3]);
                    $harvest_hours--;
                }
            }
        }
    }
    
    public function validate()
    {
        $validation_result = SchemaValidator::validate($this->resource_path);
        if($validation_result!==true)
        {
            $error_string = $this->mysqli->escape(implode("<br>", $validation_result));
            $this->mysqli->update("UPDATE resources SET notes='$error_string', resource_status_id=".ResourceStatus::insert("Processing Failed")." WHERE id=$this->id");
            return false;
        }
        
        unset($validator);
        
        return true;
    }
    
    public function change_status($status)
    {
        $this->mysqli->update("UPDATE resources SET resource_status_id=".ResourceStatus::insert($status)." WHERE id=$this->id");
    }
    
    public function insert_hierarchy()
    {
        if($this->hierarchy_id) return $this->hierarchy_id;
        
        $provider_agent = $this->data_supplier();
        
        $params = array();
        if(@$provider_agent->id) $params["agent_id"] = $provider_agent->id;
        $params["label"] = $this->title;
        $params["description"] = "From resource $this->title ($this->id)";
        $hierarchy_mock = Functions::mock_object("Hierarchy", $params);
        $hierarchy_id = Hierarchy::insert($hierarchy_mock);
        
        $this->mysqli->insert("UPDATE resources SET hierarchy_id=$hierarchy_id WHERE id=$this->id");
        # TODO - get real object updating in place to take care of value updates
        $this->hierarchy_id = $hierarchy_id;
        
        return $hierarchy_id;
    }
    
    private function insert_dwc_hierarchy()
    {
        // if there is already an archive hierarchy - make a copy for the new data
        if($this->dwc_hierarchy_id)
        {
            return $this->mysqli->query("INSERT INTO hierarchies (agent_id, label, description, browsable, complete) SELECT agent_id, label, description, browsable, complete FROM hierarchies WHERE id=$this->dwc_hierarchy_id");
        }
        
        $provider_agent = $this->data_supplier();
        $params = array();
        if(@$provider_agent->id) $params["agent_id"] = $provider_agent->id;
        $params["label"] = $this->title;
        $params["description"] = "From resource $this->title dwc archive";
        $hierarchy_id = Hierarchy::insert($params);
        
        $this->mysqli->insert("UPDATE resources SET dwc_hierarchy_id=$hierarchy_id WHERE id=$this->id");
        # TODO - get real object updating in place to take care of value updates
        $this->dwc_hierarchy_id = $hierarchy_id;
        
        return $hierarchy_id;
    }
    
    private function import_dwc_archive()
    {
        if(!$this->dwc_archive_url) return false;
        try
        {
            $archive_hierarchy_id = $this->insert_dwc_hierarchy();
            
            $dwca = new DarwinCoreArchiveHarvester($this->dwc_archive_url);
            $taxa = $dwca->get_core_taxa();
            $vernaculars = $dwca->get_vernaculars();
            $taxa = array_merge($taxa, $vernaculars);
            
            $vetted_id = $this->vetted() ? Vetted::insert('trusted') : Vetted::insert('unknown');
            $archive_hierarchy = new Hierarchy($archive_hierarchy_id);
            $importer = new TaxonImporter($archive_hierarchy, $vetted_id, Visibility::insert('visible'), 1);
            $importer->import_taxa($taxa);
            
            $result = $this->mysqli->query("SELECT taxon_concept_id FROM hierarchy_entries WHERE hierarchy_id=$archive_hierarchy_id");
            while($result && $row=$result->fetch_assoc())
            {
                Tasks::update_taxon_concept_names($row['taxon_concept_id']);
            }
            
            // Rebuild the Solr index for this hierarchy
            $indexer = new HierarchyEntryIndexer();
            $indexer->index($archive_hierarchy_id);
            
            // Compare this hierarchy to all others and store the results in the hierarchy_entry_relationships table
            CompareHierarchies::process_hierarchy($archive_hierarchy, null, true);
            
            // Use the entry relationships to assign the proper concept IDs
            CompareHierarchies::begin_concept_assignment($archive_hierarchy_id);
            
            // this means the resource already had a hierarchy - and we just inserted one to take its place, so
            // we now need to update resources to point to the new one now that its ready
            if($archive_hierarchy_id != $this->dwc_hierarchy_id)
            {
                $this->mysqli->update("UPDATE resources SET dwc_hierarchy_id=$archive_hierarchy_id WHERE id=$this->id");
                Hierarchy::delete($this->dwc_hierarchy_id);
                $this->dwc_hierarchy_id = $archive_hierarchy_id;
            }
        }catch(Exception $e)
        {
            return false;
        }
    }
    
    // private function add_orphaned_entries()
    // {
    //     // ADD TO TAXA
    //     $tmp_file_path = temp_filepath();
    //     $OUT = fopen($tmp_file_path, 'w+');
    //     $result = $this->mysqli->query("SELECT he.id, he.name_id, n.string FROM (hierarchy_entries he JOIN names n ON (he.name_id=n.id)) LEFT JOIN taxa t ON (he.id=t.hierarchy_entry_id) WHERE he.hierarchy_id=$this->hierarchy_id");
    //     while($result && $row=$result->fetch_assoc())
    //     {
    //         $guid = Functions::generate_guid();
    //         $string = $this->mysqli->escape($row['string']);
    //         $name_id = $row['name_id'];
    //         $he_id = $row['id'];
    //         fwrite($OUT, "NULL\t$guid\tNULL\tNULL\tNULL\tNULL\tNULL\t$string\t$name_id\t$he_id\tNULL\tNULL\n");
    //     }
    //     fclose($OUT);
    //     $this->mysqli->load_data_infile($tmp_file_path, 'taxa', 'IGNORE', '', 6000000);
    //     @unlink($tmp_file_path);
    //     
    //     // ADD TO RESOURCES TAXA
    //     $rt_path = temp_filepath();
    //     $RESOURCES_TAXA = fopen($rt_path, 'w+');
    //     $het_path = temp_filepath();
    //     $HARVEST_EVENTS_TAXA = fopen($het_path, 'w+');
    //     $result = $this->mysqli->query("SELECT he.id, he.name_id, n.string FROM (hierarchy_entries he JOIN names n ON (he.name_id=n.id)) LEFT JOIN taxa t ON (he.id=t.hierarchy_entry_id) WHERE he.hierarchy_id=$this->hierarchy_id");
    //     while($result && $row=$result->fetch_assoc())
    //     {
    //         $guid = Functions::generate_guid();
    //         $string = $this->mysqli->escape($row['string']);
    //         $name_id = $row['name_id'];
    //         $he_id = $row['id'];
    //         fwrite($OUT, "NULL\t$guid\tNULL\tNULL\tNULL\tNULL\tNULL\t$string\t$name_id\t$he_id\tNULL\tNULL\n");
    //     }
    //     fclose($RESOURCES_TAXA);
    //     fclose($HARVEST_EVENTS_TAXA);
    //     @unlink($rt_path);
    //     @unlink($het_path);
    // }
    
    static function insert($parameters)
    {
        if($result = self::find($parameters)) return $result;
        return parent::insert_fields_into($parameters, Functions::class_name(__FILE__));
    }
    
    static function find($parameters)
    {
        return 0;
    }
    
    static function find_by_title($string)
    {
        return parent::find_by("title", $string, Functions::class_name(__FILE__));
    }
    
}

?>