<?php
exec("curl http://localhost:8983/solr/activity_logs/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/activity_logs/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/taxon_concepts/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/taxon_concepts/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/data_objects/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/data_objects/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/site_search/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/site_search/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/hierarchy_entries/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/hierarchy_entries/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/hierarchy_entry_relationship/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/hierarchy_entry_relationship/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/collection_items/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/collection_items/update?stream.body=%3Ccommit/%3E");
exec("curl http://localhost:8983/solr/bhl/update?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E");
exec("curl http://localhost:8983/solr/bhl/update?stream.body=%3Ccommit/%3E");
?>