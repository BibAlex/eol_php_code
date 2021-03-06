<?php

class SchemaParser
{
    public static function parse($uri, &$connection, $validate = true)
    {
        if(!$uri) return false;
        $mysqli =& $GLOBALS['mysqli_connection'];
        
        // set valid to true if we don't need validation
        $valid = $validate ? SchemaValidator::validate($uri) : true;
        if($valid !== true) return false;
        
        $reader = new XMLReader();
        $reader->open($uri);
        $resource = $connection->get_resource();
        
        $i = 0;
        while(@$reader->read())
        {
            if($reader->nodeType == XMLReader::ELEMENT && $reader->name == "taxon")
            {
                $taxon_xml = $reader->readOuterXML();
                $t = simplexml_load_string($taxon_xml, null, LIBXML_NOCDATA);
                
                $taxon_parameters = self::read_taxon_xml($t, $resource);
                $connection->add_taxon($taxon_parameters);
                
                $i++;
                if($i%100==0) debug("Parsed taxon $i");
                
                // trying now to see if commiting every 200 taxa will help with replication
                if($i%200==0) $mysqli->commit();
                
                if(defined("DEBUG_PARSE_TAXON_LIMIT") && DEBUG_PARSE_TAXON_LIMIT && $i >= DEBUG_PARSE_TAXON_LIMIT) break;
            }
        }
    }
    
