<?php
define("GOOGLE_DATA_PATH", DOC_ROOT . "applications/google_stats/data/");
define("USE_SQL_LOAD_INFILE", false);
define("PAGE_METRICS_TEXT_PATH", DOC_ROOT . "applications/taxon_page_metrics/text_files/");

class MonthlyGoogleAnalytics
{
    public function __construct()
    {
        $this->mysqli =& $GLOBALS['mysqli_connection']; 
        $this->mysqli_local = $this->mysqli;
    }

    function process_parameters()
    {
        global $argv;
        $month = ""; 
        if(isset($_REQUEST['month'])) $month = $_REQUEST['month'];
        $year = ""; 
        if(isset($_REQUEST['year'])) $year = $_REQUEST['year'];

        if($month == "")
        {
            if(isset($argv[1])) $month = $argv[1];
            if(isset($argv[2])) $year = $argv[2];
            if($month != "" AND $year != "") print "Processing, please wait... \n\n";
        }
        if($month == "" && $year == "")
        {
            $month = date('n') - 1;
            $year = date('Y');
            if($month == 0)
            {
                $month = 12;
                $year = $year - 1;
            }
        }
        else
        {
            if($year < 2008 || $year > date('Y') || $month < 1 || $month > 12)
            {
                print "\n Invalid parameters! \n e.g. for July 2009 enter: \n\t php generate_monthly_stats.php 7 2009 \n\n";
                exit();
            }
        }
        print "month: $month \n year: $year \n\n";

        $month = GetNumMonthAsString($month, $year);
        $arr = array();
        $arr[] = $month;
        $arr[] = $year;
        return $arr;
    }

    function save_to_txt2($arr, $filename, $year_month, $field_separator, $file_extension)
    {
        $str = "";
        for ($i = 0; $i < count($arr); $i++)
        {
            $field = $arr[$i];
            $str .= $field . $field_separator;
        }
        //to remove last char - for field separator
        $str = substr($str, 0, strlen($str) - 1);
        $str .= "\n";  
        $filename = GOOGLE_DATA_PATH . $year_month . "/" . "$filename" . "." . $file_extension;
        if($fp = fopen($filename, "a"))
        {
            fwrite($fp,$str);
            fclose($fp);
        }
        return "";
    }
    
    function get_monthly_summaries_per_partner($agent_id, $year, $month, $count_of_taxa_pages, $count_of_taxa_pages_viewed)
    {
        //start get count_of_taxa_pages viewed during the month, etc.
        $query = "SELECT
        SUM(gaps.page_views)                AS page_views,
        SUM(gaps.unique_page_views)         AS unique_page_views,
        SUM(time_to_sec(gaps.time_on_page)) AS time_on_page
        FROM google_analytics_partner_taxa gapt
        JOIN google_analytics_page_stats gaps ON gapt.taxon_concept_id = gaps.taxon_concept_id AND gapt.`year` = gaps.`year` AND gapt.`month` = gaps.`month`
        WHERE gapt.agent_id = $agent_id AND gapt.`year` = $year AND gapt.`month` = $month ";
        $result = $this->mysqli_local->query($query);
        $row = $result->fetch_row();
        $page_views         = $row[0];
        $unique_page_views  = $row[1];
        $time_on_page       = $row[2];
        $fields = array();
        $fields[] = $year;
        $fields[] = $month;
        $fields[] = $agent_id;
        $fields[] = intval($count_of_taxa_pages);
        $fields[] = intval($count_of_taxa_pages_viewed);
        $fields[] = intval($unique_page_views);
        $fields[] = intval($page_views);
        $fields[] = floatval($time_on_page);   //this has to be floatval()
        return $fields;
    }
    
