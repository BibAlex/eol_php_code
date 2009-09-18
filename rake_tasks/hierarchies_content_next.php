<?php

//define('DEBUG', true);
//define('MYSQL_DEBUG', true);
//define('DEBUG_TO_FILE', true);
//define("MYSQL_DEBUG", true);
//define('ENVIRONMENT', 'integration');
include_once(dirname(__FILE__) . "/../config/start.php");

$mysqli =& $GLOBALS['mysqli_connection'];




// Running this query first in case there is a problem and we need to quit
echo "starting query\n";
$query = "SELECT tc.id, do.data_type_id, do.visibility_id, do.published FROM taxon_concepts tc STRAIGHT_JOIN hierarchy_entries he ON (tc.id=he.taxon_concept_id) STRAIGHT_JOIN taxa t ON (he.id=t.hierarchy_entry_id) STRAIGHT_JOIN data_objects_taxa dot ON (t.id=dot.taxon_id) STRAIGHT_JOIN data_objects do ON (dot.data_object_id=do.id) WHERE (tc.supercedure_id IS NULL OR tc.supercedure_id=0)";
$result = $mysqli->query($query);
echo "ended query\n";
if(@!$result || @!$result->num_rows) exit;




$visible_id = Visibility::find("visible");

$taxon_concept_content = array();
$i = 0;
while($result && $row=$result->fetch_assoc())
{
    if(($i%20000)==0) echo "$i ".Functions::time_elapsed()."\n";
    $i++;
    
    $id = $row["id"];
    $data_type_id = $row["data_type_id"];
    $visibility_id = $row["visibility_id"];
    $published = $row["published"];
    
    $data_type = new DataType($data_type_id);
    $type_label = strtolower($data_type->label);
    
    if($type_label == "text" ||  $type_label == "flash" || $type_label == "youtube" || $type_label == "image")
    {
        $attribute = $type_label;
        
        if($visibility_id != $visible_id || !$published)
        {
            if($type_label == "text" || $type_label == "image") $attribute .= "_unpublished";
            else continue;
        }
        
        $taxon_concept_content[$id][$attribute] = 1;
    }
}






$hc_data = fopen(LOCAL_ROOT . "temp/hc.sql", "w+");
$tcc_data = fopen(LOCAL_ROOT . "temp/tcc.sql", "w+");

$i = 0;
$used_tc_id = array();
$query = "SELECT tc.id tc_id, he.id he_id FROM taxon_concepts tc STRAIGHT_JOIN hierarchy_entries he ON (tc.id=he.taxon_concept_id) WHERE (tc.supercedure_id IS NULL OR tc.supercedure_id=0)";
$result = $mysqli->query($query);
while($result && $row=$result->fetch_assoc())
{
    if(($i%20000)==0) echo "$i ".Functions::time_elapsed()."\n";
    $i++;
    
    $tc_id = $row["tc_id"];
    $he_id = $row["he_id"];
    
    if(@!$taxon_concept_content[$tc_id]) continue;
    
    $attributes = array(
        "text"                      => 0,
        "text_unpublished"          => 0,
        "image"                     => 0,
        "image_unpublished"         => 0,
        "child_image"               => 0,
        "child_image_unpublished"   => 0,
        "flash"                     => 0,
        "youtube"                   => 0,
        "map"                       => 0,
        "content_level"             => 1,
        "image_object_id"           => 0
    );
    if(@$taxon_concept_content[$tc_id])
    {
        foreach($taxon_concept_content[$tc_id] as $attr => $val) $attributes[$attr] = $val;
    }
    
    
    if(@!$used_tc_id[$tc_id])
    {
        fwrite($tcc_data, "$tc_id\t". implode("\t", $attributes) ."\n");
        $used_tc_id[$tc_id] = 1;
    }
    
    fwrite($hc_data, "$he_id\t". implode("\t", $attributes) ."\n");
}

fclose($hc_data);
fclose($tcc_data);


// exit if there is no new data
if(!filesize(LOCAL_ROOT ."temp/hc.sql") || !filesize(LOCAL_ROOT ."temp/tcc.sql")) exit;



$mysqli->begin_transaction();

echo "Deleting old data\n";
echo "1 of 2\n";
$mysqli->delete("DELETE FROM hierarchies_content");
echo "2 of 2\n";
$mysqli->delete("DELETE FROM taxon_concept_content");


echo "inserting new data\n";
echo "1 of 2\n";
$mysqli->load_data_infile(LOCAL_ROOT ."temp/hc.sql", "hierarchies_content");
echo "1 of 2\n";
$mysqli->load_data_infile(LOCAL_ROOT ."temp/tcc.sql", "taxon_concept_content");


echo "deleting files\n";
// shell_exec("rm ". LOCAL_ROOT ."temp/hc.sql");
// shell_exec("rm ". LOCAL_ROOT ."temp/tcc.sql");


echo "inserting empty rows\n";
echo "1 of 2\n";
$mysqli->query("INSERT IGNORE INTO hierarchies_content SELECT id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0 FROM hierarchy_entries he LEFT JOIN hierarchies_content hc ON (he.id=hc.hierarchy_entry_id) WHERE hc.hierarchy_entry_id IS NULL");
echo "2 of 2\n";
$mysqli->query("INSERT IGNORE INTO taxon_concept_content SELECT id, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0 FROM taxon_concepts tc LEFT JOIN taxon_concept_content tcc ON (tc.id=tcc.taxon_concept_id) WHERE tcc.taxon_concept_id IS NULL");



Functions::debug("gbif_maps\n\n");

$mysqli->update("UPDATE hierarchies_content SET map=0");
$mysqli->commit();

$mysqli_maps = load_mysql_environment("maps");
if($mysqli_maps)
{
    echo "starting gbif maps\n";
    $result = $mysqli_maps->query("SELECT DISTINCT taxon_id FROM tile_0_taxon");
    $ids = array();
    while($result && $row=$result->fetch_assoc())
    {
        $ids[] = $row["taxon_id"];
    
        if(count($ids) >= 50000)
        {
            echo "ADDING\n";
            $query = "update hierarchy_entries he join hierarchies_content hc on (he.id=hc.hierarchy_entry_id) set hc.map=1 where he.hierarchy_id=129 and he.identifier IN ('". implode("','", $ids) ."')";
            //echo $query."\n";
            $mysqli->update($query);
            $mysqli->commit();
        
            $ids = array();
            //exit;
        }
    
    }

    if($ids)
    {
        echo "ADDING\n";
        $query = "update hierarchy_entries he join hierarchies_content hc on (he.id=hc.hierarchy_entry_id) set hc.map=1 where he.hierarchy_id=129 and he.identifier IN (". implode(",", $ids) .")";
        //echo $query."\n";
        $mysqli->update($query);
    
        $ids = array();
    }
}else
{
    echo "skipping gbif maps\n";
}


$mysqli->end_transaction();


?>