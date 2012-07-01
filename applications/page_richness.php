<?php
namespace php_active_record;
  
  include_once(dirname(__FILE__) . "/../config/environment.php");
  require_library("PageRichnessCalculator");
  
  //print_pre($_REQUEST);
  $taxon_concept_id = @$_REQUEST['taxon_concept_id'] ?: '';
  $taxon_concept_id2 = @$_REQUEST['taxon_concept_id2'] ?: '';
  
  $variable_names = array( 'VETTED_FACTOR', 'IMAGE_BREADTH_MAX', 'INFO_ITEM_BREADTH_MAX', 'MAP_BREADTH_MAX', 'VIDEO_BREADTH_MAX',
      'SOUND_BREADTH_MAX', 'IUCN_BREADTH_MAX', 'REFERENCE_BREADTH_MAX', 'IMAGE_BREADTH_WEIGHT',
      'INFO_ITEM_BREADTH_WEIGHT', 'MAP_BREADTH_WEIGHT', 'VIDEO_BREADTH_WEIGHT', 'SOUND_BREADTH_WEIGHT', 'IUCN_BREADTH_WEIGHT',
      'REFERENCE_BREADTH_WEIGHT', 'TEXT_TOTAL_MAX', 'TEXT_AVERAGE_MAX', 'TEXT_TOTAL_WEIGHT', 'TEXT_AVERAGE_WEIGHT',
      'PARTNERS_DIVERSITY_MAX', 'PARTNERS_DIVERSITY_WEIGHT', 'BREADTH_WEIGHT', 'DEPTH_WEIGHT', 'DIVERSITY_WEIGHT');
  
  foreach($variable_names as $variable_name)
  {
      $$variable_name = isset($_REQUEST[$variable_name]) ? $_REQUEST[$variable_name] : TaxonConceptMetric::$$variable_name;
  }
  
?>
<html>
<head>
  <style type="text/css">
    table.main_table { border: 1px solid black; width: 750px; }
    table.sub_table { border: 1px solid black; }
    table.results { cellspacing:0; cellspacing:0; }
    table.results td { width: 120px; text-align: right; }
    table.results td.max_score { width: 40px; text-align: left; padding-left: 30px; }
    table.dual_input td { vertical-align: top; }
    table.dual_input td.second { padding-left: 10px; border-left: 2px black solid; }
    td.category_weight { height: 30px; font-weight: bold; text-align:center; }
  </style>
