<?php

class IUCNRedlistAPI
{
    // http://www.assembla.com/wiki/show/sis/Red_List_API
    const API_PREFIX = "http://api.iucnredlist.org/details/";    // http://api.iucnredlist.org/details/22823/0
    const SPECIES_LIST_API = "http://api.iucnredlist.org/index/all.json";
    
    public static function get_taxon_xml($resource_file = null)
    {
        $GLOBALS['language_to_iso_code'] = Functions::language_to_iso_code();
        $taxa = self::get_all_taxa($resource_file);
        return $taxa;
    }
    
    public static function get_all_taxa($resource_file = null)
    {
        $species_list_path = DOC_ROOT . "update_resources/connectors/files/iucn_species_list.json";
        
        shell_exec("rm -f $species_list_path");
        shell_exec("curl ". self::SPECIES_LIST_API ." -o $species_list_path");
        
        $used = array();
        $all_taxa = array();
        if(file_exists($species_list_path))
        {
            $species_list = json_decode(file_get_contents($species_list_path));
            $i = 0;
            // shuffle($species_list);
            foreach($species_list as $species_json)
            {
                if(isset($used[$species_json->species_id])) continue;
                $used[$species_json->species_id] = 1;
                
                // if($i < 6000) { $i++; continue; }
                // if($species_json->species_id != '22823') continue;  // polar bear
                // if($i >= 100) break;
                
                $i++;
                echo "$species_json->species_id ($i)\n";
                
                $taxon = self::get_taxa_for_species($species_json);
                $taxon_xml = $taxon->__toXML();
                $taxon_xml = preg_replace("/\xE3\xBC/", "ü", $taxon_xml);
                $taxon_xml = preg_replace("/\xE3\xA9/", "é", $taxon_xml);
                $taxon_xml = preg_replace("/\xE3\x80/", "À", $taxon_xml);
                if($resource_file) fwrite($resource_file, $taxon_xml);
                else $all_taxa[] = $taxon;
                
            }
        }
        return $all_taxa;
    }
    
