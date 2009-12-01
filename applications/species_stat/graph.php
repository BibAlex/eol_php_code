<?php

/*
define("ENVIRONMENT", "slave_32");        //where stats are stored
define("DEBUG", false);
define("MYSQL_DEBUG", false);
require_once("../../config/start.php");
$mysqli =& $GLOBALS['mysqli_connection'];
*/

set_time_limit(0);

//print"<img src='http://chart.apis.google.com/chart?chs=250x100&chd=t:60,40&cht=p3&chl=Hello|World'>";

//$title = "Total number of pages with names not in CoL";
$title = get_val_var('title');

$title = str_ireplace('Pages with links (specialist projects) and no text', "Pages with links and no text", $title);
$title = str_ireplace('Number of taxa with no data objects (in CoL), i.e. base pages', "Number of taxa with no data objects (in CoL)&#44; i.e. base pages", $title);

$img = get_graph($title);
print $img;


function get_graph($title)
{   
    $arr = get_values_fromCSV($title);
    $comma_separated = get_comma_separated($arr,",");
    /*
    $range1 = $arr[0]-1000;
    $range2 = $arr[count($arr)-1]+1000;
    */
    $range1 = min($arr)-1000;
    $range2 = max($arr)+1000;
    

    $arr = get_values_fromCSV("date");
    $date_comma_separated = get_comma_separated($arr,"|");

    //print "$comma_separated <hr> $date_comma_separated <hr>";
    //return "<img src='http://chart.apis.google.com/chart?chs=700x300&amp;chtt=$title&amp;cht=lc&amp;chd=t:$comma_separated&amp;chds=$range1,$range2&amp;chl=$date_comma_separated' alt=''/>";    
    return "<img src='http://chart.apis.google.com/chart?chs=700x300&amp;chxt=y&amp;chxr=0,$range1,$range2&amp;chtt=$title&amp;cht=lc&amp;chd=t:$comma_separated&amp;chds=$range1,$range2&amp;chl=$date_comma_separated' alt=''/>
    <p><a style='font-size : x-small; font-family : Arial;' href='javascript:history.go(-1)'>&lt;&lt; Back</a>";    

    /*
chxt=x,y,r
chxr=0,100,500|
     1,0,200|
     2,1000,0    
    
    range for y axis: 
    chxt=y&amp;chxr=0,$range1,$range2&amp;
    
    background color:
    chf=bg,s,EFEFEF&amp;
    chf=bg,s,EFEFEF&amp;
    
    fill color:
    chm=B,76A4FB,0,0,0&amp;
    chm=B,76A4FB,0,0,0&amp;
    chd=s:ATSTaVd21981uocA
    
    solid fill:
    chf=bg,s,EFEFEF&amp;
    chf=bg,s,EFEFEF&amp;
    
    */
    
}

function get_comma_separated($arr,$sep)
{
    $str="";
    for ($i = 0; $i < count($arr); $i++) 
    {	
        $str .= "$arr[$i]$sep";
    }
    $str=trim($str);
    $str=substr($str,0,strlen($str)-1); //removes the last comma (,)
    
    return $str;    
}

function get_values_fromCSV($title)
{
    $filename = "saved_stats.csv";
    $row = 0;
    if(!($handle = fopen($filename, "r")))return;
    
    $label=array();
    $arr = array();
    
    while (($data = fgetcsv($handle)) !== FALSE) 
    {
        //if($row > 0) //to bypass first row, which is the row for the labels
        if($row > -1)
        {                
            $num = count($data);
            //echo "<p> $num fields in line $row: <br /></p>\n";        
            for ($c=0; $c < $num; $c++) 
            {        
                if($row==0) $label[]=$data[$c];
				else        $arr["$label[$c]"][]=$data[$c];
                //else        $arr["$label[$c]"][]=log10($data[$c])/10;                
                /*
                if($c==0)$arr["date"]                   =$data[$c];
                if($c==1)$arr["time"]                   =$data[$c];
                */
            }                        
            //if($row == 10)break;    
        }
        $row++;
    }//end while

    $label = delete_null_in_array($label);
    
    /*
    print_r($label); print "<hr>";
    print_r($arr); print "<hr>";
    */    
    
    if($title == "date")
    {
        $total = count($arr["$title"]);
        $div = intval($total/7);
        //print "<hr>$total<hr>";
        $arr_new = array();
        $arr_new[] = $arr[$title][0];
        for ($i = 1; $i < $total-1; $i++) //not use $i = 0 and $i < $total so that u wont get the 1st and last date
        {	
            //if($i % $div == 0)$arr_new[] = $arr[$title][$i]; //with year
            if($i % $div == 0)$arr_new[] = substr($arr[$title][$i],5,5); //no year
        }
        $arr_new[] = $arr[$title][$total-1];
        return $arr_new;
    }

    //$arr["$title"][0] = $arr["$title"][0] - 100;
    return $arr["$title"];

}//end function


function delete_null_in_array($arr)
{
    foreach ($arr as $key => $value) 
    {if (is_null($value) or trim($value)=='')unset($arr[$key]);}
    return $arr;
}

function get_val_var($v)
{
    if         (isset($_GET["$v"])){$var=$_GET["$v"];}
    elseif     (isset($_POST["$v"])){$var=$_POST["$v"];}
    
    if(isset($var)){return $var;}
    else    {return NULL;}    
}


?>

