<?php
namespace php_active_record;

include_once(dirname(__FILE__) . "/../config/environment.php");

$cores_affected = array(
	'data_objects' => false,
	'site_search' => false,
	'hierarchy_entries' => false,
	'hierarchy_entry_relationship' => false,
	'collection_items' => false,
	'bhl' => false,
	'activity_logs' => false
);

$mysqli =& $GLOBALS['mysqli_connection'];

# I have join these tables. I need to check wether an old record has been delayed because of replication delay.
#  this may cause a performance peak, so we may need to reconsider this query.
$query ="select solr_logs.*, 
						solr_activity_logs.activity_log_unique_key,
						solr_activity_logs.activity_log_type,
						solr_activity_logs.activity_log_id,
						solr_activity_logs.action_keyword,
						solr_activity_logs.reply_to_id,
						solr_activity_logs.user_id,
						UNIX_TIMESTAMP(solr_activity_logs.date_created) as  date_created
					from solr_logs
					left outer join  solr_activity_logs on solr_activity_logs.solr_log_id=solr_logs.id
					where not exists 
						(select * from solr_logs_site_peers where solr_logs.id=solr_logs_site_peers.solr_log_id 
								and solr_logs_site_peers.peer_site_id=".$GLOBALS['PEER_SITE_ID'].")
						and solr_logs.peer_site_id<>".$GLOBALS['PEER_SITE_ID']." 
						order by solr_logs.created_at";

$result = $mysqli->query($query);
while($result && $row=$result->fetch_assoc())
{
    $action = $row['action'];
	switch ($action) {
		case 'delete_all':
			echo "delete all from #{$row['core']}";
			delete_all($row['core']);			
			break;
		case 'index_all':
			echo "index all for #{$row['core']}";
			rebuild_all($row['core'], $row['object_type']);
			break;
		case 'delete':
			echo "delete by id from #{$row['core']}";
			delete_by_id($row['core'], $row['object_id']);
			break;
		case 'update':
			echo "update from #{$row['core']}";
			update($row);
			break;
	}
	update_solr_log_site_peer($row['id']);
	$cores_affected[$row['core']] = true;	
}

commit_and_optimize_affected_cores($cores_affected);

function commit_and_optimize_affected_cores($cores_affected) {
	foreach($cores_affected as $core=>$is_updated){
		if ($is_updated) {
			if(!defined('SOLR_SERVER') || !SolrAPI::ping(SOLR_SERVER, $core)) return false;
    		$solr = new SolrAPI(SOLR_SERVER, $core);
			$solr->commit();
			$solr->optimize();
		}
	}
}

function update_solr_log_site_peer($solr_log_id) {
	$slsp = new SolrLogsSitePeer();
	$slsp->solr_log_id = $solr_log_id;
	$slsp->peer_site_id=$GLOBALS['PEER_SITE_ID'];
	$created_date = date('Y/m/d H:i:s');
	$slsp->created_at = $created_date;
	$slsp->updated_at = $created_date;		
	$slsp->save();
}

function delete_all($core) {
	if(!defined('SOLR_SERVER') || !SolrAPI::ping(SOLR_SERVER, $core)) return false;
    $solr = new SolrAPI(SOLR_SERVER, $core);
	$solr->delete_all_documents(false);
}

function delete_by_id($core, $id) {
	if(!defined('SOLR_SERVER') || !SolrAPI::ping(SOLR_SERVER, $core)) return false;
    $solr = new SolrAPI(SOLR_SERVER, $core);
	$solr->delete_by_ids($id);
}

function rebuild_all($core, $object_type) {
	switch ($core) {
		case 'data_objects':
			$do_ai = new DataObjectAncestriesIndexer();
			$do_ai->index(false);
			break;
		case 'bhl':
			$bhl_i = new BHLIndexer();
			$bhl_i->index(true, true);
			break;
		case 'site_search':
			$ssi = new DataObjectAncestriesIndexer();
			switch ($object_type) {
				case 'Collection':
					$ssi->index_type('Collection', 'collections', 'lookup_collections');
					break;
				case 'Community':
					$ssi->index_type('Community', 'communities', 'lookup_communities');
					break;
				case 'User':
					$ssi->index_type('User', 'users', 'lookup_users');
					break;
				case 'DataObject':
					$ssi->index_type('DataObject', 'data_objects', 'lookup_objects');
					break;
				case 'TaxonConcept':
					$ssi->index_type('TaxonConcept', 'taxon_concepts', 'index_taxa');
					break;
			}
			break;
	} 
}

function delete($core, $id, $object_type) {
	if(!defined('SOLR_SERVER') || !SolrAPI::ping(SOLR_SERVER, $core)) return false;
    $solr = new SolrAPI(SOLR_SERVER, $core);	
	switch ($core) {
		case 'collection_item':
			$queries[] = "collection_item_id:$id";
			$solr->delete_by_queries($queries, false);
			break;
		default: 
			$solr->delete_by_id($core, $id);
			break;
	}
}

function index_activity_log($row) {
	$attributes = array();
	$attributes['activity_log_unique_key'] = $row['activity_log_unique_key'];
	$attributes['activity_log_type'] = $row['activity_log_type'];
	$attributes['activity_log_id'] = $row['activity_log_id'];
	$attributes['action_keyword'] = $row['action_keyword'];
	$attributes['reply_to_id'] = $row['reply_to_id'];
	$attributes['user_id'] = $row['user_id'];
	$attributes['date_created'] = SolrAPI::mysql_date_to_solr_date($row['date_created']);
	$attributes['feed_type_affected'] = $row['object_type'];
	$attributes['feed_type_primary_key'] = explode(",", $row['object_id']);
	
	$activity_solr_log =  new SolrAPI(SOLR_SERVER, 'activity_logs');
	$activity_solr_log->send_attributes_for_activity_log($attributes);
}

function update($row) {
	switch ($row['core']) {
		case 'hierarchy_entries':
			$hei = new HierarchyEntryIndexer();
			$hei->index($row['object_id'], true);
			break;
		case 'data_objects':			
			$do[] = $row['object_id'];
			$doai = new DataObjectAncestriesIndexer();
			$doai->index_objects($do, false, false);
			break;
		case 'activity_logs':
			index_activity_log($row);		
			break;
		case'collection_items':
			 $collection_item_ids[]  = $row['object_id'];
			 $cii = new CollectionItemIndexer();
			 $cii->index_collection_items($collection_item_ids, false, false);
			 break;
		case 'site_search':
			$ids[] = $row['object_id'];
			$ssi = new SiteSearchIndexer();
			$ssi->index($ids, false, false);
			break;	
	}
} 
?>