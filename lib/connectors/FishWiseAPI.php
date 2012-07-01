<?php
namespace php_active_record;
/* connector: [190]  */

define("FWP_SPECIES_DOC_PATH", DOC_ROOT . "/update_resources/connectors/files/FishWisePro/species.xls");
define("FWP_IMAGES_DOC_PATH", DOC_ROOT . "/update_resources/connectors/files/FishWisePro/images.xls");
define("FWP_COMNAMES_DOC_PATH", DOC_ROOT . "/update_resources/connectors/files/FishWisePro/comnames.xls");
define("FWP_SYNONYMS_DOC_PATH", DOC_ROOT . "/update_resources/connectors/files/FishWisePro/synonyms.xls");

define("FWP_IMAGE_URL", "http://www.fishwisepro.com/pics/");
define("FWP_SPECIES_PAGE_URL", "http://www.fishwisepro.com/Species/details.aspx?Zoom=True&SId=");//45079
define("FWP_IMAGE_PAGE_URL", "http://www.fishwisepro.com/Pictures/details.aspx?Zoom=True");//&SId=45079&PictureId=83

class FishWiseAPI
{
    public static function get_all_taxa($resource_id)
    {
        $all_taxa = array();
        $used_collection_ids = array();

        $urls = array(FWP_SPECIES_DOC_PATH,);
        $taxa_arr = self::compile_taxa($urls);

        require_library('XLSParser');
        $parser = new XLSParser();

        $images = self::prepare_table($parser->convert_sheet_to_array(FWP_IMAGES_DOC_PATH), "multiple",
        "SId", "SId", "PictureId", "dbo_Picture_PictureNote", "PictureType", 
        "IsLegal", "Location", "PicComments", "IsAvailable", "LifeStage",
        "CollectionName", "CollectionAcronym", "PictureSource", "Surname", "Firstname", "DisplayName", "FileName");

        $comnames=self::prepare_table($parser->convert_sheet_to_array(FWP_COMNAMES_DOC_PATH), "multiple",
        "SId", "CommonName", "Language");

        $synonyms=self::prepare_table($parser->convert_sheet_to_array(FWP_SYNONYMS_DOC_PATH),"multiple",
        "SId", "SynGenusSpecies", "SynStatus");
        
        $i = 1; 
        $total = sizeof($taxa_arr);
        $j = 0;
        foreach($taxa_arr as $taxon_arr)
        {
            print "\n $i of $total -- " . $taxon_arr['SId'];
            $i++;
            $taxon_id = $taxon_arr['SId'];

            $page_taxa = self::get_fishwise_taxa($taxon_arr, @$images[$taxon_id], @$comnames[$taxon_id], @$synonyms[$taxon_id]);
            $all_taxa = array_merge($all_taxa, $page_taxa);

            if($i % 10000 == 0)
            {
                $j++; 
                $xml = SchemaDocument::get_taxon_xml($all_taxa);
                if($j < 10) $j_str = substr(strval($j/100),2,2);
                else        $j_str = strval($j);
                $resource_path = DOC_ROOT . "/update_resources/connectors/files/FishWisePro/" . $j_str . ".xml";
                $OUT = fopen($resource_path, "w+"); fwrite($OUT, $xml); fclose($OUT);            
                $all_taxa = array();
            } 
        }
        if($all_taxa)
        {
            $j++; 
            $xml = \SchemaDocument::get_taxon_xml($all_taxa);
            if($j < 10) $j_str = substr(strval($j/100), 2, 2);
            else        $j_str = strval($j);                    
            $resource_path = DOC_ROOT . "/update_resources/connectors/files/FishWisePro/" . $j_str . ".xml";
            $OUT = fopen($resource_path, "w+"); fwrite($OUT, $xml); fclose($OUT);
        }
        self::combine_all_xmls($resource_id);
        return;
    }

