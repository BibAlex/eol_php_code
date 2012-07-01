<?php
namespace php_active_record;

class ArchiveDataIngester
{
    private $resource;
    private static $valid_taxonomic_statuses = array("valid", "accepted");
    
    public function __construct($harvest_event)
    {
        $this->harvest_event = $harvest_event;
        $this->mysqli =& $GLOBALS['db_connection'];
    }
    
    public function parse($validate = true)
    {
        if(!is_dir($this->harvest_event->resource->archive_path())) return false;
        $this->archive_reader = new ContentArchiveReader(null, $this->harvest_event->resource->archive_path());
        $this->archive_validator = new ContentArchiveValidator($this->archive_reader);
        $this->content_manager = new ContentManager();
        
        // set valid to true if we don't need validation
        $valid = $validate ? $this->archive_validator->is_valid() : true;
        if($valid !== true) return $this->archive_validator->errors();
        
        $this->taxon_reference_ids = array();
        $this->media_reference_ids = array();
        $this->media_agent_ids = array();
        
        $this->media_ids_inserted = array();
        
        /* During harvesting we need to delete all the old records associated with HierarchyEntries
           and DataObjects so we can add the new ones, and also properly represent the case where a
           provider deletes a common name or reference from an object or taxon
        */
        $this->object_references_deleted = array();
        $this->entry_references_deleted = array();
        $this->entry_vernacular_names_deleted = array();
        
        $this->mysqli->begin_transaction();
        $this->start_reading_taxa();
        $this->mysqli->commit();
        $this->archive_reader->process_table("http://rs.gbif.org/terms/1.0/VernacularName", array($this, 'insert_vernacular_names'));
        $this->archive_reader->process_table("http://eol.org/schema/media/Document", array($this, 'insert_data_object'));
        $this->archive_reader->process_table("http://eol.org/schema/reference/Reference", array($this, 'insert_references'));
        $this->archive_reader->process_table("http://eol.org/schema/agent/Agent", array($this, 'insert_agents'));
        $this->mysqli->end_transaction();
        
        // returning true so we know that the parsing/ingesting succeeded
        return true;
    }
    
    public function start_reading_taxa()
    {
        $this->children = array();
        $this->synonyms = array();
        $this->archive_reader->process_table("http://rs.tdwg.org/dwc/terms/Taxon", array($this, 'read_taxon'));
        $this->begin_adding_taxa();
    }
    
    private function begin_adding_taxa()
    {
        $this->taxon_ids_inserted = array();
        if(isset($this->children[0]))
        {
            // get all the roots, or taxa with no parents
            foreach($this->children[0] as $taxon_id => &$row)
            {
                $parent_hierarchy_entry_id = 0;
                $ancestry = "";
                $this->add_hierarchy_entry($row, $parent_hierarchy_entry_id, $ancestry);
                unset($this->children[$taxon_id]);
            }
        }else echo "THERE ARE NO ROOT TAXA\nAborting import\n";
    }
    
    public function read_taxon($row)
    {
        self::debug_iterations("Loading taxon");
        
        $taxon_id = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonID']);
        if(!$taxon_id) $taxon_id = @self::field_decode($row['http://purl.org/dc/terms/identifier']);
        $parent_taxon_id = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/parentNameUsageID']);
        $accepted_taxon_id = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/acceptedNameUsageID']);
        $is_valid = self::check_taxon_validity($row);
        
        if($taxon_id && $is_valid)
        {
            if(!$parent_taxon_id) $parent_taxon_id = 0;
            $this->children[$parent_taxon_id][] = $row;
        }elseif(!$is_valid && $parent_taxon_id)
        {
            $this->synonyms[$parent_taxon_id][] = $row;
        }elseif(!$is_valid && $accepted_taxon_id)
        {
            $this->synonyms[$accepted_taxon_id][] = $row;
        }
    }
    