    public static function get_taxa_for_species($species_json)
    {
        $species_id = $species_json->species_id;
        $details_html = Functions::get_remote_file(self::API_PREFIX.$species_id);
        
        // this is a hack to get the document to load as UTF8. Nothing else seemed to work when using DOMDocument
        // http://www.php.net/manual/en/domdocument.loadhtml.php#95251
        $details_html = str_replace("<!DOCTYPE html>", "<?xml encoding=\"UTF-8\">", $details_html);
        $details_html = str_replace("<meta charset=\"UTF-8\"/>", "<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\">", $details_html);
        
        $details_html = str_replace("Downloaded on <b>", "Downloaded on ", $details_html);
        $details_html = str_replace("& ", "&amp; ", $details_html);
        
        $dom_doc = new DOMDocument("1.0", "UTF-8");
        $dom_doc->loadHTML($details_html);
        $dom_doc->encoding = 'UTF-8';
        $dom_doc->preserveWhiteSpace = false;
        $xpath = new DOMXpath($dom_doc);
        
        $taxon_parameters = array();
        $taxon_parameters['identifier'] = $species_id;
        $taxon_parameters['source'] = "http://www.iucnredlist.org/apps/redlist/details/" . $species_id;
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='kingdom']");
        $taxon_parameters['kingdom'] = @ucfirst(strtolower(trim($element->item(0)->nodeValue)));
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='phylum']");
        $taxon_parameters['phylum'] = @ucfirst(strtolower(trim($element->item(0)->nodeValue)));
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='class']");
        $taxon_parameters['class'] = @ucfirst(strtolower(trim($element->item(0)->nodeValue)));
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='order']");
        $taxon_parameters['order'] = @ucfirst(strtolower(trim($element->item(0)->nodeValue)));
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='family']");
        $taxon_parameters['family'] = @ucfirst(strtolower(trim($element->item(0)->nodeValue)));
        
        $element = $xpath->query("//h1[@id='scientific_name']");
        $scientific_name = @$element->item(0)->nodeValue;
        
        $element = $xpath->query("//div[@id='x_taxonomy']//div[@id='species_authority']");
        $species_authority = @$element->item(0)->nodeValue;
        
        $taxon_parameters['scientificName'] = htmlspecialchars_decode(trim($scientific_name ." ". $species_authority));
        
        $taxon_parameters['commonNames'] = array();
        $common_name_languages = $xpath->query("//ul[@id='common_names']//div[@class='lang']");
        foreach($common_name_languages as $language_list)
        {
            $language = $language_list->nodeValue;
            $langauge_names = $xpath->query("//ul[@id='common_names']/li[@class='x_lang' and div = '$language']//li[@class='name']");
            
            if(isset($GLOBALS['language_to_iso_code'][$language])) $language = $GLOBALS['language_to_iso_code'][$language];
            foreach($langauge_names as $langauge_name)
            {
                $common_name = @ucfirst(strtolower(trim($langauge_name->nodeValue)));
                $taxon_parameters['commonNames'][] = new SchemaCommonName(array('name' => $common_name, 'language' => $language));
            }
        }
        
        $taxon_parameters['synonyms'] = array();
        $synonyms = $xpath->query("//ul[@id='synonyms']//li[@class='synonym']");
        foreach($synonyms as $synonym_node)
        {
            $synonym = trim($synonym_node->nodeValue);
            $taxon_parameters['synonyms'][] = new SchemaSynonym(array('synonym' => $synonym, 'relationship' => 'synonym'));
        }
        
        
        
        
        list($agents, $citation) = self::get_agents_and_citation($dom_doc, $xpath);
        
        $taxon_parameters['dataObjects'] = array();
        
        $section = self::get_redlist_status($dom_doc, $xpath, $species_id);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'x_assessment_information', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Conservation', $agents, $citation);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'range_description', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Distribution', $agents, $citation, 'Range Description');
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'x_population', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Trends', $agents, $citation);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'x_habitat', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Habitat', $agents, $citation);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'x_threats', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Threats', $agents, $citation);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $section = self::get_text_section($dom_doc, $xpath, $species_id, 'x_conservation_actions', 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Management', $agents, $citation);
        if($section) $taxon_parameters['dataObjects'][] = $section;
        
        $taxon = new SchemaTaxon($taxon_parameters);
        // echo $taxon->__toXML();
        return $taxon;
    }
    
    public static function get_text_section($dom_doc, $xpath, $species_id, $div_id, $subject, $agents, $citation, $title = null)
    {
        $element = $xpath->query("//div[@id='$div_id']");
        // the element doesn't exist. The next line was returning the entire document for some reason even if we found no match
        if(!$element->item(0)) return null;
        $section_html = $dom_doc->saveXML($element->item(0));
        $section_title = $div_id;
        
        if(preg_match("/^<div.*?>(.*)<\/div>$/ims", $section_html, $arr)) $section_html = trim($arr[1]);
        if(preg_match("/^<h2>(.*?)<\/h2>(.*)$/ims", $section_html, $arr))
        {
            $section_title = trim($arr[1]);
            $section_html = trim($arr[2]);
        }
        
        if($title) $section_title = $title;
        $section_html = preg_replace("/<div class=\"x_label\">(.*?)<\/div>/", "<br/><b>\\1</b><br/>", $section_html);
        $section_html = str_replace("\n", " ", $section_html);
        $section_html = str_replace("\t", " ", $section_html);
        while(preg_match("/  /", $section_html)) $section_html = str_replace("  ", " ", $section_html);
        $section_html = preg_replace("/^<br\/>/", "", $section_html);
        
        if($section_html)
        {
            $identifier = $species_id ."/". $div_id;
            $object_parameters = array();
            $object_parameters['identifier'] = $identifier;
            $object_parameters['title'] = $section_title;
            $object_parameters['description'] = htmlspecialchars_decode($section_html);
            $object_parameters['dataType'] = "http://purl.org/dc/dcmitype/Text";
            $object_parameters['mimeType'] = "text/html";
            $object_parameters['language'] = "en";
            $object_parameters['license'] = "http://creativecommons.org/licenses/by-nc-sa/3.0/";
            $object_parameters['rights'] = "© International Union for Conservation of Nature and Natural Resources";
            $object_parameters['source'] = "http://www.iucnredlist.org/apps/redlist/details/" . $species_id;
            $object_parameters['subjects'] = array(new SchemaSubject(array('label' => $subject)));
            $object_parameters['agents'] = $agents;
            $object_parameters['bibliographicCitation'] = $citation;
            
            return new SchemaDataObject($object_parameters);
        }
        return null;
    }
    
    public static function get_redlist_status($dom_doc, $xpath, $species_id)
    {
        $element = $xpath->query("//div[@id='x_category_and_criteria']//div[@id='red_list_category_title']");
        $redlist_category = trim($element->item(0)->nodeValue);
        
        $element = $xpath->query("//div[@id='x_category_and_criteria']//div[@id='red_list_category_code']");
        $redlist_category_code = trim($element->item(0)->nodeValue);
        
        $section_text = $redlist_category;
        if($redlist_category_code) $section_text .= " ($redlist_category_code)";
        
        $identifier = $species_id ."/red_list_category";
        $object_parameters = array();
        $object_parameters['identifier'] = $identifier;
        $object_parameters['description'] = $section_text;
        $object_parameters['dataType'] = "http://purl.org/dc/dcmitype/Text";
        $object_parameters['mimeType'] = "text/html";
        $object_parameters['language'] = "en";
        $object_parameters['title'] = "IUCNConservationStatus";
        $object_parameters['license'] = "http://creativecommons.org/licenses/by-nc-sa/3.0/";
        $object_parameters['rights'] = "© International Union for Conservation of Nature and Natural Resources";
        $object_parameters['rightsHolder'] = "International Union for Conservation of Nature and Natural Resources";
        $object_parameters['source'] = "http://www.iucnredlist.org/apps/redlist/details/" . $species_id;
        $object_parameters['subjects'] = array(new SchemaSubject(array('label' => 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#ConservationStatus')));
        
        return new SchemaDataObject($object_parameters);
    }
    
    public static function get_agents_and_citation($dom_doc, $xpath)
    {
        $element = $xpath->query("//div[@id='x_citation']//div[@id='citation']");
        $citation = $dom_doc->saveXML($element->item(0));
        if(preg_match("/^<div id=\"citation\">(.*?)<\/div>$/", $citation, $arr)) $citation = htmlspecialchars_decode(trim($arr[1]));
        
        $agents = array();
        if(preg_match("/^(.*?) [0-9]{4}\. +<i>/", $citation, $arr))
        {
            $all_authors = $arr[1];
            $agents[] = new SchemaAgent(array('fullName' => $all_authors, 'role' => 'author'));
        }
        
        $element = $xpath->query("//div[@id='assessors']");
        $assessors = trim($element->item(0)->nodeValue);
        $agents[] = new SchemaAgent(array('fullName' => $assessors, 'role' => 'compiler'));
        
        return array($agents, $citation);
    }
}

?>