    function combine_all_xmls($resource_id)
    {
        $old_resource_path = CONTENT_RESOURCE_LOCAL_PATH . $resource_id .".xml";
        $OUT = fopen($old_resource_path, "w+");
        $str = "<?xml version='1.0' encoding='utf-8' ?>\n";
        $str .= "<response\n";
        $str .= "  xmlns='http://www.eol.org/transfer/content/0.3'\n";
        $str .= "  xmlns:xsd='http://www.w3.org/2001/XMLSchema'\n";
        $str .= "  xmlns:dc='http://purl.org/dc/elements/1.1/'\n";
        $str .= "  xmlns:dcterms='http://purl.org/dc/terms/'\n";
        $str .= "  xmlns:geo='http://www.w3.org/2003/01/geo/wgs84_pos#'\n";
        $str .= "  xmlns:dwc='http://rs.tdwg.org/dwc/dwcore/'\n";
        $str .= "  xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'\n";
        $str .= "  xsi:schemaLocation='http://www.eol.org/transfer/content/0.3 http://services.eol.org/schema/content_0_3.xsd'>\n";
        fwrite($OUT, $str);
        $i = 1;

        while(true)
        {
            if($i < 10) $i_str = substr(strval($i/100),2,2);
            else        $i_str = strval($i);

            print " $i "; 
            $filename = DOC_ROOT . "/update_resources/connectors/files/FishWisePro/" . $i_str . ".xml";
            if(!is_file($filename))
            {
                print " - not yet ready";
                break;
            }
            $READ = fopen($filename, "r");
            $contents = fread($READ,filesize($filename));
            fclose($READ);

            if($contents)
            {
                $pos1 = stripos($contents,"<taxon>");
                $pos2 = stripos($contents,"</response>");
                $str  = substr($contents,$pos1,$pos2-$pos1);
                fwrite($OUT, $str);
                unlink($filename);
            }            
            $i++; 
        }
        fwrite($OUT, "</response>"); fclose($OUT);
    }

    public static function get_fishwise_taxa($rec, $taxon_images, $taxon_comnames, $taxon_syn)
    {
        global $used_collection_ids;

        $response = self::search_collections($rec, $taxon_images, $taxon_comnames, $taxon_syn); //this will output the raw (but structured) output from the external service
        $page_taxa = array();
        foreach($response as $rec)
        {
            if(@$used_collection_ids[$rec["sciname"]]) continue;
            $taxon = self::get_taxa_for_photo($rec);
            if($taxon) $page_taxa[] = $taxon;
            $used_collection_ids[$rec["sciname"]] = true;
        }
        return $page_taxa;
    }

    function compile_taxa($urls)
    {
        require_library('XLSParser');
        $parser = new XLSParser();

        $taxa_arr = array();
        foreach($urls as $url)   
        {            
            $arr = self::prepare_table($parser->convert_sheet_to_array($url), "single",
            "SId", "SId", "GenusSpecies", "AuthorSpecies", "Family", "DistributionT", "OrderName", "Notes",
            "Habitat", "HabitatNotes", "DepthRange", "DepthRangeShallow", "DepthRangeDeep",
            "LengthMax", "LengthMaxSuffix", "LengthMaxType", "Journal", "Citation", "TextPage"            
            );
            //normal operation
            $taxa_arr=$arr;
        }
        return $taxa_arr;
    }

    function search_collections($taxon, $taxon_images, $taxon_comnames, $taxon_syn)
    {
        $taxon_id = $taxon["SId"];                    
        $response = self::prepare_species_page($taxon, @$taxon_images, $taxon_comnames, $taxon_syn);
        return $response;
    }           
        
