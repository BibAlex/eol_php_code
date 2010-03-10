<?php

include_once(dirname(__FILE__) . "/../config/environment.php");

Functions::log("Starting denormalizing");

shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/hierarchies_content.php");
shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/top_images.php");
shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/random_taxa.php");
shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/random_hierarchy_images.php");
shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/table_of_contents.php");
//shell_exec(PHP_BIN_PATH . dirname(__FILE__)."/clear_eol_cache.php");

Functions::log("Ended denormalizing");


?>