    function get_count_of_taxa_pages_per_partner($agent_id, $year, $month)
    {
        $BHL_id = Agent::find("Biodiversity Heritage Library");
        $COL_id = Agent::find("Catalogue of Life");
        
        $totals = array();
        if($agent_id == 38205) //BHL agent_id 38205
        {
            //start reading text file
            print "\n Start reading text file [taxon_concept_with_bhl_links] \n";
            $filename = PAGE_METRICS_TEXT_PATH . "taxon_concept_with_bhl_links.txt";
            $FILE = fopen($filename, "r");
            $arr_tmp = array();
            while(!feof($FILE))
            {
                if($line = fgets($FILE))
                {
                    $line = trim($line); 
                    $fields = explode("\t", $line);
                    $tc_id = trim($fields[0]);
                    $arr_tmp[$tc_id] = '';
                }
            }
            $totals[] = sizeof(array_keys($arr_tmp));
        }
        elseif($agent_id == 11)//Catalogue of Life agent_id 11
        {
            $query = "SELECT COUNT(he.taxon_concept_id) count FROM hierarchy_entries he WHERE he.hierarchy_id = " . Hierarchy::default_id();
            $result = $this->mysqli->query($query);
            $row = $result->fetch_row();
            $totals[] = $row[0]; //count of taxa pages
        }
        else //rest of the partners
        {
            $query = "SELECT MAX(he.id) latest_harvest_event_id FROM harvest_events he JOIN agents_resources ar ON ar.resource_id = he.resource_id WHERE ar.agent_id = $agent_id AND he.published_at IS NOT NULL ";
            $result = $this->mysqli->query($query);
            $row = $result->fetch_row();
            $latest_harvest_event_id = $row[0];

            $query = "SELECT tc.id tc_id FROM agents_resources er JOIN harvest_events hev ON er.resource_id = hev.resource_id JOIN harvest_events_hierarchy_entries hehe ON hev.id = hehe.harvest_event_id JOIN hierarchy_entries he ON hehe.hierarchy_entry_id = he.id JOIN taxon_concepts tc ON he.taxon_concept_id = tc.id WHERE er.agent_id = $agent_id AND tc.published=1 AND tc.supercedure_id=0 AND hev.id = $latest_harvest_event_id ";            
            $result = $this->mysqli->query($query);
            $arr_tmp = array();
            while($result && $row = $result->fetch_assoc())
            {
                $tc_id = $row['tc_id'];
                $arr_tmp[$tc_id] = '';
            }
            $totals[] = sizeof(array_keys($arr_tmp)); //count of taxa pages
        }    
        
        $query = "SELECT COUNT(gapt.taxon_concept_id) FROM google_analytics_partner_taxa gapt WHERE gapt.agent_id = $agent_id AND gapt.`year` = $year AND gapt.`month` = $month";
        $result = $this->mysqli->query($query);
        $row = $result->fetch_row();
        $totals[] = $row[0]; //count of taxa pages viewed during the month
        return $totals;
    }
    
    function get_sql_for_partners_with_published_data()
    {
        //this query now only gets partners with a published data on the time the report was run.
        $query = "SELECT DISTINCT a.id FROM agents a JOIN agents_resources ar ON a.id = ar.agent_id JOIN harvest_events he ON ar.resource_id = he.resource_id WHERE he.published_at IS NOT NULL AND a.id NOT in(11, 38205)";
        $query .= " ORDER BY a.full_name ";
        return $query;
    }
    