    function prepare_species_page($taxon, $taxon_images, $taxon_comnames, $taxon_syn)
    {   
        $arr_scraped = array();
        $arr_photos = array();
        $arr_sciname = array();
        $taxon_id = $taxon["SId"];
            if(true)
            {
                $sciname = $taxon['GenusSpecies'];
                $agent = array();
                $agent[] = array("role" => "project" , "homepage" => "http://www.fishwisepro.com" , "name" => "FishWise Professional");
                $rights_holder = "FishWise Professional";
                $mimeType = "text/html";
                $dataType = "http://purl.org/dc/dcmitype/Text";
                $mediaURL = "";
                $reference = array();
                $dc_source = FWP_SPECIES_PAGE_URL . $taxon['SId'];
                if(trim(@$taxon['DistributionT']) != "---")
                {                    
                    $desc = $taxon['DistributionT'];
                    $desc = utf8_decode($desc);
                    $arr_texts[$sciname][] = self::fill_data_object($desc, "http://rs.tdwg.org/ontology/voc/SPMInfoItems#Distribution", "", $agent, $rights_holder, $mediaURL, $mimeType, $dataType, $dc_source);
                }
                if(trim(@$taxon['LengthMax']) > 0)
                {
                    $desc = "Maximum size: $taxon[LengthMax] $taxon[LengthMaxSuffix] $taxon[LengthMaxType]";
                    $desc = utf8_decode($desc);                        
                    $arr_texts[$sciname][] = self::fill_data_object($desc, "http://rs.tdwg.org/ontology/voc/SPMInfoItems#Size", "", $agent, $rights_holder, $mediaURL, $mimeType, $dataType, $dc_source);
                }
                if(trim(@$taxon['DepthRangeDeep']) > 0)
                {                
                    $desc = "Depth: $taxon[DepthRangeShallow] -  $taxon[DepthRangeDeep]m. ";
                    if(trim(@$taxon['DepthRange']) != "---")$desc.="<br>$taxon[DepthRange]";
                    if(trim(@$taxon['Habitat']) != "---")$desc.="<br><br>Habitat: $taxon[Habitat].";
                    if(trim(@$taxon['HabitatNotes']) != "---")$desc.=" $taxon[HabitatNotes]";
                    $desc = utf8_decode($desc);
                    $arr_texts[$sciname][] = self::fill_data_object($desc, "http://rs.tdwg.org/ontology/voc/SPMInfoItems#Habitat", "", $agent, $rights_holder, $mediaURL, $mimeType, $dataType, $dc_source);
                }
                
                $arr_photos = array();
                if($arr = @$taxon_images)
                {
                    foreach($arr as $r)
                    {
                        if  ( trim(@$r['FileName']) != "---" )
                        {                         
                            $path_info = pathinfo($r['FileName']);
                            $extension = strtolower($path_info['extension']);                                                                                                
                            $mediaURL = FWP_IMAGE_URL . "$extension/" . trim(@$r['FileName']);
                            $mimeType = Functions::get_mimetype($mediaURL);
                            $dataType = "http://purl.org/dc/dcmitype/StillImage";
                            $desc = "";
                            if(trim(@$r['LifeStage'])!= "---")                $desc .= "Life stage: " . $r['LifeStage'];
                            if(trim(@$r['dbo_Picture_PictureNote'])!= "---")  $desc .= "<br>Note: " . $r['dbo_Picture_PictureNote'];
                            if(trim(@$r['PictureType'])!= "---")              $desc .= "<br>Type: " . $r['PictureType'];
                            if(trim(@$r['Location'])!= "---")                 $desc .= "<br>Location: " . $r['Location'];
                            $desc = utf8_decode(trim($desc));                        
                            $agent = array();
                            $agent[] = array("role" => "photographer", "homepage" => "", "name" => @$r['DisplayName']);                                            
                            $dc_source = FWP_IMAGE_PAGE_URL . "&SId=" . $r['SId'] . "&PictureId=" . $r['PictureId'];
                            $arr_photos[$sciname][] = self::fill_data_object($desc, "", "", $agent, $rights_holder, $mediaURL, $mimeType, $dataType, $dc_source);
                        }
                    }
                }

                if(@$arr_texts[$sciname] || @$arr_photos[$sciname])
                {
                    $dc_source = FWP_SPECIES_PAGE_URL . $taxon['SId'];
                    $arr_scraped[]=array("identifier"   => "SId-" . $taxon['SId'],
                                         "kingdom"      => "",
                                         "phylum"       => "",
                                         "class"        => "",
                                         "order"        => $taxon['OrderName'],
                                         "family"       => $taxon['Family'],
                                         "sciname"      => $sciname,
                                         "dc_source"    => $dc_source,   
                                         "texts"        => @$arr_texts[$sciname],
                                         "photos"       => @$arr_photos[$sciname],
                                         "references"   => $reference,
                                         "comnames"     => $taxon_comnames,
                                         "synonyms"     => $taxon_syn
                                        );                
                }
            }
        return $arr_scraped;
    }

    function fill_data_object($desc, $subject, $title, $agent, $rights_holder, $mediaURL, $mimeType, $dataType, $dc_source)
    {
        $desc = str_ireplace('<a href="/species_images/','<a target="_blank" href="http://www.cmarz.org/species_images/', $desc);
        $desc = str_ireplace('<a href="../species_images/','<a target="_blank" href="http://www.cmarz.org/species_images/', $desc);
        return
            array(
            "identifier"    => "",
            "mediaURL"      => $mediaURL,
            "mimeType"      => $mimeType,
            "date_created"  => "",
            "rights"        => "",
            "rights_holder" => $rights_holder,
            "dataType"      => $dataType,
            "description"   => $desc,
            "title"         => $title,
            "location"      => "",
            "dc_source"     => $dc_source,
            "subject"       => $subject,
            "agent"         => $agent,
            "license"       => "http://creativecommons.org/licenses/by-nc-sa/3.0/"
            );
    }

