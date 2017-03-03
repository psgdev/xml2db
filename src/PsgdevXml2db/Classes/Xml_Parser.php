<?php

/**
 * Xml_Parser
 * parse xml using parsed dtd and load the data into database
 *
 * @author Tibor(tibor@planetsg.com)
 * @version aa-v1.0
 */

namespace PsgdevXml2db;

use PsgdevXml2db\DTD_Parser;
use PsgdevMusqlidb\Musqlidb;

class Xml_Parser
{
    /**
     * this class parses the xml using table and dtd structure created by DTD_Parser class
     */

    /**
     * $systemTableFields & $systemTableFieldsDefaultValue are optional, these fields can me managed in the extended ORM or extenden custom DM
     * public var
     */
    public $systemTableFields = []; // like array(["xx_Created" => "datetime DEFAULT NULL", "xx_Modified" => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "xx_Created_User" => "int(10) unsigned DEFAULT NULL", "xx_Modified_User" => "int(10) unsigned DEFAULT NULL"]
    public $systemTableFieldsDefaultValue = []; // like array(["xx_Created" => "[dbFunction]NOW()"], "xx_Created_User" => 1)

    /**
     * protected var
     */
    protected $dbx = null;
    protected $xmlPath;
    protected $dtdStructure;
    protected $fieldTypeText = []; // associative array table =>[keys]
    protected $textField = false;
    protected $createTableSQL = '';
    protected $connectionArray = [];
    protected $ignoredTable = []; // ignore tables - tables will not be filled, be carefully about relations between tables
    protected $utf8mb4Table = []; // utf8mb4 tables needs to be handled properly
    //protected $enabledTable = []; //do this tables
    protected $dumpFilePath; // file path for logging

    /**
     *
     * @param string $xmlFilePath
     * @param array $dtdRelationalStructure
     * @param string $database
     */
    public function __construct($xmlFilePath = '', $dtdRelationalStructure = [], $database = '')
    {

        $this->xmlPath = $xmlFilePath;
        $this->dtdStructure = $dtdRelationalStructure;
        $this->connectionArray = config('xml2db.databaseConnections.xml2db');
        $this->connectionArray['database'] = $database;
        $this->dumpFilePath = config('xml2db.dumpFilePath');

        ///file_put_contents($this->dumpFilePath, '');
    }

    /**
     * setIgnoredTable
     *
     * @param array $tArray
     */
    public function setIgnoredTable($tArray = [])
    {
        if (is_array($tArray))
            $this->ignoredTable = $tArray;
    }

    /**
     * setEnabledTable
     *
     * @param array $tArray
     */
//    public function setEnabledTable($tArray = []) {
//	if (is_array($tArray))
//	    $this->enabledTable = $tArray;
//    }  


    /**
     * setUTF8mb4Table
     *
     * @param array $tables
     */
    public function setUTF8mb4Table($tables = [])
    {
        if (is_array($tables))
            $this->utf8mb4Table = $tables;
    }


    /**
     * setFieldTypeText
     *
     * @param array $assocArray
     */
    public function setFieldTypeText($assocArray = [])
    {
        $this->fieldTypeText = $assocArray;
        if (is_array($this->fieldTypeText) && !empty($this->fieldTypeText))
            $this->textField = true;
    }

    /**
     * checkTextType
     *
     * @param array $typeArray
     * @param string $elemName
     * @param string $parentName
     * @return boolean
     */
    protected function checkTextType($typeArray, $elemName = '', $parentName = '')
    {

        if ($this->textField == false) {
            return false;
        }

        if (empty($parentName)) {

            if (in_array($elemName, $typeArray)) {
                return true;
            }
        } else {

            foreach ($typeArray as $key => $val) {

                if ($key == $parentName) {

                    if (is_array($val)) {
                        return $this->checkTextType($typeArray[$key], $elemName);
                    } else {
                        return $elemName == $val;
                    }
                }
            }
        }

        return false;
    }