    function save_agent_monthly_summary($year_month)
    {
        $year = intval(substr($year_month, 0, 4));
        $month = intval(substr($year_month, 5, 2));
        //=================================================================
        $query = self::get_sql_for_partners_with_published_data();
        $result = $this->mysqli->query($query);
        
        //initialize txt file
        $filename = GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_summaries.txt"; 
        $fp = fopen($filename, "w");
        fclose($fp);
        
        print "\n start agent stat summaries...\n";
        $num_rows = $result->num_rows; 
        $i = 0;
        while($result && $row = $result->fetch_assoc())
        {
            $time_start = microtime(1);
            $i++;
            
            print "agent id = $row[id] $i of $num_rows ";
            $totals = self::get_count_of_taxa_pages_per_partner($row["id"], $year, $month);
            $count_of_taxa_pages        = $totals[0];
            $count_of_taxa_pages_viewed = $totals[1];

            $report  = self::get_monthly_summaries_per_partner($row["id"], $year, $month, $count_of_taxa_pages, $count_of_taxa_pages_viewed);
            $temp = self::save_to_txt2($report, "google_analytics_partner_summaries", $year_month, "\t", "txt");
            
            $elapsed_time_in_sec = microtime(1) - $time_start;
            print " --- " . number_format($elapsed_time_in_sec / 60, 3) . " mins to process  \n";
        }
        //=================================================================
        print "\n start BHL stats summaries...\n";
        $time_start = microtime(1);
        $totals = self::get_count_of_taxa_pages_per_partner(38205, $year, $month);
        $count_of_taxa_pages = $totals[0];
        $count_of_taxa_pages_viewed = $totals[1];
        $report = self::get_monthly_summaries_per_partner(38205, $year, $month, $count_of_taxa_pages, $count_of_taxa_pages_viewed);
        $temp = self::save_to_txt2($report, "google_analytics_partner_summaries", $year_month, "\t", "txt");
        $elapsed_time_in_sec = microtime(1) - $time_start;
        print " --- " . number_format($elapsed_time_in_sec / 60, 3) . " mins to process  \n";
        //=================================================================
        print "\n start COL stats summaries...\n";
        $time_start = microtime(1);
        $totals = self::get_count_of_taxa_pages_per_partner(11, $year, $month);
        $count_of_taxa_pages = $totals[0];
        $count_of_taxa_pages_viewed = $totals[1];
        $report = self::get_monthly_summaries_per_partner(11, $year, $month, $count_of_taxa_pages, $count_of_taxa_pages_viewed);
        $temp = self::save_to_txt2($report, "google_analytics_partner_summaries", $year_month, "\t", "txt");
        $elapsed_time_in_sec = microtime(1) - $time_start;
        print " --- " . number_format($elapsed_time_in_sec / 60, 3) . " mins to process  \n";
        //=================================================================
        if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE '" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_summaries.txt' INTO TABLE google_analytics_partner_summaries");
        else                    $update = $this->mysqli_local->load_data_infile(             "" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_summaries.txt",          "google_analytics_partner_summaries");
        //=================================================================
    }
    
    function get_taxon_concept_id_viewed_for_dmonth($agent_id, $month, $year)
    {
        if($agent_id == 38205)//BHL
        {
            $query="SELECT gaps.taxon_concept_id tc_id FROM google_analytics_page_stats gaps WHERE gaps.year = $year AND gaps.month = $month";
            $result = $this->mysqli->query($query);
            $arr1 = array();
            while($result && $row = $result->fetch_assoc())
            {
                $tc_id = $row['tc_id'];
                $arr1[$tc_id] = '';
            }
            //start reading text file
            print"\n Start reading text file [taxon_concept_with_bhl_links] \n";
            $filename = PAGE_METRICS_TEXT_PATH . "taxon_concept_with_bhl_links.txt";
            $FILE = fopen($filename, "r");
            $num_rows = 0; 
            $arr2 = array();
            while(!feof($FILE))
            {
                if($line = fgets($FILE))
                {
                    $num_rows++; 
                    $line = trim($line); 
                    $fields = explode("\t", $line);
                    $tc_id = trim($fields[0]);
                    $arr2[$tc_id] = '';
                }
            }
            $arr1 = array_keys($arr1);
            $arr2 = array_keys($arr2);
            $arr = array_intersect($arr1, $arr2);
        }
        elseif($agent_id == 11)//Catalogue of Life
        {
            $query = "SELECT he.taxon_concept_id FROM hierarchy_entries he JOIN google_analytics_page_stats gaps ON he.taxon_concept_id = gaps.taxon_concept_id WHERE he.hierarchy_id  = " . Hierarchy::default_id() . " AND gaps.month = $month AND gaps.year = $year";
        }
        else //rest of the partners
        {
            $query = "SELECT he.taxon_concept_id FROM agents a JOIN agents_resources ar ON (a.id=ar.agent_id) JOIN harvest_events hev ON (ar.resource_id=hev.resource_id) JOIN harvest_events_hierarchy_entries hehe ON (hev.id=hehe.harvest_event_id) JOIN hierarchy_entries he on hehe.hierarchy_entry_id = he.id JOIN taxon_concepts tc on he.taxon_concept_id = tc.id JOIN google_analytics_page_stats gaps ON tc.id = gaps.taxon_concept_id WHERE a.id = $agent_id AND tc.published=1 AND tc.supercedure_id=0 AND gaps.month=$month AND gaps.year=$year";
        }
        
        if($agent_id != 38205)//not for BHL -- for Catalogue of Life AND others.
        {
            $result = $this->mysqli->query($query);
            $arr = array();
            while($result && $row = $result->fetch_assoc())
            {
                $tc_id = $row['taxon_concept_id'];
                $arr[$tc_id] = '';
            }
            $arr = array_keys($arr);
        }

        $final = array();
        foreach($arr as $tc_id)
        {
            $final[] = array("agent_id" => $agent_id, "taxon_concept_id" => $tc_id);
        }
        return $final;
    }
    
    function save_agent_taxa($year_month)
    {
        $year = intval(substr($year_month, 0, 4));
        $month = intval(substr($year_month, 5, 2));

        $query = self::get_sql_for_partners_with_published_data();
        $result = $this->mysqli->query($query);
        
        //initialize txt file
        $filename = GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa.txt";     $fp = fopen($filename, "w"); fclose($fp);
        $filename = GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa_bhl.txt"; $fp = fopen($filename, "w"); fclose($fp);
        $filename = GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa_col.txt"; $fp = fopen($filename, "w"); fclose($fp);
        
        print "\n start agent stats...\n";
        $num_rows = $result->num_rows; 
        $i = 0;
        while($result && $row = $result->fetch_assoc())
        {
            $time_start = microtime(1); 
            $i++;
            print "agent id = $row[id] $i of $num_rows ";
            $tc_ids = self::get_taxon_concept_id_viewed_for_dmonth($row["id"], $month, $year);
            $fields = array();
            $fields[] = "taxon_concept_id"; 
            $fields[] = "agent_id";
            $temp = self::save_to_txt($tc_ids, "google_analytics_partner_taxa", $fields, $year_month, "\t", 0, "txt");
            $elapsed_time_in_sec = microtime(1) - $time_start;
            print " --- " . number_format($elapsed_time_in_sec / 60, 3) . " mins to process  \n";
        }
        //=================================================================
        print "\n start BHL stats...\n";
        $time_start = microtime(1);
        $tc_ids = self::get_taxon_concept_id_viewed_for_dmonth(38205, $month, $year);
        $fields = array();
        $fields[] = "taxon_concept_id"; 
        $fields[] = "agent_id";
        $temp = self::save_to_txt($tc_ids, "google_analytics_partner_taxa_bhl", $fields, $year_month, "\t", 0, "txt");
        $elapsed_time_in_sec = microtime(1) - $time_start;
        print " --- " . number_format($elapsed_time_in_sec / 60, 3) . " mins to process \n";
        
        print "\n start COL stats...\n";
        $time_start = microtime(1);
        $tc_ids = self::get_taxon_concept_id_viewed_for_dmonth(11, $month, $year);
        $fields = array();
        $fields[] = "taxon_concept_id"; 
        $fields[] = "agent_id";
        $temp = self::save_to_txt($tc_ids, "google_analytics_partner_taxa_col", $fields, $year_month, "\t", 0, "txt");
        $elapsed_time_in_sec = microtime(1) - $time_start;
        print " --- " . number_format($elapsed_time_in_sec/60,3) . " mins to process  \n";
    
        //=================================================================
        if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE '" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa.txt'     INTO TABLE google_analytics_partner_taxa");
        else                    $update = $this->mysqli_local->load_data_infile(             "" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa.txt",              "google_analytics_partner_taxa");
        
        if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE '" . GOOGLE_DATA_PATH .  $year_month . "/google_analytics_partner_taxa_bhl.txt' INTO TABLE google_analytics_partner_taxa");
        else                    $update = $this->mysqli_local->load_data_infile(             "" . GOOGLE_DATA_PATH .  $year_month . "/google_analytics_partner_taxa_bhl.txt",          "google_analytics_partner_taxa");
        
        if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE '" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa_col.txt' INTO TABLE google_analytics_partner_taxa");
        else                    $update = $this->mysqli_local->load_data_infile(             "" . GOOGLE_DATA_PATH . $year_month . "/google_analytics_partner_taxa_col.txt",          "google_analytics_partner_taxa");
        //=================================================================
    }
    
    function save_eol_taxa_google_stats($month, $year)
    {
        $year_month = $year . "_" . $month;
        $start_date = "$year-$month-01";
        $end_date   = "$year-$month-" . getlastdayofmonth(intval($month), $year);
        print "\n start day = $start_date \n end day = $end_date \n";
        $final = array();
        require_once(DOC_ROOT . 'vendor/Google_Analytics_API_PHP/analytics_api.php');
        $login = GOOGLE_ANALYTICS_API_USERNAME;
        $password = GOOGLE_ANALYTICS_API_PASSWORD;
        $id = '';
        $api = new analytics_api();
        if($api->login($login, $password))
        {
            //login success
            if(true)
            {
                $api->load_accounts();
                $arr = $api->accounts;
            }
            $id = $arr["www.eol.org"]["tableId"];
        
            // get some account summary information without a dimension
            $i = 0;
            $continue = true; 
            $start_count = 1; 
            $range = 10000; //normal operation

            mkdir(GOOGLE_DATA_PATH , 0777);
            mkdir(GOOGLE_DATA_PATH . $year . "_" . $month , 0777);
            
            $cr = "\n";
            $sep = "\t";

            $cnt = 0;
            while($continue == true)
            {
                $data = $api->data($id, 'ga:pagePath', 'ga:pageviews,ga:uniquePageviews,ga:bounces,ga:entrances,ga:exits,ga:timeOnPage', false, $start_date, $end_date, $range, $start_count, false, false);//96480
                /* doesn't work with ,ga:visitors,ga:visits - these 2 will work if there is no dimension, this one has a dimension 'ga:pagePath' */
                $start_count += $range;
                $val = array();
                print "Process batch of = " . count($data) . "\n";
                $cnt++;
                if(count($data) == 0) $continue = false;
                $str = "";
                foreach($data as $metric => $count)
                {
                    $i++; 
                    if($count["ga:entrances"] > 0)  $bounce_rate = number_format($count["ga:bounces"] / $count["ga:entrances"] * 100, 2);
                    else                            $bounce_rate = "";
                    if($count["ga:pageviews"] > 0)  $percent_exit = number_format($count["ga:exits"] / $count["ga:pageviews"] * 100, 2);
                    else                            $percent_exit = "";
                    if($count["ga:pageviews"] - $count["ga:exits"] > 0)
                    {
                        $secs = round($count["ga:timeOnPage"] / ($count["ga:pageviews"] - $count["ga:exits"]));
                        $averate_time_on_page = $api->sec2hms($secs, false);
                    }
                    else $averate_time_on_page = "";
                    $money_index = '';
                    $url = "http://www.eol.org" . $metric;
                    $taxon_id = parse_url($url, PHP_URL_PATH);
                    if(strval(stripos($taxon_id, "/pages/")) != '') $taxon_id = str_ireplace("/pages/", "", $taxon_id);
                    else                                            $taxon_id = '';
                    if($taxon_id > 0)
                    {
                        if(!USE_SQL_LOAD_INFILE) $str .= "(";                        
                        $str .= intval($taxon_id) . $sep .
                                intval(substr($year_month,0,4)) . $sep .
                                intval(substr($year_month,5,2)) . $sep .
                                intval($count["ga:pageviews"]) . $sep .
                                intval($count["ga:uniquePageviews"]) . $sep . "'" . $averate_time_on_page . "'" . $cr;
                        if(!USE_SQL_LOAD_INFILE) $str .= "),";
                    }
                }//end for loop
                
                $OUT = fopen(GOOGLE_DATA_PATH . $year . "_" . $month . "/google_analytics_page_stats.txt", "w"); // open for writing, truncate file to zero length.
                fwrite($OUT, $str);
                fclose($OUT);
                if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE '" . GOOGLE_DATA_PATH . $year . "_" . $month . "/google_analytics_page_stats.txt' INTO TABLE google_analytics_page_stats");
                else
                {
                    if($str)
                    {
                        $str = str_ireplace("\t", ",", $str);
                        $str = str_ireplace("\n", "", $str);
                        $str = substr($str, 0, strlen($str) - 1); //to remove the last char which is a "," comma.
                        $update = $this->mysqli_local->query("INSERT IGNORE INTO `google_analytics_page_stats` VALUES $str");
                    }
                }
                //this is just monitoring...
                $update = $this->mysqli_local->query("SELECT COUNT(*) total FROM google_analytics_page_stats "); 
                $row = $update->fetch_row();
                print "\n current no of recs: " . $row[0];
                print "\n Getting data FROM Google Analytics... \n More, please wait... $start_count \n";
            }//end while
        }
        else print "login failed \n";
        return $final;        
    }
    
    function initialize_tables_4dmonth($year, $month)
    {
        $query = "delete FROM `google_analytics_page_stats`        WHERE `year` = $year AND `month` = $month ";  $update = $this->mysqli_local->query($query);
        $query = "delete FROM `google_analytics_partner_taxa`      WHERE `year` = $year AND `month` = $month ";  $update = $this->mysqli_local->query($query);
        $query = "delete FROM `google_analytics_partner_summaries` WHERE `year` = $year AND `month` = $month ";  $update = $this->mysqli_local->query($query);
        $query = "delete FROM `google_analytics_summaries`         WHERE `year` = $year AND `month` = $month ";  $update = $this->mysqli_local->query($query);
    }
    
    function save_to_txt($result, $filename, $fields, $year_month, $field_separator, $with_col_header, $file_extension)
    {
        $str = "";
        if($with_col_header)
        {
            for ($i = 0; $i < count($fields); $i++)
            {
                $field = $fields[$i];
                $str .= $field . $field_separator;
            }
            $str .= "\n";
        }
        foreach($result as $row)
        {
            for ($i = 0; $i < count($fields); $i++)
            {
                $field = $fields[$i];
                $str .= $row[$field] . $field_separator;
            }
            $str .= intval(substr($year_month, 0, 4)) . $field_separator;
            $str .= intval(substr($year_month, 5, 2)); //no more field separator for last item
            $str .= "\n";
        }
        $filename = GOOGLE_DATA_PATH . $year_month . "/" . "$filename" . "." . $file_extension;
        if($fp = fopen($filename,"a"))
        {
            fwrite($fp, $str);
            fclose($fp);
        }            
        return "";
    }
    
    function save_eol_monthly_summary($year, $month)
    {
        $tab_delim = "";
        $tab_delim .= $year . "\t" . $month . "\t";
        
        $api = get_FROM_api(GetNumMonthAsString($month, $year), $year);
        foreach($api[0] as $label => $value)
        {
            $a = date("Y m d", mktime(0, 0, 0, $month, getlastdayofmonth(intval($month), $year), $year)) . " 23:59:59";
            $b = date("Y m d H:i:s");
            if($a <= $b) $tab_delim .= $value . "\t";
        }
        
        $query = "SELECT COUNT(*) count FROM taxon_concepts tc WHERE tc.published=1 AND tc.supercedure_id=0";
        $result = $this->mysqli->query($query);
        $row = $result->fetch_row();
        $taxa_pages = $row[0];
    
        $query = "SELECT google_analytics_page_stats.taxon_concept_id tc_id FROM google_analytics_page_stats WHERE year = $year AND month = $month ";
        $result = $this->mysqli->query($query);
        $arr = array();
        while($result && $row = $result->fetch_assoc())
        {
            $tc_id = $row['tc_id'];
            $arr[$tc_id] = '';
        }
        $taxa_pages_viewed = sizeof(array_keys($arr));
        
        $query = "SELECT sum(time_to_sec(google_analytics_page_stats.time_on_page)) time_on_pages FROM google_analytics_page_stats WHERE google_analytics_page_stats.`year` = $year AND google_analytics_page_stats.`month` = $month ";
        $result = $this->mysqli->query($query);
        $row = $result->fetch_row();
        $time_on_pages = $row[0];    
        $tab_delim .= $taxa_pages . "\t" . $taxa_pages_viewed . "\t" . $time_on_pages;
     
        //start saving...
        $fp = fopen("temp.txt", "w");
        fwrite($fp, $tab_delim);
        fclose($fp);
        if(USE_SQL_LOAD_INFILE) $update = $this->mysqli_local->query("LOAD DATA LOCAL INFILE 'temp.txt' INTO TABLE google_analytics_summaries");
        else                    $update = $this->mysqli_local->load_data_infile(             "temp.txt",          "google_analytics_summaries");
    }
}
?>