    function prepare_table($arr,$entry,$index_key,
        $attr1,$attr2=NULL,$attr3=NULL,$attr4=NULL,$attr5=NULL,$attr6=NULL,$attr7=NULL,$attr8=NULL,$attr9=NULL,$attr10=NULL,
        $attr11=NULL,$attr12=NULL,$attr13=NULL,$attr14=NULL,$attr15=NULL,$attr16=NULL,$attr17=NULL,$attr18=NULL,$attr19=NULL,$attr20=NULL,
        $attr21=NULL,$attr22=NULL,$attr23=NULL,$attr24=NULL,$attr25=NULL,$attr26=NULL)
    {
        $arr_hash=array();
        $i=0;
        foreach($arr[$index_key] as $id)
        {
            $temp = array($attr1=>@$arr[$attr1][$i],
                                 $attr2=>@$arr[$attr2][$i],
                                 $attr3=>@$arr[$attr3][$i],
                                 $attr4=>@$arr[$attr4][$i],
                                 $attr5=>@$arr[$attr5][$i],
                                 $attr6=>@$arr[$attr6][$i],
                                 $attr7=>@$arr[$attr7][$i],
                                 $attr8=>@$arr[$attr8][$i],
                                 $attr9=>@$arr[$attr9][$i],
                                 $attr10=>@$arr[$attr10][$i],
                                 $attr11=>@$arr[$attr11][$i],
                                 $attr12=>@$arr[$attr12][$i],
                                 $attr13=>@$arr[$attr13][$i],
                                 $attr14=>@$arr[$attr14][$i],
                                 $attr15=>@$arr[$attr15][$i],
                                 $attr16=>@$arr[$attr16][$i],
                                 $attr17=>@$arr[$attr17][$i],
                                 $attr18=>@$arr[$attr18][$i],
                                 $attr19=>@$arr[$attr19][$i],
                                 $attr20=>@$arr[$attr20][$i],
                                 $attr21=>@$arr[$attr21][$i],
                                 $attr22=>@$arr[$attr22][$i],
                                 $attr23=>@$arr[$attr23][$i],
                                 $attr24=>@$arr[$attr24][$i],
                                 $attr25=>@$arr[$attr25][$i],
                                 $attr26=>@$arr[$attr26][$i]
                                 );

            if    ($entry == "single")    $arr_hash[$id]   = $temp;
            elseif($entry == "multiple")  $arr_hash[$id][] = $temp;
            $i++;
        }            
        return $arr_hash;
    }

    function fill_text_array($sourceURL, $subject, $desc, $title)
    {
        $agent[] = array("role" => "compiler", "homepage" => "http://www..org.uk/index.htm", "name" => "Malcolm Storey");            
        $rights_holder = "Malcolm Storey";
        return          array(
                        "identifier"    => $sourceURL,
                        "mediaURL"      => "",
                        "mimeType"      => "text/html",
                        "date_created"  => "",
                        "rights"        => "",
                        "rights_holder" => $rights_holder,
                        "dataType"      => "http://purl.org/dc/dcmitype/Text",
                        "description"   => $desc,
                        "title"         => $title,
                        "location"      => "",
                        "dc_source"     => $sourceURL,
                        "agent"         => $agent,
                        "subject"       => $subject
                        );
    }

    function get_taxa_for_photo($rec)
    {
        $taxon = array();
        $taxon["commonNames"] = array();
        $taxon["identifier"] = $rec["identifier"];
        $taxon["source"] = $rec["dc_source"];
        $taxon["scientificName"] = ucfirst(trim($rec["sciname"]));
        $taxon["kingdom"] = ucfirst(trim($rec["kingdom"]));
        $taxon["phylum"] = ucfirst(trim($rec["phylum"]));
        $taxon["class"] = ucfirst(trim($rec["class"]));
        $taxon["order"] = ucfirst(trim($rec["order"]));
        $taxon["family"] = ucfirst(trim($rec["family"]));
        //start common names
        if(@$rec["comnames"])
        {
            foreach($rec["comnames"] as $comname)
            {            
                if($comname)$taxon["commonNames"][] = new \SchemaCommonName(array("name" => $comname['CommonName'], "language" => $comname['Language']));
            }
        }
        //end common names
        //start synonyms
        $taxon["synonyms"] = array();
        if(@$rec["synonyms"])
        {
            foreach(@$rec["synonyms"] as $synonym)
            {
                //don't add problematic synonym records
                $arr = array("junior synonym ?", "Synonym ?", "Valid ?", "Suppres'd", "Action", "tentative synonym", "to be filled", "Preoccupied", "Spelling");
                if (in_array($synonym['SynStatus'], $arr)) continue;
                
                //don't add synonym if same as scientificname
                if(ucfirst(trim($rec["sciname"])) == trim($synonym['SynGenusSpecies'])) continue;

                $status = self::translate_synonym(trim($synonym['SynStatus']));
                $taxon["synonyms"][] = new \SchemaSynonym(array("synonym" => $synonym['SynGenusSpecies'], "relationship" => $status));
            }
        }
        //end synonyms
        if(@$rec["photos"]) $taxon["dataObjects"] = self::prepare_objects($rec["photos"],@$taxon["dataObjects"],array());
        if(@$rec["texts"])  $taxon["dataObjects"] = self::prepare_objects($rec["texts"],@$taxon["dataObjects"],$rec["references"]);
        $taxon_object = new \SchemaTaxon($taxon);
        return $taxon_object;
    }

