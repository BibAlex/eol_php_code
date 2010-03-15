<?php

include_once(dirname(__FILE__) . "/../config/environment.php");
require_library('TopImages_new');
require_library('DenormalizeTables');

Functions::log("Starting top_images");

DenormalizeTables::data_objects_taxon_concepts();

$top_images = new TopImages();
$top_images->begin_process();
$top_images->top_concept_images(true);
$top_images->top_concept_images(false);

Functions::log("Ended top_images");

?>