    /**
     * createTable
     */
    public function createTable()
    {

        foreach ($this->dtdStructure['table'] as $table => $desc) {

            $this->dbx = Musqlidb::getInstance($this->connectionArray);
            if (in_array($table, $this->utf8mb4Table)) { // force UTF8mb4
                $this->dbx->setConnectionUTF8mb4Uni();
            }

            $sqlCheck = "SHOW TABLES LIKE '$table'";
            $this->dbx->run($sqlCheck);

            $this->checkIsValidQuery();

            if ($this->dbx->rows > 0) {

                $sql_alter = '';

                foreach ($desc['field'] as $field) {

                    $sqlCheck = "SHOW COLUMNS FROM `$table` LIKE '$field'";
                    $this->dbx->run($sqlCheck);

                    $this->checkIsValidQuery();

                    if ($this->dbx->rows < 1) {

                        if (strstr($field, 'z_')) {
                            $sql_alter .= " ADD `$field` INT(10) UNSIGNED DEFAULT NULL,";
                        } else {

                            if ($this->checkTextType($this->fieldTypeText, $field, $table)) {
                                $sql_alter .= " ADD `$field` TEXT DEFAULT NULL,";
                            } else {
                                $sql_alter .= " ADD `$field` VARCHAR(255) DEFAULT NULL,";
                            }
                        }
                    }
                }


                foreach ($desc['attlist'] as $fieldDef) {
                    $field = $fieldDef['name'];

                    $sqlCheck = "SHOW COLUMNS FROM `$table` LIKE '$field'";
                    $this->dbx->run($sqlCheck);

                    $this->checkIsValidQuery();

                    if ($this->dbx->rows < 1) {

                        if (strtolower($field) == 'id' || strstr(strtolower($field), '_id')) {
                            $sql_alter .= " `$field` INT(10) UNSIGNED DEFAULT NULL,";
                        } else {
                            $sql_alter .= " `$field` VARCHAR(255) DEFAULT NULL,";
                        }
                    }
                }


                if (!empty($sql_alter)) {
                    $sql_alter = rtrim($sql_alter, ',');
                    $sql = "ALTER TABLE `$table` " . $sql_alter . "";
                    $this->dbx->run($sql);

                    $this->checkIsValidQuery();

                }


            } else {

                $sql = "CREATE TABLE IF NOT EXISTS `$table` (`z_PRIMARY_KEY` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,";


                if (count($this->systemTableFields) > 0) {
                    $sysF = '';
                    foreach ($this->systemTableFields as $fd => $prop) {
                        $sysF .= "\n `" . $fd . "` " . $prop . ",";
                    }

                    $sql .= $sysF;
                }


                foreach ($desc['field'] as $field) {
                    if (strstr($field, 'z_')) {
                        $sql .= " `$field` INT(10) UNSIGNED NULL DEFAULT NULL,";
                    } else {

                        if ($this->checkTextType($this->fieldTypeText, $field, $table)) {
                            $sql .= " `$field` TEXT NULL DEFAULT NULL,";
                        } else {
                            $sql .= " `$field` VARCHAR(255) NULL DEFAULT NULL,";
                        }
                    }
                }

                foreach ($desc['attlist'] as $fieldDef) {
                    $field = $fieldDef['name'];
                    if (strtolower($field) == 'id' || strstr(strtolower($field), '_id')) {
                        $sql .= " `$field` INT(10) UNSIGNED NULL DEFAULT NULL,";
                    } else {
                        $sql .= " `$field` VARCHAR(255) NULL DEFAULT NULL,";
                    }
                }

                $sql = rtrim($sql, ',');

                $sql .= ") ENGINE=MYISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"; //ENGINE=MYISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                $this->dbx->run($sql);

                $this->checkIsValidQuery();

            }
        }
    }


    /**
     * addTableIndex
     *
     * @param array $tableIndex
     */
    public function addTableIndex($tableIndex = [])
    {
        $this->dbx = Musqlidb::getInstance($this->connectionArray);

        foreach ($tableIndex as $tbl => $par) {
            $sql = "ALTER TABLE `" . $tbl . "` ";
            foreach ($par as $fld) {
                $ind = $fld . '_X';
                $sql .= "  ADD INDEX `" . $ind . "` (`" . $fld . "`),";
            }
            $sql = rtrim($sql, ',');

            $this->dbx->run($sql);

            $this->checkIsValidQuery();

        }
    }


    /**
     * parse
     */
    public function parse()
    {

        $xml = simplexml_load_file($this->xmlPath);

        //echo  var_export($xml, true);
        $this->load($xml, $this->dtdStructure['root_tag_table']);
    }


