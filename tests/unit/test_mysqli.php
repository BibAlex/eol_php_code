<?php

class test_mysqli extends SimpletestUnitBase
{
    function testLoadData()
    {
        $tmp_file_path = temp_filepath();
        $FILE = fopen($tmp_file_path, 'w+');
        for($i=1 ; $i<=50002 ; $i++)
        {
            fwrite($FILE, "$i\ttest string $i\t\N\n");
        }
        fclose($FILE);
        
        // creating a temp table for this test
        $GLOBALS['db_connection']->update('CREATE TABLE `test_load_data` ( `id` INT, `test_string` VARCHAR(50), `test_blank` VARCHAR(10), PRIMARY KEY (`id`))');
        $result = $GLOBALS['db_connection']->query('SELECT * FROM test_load_data');
        $this->assertTrue($result->num_rows == 0, 'Table should be empty');
        
        // load data
        $GLOBALS['db_connection']->load_data_infile($tmp_file_path, 'test_load_data');
        $result = $GLOBALS['db_connection']->query('SELECT * FROM test_load_data');
        $this->assertTrue($result->num_rows == 50002, 'Table should have all the data in it');
        
        // test values
        $row = $result->fetch_assoc();
        $this->assertTrue($row['id'] == 1, 'first row id should be right');
        $this->assertTrue($row['test_string'] == 'test string 1', 'first row test_string should be right');
        $this->assertTrue($row['test_blank'] == NULL, 'first row test_string should be right');
        
        
        // making a smaler file for two more tests
        $FILE = fopen($tmp_file_path, 'w+');
        for($i=1 ; $i<=10 ; $i++)
        {
            fwrite($FILE, "$i\tnew test string $i\tnot null\n");
        }
        fclose($FILE);
        
        // load data again
        $GLOBALS['db_connection']->load_data_infile($tmp_file_path, 'test_load_data');
        $result = $GLOBALS['db_connection']->query('SELECT * FROM test_load_data');
        $this->assertTrue($result->num_rows == 50002, 'Table should have all the data in it');
        
        // should be the same
        $row = $result->fetch_assoc();
        $this->assertTrue($row['id'] == 1, 'first row id should be right');
        $this->assertTrue($row['test_string'] == 'test string 1', 'first row test_string should be what is used to be');
        $this->assertTrue($row['test_blank'] == NULL, 'first row test_string should be what it used to be');
        
        // now load data again but this time with REPLACE
        $GLOBALS['db_connection']->load_data_infile($tmp_file_path, 'test_load_data', 'REPLACE');
        $result = $GLOBALS['db_connection']->query('SELECT * FROM test_load_data');
        $row = $result->fetch_assoc();
        $this->assertTrue($row['id'] == 1, 'first row id should be right');
        $this->assertTrue($row['test_string'] == 'new test string 1', 'first row test_string should be the new value');
        $this->assertTrue($row['test_blank'] == 'not null', 'first row test_string should be the new value');
        
        // cleanup
        $GLOBALS['db_connection']->update('DROP TABLE `test_load_data`');
        unlink($tmp_file_path);
    }
    
    function testSelectIntoOutfile()
    {
        $GLOBALS['db_connection']->update('CREATE TABLE `test_load_data` ( `id` INT, `test_string` VARCHAR(50), PRIMARY KEY (`id`))');
        $GLOBALS['db_connection']->query("INSERT INTO test_load_data VALUES (1, 'one'),(2, 'two'),(3, 'three'),(4, 'four')");
        
        $outfile = $GLOBALS['db_connection']->select_into_outfile('SELECT * FROM test_load_data');
        $contents = file($outfile);
        $this->assertTrue(count($contents) == 4, 'file should have 4 lines');
        $this->assertTrue(trim($contents[0]) == "1\tone", 'first row should be correct');
        $this->assertTrue(trim($contents[3]) == "4\tfour", 'last row should be correct');
        
        // cleanup
        $GLOBALS['db_connection']->update('DROP TABLE `test_load_data`');
        unlink($outfile);
    }
}

?>