</head>
<body>
  <form action='page_richness.php' method='post'>
  <table class='main_table'>
    <tr valign='top'>
      <td><h3 align='center'>Breadth</h2>
        <table class='sub_table'>
          <tr>
            <th>Category</th>
            <th>Max Count</th>
            <th>Weight</th>
          </tr>
          <tr>
            <td>Images:</td>
            <td><input type='text' size='5' name='IMAGE_BREADTH_MAX' value='<?= $IMAGE_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='IMAGE_BREADTH_WEIGHT' value='<?= $IMAGE_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>InfoItems:</td>
            <td><input type='text' size='5' name='INFO_ITEM_BREADTH_MAX' value='<?= $INFO_ITEM_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='INFO_ITEM_BREADTH_WEIGHT' value='<?= $INFO_ITEM_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>References:</td>
            <td><input type='text' size='5' name='REFERENCE_BREADTH_MAX' value='<?= $REFERENCE_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='REFERENCE_BREADTH_WEIGHT' value='<?= $REFERENCE_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>Maps:</td>
            <td><input type='text' size='5' name='MAP_BREADTH_MAX' value='<?= $MAP_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='MAP_BREADTH_WEIGHT' value='<?= $MAP_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>Videos:</td>
            <td><input type='text' size='5' name='VIDEO_BREADTH_MAX' value='<?= $VIDEO_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='VIDEO_BREADTH_WEIGHT' value='<?= $VIDEO_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>Sounds:</td>
            <td><input type='text' size='5' name='SOUND_BREADTH_MAX' value='<?= $SOUND_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='SOUND_BREADTH_WEIGHT' value='<?= $SOUND_BREADTH_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>IUCN:</td>
            <td><input type='text' size='5' name='IUCN_BREADTH_MAX' value='<?= $IUCN_BREADTH_MAX; ?>'/></td>
            <td><input type='text' size='5' name='IUCN_BREADTH_WEIGHT' value='<?= $IUCN_BREADTH_WEIGHT; ?>'/></td>
          </tr>
        </table>
      </td>
      
      
      <td><h3 align='center'>Depth</h3>
        <table class='sub_table'>
          <tr>
            <th>Category</th>
            <th>Max Count</th>
            <th>Weight</th>
          </tr>
          <tr>
            <td>#Words per text:</td>
            <td><input type='text' size='5' name='TEXT_AVERAGE_MAX' value='<?= $TEXT_AVERAGE_MAX; ?>'/></td>
            <td><input type='text' size='5' name='TEXT_AVERAGE_WEIGHT' value='<?= $TEXT_AVERAGE_WEIGHT; ?>'/></td>
          </tr>
          <tr>
            <td>#Words total:</td>
            <td><input type='text' size='5' name='TEXT_TOTAL_MAX' value='<?= $TEXT_TOTAL_MAX; ?>'/></td>
            <td><input type='text' size='5' name='TEXT_TOTAL_WEIGHT' value='<?= $TEXT_TOTAL_WEIGHT; ?>'/></td>
          </tr>
        </table>
      </td>
      
      <td><h3 align='center'>Diversity</h3>
        <table class='sub_table'>
          <tr>
            <th>Category</th>
            <th>Max Count</th>
            <th>Weight</th>
          </tr>
          <tr>
            <td>Partners:</td>
            <td><input type='text' size='5' name='PARTNERS_DIVERSITY_MAX' value='<?= $PARTNERS_DIVERSITY_MAX; ?>'/></td>
            <td><input type='text' size='5' name='PARTNERS_DIVERSITY_WEIGHT' value='<?= $PARTNERS_DIVERSITY_WEIGHT; ?>'/></td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td class='category_weight'>Category Weight: <input type='text' size='5' name='BREADTH_WEIGHT' value='<?= $BREADTH_WEIGHT; ?>'/></td>
      <td class='category_weight'>Category Weight: <input type='text' size='5' name='DEPTH_WEIGHT' value='<?= $DEPTH_WEIGHT; ?>'/></td>
      <td class='category_weight'>Category Weight: <input type='text' size='5' name='DIVERSITY_WEIGHT' value='<?= $DIVERSITY_WEIGHT; ?>'/></td>
    </tr>
    <tr>
      <td colspan='3' class='category_weight'>Vetted Factor: <input type='text' size='5' name='VETTED_FACTOR' value='<?= $VETTED_FACTOR; ?>'/></td>
    </tr>
  </table>
  <br/><br/>
  <table class='dual_input'><tr><td>
      PageID to evaluate: <input type='text' size='20' name='taxon_concept_id' value='<?= $taxon_concept_id ?>'/>
      <input type='submit' value='Calculate'/>
      <input type='hidden' name='ENV_NAME' value='<?= $GLOBALS['ENV_NAME']; ?>'/>
      <hr/>
      <? show_results_for($taxon_concept_id); ?>
  </td><td class='second'>
      PageID to evaluate: <input type='text' size='20' name='taxon_concept_id2' value='<?= $taxon_concept_id2 ?>'/>
      <input type='submit' value='Calculate'/>
      <input type='hidden' name='ENV_NAME' value='<?= $GLOBALS['ENV_NAME']; ?>'/>
      </form>
      <hr/>
      <? show_results_for($taxon_concept_id2); ?>
  </td></tr></table>
  </form>
</body>
</html>
<?php




