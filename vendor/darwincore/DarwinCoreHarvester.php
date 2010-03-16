<?php

class DarwinCoreHarvester
{
    public static function harvest($uri, &$hierarchy, $vetted_id = 0, $published = 0)
    {
        if(!$uri) return false;
        $errors = SchemaValidator::validate($uri, true);
        if($errors !== true)
        {
            print_r($errors);
            return false;
        }
        
        $mysqli =& $GLOBALS['mysqli_connection'];
        $mysqli->begin_transaction();
        
        $valid_relation_array = array(SynonymRelation::insert("valid"), SynonymRelation::insert("accepted"));
        $GLOBALS['node_children'] = array();
        $GLOBALS['node_attributes'] = array();
        echo "Memory: ".memory_get_usage()."\n";
        
        $reader = new XMLReader();
        $reader->open($uri, "utf-8");
        $i = 0;
        while(@$reader->read())
        {
            if($reader->nodeType == XMLReader::ELEMENT && $reader->name == "dwc:Taxon")
            {
                $taxon_xml = $reader->readOuterXML();
                $t = simplexml_load_string($taxon_xml, null, LIBXML_NOCDATA);
                $t_dwc = $t->children("http://rs.tdwg.org/dwc/terms/");
                
                $taxon_id = Functions::import_decode($t_dwc->taxonID);
                $parent_id = Functions::import_decode($t_dwc->parentNameUsageID);
                $synonym_relation_id = SynonymRelation::insert(Functions::import_decode($t_dwc->taxonomicStatus));
                $name_id = Name::insert(Functions::import_decode($t_dwc->scientificName));
                $rank_id = Rank::insert(Functions::import_decode($t_dwc->taxonRank));
                
                if(in_array($synonym_relation_id, $valid_relation_array)) $valid = true;
                else $valid = false;
                
                if($taxon_id && $valid)
                {
                    $i++;
                    //if($i >= 200000) break;
                    $GLOBALS['node_attributes'][$taxon_id]['name_id'] = $name_id;
                    $GLOBALS['node_attributes'][$taxon_id]['rank_id'] = $rank_id;
                    
                    if(!$parent_id) $parent_id = 0;
                    $GLOBALS['node_children'][$parent_id][] = $taxon_id;
                    $GLOBALS['node_attributes'][$taxon_id]['parent_id'] = $parent_id;
                    
                    foreach($t_dwc->vernacularName as $v)
                    {
                        $xml_attr = $v->attributes("http://www.w3.org/XML/1998/namespace");
                        $vernacular_name_id = Name::insert(Functions::import_decode($v));
                        $language_id = Language::insert(@Functions::import_decode($xml_attr["lang"]));
                        
                        if($vernacular_name_id) $GLOBALS['node_attributes'][$taxon_id]['vernacular_names'][$language_id][$vernacular_name_id] = 1;
                    }
                }
                
                if(!$valid && $parent_id)
                {
                    $GLOBALS['node_attributes'][$parent_id]['synonyms'][$synonym_relation_id][$name_id] = 1;
                }
            }
        }
        
        // this will take the information in memory and add it to the database as hierarchy entries and synonyms
        self::begin_adding_nodes($hierarchy);
        $mysqli->end_transaction();
        
        // set the nested set values
        Tasks::rebuild_nested_set($hierarchy->id);
        
        // Rebuild the Solr index for this hierarchy
        $indexer = new HierarchyEntryIndexer();
        $indexer->index($hierarchy->id);
        
        // Compare this hierarchy to all others and store the results in the hierarchy_entry_relationships table
        CompareHierarchies::process_hierarchy($hierarchy, null, true);
        
        // Use the entry relationships to assign the proper concept IDs
        CompareHierarchies::begin_concept_assignment($hierarchy->id);
    }
    
    private static function begin_adding_nodes(&$hierarchy, $vetted_id = 0, $published = 0)
    {
        $mysqli =& $GLOBALS['mysqli_connection'];
        $GLOBALS['taxon_ids_inserted'] = array();
        foreach($GLOBALS['node_children'][0] as $taxon_id)
        {
            $parent_hierarchy_entry_id = 0;
            $ancestry = "";
            self::add_hierarchy_entry($taxon_id, $parent_hierarchy_entry_id, $ancestry, $hierarchy, $vetted_id, $published);
        }
    }
    
    function add_hierarchy_entry($taxon_id, $parent_hierarchy_entry_id, $ancestry, &$hierarchy, $vetted_id = 0, $published = 0)
    {
        echo "Memory: ".memory_get_usage()."\n";
        $mysqli =& $GLOBALS['mysqli_connection'];
        
        // make sure this taxon has a name, otherwise skip this branch
        if(!isset($GLOBALS['node_attributes'][$taxon_id]['name_id'])) return false;
        // this taxon_id has already been inserted meaning this tree has a loop in it - so stop
        if(isset($GLOBALS['taxon_ids_inserted'][$taxon_id])) return false;
        
        $taxon_attributes = $GLOBALS['node_attributes'][$taxon_id];
        
        if(isset($taxon_attributes["parent_id"]) && @$GLOBALS['node_attributes'][$taxon_attributes["parent_id"]]["name_id"]) $parent_id = $GLOBALS['node_attributes'][$taxon_attributes["parent_id"]]["name_id"];
        else $parent_id = 0;
        
        $params = array("identifier"    => $taxon_id,
                        "name_id"       => $taxon_attributes["name_id"],
                        "parent_id"     => $parent_hierarchy_entry_id,
                        "hierarchy_id"  => $hierarchy->id,
                        "rank_id"       => $taxon_attributes["rank_id"],
                        "ancestry"      => $ancestry);
        
        $mock_hierarchy_entry = Functions::mock_object("HierarchyEntry", $params);
        $hierarchy_entry = new HierarchyEntry(HierarchyEntry::insert($mock_hierarchy_entry));
        $GLOBALS['taxon_ids_inserted'][$taxon_id] = 1;
        unset($params);
        unset($mock_hierarchy_entry);
        
        if(isset($taxon_attributes['synonyms']))
        {
            foreach($taxon_attributes['synonyms'] as $synonym_relation_id => $array)
            {
                foreach($array as $name_id => $junk)
                {
                    $hierarchy_entry->add_synonym($name_id, $synonym_relation_id, 0, 0, $vetted_id, $published);
                }
            }
        }
        
        if(isset($taxon_attributes['vernacular_names']))
        {
            foreach($taxon_attributes['vernacular_names'] as $language_id => $array)
            {
                foreach($array as $name_id => $junk)
                {
                    $hierarchy_entry->add_synonym($name_id, SynonymRelation::insert("Common name"), $language_id, 0, $vetted_id, $published);
                }
            }
        }
        
        if($ancestry) $this_ancestry = $ancestry."|".$taxon_attributes["name_id"];
        else $this_ancestry = $taxon_attributes["name_id"];
        
        unset($taxon_attributes);
        unset($GLOBALS['node_attributes'][$taxon_id]);
        if(isset($GLOBALS['node_children'][$taxon_id]))
        {
            foreach($GLOBALS['node_children'][$taxon_id] as $child_taxon_id)
            {
                self::add_hierarchy_entry($child_taxon_id, $hierarchy_entry->id, $this_ancestry, $hierarchy, $vetted_id, $published);
            }
            unset($GLOBALS['node_children'][$taxon_id]);
        }
        unset($hierarchy_entry);
    }
}

?>