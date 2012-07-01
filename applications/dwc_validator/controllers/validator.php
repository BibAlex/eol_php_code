<?php
namespace php_active_record;

class dwc_validator_controller extends ControllerBase
{
    public static function index($parameters)
    {
        extract($parameters);
        
        $errors = array();
        $eol_errors = array();
        $eol_warnings = array();
        $stats = array();
        
        
        $dwca_file = @$file_url;
        if(@$dwca_upload['tmp_name']) $dwca_file = $dwca_upload['tmp_name'];
        
        if($dwca_file)
        {
            if($temp_dir = ContentManager::download_temp_file_and_assign_extension($dwca_file))
            {
                if(is_dir($temp_dir))
                {
                    if(file_exists($temp_dir . "/meta.xml"))
                    {
                        $archive = new ContentArchiveReader(null, $temp_dir);
                        $validator = new ContentArchiveValidator($archive);
                        $validator->get_validation_errors();
                        list($errors, $warnings, $stats) = array($validator->errors(), $validator->warnings(), $validator->stats());
                    }else
                    {
                        $error = new \eol_schema\ContentArchiveError();
                        $error->message = "Unable to locate a meta.xml file";
                        $errors[] = $error;
                    }
                    recursive_rmdir($temp_dir);
                }else
                {
                    $path_parts = pathinfo($temp_dir);
                    $extension = @$path_parts['extension'];
                    $archive_tmp_dir = @$path_parts['dirname'] ."/". @$path_parts['filename'];
                    recursive_rmdir($archive_tmp_dir);
                    mkdir($archive_tmp_dir);
                    if($extension == 'zip' || $extension == 'xls')
                    {
                        require_library('ExcelToText');
                        $archive_converter = new ExcelToText($temp_dir, $archive_tmp_dir);
                        if($archive_converter->errors())
                        {
                            $errors = $archive_converter->errors();
                        }elseif($archive_converter->is_new_schema_spreadsheet())
                        {
                            $archive_converter->convert_to_new_schema_archive();
                            if($archive_converter->errors())
                            {
                                $errors = $archive_converter->errors();
                            }else
                            {
                                $archive = new ContentArchiveReader(null, $archive_tmp_dir);
                                $validator = new ContentArchiveValidator($archive);
                                $validator->get_validation_errors();
                                list($errors, $warnings, $stats) = array($validator->errors(), $validator->warnings(), $validator->stats());
                            }
                        }elseif($archive_converter->errors())
                        {
                            $errors = $archive_converter->errors();
                        }else
                        {
                            $error = new \eol_schema\ContentArchiveError();
                            $error->message = "Unable to determine the template of Excel file";
                            $errors[] = $error;
                        }
                    }else
                    {
                        $error = new \eol_schema\ContentArchiveError();
                        $error->message = "The uploaded file was not in a format we recognize";
                        $errors[] = $error;
                    }
                    wildcard_rm($archive_tmp_dir);
                }
            }else
            {
                $error = new \eol_schema\ContentArchiveError();
                $error->message = "There was a problem with the uploaded file";
                $errors[] = $error;
            }
        }
        render_template("validator/index", array("file_url" => @$file_url, "file_upload" => @$dwca_upload['name'], "errors" => @$errors, "warnings" => @$warnings, "stats" => $stats));
    }
}

?>