    function add_hierarchy_entry(&$row, $parent_hierarchy_entry_id, $ancestry)
    {
        self::debug_iterations("Inserting taxon");
        self::commit_iterations("Taxa", 500);
        
        // make sure this taxon has a name, otherwise skip this branch
        $scientific_name = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/scientificName']);
        if(!$scientific_name) return false;
        
        // this taxon_id has already been inserted meaning this tree has a loop in it - so stop
        $taxon_id = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonID']);
        if(!$taxon_id) $taxon_id = @self::field_decode($row['http://purl.org/dc/terms/identifier']);
        if(!$taxon_id) return false;
        if(isset($this->taxon_ids_inserted[$taxon_id])) return false;
        
        $name = Name::find_or_create_by_string($scientific_name);
        if(@!$name->id) return false;
        
        $kingdom = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/kingdom']);
        $phylum = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/phylum']);
        $class = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/class']);
        $order = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/order']);
        $family = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/family']);
        $genus = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/genus']);
        $rank = Rank::find_or_create_by_translated_label(@self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonRank']));
        $source_url = @self::field_decode($row['http://rs.tdwg.org/ac/terms/furtherInformationURL']);
        if(!$source_url) $source_url = @self::field_decode($row['http://purl.org/dc/terms/source']);
        if(isset($row['http://rs.tdwg.org/dwc/terms/taxonRemarks'])) $taxon_remarks = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonRemarks']);
        else $taxon_remarks = NULL;
        
        // these are the taxa using the adjacency list format
        if(!$parent_hierarchy_entry_id && ($kingdom || $phylum || $class || $order || $family || $genus))
        {
            $params = array("identifier"        => $taxon_id,
                            "source_url"        => $source_url,
                            "kingdom"           => $kingdom,
                            "phylum"            => $phylum,
                            "class"             => $class,
                            "order"             => $order,
                            "family"            => $family,
                            "genus"             => $genus,
                            "scientificName"    => $scientific_name,
                            "name"              => $name,
                            "rank"              => $rank,
                            "taxon_remarks"     => $taxon_remarks);
            $hierarchy_entry = HierarchyEntry::create_entries_for_taxon($params, $this->harvest_event->resource->hierarchy_id);
            if(@!$hierarchy_entry->id) return;
            $this->harvest_event->add_hierarchy_entry($hierarchy_entry, 'inserted');
            $this->taxon_ids_inserted[$taxon_id] = array('hierarchy_entry_id' => $hierarchy_entry->id, 'taxon_concept_id' => $hierarchy_entry->taxon_concept_id, 'source_url' => $source_url);
        }
        // these are the taxa using the parent-child format
        else
        {
            $params = array("identifier"        => $taxon_id,
                            "source_url"        => $source_url,
                            "name_id"           => $name->id,
                            "parent_id"         => $parent_hierarchy_entry_id,
                            "hierarchy_id"      => $this->harvest_event->resource->hierarchy_id,
                            "rank"              => $rank,
                            "ancestry"          => $ancestry,
                            "taxon_remarks"     => $taxon_remarks);
            $hierarchy_entry = HierarchyEntry::find_or_create_by_array($params);
            if(@!$hierarchy_entry->id) return;
            $this->harvest_event->add_hierarchy_entry($hierarchy_entry, 'inserted');
            $this->taxon_ids_inserted[$taxon_id] = array('hierarchy_entry_id' => $hierarchy_entry->id, 'taxon_concept_id' => $hierarchy_entry->taxon_concept_id, 'source_url' => $source_url);
        }
        
        if(!isset($this->entry_references_deleted[$hierarchy_entry->id]))
        {
            $hierarchy_entry->delete_refs();
            $this->entry_references_deleted[$hierarchy_entry->id] = true;
        }
        
        if($name_published_in = @$row['http://rs.tdwg.org/dwc/terms/namePublishedIn'])
        {
            $individual_references = explode("||", $name_published_in);
            foreach($individual_references as $reference_string)
            {
                $reference = Reference::find_or_create_by_full_reference(trim($reference_string));
                if(@$reference->id)
                {
                    $hierarchy_entry->add_reference($reference->id);
                    $this->mysqli->query("UPDATE refs SET published=1, visibility_id=".Visibility::visible()->id." WHERE id=$reference->id");
                }
            }
        }
        
        // keep track of reference foreign keys
        self::append_foreign_keys_from_row($row, 'http://eol.org/schema/reference/referenceID', $this->taxon_reference_ids, $hierarchy_entry->id);
        
        if(isset($this->synonyms[$taxon_id]))
        {
            foreach($this->synonyms[$taxon_id] as $synonym_row)
            {
                $synonym_scientific_name = @self::field_decode($synonym_row['http://rs.tdwg.org/dwc/terms/scientificName']);
                if(!$synonym_scientific_name) continue;
                
                $synonym_taxon_id = @self::field_decode($synonym_row['http://rs.tdwg.org/dwc/terms/taxonID']);
                if(!$synonym_taxon_id) $taxon_id = @self::field_decode($synonym_row['http://purl.org/dc/terms/identifier']);
                if(!$synonym_taxon_id) continue;
                
                $synonym_name = Name::find_or_create_by_string($synonym_scientific_name);
                if(@!$synonym_name->id) continue;
                
                $taxonomic_status = @self::field_decode($synonym_row['http://rs.tdwg.org/dwc/terms/taxonomicStatus']) ?: 'synonym';
                if(isset($synonym_row['http://rs.tdwg.org/dwc/terms/taxonRemarks'])) $taxon_remarks = @self::field_decode($synonym_row['http://rs.tdwg.org/dwc/terms/taxonRemarks']);
                else $taxon_remarks = NULL;
                
                $synonym_relation = SynonymRelation::find_or_create_by_translated_label($taxonomic_status);
                $hierarchy_entry->add_synonym($synonym_name->id, @$synonym_relation->id ?: 0, 0, 0, 0, 0, $taxon_remarks);
            }
            unset($this->synonyms[$taxon_id]);
        }
        
        if(isset($this->children[$taxon_id]))
        {
            // set the ancestry for its children
            if($ancestry) $this_ancestry = $ancestry ."|". $name->id;
            else $this_ancestry = $name->id;
            
            foreach($this->children[$taxon_id] as $row)
            {
                $this->add_hierarchy_entry($row, $hierarchy_entry->id, $this_ancestry);
            }
            unset($this->children[$taxon_id]);
        }
        unset($hierarchy_entry);
    }
    
    public function insert_vernacular_names($row)
    {
        self::debug_iterations("Inserting VernacularName");
        $this->commit_iterations("VernacularName", 500);
        
        $taxon_ids = self::get_foreign_keys_from_row($row, 'http://rs.tdwg.org/dwc/terms/taxonID');
        $taxon_info = array();
        if($taxon_ids)
        {
            foreach($taxon_ids as $taxon_id)
            {
                if($taxon_info = @$this->taxon_ids_inserted[$taxon_id])
                {
                    $taxon_info[] = $taxon_info;
                }
            }
        }
        if(!$taxon_info) return false;
        
        $vernacularName = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/vernacularName']);
        $source = @self::field_decode($row['http://purl.org/dc/terms/source']);
        $languageString = @self::field_decode($row['http://purl.org/dc/terms/language']);
        $locality = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/locality']);
        $countryCode = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/countryCode']);
        $isPreferredName = @self::field_decode($row['http://rs.gbif.org/terms/1.0/isPreferredName']);
        $taxonRemarks = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonRemarks']);
        
        $name = Name::find_or_create_by_string($vernacularName);
        $language = Language::find_or_create_for_parser($languageString);
        if(!$name) return false;
        
        foreach($taxon_info as $info)
        {
            $he_id = $taxon_info['hierarchy_entry_id'];
            $tc_id = $taxon_info['taxon_concept_id'];
            if(!isset($this->entry_vernacular_names_deleted[$he_id]))
            {
                $this->mysqli->delete("DELETE FROM synonyms WHERE hierarchy_entry_id=$he_id AND hierarchy_id=". $this->harvest_event->resource->hierarchy_id ." AND language_id!=0 AND language_id!=". Language::find_or_create_for_parser('scientific name')->id);
                $this->entry_vernacular_names_deleted[$he_id] = true;
            }
            $common_name_relation = SynonymRelation::find_or_create_by_translated_label('common name');
            
            Synonym::find_or_create(array('name_id'               => $name->id,
                                          'synonym_relation_id'   => $common_name_relation->id,
                                          'language_id'           => @$language->id ?: 0,
                                          'hierarchy_entry_id'    => $he_id,
                                          'preferred'             => 0,
                                          'hierarchy_id'          => $this->harvest_event->resource->hierarchy_id,
                                          'vetted_id'             => 0,
                                          'published'             => 0,
                                          'taxonRemarks'          => $taxonRemarks));
        }
    }
    
    public function insert_data_object($row)
    {
        self::debug_iterations("Inserting DataObject");
        $this->commit_iterations("DataObject", 20);
        
        $object_taxon_ids = self::get_foreign_keys_from_row($row, 'http://rs.tdwg.org/dwc/terms/taxonID');
        $object_taxon_info = array();
        if($object_taxon_ids)
        {
            foreach($object_taxon_ids as $taxon_id)
            {
                if($taxon_info = @$this->taxon_ids_inserted[$taxon_id])
                {
                    $object_taxon_info[] = $taxon_info;
                }
            }
        }
        
        // // for when we allow taxon names in the media file
        // elseif(!$taxon_id)
        // {
        //     $scientific_name = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/scientificName']);
        //     if(!$scientific_name) return false;
        //     $taxon_parameters = array("scientific_name" => $scientific_name);
        //     $hierarchy_entry = HierarchyEntry::create_entries_for_taxon($taxon_parameters, $this->resource->hierarchy_id);
        //     if(@!$hierarchy_entry->id) return false;
        //     $this->resource->harvest_event->add_hierarchy_entry($hierarchy_entry, 'inserted');
        // }
        
        if(!$object_taxon_info) return false;
        
        
        $data_object = new DataObject();
        $data_object->identifier = @self::field_decode($row['http://purl.org/dc/terms/identifier']);
        if(isset($this->media_ids_inserted[$data_object->identifier])) return false;
        
        $data_object->data_type = DataType::find_or_create_by_schema_value(@self::field_decode($row['http://purl.org/dc/terms/type']));
        if($dt = DataType::find_or_create_by_schema_value(@self::field_decode($row['http://rs.tdwg.org/audubon_core/subtype'])))
        {
            $data_object->data_subtype_id = $dt->id;
        }
        $data_object->mime_type = MimeType::find_or_create_by_translated_label(@self::field_decode($row['http://purl.org/dc/terms/format']));
        $data_object->object_created_at = @self::field_decode($row['http://ns.adobe.com/xap/1.0/CreateDate']);
        $data_object->object_modified_at = @self::field_decode($row['http://purl.org/dc/terms/modified']);
        $data_object->available_at = @self::field_decode($row['http://purl.org/dc/terms/available']);
        $data_object->object_title = @self::field_decode($row['http://purl.org/dc/terms/title']);
        $data_object->language = Language::find_or_create_for_parser(@self::field_decode($row['http://purl.org/dc/terms/language']));
        $data_object->license = License::find_or_create_for_parser(@self::field_decode($row['http://ns.adobe.com/xap/1.0/rights/UsageTerms']));
        $data_object->rights_statement = @self::field_decode($row['http://purl.org/dc/terms/rights']);
        $data_object->rights_holder = @self::field_decode($row['http://ns.adobe.com/xap/1.0/rights/Owner']);
        $data_object->bibliographic_citation = @self::field_decode($row['http://purl.org/dc/terms/bibliographicCitation']);
        $data_object->source_url = @self::field_decode($row['http://rs.tdwg.org/ac/terms/furtherInformationURL']);
        $data_object->derived_from = @self::field_decode($row['http://rs.tdwg.org/ac/terms/derivedFrom']);
        $data_object->description = @self::field_decode($row['http://purl.org/dc/terms/description']);
        // Turn newlines into paragraphs
        $data_object->description = str_replace("\n","</p><p>", $data_object->description);
        
        $data_object->object_url = @self::field_decode($row['http://rs.tdwg.org/ac/terms/accessURI']);
        $data_object->thumbnail_url = @self::field_decode($row['http://eol.org/schema/media/thumbnailURL']);
        $data_object->location = @self::field_decode($row['http://iptc.org/std/Iptc4xmpExt/1.0/xmlns/LocationCreated']);
        $data_object->spatial_location = @self::field_decode($row['http://purl.org/dc/terms/spatial']);
        $data_object->latitude = @self::field_decode($row['http://www.w3.org/2003/01/geo/wgs84_pos#lat']);
        $data_object->longitude = @self::field_decode($row['http://www.w3.org/2003/01/geo/wgs84_pos#long']);
        $data_object->altitude = @self::field_decode($row['http://www.w3.org/2003/01/geo/wgs84_pos#alt']);
        
        $rating = @self::field_decode($row['http://ns.adobe.com/xap/1.0/Rating']);
        // ratings may be 0 to 5
        // TODO: technically 0 means untrusted, and then anywhere from 1-5 is OK.
        // 0.5 for example isn't really valid acording to the schema
        if((is_numeric($rating)) && $rating > 0 && $rating <= 5) $data_object->data_rating = $rating;
        
        //TODO - update this
        if($data_object->mime_type && $data_object->mime_type->equals(MimeType::flash()) && $data_object->is_video())
        {
            $data_object->data_type = DataType::youtube();
            $data_object->data_type_id = DataType::youtube()->id;
        }
        
        // //take the first available source_url of one of this object's taxa
        if(!@$data_object->source_url && @$taxon_parameters["source_url"])
        {
            foreach($object_taxon_info as $taxon_info)
            {
                if($source_url = $taxon_info['source_url'])
                {
                    $data_object->source_url = $source_url;
                    break;
                }
            }
        }
        
        /* Checking requirements */
        // if text: must have description
        if($data_object->data_type->equals(DataType::text()) && !$data_object->description) return false;
        // if image, movie or sound: must have object_url
        if(($data_object->data_type->equals(DataType::video()) || $data_object->data_type->equals(DataType::sound()) || $data_object->data_type->equals(DataType::image())) && !$data_object->object_url) return false;
        
        
        
        /* ADDING THE DATA OBJECT */
        list($data_object, $status) = DataObject::find_and_compare($this->harvest_event->resource, $data_object, $this->content_manager);
        if(@!$data_object->id) return false;
        $this->media_ids_inserted[$data_object->identifier] = $data_object->id;
        
        $this->harvest_event->add_data_object($data_object, $status);
        
        $data_object->delete_hierarchy_entries();
        $vetted_id = Vetted::unknown()->id;
        $visibility_id = Visibility::preview()->id;
        foreach($object_taxon_info as $taxon_info)
        {
            $he_id = $taxon_info['hierarchy_entry_id'];
            $tc_id = $taxon_info['taxon_concept_id'];
            $this->mysqli->insert("INSERT IGNORE INTO data_objects_hierarchy_entries (hierarchy_entry_id, data_object_id, vetted_id, visibility_id) VALUES ($he_id, $data_object->id, $vetted_id, $visibility_id)");
            $this->mysqli->insert("INSERT IGNORE INTO data_objects_taxon_concepts (taxon_concept_id, data_object_id) VALUES ($tc_id, $data_object->id)");
        }
        
        
        
        
        
        
        
        
        
        
        
        // a few things to add after the DataObject is inserted
        
        // keep track of reference foreign keys
        self::append_foreign_keys_from_row($row, 'http://eol.org/schema/reference/referenceID', $this->media_reference_ids, $data_object->id, $data_object->guid);
        // keep track of agent foreign keys
        self::append_foreign_keys_from_row($row, 'http://eol.org/schema/agent/agentID', $this->media_agent_ids, $data_object->id);
        
        $data_object->delete_info_items();
        $data_object->delete_table_of_contents();
        if($s = @self::field_decode($row['http://iptc.org/std/Iptc4xmpExt/1.0/xmlns/CVterm']))
        {
            $ii = InfoItem::find_or_create_by_schema_value($s);
            $data_object->add_info_item($ii->id);
            unset($ii);
        }
        
        if($a = @self::field_decode($row['http://purl.org/dc/terms/audience']))
        {
            $a = Audience::find_or_create_by_translated_label(trim((string) $a));
            $data_object->add_audience($a->id);
            unset($a);
        }
        
        
        
        
        $data_object_parameters["agents"] = array();
        self::append_agents($row, $data_object_parameters, 'http://purl.org/dc/terms/creator', 'Creator');
        self::append_agents($row, $data_object_parameters, 'http://purl.org/dc/terms/publisher', 'Publisher');
        self::append_agents($row, $data_object_parameters, 'http://purl.org/dc/terms/contributor', 'Contributor');
        
        $data_object->delete_agents();
        $i = 0;
        foreach($data_object_parameters['agents'] as &$a)
        {
            $agent = Agent::find_or_create($a);
            if($agent->logo_url && !$agent->logo_cache_url)
            {
                if($logo_cache_url = $this->content_manager->grab_file($agent->logo_url, 0, "partner"))
                {
                    $agent->logo_cache_url = $logo_cache_url;
                    $agent->save();
                }
            }
            
            $data_object->add_agent($agent->id, @$a['agent_role']->id ?: 0, $i);
            unset($a);
            $i++;
        }
        
        if(!isset($this->object_references_deleted[$data_object->id]))
        {
            $data_object->delete_refs();
            $this->object_references_deleted[$data_object->id] = true;
        }
    }
    
    public function insert_references($row)
    {
        self::debug_iterations("Inserting reference");
        $this->commit_iterations("Reference", 500);
        
        $reference_id = @self::field_decode($row['http://purl.org/dc/terms/identifier']);
        // we really only need to insert the references that relate to taxa or media
        if(!isset($this->taxon_reference_ids[$reference_id]) && !isset($this->media_reference_ids[$reference_id])) return;
        
        $full_reference = @self::field_decode($row['http://eol.org/schema/reference/full_reference']);
        $title = @self::field_decode($row['http://purl.org/dc/terms/title']);
        $pages = @self::field_decode($row['http://purl.org/ontology/bibo/pages']);
        $pageStart = @self::field_decode($row['http://purl.org/ontology/bibo/pageStart']);
        $pageEnd = @self::field_decode($row['http://purl.org/ontology/bibo/pageEnd']);
        $volume = @self::field_decode($row['http://purl.org/ontology/bibo/volume']);
        $edition = @self::field_decode($row['http://purl.org/ontology/bibo/edition']);
        $publisher = @self::field_decode($row['http://purl.org/dc/terms/publisher']);
        $authorList = @self::field_decode($row['http://purl.org/ontology/bibo/authorList']);
        $editorList = @self::field_decode($row['http://purl.org/ontology/bibo/editorList']);
        $created = @self::field_decode($row['http://purl.org/dc/terms/created']);
        $language = Language::find_or_create_for_parser(@self::field_decode($row['http://purl.org/dc/terms/language']));
        $uri = @self::field_decode($row['http://purl.org/ontology/bibo/uri']);
        $doi = @self::field_decode($row['http://purl.org/ontology/bibo/doi']);
        
        $params = array("provider_mangaed_id"       => $reference_id,
                        "full_reference"            => $full_reference,
                        "title"                     => $title,
                        "pages"                     => $pages,
                        "page_start"                => $pageStart,
                        "page_end"                  => $pageEnd,
                        "volume"                    => $volume,
                        "edition"                   => $edition,
                        "publisher"                 => $publisher,
                        "authors"                   => $authorList,
                        "editors"                   => $editorList,
                        "publication_created_at"    => @$created ?: '0000-00-00 00:00:00',
                        "language_id"               => @$language->id ?: 0,
                        "editors"                   => $editorList);
        $reference = Reference::find_or_create($params);
        if($uri)
        {
            $type = RefIdentifierType::find_or_create_by_label('uri');
            $reference->add_ref_identifier(@$type->id ?: 0, $uri);
        }
        if($doi)
        {
            $type = RefIdentifierType::find_or_create_by_label('doi');
            $reference->add_ref_identifier(@$type->id ?: 0, $doi);
        }
        
        
        if(isset($this->taxon_reference_ids[$reference_id]))
        {
            foreach($this->taxon_reference_ids[$reference_id] as $hierarchy_entry_id => $val)
            {
                $this->mysqli->insert("INSERT IGNORE INTO hierarchy_entries_refs (hierarchy_entry_id, ref_id) VALUES ($hierarchy_entry_id, $reference->id)");
                $this->mysqli->query("UPDATE refs SET published=1, visibility_id=".Visibility::visible()->id." WHERE id=$reference->id");
                // TODO: find_or_create doesn't work here because of the dual primary key
                // HierarchyEntriesRef::find_or_create(array(
                //     'hierarchy_entry_id'    => $hierarchy_entry_id,
                //     'ref_id'                => $reference->id));
            }
        }
        if(isset($this->media_reference_ids[$reference_id]))
        {
            foreach($this->media_reference_ids[$reference_id] as $data_object_id => $data_object_guid)
            {
                $this->mysqli->insert("INSERT IGNORE INTO data_objects_refs (data_object_id, ref_id) VALUES ($data_object_id, $reference->id)");
                $this->mysqli->query("UPDATE refs SET published=1, visibility_id=".Visibility::visible()->id." WHERE id=$reference->id");
                // TODO: find_or_create doesn't work here because of the dual primary key - same as above with entries
            }
        }
    }
    
    public function insert_agents($row)
    {
        self::debug_iterations("Inserting agent");
        
        $agent_id = @self::field_decode($row['http://purl.org/dc/terms/identifier']);
        // we really only need to insert the agents that relate to media
        if(!isset($this->media_agent_ids[$agent_id])) return;
        
        $params = array("full_name"     => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_name']),
                        "given_name"    => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_firstName']),
                        "family_name"   => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_familyName']),
                        "email"         => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_mbox']),
                        "homepage"      => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_homepage']),
                        "logo_url"      => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_logo']),
                        "project"       => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_currentProject']),
                        "organization"  => @self::field_decode($row['http://eol.org/schema/agent/organization']),
                        "account_name"  => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_accountName']),
                        "openid"        => @self::field_decode($row['http://xmlns.com/foaf/spec/#term_openid']));
        // find or create this agent
        $agent = Agent::find_or_create($params);
        if(!$agent) return;
        // download the logo if there is one, and it hasn't ever been downloaded before
        if($agent->logo_url && !$agent->logo_cache_url)
        {
            if($logo_cache_url = $this->content_manager->grab_file($agent->logo_url, 0, "image"))
            {
                $agent->logo_cache_url = $logo_cache_url;
                $agent->save();
            }
        }
        
        $agent_role = AgentRole::find_or_create_by_translated_label(@self::field_decode($row['http://eol.org/schema/agent/agentRole']));
        $agent_role_id = @$agent_role->id ?: 0;
        foreach($this->media_agent_ids[$agent_id] as $data_object_id => $val)
        {
            # TODO: intelligently delete agents for objects ONLY ONCE during an import
            # TODO: figure out view order
            $this->mysqli->insert("INSERT IGNORE INTO agents_data_objects VALUES ($data_object_id, $agent->id, $agent_role_id, 0)");
        }
    }
    
    private function commit_iterations($namespace, $iteration_size = 500)
    {
        static $iteration_counts = array();
        if(!isset($iteration_counts[$namespace])) $iteration_counts[$namespace] = 0;
        if($iteration_counts[$namespace] % $iteration_size == 0)
        {
            $this->mysqli->commit();
        }
        $iteration_counts[$namespace]++;
    }
    
    private static function debug_iterations($message_prefix, $iteration_size = 500)
    {
        static $iteration_counts = array();
        if(!isset($iteration_counts[$message_prefix])) $iteration_counts[$message_prefix] = 0;
        if($iteration_counts[$message_prefix] % $iteration_size == 0)
        {
            if($GLOBALS['ENV_DEBUG']) echo $message_prefix ." $iteration_counts[$message_prefix]: ". memory_get_usage() ."\n";
        }
        $iteration_counts[$message_prefix]++;
    }
    
    // this method will compare the taxonomic status of a taxon with a list of known valid statuses
    private static function check_taxon_validity(&$row)
    {
        // assume the taxon is valid by default
        $is_valid = true;
        // if the taxon has a status which isn't valid, then it isn't valid
        $taxonomic_status = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/taxonomicStatus']);
        if($taxonomic_status && trim($taxonomic_status) != '')
        {
            $is_valid = Functions::array_searchi($taxonomic_status, self::$valid_taxonomic_statuses);
            // $is_valid might be zero at this point so we need to check
            if($is_valid === null) $is_valid = false;
            else $is_valid = true;  // $is_valid could be 0 here
        }
        
        // if the taxon has an acceptedNameUsageID then it isn't valid
        $accepted_taxon_id = @self::field_decode($row['http://rs.tdwg.org/dwc/terms/acceptedNameUsageID']);
        if($is_valid && $accepted_taxon_id && $accepted_taxon_id != '')
        {
            $is_valid = false;
        }
        return $is_valid;
    }
    
    private static function append_foreign_keys_from_row(&$row, $uri, &$ids_array, &$index_key, $index_value = 1)
    {
        $ids = self::get_foreign_keys_from_row($row, $uri);
        foreach($ids as $id)
        {
            $ids_array[$id][$index_key] = $index_value;
        }
    }
    
    private static function get_foreign_keys_from_row(&$row, $uri)
    {
        $foreign_keys = array();
        if($field_value = @self::field_decode(@$row[$uri]))
        {
            $ids = preg_split("/[;,]/", $field_value);
            foreach($ids as $id)
            {
                $id = trim($id);
                if(!$id) continue;
                $foreign_keys[] = $id;
            }
        }
        return $foreign_keys;
    }
    
    
    private static function append_agents(&$row, &$data_object_parameters, $uri, $agent_role)
    {
        if($field_value = @self::field_decode($row[$uri]))
        {
            $individual_agents = preg_split("/[;]/", $field_value);
            foreach($individual_agents as $agent_name)
            {
                $agent_name = trim($agent_name);
                if(!$agent_name) continue;
                $params = array("full_name" => $agent_name,
                                "agent_role" => AgentRole::find_or_create_by_translated_label($agent_role));
                $data_object_parameters["agents"][] = $params;
            }
        }
    }
    
    private static function field_decode($string)
    {
        $string = str_replace("\\n", "\n", $string);
        $string = str_replace("\\r", "\r", $string);
        $string = str_replace("\\t", "\t", $string);
        return trim($string);
    }
}

?>