function show_results_for($taxon_concept_id)
{
    if($taxon_concept_id)
    {
        $name = TaxonConcept::get_name($taxon_concept_id);
        $metric = new TaxonConceptMetric($taxon_concept_id);
        $metric->set_weights($_REQUEST);
        if(!isset($metric->image_total)) return;
        $scores = $metric->scores();
        
        $statistics = new TextStatistics();
        $grades = array();
        $result = $GLOBALS['db_connection']->query("SELECT do.description FROM data_objects_taxon_concepts dotc JOIN data_objects do ON (dotc.data_object_id=do.id) WHERE dotc.taxon_concept_id=$taxon_concept_id AND do.published=1 AND do.visibility_id=".Visibility::visible()->id." AND do.data_type_id=".DataType::text()->id);
        while($result && $row=$result->fetch_assoc())
        {
            $grades[] = $statistics->flesch_kincaid_grade_level($row['description']);
        }
        $average_grade = 0;
        if($grades) $average_grade = round(array_sum($grades) / count($grades), 4);
        
        ?>
        <h3><a href='http://www.eol.org/pages/<?= $taxon_concept_id; ?>' target='_blank'><?= $name; ?></a></h3>
        <table class='results'>
          <tr><td>Breadth:</td><td><?= round($scores['breadth'] / $metric->BREADTH_WEIGHT, 4); ?></td></tr>
          <tr><td>Depth:</td><td><?= round($scores['depth'] / $metric->DEPTH_WEIGHT, 4); ?></td></tr>
          <tr><td>Diversity:</td><td><?= round($scores['diversity'] / $metric->DIVERSITY_WEIGHT, 4); ?></td></tr>
          <tr><td>Total:</td><td><?= round($scores['total'], 4); ?></td></tr>
        </table>
        <hr/>
        <table class='results'>
          <tr><th>Stat</th><th>Value</th><th>Max</th><th>Impact on Score</th><th>Max</th></tr>
          <tr><td>Images:</td><td><?= $metric->weighted_images(); ?></td>
            <td class='max_score'><?= $metric->IMAGE_BREADTH_MAX; ?></td>
            <td><?= $metric->image_score(); ?></td>
            <td class='max_score'>/<?= $metric->IMAGE_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>InfoItems:</td><td><?= $metric->info_items; ?></td>
            <td class='max_score'><?= $metric->INFO_ITEM_BREADTH_MAX; ?></td>
            <td><?= $metric->info_items_score(); ?></td>
            <td class='max_score'>/<?= $metric->INFO_ITEM_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>References:</td><td><?= $metric->references(); ?></td>
            <td class='max_score'><?= $metric->REFERENCE_BREADTH_MAX; ?></td>
            <td><?= $metric->references_score(); ?></td>
            <td class='max_score'>/<?= $metric->REFERENCE_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>Maps:</td><td><?= $metric->has_GBIF_map; ?></td>
            <td class='max_score'><?= $metric->MAP_BREADTH_MAX; ?></td>
            <td><?= $metric->maps_score(); ?></td>
            <td class='max_score'>/<?= $metric->MAP_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>Videos:</td><td><?= $metric->weighted_videos(); ?></td>
            <td class='max_score'><?= $metric->VIDEO_BREADTH_MAX; ?></td>
            <td><?= $metric->videos_score(); ?></td>
            <td class='max_score'>/<?= $metric->VIDEO_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>Sounds:</td><td><?= $metric->weighted_sounds(); ?></td>
            <td class='max_score'><?= $metric->SOUND_BREADTH_MAX; ?></td>
            <td><?= $metric->sounds_score(); ?></td>
            <td class='max_score'>/<?= $metric->SOUND_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>IUCN:</td><td><?= $metric->iucn_total; ?></td>
            <td class='max_score'><?= $metric->IUCN_BREADTH_MAX; ?></td>
            <td><?= $metric->iucn_score(); ?></td>
            <td class='max_score'>/<?= $metric->IUCN_BREADTH_WEIGHT * $metric->BREADTH_WEIGHT; ?></td></tr>
          
          <tr><td>Average #Words:</td><td><?= round($metric->average_words_weighted()); ?></td>
            <td class='max_score'><?= $metric->TEXT_AVERAGE_MAX; ?></td>
            <td><?= $metric->average_words_score(); ?></td>
            <td class='max_score'>/<?= $metric->TEXT_AVERAGE_WEIGHT * $metric->DEPTH_WEIGHT; ?></td></tr>
          
          <tr><td>Total #Words:</td><td><?= round($metric->weighted_text_words()); ?></td>
            <td class='max_score'><?= $metric->TEXT_TOTAL_MAX; ?></td>
            <td><?= $metric->total_words_score(); ?></td>
            <td class='max_score'>/<?= $metric->TEXT_TOTAL_WEIGHT * $metric->DEPTH_WEIGHT; ?></td></tr>
          
          <tr><td>Content Partners:</td><td><?= $metric->content_partners(); ?></td>
            <td class='max_score'><?= $metric->PARTNERS_DIVERSITY_MAX; ?></td>
            <td><?= $metric->content_partners_score(); ?></td>
            <td class='max_score'>/<?= $metric->PARTNERS_DIVERSITY_WEIGHT * $metric->DIVERSITY_WEIGHT; ?></td></tr>
            
          <tr><td>Reading Level:</td><td><?= $average_grade; ?></td>
            <td class='max_score'></td>
            <td></td>
            <td class='max_score'></td></tr>
        </table>
        <?
    }
}