    public static function read_taxon_xml($t, $resource)
    {
        $t_dc = $t->children("http://purl.org/dc/elements/1.1/");
        $t_dcterms = $t->children("http://purl.org/dc/terms/");
        $t_dwc = $t->children("http://rs.tdwg.org/dwc/dwcore/");
        
        $taxon_parameters = array();
        $taxon_parameters["identifier"] = Functions::import_decode($t_dc->identifier);
        $taxon_parameters["source_url"] = Functions::import_decode($t_dc->source);
        $taxon_parameters["kingdom"] = Functions::import_decode($t_dwc->Kingdom);
        $taxon_parameters["phylum"] = Functions::import_decode($t_dwc->Phylum);
        $taxon_parameters["class"] = Functions::import_decode($t_dwc->Class);
        $taxon_parameters["order"] = Functions::import_decode($t_dwc->Order);
        $taxon_parameters["family"] = Functions::import_decode($t_dwc->Family);
        $taxon_parameters["genus"] = Functions::import_decode($t_dwc->Genus);
        $taxon_parameters["scientific_name"] = Functions::import_decode($t_dwc->ScientificName);
        $taxon_parameters["name_id"] = Name::insert(Functions::import_decode($t_dwc->ScientificName));
        $taxon_parameters["taxon_created_at"] = trim($t_dcterms->created);
        $taxon_parameters["taxon_modified_at"] = trim($t_dcterms->modified);
        
        if(!$taxon_parameters["scientific_name"])
        {
            if($name = $taxon_parameters["genus"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["genus"] = "";
            }elseif($name = $taxon_parameters["family"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["family"] = "";
            }elseif($name = $taxon_parameters["order"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["order"] = "";
            }elseif($name = $taxon_parameters["class"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["class"] = "";
            }elseif($name = $taxon_parameters["phylum"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["phylum"] = "";
            }elseif($name = $taxon_parameters["kingdom"])
            {
                $taxon_parameters["scientific_name"] = $name;
                $taxon_parameters["name_id"] = Name::insert($name);
                $taxon_parameters["kingdom"] = "";
            }else return;
        }                
        
        $taxon_parameters["common_names"] = array();
        foreach($t->commonName as $c)
        {
            $common_name = Functions::import_decode((string) $c);
            if(!$common_name) continue;
            $xml_attr = $c->attributes("http://www.w3.org/XML/1998/namespace");
            $params = array(    "common_name"   => $common_name,
                                "language_id"   => Language::insert(@Functions::import_decode($xml_attr["lang"])));
            $taxon_parameters["common_names"][] = $params;
        }
        
        $taxon_parameters["synonyms"] = array();
        foreach($t->synonym as $s)
        {
            $synonym = Functions::import_decode((string) $s);
            if(!$synonym) continue;
            
            $attr = $s->attributes();
            if(!@$attr["relationship"]) $attr["relationship"] = 'synonym';
            $params = array(    "name_id"               => Name::insert($synonym),
                                "synonym_relation_id"   => SynonymRelation::insert(trim($attr["relationship"])));
            $taxon_parameters["synonyms"][] = Functions::mock_object("Synonym", $params);
        }
        
        $taxon_parameters["agents"] = array();
        foreach($t->agent as $a)
        {
            $agent_name = Functions::import_decode((string) $a);
            if(!$agent_name) continue;
            
            $attr = $a->attributes();
            $params = array(    "full_name"     => Functions::import_decode((string) $a, 0, 0),
                                "homepage"      => @Functions::import_decode($attr["homepage"]),
                                "logo_url"      => @Functions::import_decode($attr["logoURL"]),
                                "agent_role_id" => AgentRole::insert(@trim($attr["role"])));
            $taxon_parameters["agents"][] = Functions::mock_object("Agent", $params);
            unset($params);
        }
        
        $taxon_parameters["refs"] = array();
        foreach($t->reference as $r)
        {
            $reference = Functions::import_decode((string) $r);
            if(!$reference) continue;
            $attr = $r->attributes();
            
            $params = array();
            $params["id"] = Reference::insert($reference);
            
            $id_labels = array("bici", "coden", "doi", "eissn", "handle", "issn", "isbn", "lsid", "oclc", "sici", "url", "urn");
            
            $ids = array();
            foreach($id_labels as $label)
            {
                if($id = @Functions::import_decode($attr[$label], 0, 0))
                {
                    $id_params = array( "ref_identifier_type_id" => RefIdentifierType::insert($label),
                                        "identifier"             => $id);
                    $ids[] = (object) $id_params;
                }
            }
            $params["identifiers"] = (object) $ids;
            
            $taxon_parameters["refs"][] = Functions::mock_object("Reference", $params);
        }
        
        $taxon_parameters["data_objects"] = array();
        foreach($t->dataObject as $d)
        {
            $d_dc = $d->children("http://purl.org/dc/elements/1.1/");
            $d_dcterms = $d->children("http://purl.org/dc/terms/");
            $d_geo = $d->children("http://www.w3.org/2003/01/geo/wgs84_pos#");
            
            
            $data_object_parameters = array();
            $data_object_parameters["identifier"] = Functions::import_decode($d_dc->identifier);
            $data_object_parameters["data_type_id"] = DataType::insert(Functions::import_decode($d->dataType));
            $data_object_parameters["mime_type_id"] = MimeType::insert(Functions::import_decode($d->mimeType));
            $data_object_parameters["object_created_at"] = Functions::import_decode($d_dcterms->created);
            $data_object_parameters["object_modified_at"] = Functions::import_decode($d_dcterms->modified);
            $data_object_parameters["object_title"] = Functions::import_decode($d_dc->title, 0, 0);
            $data_object_parameters["language_id"] = Language::insert(Functions::import_decode($d_dc->language));
            $data_object_parameters["license_id"] = License::insert(Functions::import_decode($d->license));
            $data_object_parameters["rights_statement"] = Functions::import_decode($d_dc->rights, 0, 0);
            $data_object_parameters["rights_holder"] = Functions::import_decode($d_dcterms->rightsHolder, 0, 0);
            $data_object_parameters["bibliographic_citation"] = Functions::import_decode($d_dcterms->bibliographicCitation, 0, 0);
            $data_object_parameters["source_url"] = Functions::import_decode($d_dc->source);
            $data_object_parameters["description"] = Functions::import_decode($d_dc->description, 0, 0);
            $data_object_parameters["object_url"] = Functions::import_decode($d->mediaURL);
            $data_object_parameters["thumbnail_url"] = Functions::import_decode($d->thumbnailURL);
            $data_object_parameters["location"] = Functions::import_decode($d->location, 0, 0);
            
            if(!$data_object_parameters["language_id"])
            {
                $xml_attr = $d_dc->description->attributes("http://www.w3.org/XML/1998/namespace");
                $data_object_parameters["language_id"] = Language::insert(@Functions::import_decode($xml_attr["lang"]));
            }
            
            //TODO - update this
            if($data_object_parameters["mime_type_id"] == MimeType::insert("video/x-flv") && $data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/MovingImage"))
            {
                $data_object_parameters["data_type_id"] = DataType::insert("YouTube");
            }
            
            //take the taxon's source_url if none present
            if(!@$data_object_parameters["source_url"] && @$taxon_parameters["source_url"]) $data_object_parameters["source_url"] = $taxon_parameters["source_url"];
            
            // Turn newlines into paragraphs
            $data_object_parameters["description"] = str_replace("\n","</p><p>",$data_object_parameters["description"]);
            
            
            /* Checking requirements*/
            
            //if text: must have description
            if($data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/Text") && !$data_object_parameters["description"]) continue;
            
            //if image, movie or sound: must have object_url
            if(($data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/MovingImage") || $data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/Sound") || $data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/StillImage")) && !$data_object_parameters["object_url"]) continue;
            
            
            
            
            
            
            $data_object_parameters["latitude"] = 0;
            $data_object_parameters["longitude"] = 0;
            $data_object_parameters["altitude"] = 0;
            foreach($d_geo->Point as $p)
            {
                $p_geo = $p->children("http://www.w3.org/2003/01/geo/wgs84_pos#");
                
                $data_object_parameters["latitude"] = Functions::import_decode($p_geo->lat);
                $data_object_parameters["longitude"] = Functions::import_decode($p_geo->long);
                $data_object_parameters["altitude"] = Functions::import_decode($p_geo->alt);
            }
            
            $data_object_parameters["agents"] = array();
            foreach($d->agent as $a)
            {
                $agent_name = Functions::import_decode((string) $a);
                if(!$agent_name) continue;
                
                $attr = $a->attributes();
                
                $params = array(    "full_name"     => Functions::import_decode((string) $a, 0, 0),
                                    "homepage"      => @Functions::import_decode($attr["homepage"]),
                                    "logo_url"      => @Functions::import_decode($attr["logoURL"]),
                                    "agent_role_id" => AgentRole::insert(@trim($attr["role"])));
                $data_object_parameters["agents"][] = Functions::mock_object("Agent", $params);
                unset($params);
            }
            
            $data_object_parameters["audience_ids"] = array();
            foreach($d->audience as $a)
            {
                $data_object_parameters["audience_ids"][] = Audience::insert(trim((string) $a));
            }
            
            $data_object_parameters["info_items_ids"] = array();
            foreach($d->subject as $s)
            {
                $data_object_parameters["info_items_ids"][] = InfoItem::insert(trim((string) $s));
            }
            
            
            // EXCEPTIONS
            if($data_object_parameters["data_type_id"] == DataType::insert("http://purl.org/dc/dcmitype/Text"))
            {
                if($resource->title == "BOLD Systems Resource")
                {
                    // EXCEPTION - overriding the subject for BOLD
                    $data_object_parameters["info_items_ids"] = array(InfoItem::insert('http://www.eol.org/voc/table_of_contents#Barcode'));
                }elseif($resource->title == "Wikipedia")
                {
                    // EXCEPTION - overriding the subject for Wikipedia
                    $data_object_parameters["info_items_ids"] = array(InfoItem::insert('http://www.eol.org/voc/table_of_contents#Wikipedia'));
                }elseif($resource->title == "IUCN Red List")
                {
                    if($data_object_parameters["object_title"] == "IUCNConservationStatus")
                    {
                        // EXCEPTION - overriding the data type for IUCN text
                        $data_object_parameters["data_type_id"] = DataType::insert('IUCN');
                    }
                }
            }
            
            
            
            
            $data_object_parameters["refs"] = array();
            foreach($d->reference as $r)
            {
                $reference = Functions::import_decode((string) $r, 0, 0);
                if(!$reference) continue;
                $attr = $r->attributes();
                
                $params = array();
                $params["id"] = Reference::insert($reference);
                
                $id_labels = array("bici", "coden", "doi", "eissn", "handle", "issn", "isbn", "lsid", "oclc", "sici", "url", "urn");
                
                $ids = array();
                foreach($id_labels as $label)
                {
                    if($id = @trim($attr[$label]))
                    {
                        $id_params = array( "ref_identifier_type_id" => RefIdentifierType::insert($label),
                                        "identifier"             => $id);
                        $ids[] = (object) $id_params;
                    }
                }
                $params["identifiers"] = (object) $ids;
                
                $data_object_parameters["refs"][] = Functions::mock_object("Reference", $params);
            }
            
            $taxon_parameters["data_objects"][] = Functions::mock_object("DataObject", $data_object_parameters);
            unset($data_object_parameters);
        }
        
        return $taxon_parameters;
    }
    
    public static function eol_schema_validate($uri)
    {
        if(!$uri) return false;
        
        $valid = SchemaValidator::validate($uri);
        if($valid !== true) return array();
        
        $errors = array();
        $warnings = array();
        
        $reader = new XMLReader();
        $reader->open($uri);
        
        $i = 0;
        while(@$reader->read())
        {
            if($reader->nodeType == XMLReader::ELEMENT && $reader->name == "taxon")
            {
                $taxon_xml = $reader->readOuterXML();
                $t = simplexml_load_string($taxon_xml, null, LIBXML_NOCDATA);
                
                $t_dc = $t->children("http://purl.org/dc/elements/1.1/");
                $t_dwc = $t->children("http://rs.tdwg.org/dwc/dwcore/");
                
                $identifier = Functions::import_decode($t_dc->identifier);
                $source_url = Functions::import_decode($t_dc->source);
                $scientific_name = Functions::import_decode($t_dwc->ScientificName);
                
                if(!$identifier) $warnings[$scientific_name]["taxon without dc:identifier"] = true;
                if(!$source_url) $warnings[$scientific_name]["taxon without dc:source"] = true;
                
                foreach($t->dataObject as $d)
                {
                    $d_dc = $d->children("http://purl.org/dc/elements/1.1/");                
                    
                    $identifier = Functions::import_decode($d_dc->identifier);
                    $data_type = Functions::import_decode($d->dataType);
                    $license = Functions::import_decode($d->license);
                    $source_url = Functions::import_decode($d_dc->source);
                    $description = Functions::import_decode($d_dc->description, 0, 0);
                    $object_url = Functions::import_decode($d->mediaURL);
                    
                    
                    $subjects = array();
                    foreach($d->subject as $s)
                    {
                        $subjects[] = trim((string) $s);
                    }
                    
                    
                    /* Checking requirements*/
                    if(!$identifier) $warnings[$scientific_name]["data object without dc:identifier"] = true;
                    if(!$license) $warnings[$scientific_name]["data object without license"] = true;
                    
                    //if text: must have description
                    if($data_type == "http://purl.org/dc/dcmitype/Text" && !$description) $errors[$scientific_name]["text without dc:description"] = true;
                    
                    //if text: must have subject
                    if($data_type == "http://purl.org/dc/dcmitype/Text" && !$subjects) $errors[$scientific_name]["text without subject"] = true;
                    
                    //if image, movie or sound: must have object_url
                    if($data_type != "http://purl.org/dc/dcmitype/Text" && !$object_url) $errors[$scientific_name]["media without mediaURL"] = true;
                }
                
                //unset($xml->taxon[$i]);
                $xml->taxon[$i] = null;
                $i++;
                
                //if($i%100==0 && DEBUG) debug("Parsed taxon $i");
                //if(defined("DEBUG_PARSE_TAXON_LIMIT") && $i >= DEBUG_PARSE_TAXON_LIMIT) break;
            }
        }
        
        return array($errors, $warnings);
    }
}

?>