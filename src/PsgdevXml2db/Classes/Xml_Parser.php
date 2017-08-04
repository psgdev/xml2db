<?php

/**
 * Xml_Parser
 * parse xml using parsed dtd and load the data into database
 *
 * in this version: xml_root_element, load() = changed to looop through root element used as dataConnector (type = 'root' but forced to act as dataConnector)
 *
 * @author Tibor(tibor@planetsg.com)
 * @version aa-v2.0
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
    public $htmlEntEncode = false;

    /**
     * protected var
     */
    protected $dbx = null;
    protected $xmlPath;
    protected $dtdStructure;
    protected $fieldTypeText = []; // associative array table =>[keys]
    protected $fieldTypeLongVarchar = [];
    protected $textField = false;
    protected $longVarcharField = false;

    protected $createTableSQL = '';
    protected $connectionArray = [];
    protected $ignoredTable = []; // ignore tables - tables will not be filled, be carefully about relations between tables
    protected $utf8mb4Table = []; // utf8mb4 tables needs to be handled properly
    protected $dumpFileDirPath; // dir path for logging

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
        $this->dumpFileDirPath = config('xml2db.dumpFileDirPath') . '/db_' . $database;

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
     * setLongVarcharField
     *
     * @param array $assocArray
     */
    public function setLongVarcharField($assocArray = [])
    {
        $this->fieldTypeLongVarchar = $assocArray;
        if (is_array($this->fieldTypeLongVarchar) && !empty($this->fieldTypeLongVarchar))
            $this->longVarcharField = true;
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
     * checkLongVarcharType
     *
     * @param array $typeArray
     * @param string $elemName
     * @param string $parentName
     * @return boolean
     */
    protected function checkLongVarcharType($typeArray, $elemName = '', $parentName = '')
    {

        if ($this->longVarcharField == false) {
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
                        return $this->checkLongVarcharType($typeArray[$key], $elemName);
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

        $engine = empty($this->connectionArray['engine']) || is_null($this->connectionArray['engine']) ? '' : 'ENGINE=' . strtoupper($this->connectionArray['engine']);

        foreach ($this->dtdStructure['table'] as $table => $desc) {

            $this->dbx = Musqlidb::getInstance($this->connectionArray);
            if (in_array($table, $this->utf8mb4Table)) { // force UTF8mb4
                $this->dbx->setConnectionUTF8mb4Uni();
            }

            $sqlCheck = "SHOW TABLES LIKE '$table'";
            try {
                $this->dbx->run($sqlCheck);
            } catch (\Exception $ex) {
                print $ex->getMessage();
            }

            $this->checkIsValidQuery();

            if ($this->dbx->rows > 0) {

                $sql_alter = '';

                foreach ($desc['field'] as $field) {

                    $sqlCheck = "SHOW COLUMNS FROM `$table` LIKE '$field'";
                    try {
                        $this->dbx->run($sqlCheck);
                    } catch (\Exception $ex) {
                        print $ex->getMessage();
                    }

                    $this->checkIsValidQuery();

                    if ($this->dbx->rows < 1) {

                        if (strstr($field, 'z_')) {
                            $sql_alter .= " ADD `$field` INT(10) UNSIGNED DEFAULT NULL,";
                        } else {

                            if ($this->checkTextType($this->fieldTypeText, $field, $table)) {
                                if (strstr(strtolower($this->connectionArray['charset']), 'utf8') || in_array($table, $this->utf8mb4Table)) {
                                    $sql_alter .= " ADD `$field` TEXT COLLATE utf8mb4_unicode_ci,";
                                } else {
                                    $sql_alter .= " ADD `$field` TEXT,";
                                }
                            } elseif ($this->checkLongVarcharType($this->fieldTypeLongVarchar, $field, $table)) {
                                $sql_alter .= " ADD `$field` VARCHAR(1000) DEFAULT NULL,";
                            } else {
                                $sql_alter .= " ADD `$field` VARCHAR(255) DEFAULT NULL,";
                            }
                        }
                    }
                }


                foreach ($desc['attlist'] as $fieldDef) {
                    $field = $fieldDef['name'];

                    $sqlCheck = "SHOW COLUMNS FROM `$table` LIKE '$field'";
                    try {
                        $this->dbx->run($sqlCheck);
                    } catch (\Exception $ex) {
                        print $ex->getMessage();
                    }

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

                    //print $table.': '.$sql.'<br>';
                    try {
                        $this->dbx->run($sql);
                    } catch (\Exception $ex) {
                        print $ex->getMessage();
                    }

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
                        $sql .= " `$field` INT(10) UNSIGNED DEFAULT NULL,";
                    } else {

                        if ($this->checkTextType($this->fieldTypeText, $field, $table)) {

                            if (strstr(strtolower($this->connectionArray['charset']), 'utf8') || in_array($table, $this->utf8mb4Table)) {
                                $sql .= " `$field` TEXT COLLATE utf8mb4_unicode_ci,";
                            } else {
                                $sql .= " `$field` TEXT,";
                            }

                        } elseif ($this->checkLongVarcharType($this->fieldTypeLongVarchar, $field, $table)) {
                            $sql .= " `$field` VARCHAR(1000) DEFAULT NULL,";
                        } else {
                            $sql .= " `$field` VARCHAR(255) DEFAULT NULL,";
                        }
                    }
                }

                foreach ($desc['attlist'] as $fieldDef) {
                    $field = $fieldDef['name'];
                    // csid is added to simplify needs for integer type for this field - this is an exception in common procedure
                    if (strtolower($field) == 'id' || strtolower($field) == 'csid' || strstr(strtolower($field), '_id')) {
                        $sql .= " `$field` INT(10) UNSIGNED DEFAULT NULL,";
                    } else {
                        $sql .= " `$field` VARCHAR(255) DEFAULT NULL,";
                    }
                }

                $sql = rtrim($sql, ',');

                if (in_array($table, $this->utf8mb4Table)) {
                    $sql .= ") $engine DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                } else {
                    $sql .= ") $engine DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
                }

                try {
                    $this->dbx->run($sql);
                } catch (\Exception $ex) {
                    print $ex->getMessage();
                }
                //print $this->dbx->currentQuery;
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

            try {
                $this->dbx->run($sql);
            } catch (\Exception $ex) {
                print $ex->getMessage();
            }

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

                    file_put_contents($this->dumpFileDirPath, "\n" . 'CASE_1: '.$table . ' PK: ' . $parentTable.'::'.$parentKey, FILE_APPEND);
                    file_put_contents($this->dumpFileDirPath, "\n" . $table .' FIELDS: '. @implode(',', $this->dtdStructure['table'][$table]['node']), FILE_APPEND);

                    if (!empty($parentTable)) {
                        file_put_contents($this->dumpFileDirPath, "\n" . $table . ' keyName for parentTable: ' . $parentTable . ' value: ' . $this->dtdStructure['table'][$table]['parent'][$parentTable], FILE_APPEND);
                    }

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
                            }
                        }


                        if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                            $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                            $saveTag[$keyName] = $parentKey;
                        }

                        file_put_contents($this->dumpFileDirPath, "\n" . $table . ' INSERT: ' . $saveTag,FILE_APPEND);

                        $insertKey = $this->insertTableRow($saveTag, $table);

                        $newTagTable = [];

                        if (isset($this->dtdStructure['table'][$table]['relatedTable'])) {

                            foreach ($this->dtdStructure['table'][$table]['relatedTable'] as $rtbl) {
                                $newTagTable[$rtbl] = isset($this->dtdStructure['tag_table'][$rtbl]) ? $this->dtdStructure['tag_table'][$rtbl] : $rtbl;
                            }
                            file_put_contents($this->dumpFileDirPath, "\n" . $table . ' NEWTAGTABLE: ' . @implode(',', $newTagTable), FILE_APPEND);
                            $this->load($row, $newTagTable, $insertKey, $table);
                        }

                    }

                } else {

                    file_put_contents($this->dumpFileDirPath, "\n" . 'CASE_2: '.$table . ' PK: ' . $parentTable.'::'.$parentKey, FILE_APPEND);
                    file_put_contents($this->dumpFileDirPath, "\n" . $table .' FIELDS: '. @implode(',', $this->dtdStructure['table'][$table]['node']), FILE_APPEND);

                    if (!empty($parentTable)) {
                        file_put_contents($this->dumpFileDirPath, "\n" . $table . ' keyName for parentTable: ' . $parentTable . ' value: ' . $this->dtdStructure['table'][$table]['parent'][$parentTable], FILE_APPEND);
                    }

                    foreach ($xml->$table as $child) {

                        if($this->dtdStructure['table'][$table]['type'] == DTD_Parser::RELATION_TYPE_FIELD_VALUE) {

                            $saveTag = [];

                            foreach ($this->dtdStructure['table'][$table]['node'] as $tag) {
                                $saveTag[$tag] = (string)$child->$tag;
                            }

                            if (isset($this->dtdStructure['table'][$table]['attlist'])) {

                                foreach ($this->dtdStructure['table'][$table]['attlist'] as $attlist) {
                                    $attName = $attlist['name'];
                                    $saveTag[$attName] = (string)$child[0]->attributes()->$attName;
                                }
                            }


                            if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                $saveTag[$keyName] = $parentKey;
                            }

                            file_put_contents($this->dumpFileDirPath, "\n" . $table . ' INSERT_VALUE_TYPE: ' . $saveTag, FILE_APPEND);

                            $this->insertTableRow($saveTag, $table);


                        } else {


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

                                if (!is_null($parentKey) && is_numeric($parentKey) && $parentTable != '') {

                                    $keyName = $this->dtdStructure['table'][$table]['parent'][$parentTable];
                                    $saveTag[$keyName] = $parentKey;
                                }

                                file_put_contents($this->dumpFileDirPath, "\n" . $table . ' INSERT: ' . $saveTag, FILE_APPEND);

                                $insertKey = $this->insertTableRow($saveTag, $table);

                                $newTagTable = [];

                                if (isset($this->dtdStructure['table'][$table]['relatedTable']) && count($this->dtdStructure['table'][$table]['relatedTable']) > 0) {

                                    foreach ($this->dtdStructure['table'][$table]['relatedTable'] as $rtbl) {
                                        $newTagTable[$rtbl] = isset($this->dtdStructure['tag_table'][$rtbl]) ? $this->dtdStructure['tag_table'][$rtbl] : $rtbl;
                                    }

                                    file_put_contents($this->dumpFileDirPath, "\n" . $table . ' NEWTAGTABLE: ' . @implode(',', $newTagTable), FILE_APPEND);

                                    $this->load($row, $newTagTable, $insertKey, $table);
                                }
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

        if ($this->htmlEntEncode) {
            foreach ($saveTag as $key => $val) {
                if (is_string($val)) {
                    $saveTag["$key"] = html_entity_decode($val);
                }
            }
        }

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

            file_put_contents($this->dumpFileDirPath, "\n" . $this->dbx->getError(), FILE_APPEND);

            if (strstr($this->dbx->error, 'Duplicate entry')) {

                preg_match_all("/'([^']*?)'/", $this->dbx->error, $matched);

                $unFieldValue = trim($matched[1][0]);
                $unFieldName = trim($matched[1][1]);
                unset($saveTag["$unFieldName"]);
                $this->dbx->update($table, $saveTag, $unFieldValue, $unFieldName);

                if ($this->dbx->isError()) {
                    file_put_contents($this->dumpFileDirPath, '\nDUPLICATE_ENTRY_ISSUE_TRY_UPDATE: ' . $table . '::' . $this->dbx->getError(), FILE_APPEND);
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
            file_put_contents($this->dumpFileDirPath, "\n" . $str, FILE_APPEND);
        } elseif (!is_null($this->dbx)) {
            if ($this->dbx->isError()) {
                file_put_contents($this->dumpFileDirPath, "\n" . $this->dbx->getError(), FILE_APPEND);
            }
        }
    }

}