    /**
     * @param string $xml
     * @param array $tagTable
     * @param int $parentKey
     * @param string $parentTable
     */
    protected function load($xml, $tagTable = array(), $parentKey = null, $parentTable = '')
    {

        foreach ($tagTable as $table => $xmlTag) {

            if (!in_array($table, $this->ignoredTable)) {

                if ($table == $xmlTag) {

                    if ($this->dtdStructure['table'][$table]['data_type'] == DTD_Parser::DATA_TYPE_MULTI_ROW) {


                        $uniqueNode = $this->dtdStructure['table'][$table]['node'][0];

                        if (count($this->dtdStructure['table'][$table]['node']) == 1 && isset($xml->$uniqueNode)) {

                            $saveTag = [];
                            $saveTag[$uniqueNode] = (string)$xml->$uniqueNode;

                            if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                $saveTag[$keyName] = $parentKey;
                            }


                            $this->insertTableRow($saveTag, $table);
                        } else {

                            foreach ($xml->$table as $row) {

                                foreach ($this->dtdStructure['table'][$table]['node'] as $tag) {
                                    //print "<br>{$table}-{$tag} ".$row->$tag."<br>";

                                    foreach ($row->$tag as $val) {

                                        $saveTag = [];

                                        $saveTag[$tag] = (string)$val;

                                        if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                            $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                            $saveTag[$keyName] = $parentKey;
                                        }


                                        $this->insertTableRow($saveTag, $table);
                                    }
                                }
                            }
                        }
                    }


                    if ($this->dtdStructure['table'][$table]['data_type'] == DTD_Parser::DATA_TYPE_BLOCK || $this->dtdStructure['table'][$table]['data_type'] == DTD_Parser::DATA_TYPE_MIXED) {

//print $table."::".$this->dtdStructure['table'][$table]['type'];
                        switch ($this->dtdStructure['table'][$table]['type']) {

                            case "root":

                                foreach ($this->dtdStructure['table'][$table]['node'] as $tag) {
                                    $saveTag[$tag] = (string)$xml->$tag;
                                }


                                if (isset($this->dtdStructure['table'][$table]['attlist'])) {

                                    foreach ($this->dtdStructure['table'][$table]['attlist'] as $attlist) {
                                        $attName = $attlist['name'];
                                        $saveTag[$attName] = (string)$xml[0]->attributes()->$attName;
                                    }
                                }

                                foreach ($this->dtdStructure['table'][$table]['mergeNode'] as $parent => $tagGroup) {

                                    foreach ($tagGroup as $tag) {
                                        $sField = $parent . "_" . $tag;
                                        $saveTag[$sField] = (string)$xml->$parent->$tag;
                                        //print "<br>{$table}-{$tag} ".$row->$parent->$tag."<br>";
                                    }
                                }

                                $insertKey = $this->insertTableRow($saveTag, $table);

                                if ($this->dtdStructure['table'][$table]['data_type'] == DTD_Parser::DATA_TYPE_MIXED) {

                                    $newTagTable = [];

                                    if (isset($this->dtdStructure['table'][$table]['relatedTable'])) {

                                        foreach ($this->dtdStructure['table'][$table]['relatedTable'] as $rtbl) {
                                            $newTagTable[$rtbl] = isset($this->dtdStructure['tag_table'][$rtbl]) ? $this->dtdStructure['tag_table'][$rtbl] : $rtbl;
                                        }

                                        $this->load($xml, $newTagTable, $insertKey, $table);
                                    }
                                }

                                break;

                            default:

//print $table.'<br>';
//print_r($xml->AC_DATA);
                                foreach ($xml->$table as $row) {

                                    $saveTag = [];

                                    foreach ($this->dtdStructure['table'][$table]['node'] as $tag) {
                                        $saveTag[$tag] = (string)$row->$tag;
                                    }

                                    if (isset($this->dtdStructure['table'][$table]['attlist'])) {

                                        foreach ($this->dtdStructure['table'][$table]['attlist'] as $attlist) {
                                            $attName = $attlist['name'];
                                            $saveTag[$attName] = (string)$row[0]->attributes()->$attName;
                                        }
                                    }

                                    foreach ($this->dtdStructure['table'][$table]['mergeNode'] as $parent => $tagGroup) {

                                        foreach ($tagGroup as $tag) {
                                            $sField = $parent . "_" . $tag;
                                            $saveTag[$sField] = (string)$row->$parent->$tag;
                                            //print "<br>{$table}-{$tag} ".$row->$parent->$tag."<br>";
                                        }
                                    }


                                    if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                        $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                        $saveTag[$keyName] = $parentKey;
                                    }


                                    $insertKey = $this->insertTableRow($saveTag, $table);

                                    if ($this->dtdStructure['table'][$table]['data_type'] == DTD_Parser::DATA_TYPE_MIXED) {

                                        $newTagTable = [];

                                        if (isset($this->dtdStructure['table'][$table]['relatedTable'])) {

                                            foreach ($this->dtdStructure['table'][$table]['relatedTable'] as $rtbl) {
                                                $newTagTable[$rtbl] = isset($this->dtdStructure['tag_table'][$rtbl]) ? $this->dtdStructure['tag_table'][$rtbl] : $rtbl;
                                            }

                                            $this->load($row, $newTagTable, $insertKey, $table);
                                        }
                                    }
                                }

                                break;
                        }
                    }
                } else {

                    foreach ($xml->$table as $child) {

                        foreach ($child as $row) {

                            $saveTag = [];

                            foreach ($this->dtdStructure['table'][$table]['node'] as $tag) {
                                $saveTag[$tag] = (string)$row->$tag;
                            }

                            if (isset($this->dtdStructure['table'][$table]['attlist'])) {

                                foreach ($this->dtdStructure['table'][$table]['attlist'] as $attlist) {
                                    $attName = $attlist['name'];
                                    $saveTag[$attName] = (string)$row[0]->attributes()->$attName;
                                }
                            }

                            foreach ($this->dtdStructure['table'][$table]['mergeNode'] as $parent => $tagGroup) {

                                foreach ($tagGroup as $tag) {
                                    $sField = $parent . "_" . $tag;
                                    $saveTag[$sField] = (string)$row->$parent->$tag;
                                }
                            }
//print_r($saveTag);
//print $parentKey."::".$parentTable."::".$this->dtdStructure['table'][$table]['parent'][$parentTable];exit;
                            if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                $saveTag[$keyName] = $parentKey;
                            }

                            //print_r($saveTag);
                            $insertKey = $this->insertTableRow($saveTag, $table);

                            $newTagTable = [];

                            if (isset($this->dtdStructure['table'][$table]['relatedTable']) && count($this->dtdStructure['table'][$table]['relatedTable']) > 0) {

                                foreach ($this->dtdStructure['table'][$table]['relatedTable'] as $rtbl) {
                                    $newTagTable[$rtbl] = isset($this->dtdStructure['tag_table'][$rtbl]) ? $this->dtdStructure['tag_table'][$rtbl] : $rtbl;
                                }

                                $this->load($row, $newTagTable, $insertKey, $table);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * insertTableRow
     *
     * @param string $saveTag
     * @param string $table
     * @return int
     */
    protected function insertTableRow($saveTag, $table = '')
    {

        //if(in_array($table, $this->ignoredTable)) return 0;

        if (count($this->systemTableFieldsDefaultValue) > 0) {
            foreach ($this->systemTableFieldsDefaultValue as $key => $val) {
                $saveTag["" . $key . ""] = $val;
            }
        }
        $this->dbx = Musqlidb::getInstance($this->connectionArray);

        if (in_array($table, $this->utf8mb4Table)) { // force UTF8mb4
            $this->dbx->setConnectionUTF8mb4Uni();
        }

        $this->dbx->create($table, $saveTag);

        //print "<br>".$this->dbx->currentQuery."<br>";
        if ($this->dbx->isError()) {

            file_put_contents($this->dumpFilePath, "\n" . $this->dbx->getError(), FILE_APPEND);

            if (strstr($this->dbx->error, 'Duplicate entry')) {
// 	        $exp = explode('for key', $this->dbx->errorMessage);
// 	        $uniqueField = trim(str_replace("'","", $exp[1]));
                preg_match_all("/'([^']*?)'/", $this->dbx->error, $matched);
                // print_r($matched);exit;
                //print "<br>".$table."::".$this->dbx->errorMessage."<br>";


                $unFieldValue = trim($matched[1][0]);
                $unFieldName = trim($matched[1][1]);
                unset($saveTag["$unFieldName"]);
                $this->dbx->update($table, $saveTag, $unFieldValue, $unFieldName);

                if ($this->dbx->isError()) {
                    file_put_contents($this->dumpFilePath, '\nDUPLICATE_ENTRY_ISSUE_TRY_UPDATE: ' . $table . '::' . $this->dbx->getError(), FILE_APPEND);
                } else {
                    $this->dbx->run("SELECT z_PRIMARY_KEY FROM $table WHERE `$unFieldName` = '$unFieldValue' LIMIT 1");
                    return $this->dbx->data['z_PRIMARY_KEY'];
                }

            }

        }

        return $this->dbx->getLastInsertID();
    }

    /**
     * @param Musqlidb $this ->dbx
     * @param string $str
     */
    protected function checkIsValidQuery($str = '')
    {
        if (!empty($str)) {
            file_put_contents($this->dumpFilePath, "\n" . $str, FILE_APPEND);
        } elseif (!is_null($this->dbx)) {
            if ($this->dbx->isError()) {
                file_put_contents($this->dumpFilePath, "\n" . $this->dbx->getError(), FILE_APPEND);
            }
        }
    }

}