    function translate_synonym($syn_status)
    {
        if    (in_array($syn_status, array("1�Homonym", "2�Homonym", "other", "Uncertain"))) return "ambiguous synonym";
        elseif(in_array($syn_status, array("Valid"))) return "valid name";
        elseif(in_array($syn_status, array("Synonym"))) return "synonym";        
        elseif(in_array($syn_status, array("junior synonym"))) return "junior synonym";
        elseif(in_array($syn_status, array("senior synonym"))) return "senior synonym";        
        elseif(in_array($syn_status, array("questionable"))) return "ambiguous synonym";
        elseif(in_array($syn_status, array("misidentification", "misspelling"))) return "misapplied name";
        else return "";                
    }

    function prepare_objects($arr, $taxon_dataObjects, $references)
    {
        $arr_SchemaDataObject = array();
        if($arr)
        {
            $arr_ref = array();
            $length = sizeof($arr);
            $i = 0;
            foreach($arr as $rec)
            {
                $i++;
                //if($length == $i)$arr_ref = $references;//to add the references to the last dataObject
                $arr_ref = $references;//to add the reference to all dataObject's

                $data_object = self::get_data_object($rec, $arr_ref);
                if(!$data_object) return false;
                $taxon_dataObjects[] = new \SchemaDataObject($data_object);
            }
        }
        return $taxon_dataObjects;
    }

    function get_data_object($rec, $references)
    {
        $data_object_parameters = array();
        $data_object_parameters["identifier"] = $rec["identifier"];
        $data_object_parameters["source"] = $rec["dc_source"];
        $data_object_parameters["dataType"] = $rec["dataType"];
        $data_object_parameters["mimeType"] = @$rec["mimeType"];
        $data_object_parameters["mediaURL"] = @$rec["mediaURL"];
        $data_object_parameters["rights"] = @$rec["rights"];
        $data_object_parameters["rightsHolder"] = @$rec["rights_holder"];
        $data_object_parameters["title"] = @$rec["title"];
        $data_object_parameters["description"] = utf8_encode($rec["description"]);
        $data_object_parameters["location"] = utf8_encode($rec["location"]);
        $data_object_parameters["license"] = @$rec["license"];

        //start reference
        $data_object_parameters["references"] = array();
        $ref = array();
        foreach($references as $r)
        {
            $referenceParameters = array();
            $referenceParameters["fullReference"] = trim($r["ref"]);
            $referenceParameters["referenceIdentifiers"][] = new \SchemaReferenceIdentifier(array("label" => "url", "value" => trim($r["url"])));
            $ref[] = new \SchemaReference($referenceParameters);
        }        
        $data_object_parameters["references"] = $ref;
        //end reference
        
        if(@$rec["subject"])
        {
            $data_object_parameters["subjects"] = array();
            $subjectParameters = array();
            $subjectParameters["label"] = @$rec["subject"];
            $data_object_parameters["subjects"][] = new \SchemaSubject($subjectParameters);
        }

        if(@$rec["agent"])
        {
            $agents = array();
            foreach($rec["agent"] as $a)
            {
                $agentParameters = array();
                $agentParameters["role"]     = $a["role"];
                $agentParameters["homepage"] = $a["homepage"];
                $agentParameters["logoURL"]  = "";        
                $agentParameters["fullName"] = $a["name"];
                $agents[] = new \SchemaAgent($agentParameters);
            }
            $data_object_parameters["agents"] = $agents;
        }
        return $data_object_parameters;
    }
}
?>