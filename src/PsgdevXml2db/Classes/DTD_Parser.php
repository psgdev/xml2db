<?php

/**
 * DTD_Parser
 * parse DTD and prepare structure for tables and parsing xml file with XML_Parser
 *
 * v2.1: corrected issue when root element is realy a dataConnector defined in $fields array
 *       IMPORTANT: Has known bug to not remove foreign key from root_tag_table element after optimization (parent takes child fields)
 *                  !!! Not tested without optimization !!!
 *       NEEDS CHANGES IN THE PROCESS RELATED TO root_tag_table, root and data types of these elements when creating structure and table definitions
 * v2.0: xml_root_element, verifyRootDataConnector() = root element as dataConnector if possible (type = 'root' but forced to act as dataConnector)
 *
 * @author Tibor(tibor@planetsg.com)
 * @version aa-v2.1
 */

namespace PsgdevXml2db;

use PsgdevXml2db\Xml_Parser;
use PsgdevXml2db\Parser_Helper;

class DTD_Parser
{
    /**
     * This class creates table structures and relational data used for parsing xml defined with dtd - dbStructure array
     * It can work with optimisation enabled or disabled
     * Optimisation are set before run method - results less tables throug merging tables where is a possible and allowed by the dtd structure
     * root_tag_table and tag_table definition in dbStructure used in xml parser to know what are the main element from the root element declaration and what tables are optimised    merging child into parent element or left as it is defined in the dtd
     */

    /**
     * const
     */
    const RELATION_TYPE_ROOT = 'root';
    const RELATION_TYPE_TABLE = 'table';
    const RELATION_TYPE_OPTION = 'option';
    const RELATION_TYPE_MERGE_NODE = 'mergeNode';
    const RELATION_TYPE_FIELD_VALUE = 'value';
    const RELATION_TYPE_FIELD_LIST = 'fieldList';
    const RELATION_TYPE_CONNECTOR = 'dataConnector';
    const DATA_TYPE_BLOCK = 'dataBlock';
    const DATA_TYPE_MIXED = 'dataMixed';
    const DATA_TYPE_MULTI_ROW = 'dataMultiRow';

    /**
     * protected var
     */
    protected $lines = [];
    protected $structure = [];
    protected $fields = [];
    protected $mergedNodeRelatedFields = [];
    protected $checkMerged = false;
    protected $rootElement = '';
    protected $dbStructure = [];
    protected $dtdTable = [];
    protected $multipleParent = [];
    protected $inlineElementTableRelated = []; // element has a child that needs to be inserted in separate table (when a field set as multiple in dtd but it is an inline child element and doesn't have dataConnector parent)
    protected $ignoredTable = []; //ignore tables - tables will not be filled, be carefull about relations between tables
    protected $ignoredFields = [];
    protected $hasIgnored = false;
    protected $ignoredOptimisationTable = []; // tables ignored when optimisation = true
    protected $optimizedParentTable = []; // parents tables that had taken all properties of child
    protected $removeOptimizedChildKey = []; // child tables that had gaved all properties to parent table and removed from structure, so they foreign keys needs to be removed from their child tables
    protected $debugFilePath;
    /**
     * public var
     */
    public $optimisation = true;
    public $tableDataType = [];

    /**
     * constructor
     *
     * loads the dtd file and breaks to lines
     *
     * @param string $filePath
     */
    public function __construct($filePath)
    {

        $this->debugFilePath = storage_path('logs/my_debug');
        //file_put_contents($this->debugFilePath, '');

        $string = file_get_contents($filePath);
        //print htmlspecialchars($string);

        $exp = explode(">", $string);

        $this->lines = array_filter($exp, array($this, 'checkEmpty'));
        //Parser_Helper::nicePrint(htmlspecialchars($this->lines[0]));
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
     * checkIgnoredTable
     *
     * @param string $table
     * @return boolean
     */
    protected function checkIgnoredTable($table)
    {
        if (empty($table) || empty($this->ignoredTable)) return false;

        if (in_array($table, $this->ignoredTable)) return true;

        return false;
    }

    /**
     * setIgnoredOptimisationTable
     *
     * @param array $tArray
     */
    public function setIgnoredOptimisationTable($tArray = [])
    {
        if (is_array($tArray))
            $this->ignoredOptimisationTable = $tArray;
    }

    /**
     * isOptimisationTable
     *
     * @param string $table
     * @return boolean
     */
    protected function isOptimisationTable($table)
    {
        if (!empty($table) && !in_array($table, $this->ignoredOptimisationTable)) return true;

        return false;
    }

    /**
     * setMergedNodeRelatedFields
     *
     * set what parent-child element names from dataBlock type to merge an set as field in above parent table - if the dataBlock has attribute list it is not allowed
     * - define as [parent => child] or [parent => array(children)]
     *
     * @param array $assocArray
     */
    public function setMergedNodeRelatedFields($assocArray = [])
    {
        $this->mergedNodeRelatedFields = $assocArray;
        if (is_array($this->mergedNodeRelatedFields) && !empty($this->mergedNodeRelatedFields))
            $this->checkMerged = true;
    }

    /**
     * setIgnoredFields
     *
     * set what element needs to be ignored
     * - define as [parent => child] or [parent => array(children)]
     *
     * @param array $assocArray
     */
    public function setIgnoredFields($assocArray = [])
    {
        $this->ignoredFields = $assocArray;
        if (is_array($this->ignoredFields) && !empty($this->ignoredFields))
            $this->hasIgnored = true;
    }

    /**
     * run
     *
     * go line bu line and create fields with few different descriptions for the structure builder
     *
     * run the dtd parser
     */
    public function run()
    {

        foreach ($this->lines as $line) {

            // ATTLIST
            if ($this->checkIfAttlist($line)) {

                $parts = $this->prepareAttlist($line);
                //print_r($parts);
                $attribute = [];

                foreach ($parts as $key => $part) {

                    if ($key == 0) {
                        $belongTo = $this->getBelongsToElement($part);
                    } else {
                        $attribute[] = $this->declareAttribute($part);
                    }
                }

                $this->fields["$belongTo"]['attlist'] = $attribute;
            } else {

                // ELEMENT
                $line = $this->prepareElement($line);

                // return ELEMENT specification - children or type with requirements
                $elementDeclaration = Parser_Helper::returnBracketsContent($line);
                $exp = explode(" ", $line);

                if (empty($elementDeclaration)) {
                    $elementDeclaration = $exp[2];
                }

                $this->fields[$exp[1]]['name'] = $exp[1];
                $this->fields[$exp[1]]['spec'] = Parser_Helper::removeInvalidCharExceptRule($elementDeclaration); // don't care about possible options, you need all anyway
                $this->fields[$exp[1]]['desc'] = $elementDeclaration;
            }
        }

        // DEFINE ELEMENT TYPE
        foreach ($this->fields as $key => $val) {
            $this->fields[$key]['type'] = $this->checkSpecification($val['desc'], $key);
        }

        reset($this->fields); // important to set the pointer to the beginning
        $this->rootElement = key($this->fields);

        $this->checkForTable($this->rootElement);
        $this->dtdTable = array_unique($this->dtdTable);
        //Parser_Helper::nicePrint($this->dtdTable);

        $this->dbStructure['xml_root_element'] = $this->rootElement;

        $this->buildStructure($this->rootElement);
        $this->dbStructure['inlineTableRelated'] = $this->inlineElementTableRelated;

        //Parser_Helper::nicePrint($this->inlineElementTableRelated);
        $this->correctStructure();

        $this->prepareTableStructure($this->rootElement);
        $this->relationalTableStructure($this->rootElement);

        //Parser_Helper::nicePrint($this->dbStructure['table']);

        $this->correctDbStructure();

        // OPTIMIZATION OF TABLES IF SET TO TRUE
        if ($this->optimisation == true) {
            //print_r($this->optimizedParentTable);
            $this->optimizeTables($this->rootElement);
        }

        // rebuild the tag_table = list of tables and tags for creation of tables and fields, and slq queries
        $this->correctTableStructure();

        unset($this->dbStructure['unset_table']); // unset debug part of structure


    }

    /**
     * checkSpecification
     *
     * resolve possible type after checking the element declaration
     *
     * @param string $elementDeclaration
     * @return mixed
     */
    protected function checkSpecification($elementDeclaration, $name)
    {

        if ($this->checkPossibleTableSpecification($elementDeclaration)) {

            if (Parser_Helper::checkPossibleMultiOptionalValue($elementDeclaration)) {
                return self::RELATION_TYPE_OPTION;
            }
            if (!strstr($elementDeclaration, ',') && (!isset($this->fields["$name"]['attlist']) || count($this->fields["$name"]['attlist']) == 0)) {
                return self::RELATION_TYPE_CONNECTOR;
            }

            return self::RELATION_TYPE_FIELD_LIST;
        }

        return self::RELATION_TYPE_FIELD_VALUE;
    }

    /**
     * checkForTable
     *
     * get all elements from the dtd that have * or + sign as a multiple occurrence indicator - these elements needs tables, but after full parsing the system will define more
     *
     * @param string $node
     */
    protected function checkForTable($node)
    { //, $parent


        if ($node == $this->rootElement && !$this->checkIgnoredTable($node)) {
            $this->dtdTable[] = $node;
        }

        if (!empty($node)) {

            $exp = explode(',', $this->fields[$node]['spec']);
            $exp = array_unique($exp);

            if (count($exp) > 1) {
                $this->dtdTable[] = $node;
            }

            foreach ($exp as $e) {

                $elem = Parser_Helper::removeInvalidChar($e);

                if (Parser_Helper::isMultiple($e)) {
                    //print $elem;
                    $this->dtdTable[] = $elem;
                }

                $this->checkForTable($elem, $node);

            }
        }
    }

    /**
     * buildStructure
     *
     * recursive method - build dtd to structural form with parameters that will be used later for creating relational structure for tables and xml parser
     *
     * @param string $node
     * @param string $parent
     */
    protected function buildStructure($node, $parent = '')
    {

        if (!empty($node)) {

            $this->structure[$node]['desc'] = $this->fields[$node]['desc'];
            $this->structure[$node]['spec'] = $this->fields[$node]['spec'];

            if (!isset($this->structure[$node]['field'])) {
                $this->structure[$node]['field'] = [];
            }

            if (!isset($this->structure[$node]['node'])) {
                $this->structure[$node]['node'] = [];
            }


            $this->structure[$node]['attlist'] = [];

            if (isset($this->fields[$node]['attlist'])) {
                foreach ($this->fields[$node]['attlist'] as $val) {
                    $this->structure[$node]['attlist'][] = array('name' => $val['name'], 'type' => $val['type']);
                }
            }


            if ($node == $this->rootElement) {

                if (!$this->checkIgnoredTable($node)) {
                    $this->dbStructure['root_tag_table'][$node] = $node;
                }

                $this->structure[$node]['type'] = self::RELATION_TYPE_ROOT;

            } else {
                $this->structure[$node]['type'] = $this->fields[$node]['type'];
            }


            if ($parent == $this->rootElement) {
                $this->structure[$node]['parent'][] = $this->checkIgnoredTable($parent) ? '' : $parent;
            } else {
                $this->structure[$node]['parent'][] = $parent;
            }


            $exp = explode(',', $this->fields[$node]['spec']);
            $exp = array_unique($exp);

            foreach ($exp as $e) {

                $elem = Parser_Helper::removeInvalidChar($e);

                if ($this->fields[$elem]['type'] != self::RELATION_TYPE_FIELD_VALUE) {

                    if (!$this->checkIgnoredField($elem, $node)) {
                        $this->structure[$elem]['many'] = Parser_Helper::isMultiple($e);
                        $this->structure[$node]['relationType'][$elem] = $this->structure[$elem]['many'] ? self::RELATION_TYPE_TABLE : $this->setRelationType($elem, $node);
                        $this->structure[$node]['relation'][] = $elem;

                        if ($this->structure[$node]['relationType'][$elem] == self::RELATION_TYPE_TABLE) {
                            $this->multipleParent[$elem][] = $node;
                        }

                        $this->buildStructure($elem, $node);

                    }

                } else {

                    if (!$this->checkIgnoredField($elem, $node)) {

                        if (!isset($this->structure[$elem]['many']) || !$this->structure[$elem]['many']) {
                            $this->structure[$elem]['many'] = Parser_Helper::isMultiple($e);
                        }

                        $this->structure[$elem]['field'][] = $elem;
                        $this->structure[$elem]['type'] = $this->fields[$elem]['type'];
                        $this->structure[$elem]['parent'][] = $node;

                        if (!isset($this->structure[$elem]['attlist']) || !is_array($this->structure[$elem]['attlist'])) {
                            $this->structure[$elem]['attlist'] = [];
                            if (isset($this->fields[$elem]['attlist'])) {
                                foreach ($this->fields[$elem]['attlist'] as $val) {
                                    $this->structure[$elem]['attlist'][] = array('name' => $val['name'], 'type' => $val['type']);
                                }
                            }
                        }

                        if (!in_array($elem, $this->dtdTable)) {
                            $this->structure[$node]['field'][] = $elem;
                        } else {

                            $this->structure[$node]['relationType'][$elem] = $this->structure[$elem]['many'] ? self::RELATION_TYPE_TABLE : $this->setRelationType($elem, $node);
                            $this->structure[$node]['relation'][] = $elem;

                            if ($this->structure[$node]['relationType'][$elem] == self::RELATION_TYPE_TABLE) {
                                $this->multipleParent[$elem][] = $node;

                                // check if parent of table it's not a dataConnector => this field is an inline element in parent but need to be inserted in table with relation
                                if($this->isInlineRelatedTableElement($elem, $node)) {
                                    $this->inlineElementTableRelated[$node][] = $elem;
                                }
                            }

                        }
                    }
                }
            }
        }
    }


    /**
     * @param string $elem
     * @param string $node
     * @return bool
     */
    protected function isInlineRelatedTableElement($elem, $node) {

        if($this->fields[$node]['type'] == self::RELATION_TYPE_CONNECTOR) {
            return false;
        }

        return true;
    }

    /**
     * correctStructure
     *
     * there are complex relations that need correction during building table structures and relational data
     * elements with one child used mulitple times, or elements related to more than one element
     */
    protected function correctStructure()
    {

        foreach ($this->structure as $key => $val) {

            $this->structure["$key"]['field'] = array_unique($val['field']);

            if (!isset($this->structure["$key"]['parent'])) {
                $this->structure["$key"]['parent'] = [];
            } else {
                $this->structure["$key"]['parent'] = array_unique($val['parent']);
            }

            if (!isset($this->structure["$key"]['relation'])) {
                $this->structure["$key"]['relation'] = [];
            } else {
                $this->structure["$key"]['relation'] = array_unique($val['relation']);
            }

        }

        $this->structure['multiParent'] = array();

        if (count($this->multipleParent) > 0) {
            $dedup = [];
            foreach ($this->multipleParent as $key => $val) {
                $val = array_values(array_unique($val));
                $dedup["$key"] = $val;

                if (count($dedup["$key"]) > 1) {
                    $this->structure['multiParent']["$key"] = $dedup["$key"];
                }

            }

        }

    }


    /**
     * correctStructure
     *
     * there are complex relations that need correction during building table structures and relational data
     * elements with one child used mulitple times, or elements related to more than one element
     */
    protected function correctDbStructure()
    {

        foreach ($this->dbStructure['table'] as $key => $val) {

            $this->dbStructure['table']["$key"]['field'] = array_unique($val['field']);
            $this->dbStructure['table']["$key"]['node'] = array_unique($val['node']);

            if (isset($val['parent']) && !is_array($val['parent'])) {
                $this->dbStructure['table']["$key"]['parent'] = [];
            }

            if (!isset($this->dbStructure['table']["$key"]['relatedTable'])) {
                $this->dbStructure['table']["$key"]['relatedTable'] = [];
            } else {
                $this->dbStructure['table']["$key"]['relatedTable'] = array_unique($val['relatedTable']);
            }

        }

    }

    /**
     * setRelationType
     *
     * some elements have optional values, some elements and child elements can create field with merged names, like date, time or custom, or treated as possible table
     *
     * @param string $elemName
     * @param string $parentName
     * @return string
     */
    protected function setRelationType($elemName, $parentName = '')
    {
        $elemNameLower = strtolower($elemName);
        if ($this->fields[$elemName]['type'] == self::RELATION_TYPE_OPTION) {
            return self::RELATION_TYPE_OPTION;
        } elseif (strstr($elemNameLower, 'date') || strstr($elemNameLower, 'time')) {
            return self::RELATION_TYPE_MERGE_NODE;
        } elseif ($this->checkMerged == true && !empty($parentName) && !isset($this->fields[$elemName]['attlist']) && $this->checkMergedRelated($this->mergedNodeRelatedFields, $elemName, $parentName)) {
            return self::RELATION_TYPE_MERGE_NODE;
        } else {
            return self::RELATION_TYPE_TABLE;
        }
    }

    /**
     * checkIgnoredField
     *
     * recursive method - check if element set to be ignored
     *
     * @param string $elemName
     * @param string $parentName
     * @return bool
     */
    protected function checkIgnoredField($elemName, $parentName = '')
    {

        if ($this->hasIgnored == false) {
            return false;
        }

        if (empty($parentName)) {
            if (in_array($elemName, array_keys($this->ignoredFields))) {
                return true;
            }
        } else {

            foreach ($this->ignoredFields as $key => $val) {

                if ($key == $parentName) {

                    if (is_array($val)) {

                        return $this->checkIgnoredField($elemName);
                    } else {

                        return $elemName == $val;
                    }
                }
            }
        }

        return false;
    }

    /**
     * checkMergedRelated
     *
     * method for optimisation process
     * recursive method - check if table field is created merging parent-child element names defined through setup
     *
     * @param array $mergedArray
     * @param string $elemName
     * @param string $parentName
     * @return bool
     */
    protected function checkMergedRelated($mergedArray, $elemName = '', $parentName = '')
    {

        if (empty($parentName)) {

            if (in_array($elemName, $mergedArray)) {
                return true;
            }
        } else {

            foreach ($mergedArray as $key => $val) {

                if ($key == $parentName) {

                    if (is_array($val)) {

                        return $this->checkMergedRelated($mergedArray[$key], $elemName);
                    } else {
                        return $elemName == $val;
                    }
                }
            }
        }

        return false;
    }

    /**
     * prepareTableStructure
     *
     * recursive method - prepare table structure walking through created dtd structure (this is the first step, foreign keys are defined in the next step)
     *
     * @param array $node
     * @param string $parent
     * @param string $cond
     */
    protected function prepareTableStructure($node, $parent = '', $cond = '')
    {

        if (!empty($node)) {


            if (!$this->checkIgnoredTable($node)) {

                switch ($cond) {

                    case "table":

                        if (!empty($parent) && $parent == $this->rootElement && !isset($this->dbStructure['root_tag_table'][$parent])) {

                            if (!$this->checkIgnoredTable($node) && !isset($this->dbStructure['root_tag_table'][$node])) {

                                if ($this->isOptimisationTable($node) && isset($this->structure[$node]) && $this->structure[$node]['type'] == self::RELATION_TYPE_CONNECTOR) {
                                    $this->dbStructure['root_tag_table'][$node] = $this->structure[$node]['relation'][0];
                                } else {
                                    $this->dbStructure['root_tag_table'][$node] = $node;
                                }

                            }
                        }

                        $this->dbStructure['table'][$node]['type'] = $this->structure[$node]['type'];
                        $this->dbStructure['table'][$node]['field'] = $this->structure[$node]['field'];
                        $this->dbStructure['table'][$node]['node'] = $this->structure[$node]['field'];
                        $this->dbStructure['table'][$node]['attlist'] = $this->structure[$node]['attlist'];
                        $this->dbStructure['table'][$node]['parent'] = [];


                        if (!$this->checkIgnoredTable($node)) $this->dbStructure['tag_table'][$node] = $node;

                        if (isset($this->structure[$node]['relationType'])) {

                            foreach ($this->structure[$node]['relationType'] as $relt => $tbl) {
                                if ($tbl == self::RELATION_TYPE_TABLE) {
                                    $this->dbStructure['table'][$node]['relatedTable'][] = $relt;
                                }
                            }
                        }


                        if (empty($parent)) {

                            if (!$this->checkIgnoredTable($node)) $this->dbStructure['tag_table'][$node] = $node;

                        } elseif ($this->isOptimisationTable($node) && ($this->structure[$parent]['type'] == self::RELATION_TYPE_CONNECTOR || $this->verifyRootDataConnector($parent)) && !isset($this->fields[$parent]['attlist']) && !isset($this->structure['multiParent'][$node])) {

                            if (!$this->checkIgnoredTable($parent)) {
                                $this->dbStructure['tag_table'][$parent] = $node;

                                if( !isset($this->optimizedParentTable["$parent"]) ) {
                                    $this->optimizedParentTable["$parent"] = $parent;
                                }
                            }

                        } else {

                            if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $parent;

                        }


                        break;


                    case "mergeNode":


                        $mergeNode = [];

                        foreach ($this->structure[$node]['field'] as $val) {

                            $this->dbStructure['table'][$parent]['field'][] = $node . "_" . $val;
                            $mergeNode[] = $val;
                        }

                        if (count($mergeNode) > 0) {
                            $this->dbStructure['table'][$parent]["" . self::RELATION_TYPE_MERGE_NODE . ""][$node] = $mergeNode;
                        }

                        break;

                    case "option":

                        $this->dbStructure['table'][$parent]['field'][] = $node;
                        $this->dbStructure['table'][$parent]["" . self::RELATION_TYPE_OPTION . ""][$node] = $this->structure[$node]['field'];

                        break;
                }


            }


            foreach ($this->structure[$node]['relation'] as $val) {
                $this->prepareTableStructure($val, $node, $this->structure[$node]['relationType'][$val]);
            }
        }
    }

    /**
     * relationalTableStructure
     *
     * recursive method - second step in creation of table, add relations between tables, primary and foreign keys
     *
     * @param array $node
     * @param string $parent
     * @param string $cond
     */
    protected function relationalTableStructure($node, $parent = '', $cond = '', $parentType = self::RELATION_TYPE_FIELD_VALUE)
    {

        if (!empty($node)) {


            if (!$this->checkIgnoredTable($node)) {

                switch ($cond) {

                    case "table":
//print "TABLE: ".$node;
//Parser_Helper::nicePrint($this->structure[$node]['parent']);
                        if (isset($this->structure[$node]['parent']) && !empty($this->structure[$node]['parent'][0])) {

                            foreach ($this->structure[$node]['parent'] as $prt) {

                                if ($parentType != self::RELATION_TYPE_CONNECTOR) {

                                    $gpTblName = $this->getGrandParentDataConnectorTableName($prt);
                                    //print "<br>GRANDPA: ".$node.':|:'.$prt.':=:'.$gpTblName.'<br>';
                                    if (!empty($gpTblName)) {
                                        $pkey = 'z_' . $gpTblName . '_ID';
                                        $this->dbStructure['table'][$node]['parent']["$gpTblName"] = $pkey;
                                    } else {
                                        $pkey = 'z_' . $parent . '_ID';
                                        $this->dbStructure['table'][$node]['parent']["$parent"] = $pkey;
                                    }

                                    $this->pushToBeginning($pkey, $node);


                                } elseif (!$this->isOptimisationTable($node) || !$this->isOptimisationTable($parent)) {

                                    $pkey = 'z_' . $parent . '_ID';
                                    $this->dbStructure['table'][$node]['parent']["$parent"] = $pkey;
                                    $this->pushToBeginning($pkey, $node);

                                } elseif ($this->optimisation == false) {

                                    $pkey = 'z_' . $parent . '_ID';
                                    $this->dbStructure['table'][$node]['parent']["$parent"] = $pkey;
                                    $this->pushToBeginning($pkey, $node);

                                }

                            }

                        }

                        break;
                }


            } else {
                unset($this->dbStructure['table']["$node"]);
            }

            foreach ($this->structure[$node]['relation'] as $val) {
                $this->relationalTableStructure($val, $node, $this->structure[$node]['relationType'][$val], $this->structure[$node]['type']);
            }
        }
    }


    /**
     * @param $node
     * @param string $parent
     * @param string $cond
     * @param string $parentType
     */
    protected function optimizeTables($node, $parent = '', $cond = '', $parentType = self::RELATION_TYPE_FIELD_VALUE)
    {

        if (!$this->checkIgnoredTable($node)) {

            switch ($cond) {

                case "table":

                    if ( ($parentType == self::RELATION_TYPE_CONNECTOR || ($parentType == self::RELATION_TYPE_ROOT && $this->fields["$parent"]["type"] == self::RELATION_TYPE_CONNECTOR) ) && in_array($parent, $this->optimizedParentTable) && $this->structure[$parent]['relation'][0] == $node) {

                        $foundKey = array_search($parent, $this->optimizedParentTable, true);
                        unset($this->optimizedParentTable["$foundKey"]);

                        if(isset($this->dbStructure['inlineTableRelated'][$node])) {
                            $this->dbStructure['inlineTableRelated'][$parent] = $this->dbStructure['inlineTableRelated'][$node];
                            unset($this->dbStructure['inlineTableRelated'][$node]);
                        }

                        $parentFields = $this->dbStructure['table'][$parent]['field'];
                        $parentParent = $this->dbStructure['table'][$parent]['parent'];

                        $this->dbStructure['table'][$parent] = $this->dbStructure['table'][$node];

//                        if($parent == 'SESSIONS') {
////                            print_r($parentFields);
////                            print_r($parentParent);
//                            print "***NODE***";
//                            print_r($this->dbStructure['table'][$node]);
//                            print "***PARENT**";
//                            print_r($this->dbStructure['table'][$parent]);
//                            print "|||MERGE_1|||";
//                            print_r($parentFields);
//                            print "|||MERGE_2|||";
//                            print_r($this->dbStructure['table'][$parent]['field']);
//                        }
//                        exit;

                        $this->removeOptimizedChildKey[$node] = 'z_' . $node . '_ID';

                        if($parentType == self::RELATION_TYPE_ROOT && $this->fields["$parent"]["type"] == self::RELATION_TYPE_CONNECTOR) {

                        } else {
                            $this->dbStructure['table'][$parent]['field'] = array_merge($parentFields, $this->dbStructure['table'][$parent]['field']);
                        }

                        $this->dbStructure['table'][$parent]['parent'] = $parentParent;

                        $this->dbStructure['unset_table']['table'][$node] = $this->dbStructure['table'][$node];
                        $this->dbStructure['unset_table']['tag_table'][$node] = $this->dbStructure['tag_table'][$node];

                        $this->dbStructure['tag_table'][$parent] = $node;

                        unset($this->dbStructure['table'][$node]);
                        unset($this->dbStructure['tag_table'][$node]);

                    }

                    break;
            }
        }

        foreach ($this->structure[$node]['relation'] as $val) {
            //print $val.':'.$node.':'.$this->structure[$node]['relationType'][$val].':'.$this->structure[$node]['type'].'|';
            $this->optimizeTables($val, $node, $this->structure[$node]['relationType'][$val], $this->structure[$node]['type']);
        }
    }


    /**
     * @param string $parent
     * @return boolean
     */
    protected function getGrandParentDataConnectorTableName($parent)
    {

        if (!$this->isOptimisationTable($parent)) return '';

        if (isset($this->structure[$parent]['parent'])
            && !empty($this->structure[$parent]['parent'][0])
            && count($this->structure[$parent]['parent'] == 1)
        ) {

            $ptName = $this->structure[$parent]['parent'][0];

            if ($this->fields["$ptName"]['type'] == self::RELATION_TYPE_CONNECTOR) {
                return $ptName;
            }

        }

        return '';
    }

    /**
     * verify data type root to use as dataConnector
     *
     * @param string $parent
     * @return boolean
     */
    protected function verifyRootDataConnector($parent)
    {

        if (!isset($this->structure[$parent])) return false;

        $pName = strtolower($parent);

        if ($this->structure[$parent]['type'] == self::RELATION_TYPE_ROOT && isset($this->structure[$parent]['relation'][0])) {

            $nName = strtolower($this->structure[$parent]['relation'][0]);

            if (strstr($pName, $nName) && (strlen($pName) - strlen($nName) <= 2)) {

                if (isset($this->dbStructure['root_tag_table'][$parent])) {
                    $this->dbStructure['root_tag_table'][$parent] = $this->structure[$parent]['relation'][0];

                }

                return true;
            };

        }

        return false;
    }

    /**
     * correctTableStructure
     *
     * remove duplicate entry of elements in field and node definition
     */
    protected function correctTableStructure()
    {

        $removeTable = array_keys($this->removeOptimizedChildKey);

        foreach ($this->dbStructure['table'] as $key => $val) {

            foreach ($val as $id => $par) {

                if ($id == 'field' || $id == 'node' || $id == 'relatedTable') {

                    $this->dbStructure['table'][$key][$id] = array_unique($par);

                    if ($id == 'field') {
                        foreach ($par as $fld) {
                            if (in_array($fld, $this->removeOptimizedChildKey, true)) {
                                $foundKey = array_search('z_IMAGE_ID', $this->dbStructure['table']["$key"]["field"], true);
                                unset($this->dbStructure['table']["$key"]["field"]["$foundKey"]);
                            }
                        }
                    }

                } elseif ($id == 'parent') {

                    foreach ($removeTable as $tbl) {
                        unset($this->dbStructure['table']["$key"]["parent"]["$tbl"]);
                    }
                }
            }
        }

    }

    /**
     * pushToBeginning
     *
     * push the primary key to the beginning of the field definition
     *
     * @param string $elem
     * @param string $node
     */
    protected function pushToBeginning($elem, $node = '')
    {

        if (isset($this->dbStructure['table'][$node]['field']) && is_array($this->dbStructure['table'][$node]['field'])) {

            array_unshift($this->dbStructure['table'][$node]['field'], $elem);
        } else {

            $this->dbStructure['table'][$node]['field'][] = $elem;
        }

    }

    /**
     * checkPossibleTableSpecification
     *
     * @param string $string
     * @return bool
     */
    protected function checkPossibleTableSpecification($string)
    {
        $string = trim(Parser_Helper::removeInvalidChar($string));
        return $this->checkIfPossibleParent($string);
    }

    /**
     * checkIfPossibleParent
     *
     * @param string $string
     * @return bool
     */
    protected function checkIfPossibleParent($string)
    {

        $valueArray = array("PCDATA", "EMPTY");

        if (in_array($string, $valueArray)) {
            return false;
        }
        return true;
    }

    /**
     * checkEmpty
     *
     * @param string $string
     * @return bool
     */
    protected function checkEmpty($string)
    {
        $string = $this->prepareElement($string);
        return (!empty($string) && !strstr($string, 'version'));
    }

    /**
     * prepareElement
     *
     * @param string $string
     * @return string
     */
    protected function prepareElement($string)
    {

        $string = Parser_Helper::removeXmlBracket($string);
        $string = Parser_Helper::singularWhiteSpace($string);
        return trim($string);
    }

    /**
     * prepareAttlist
     *
     * @param string $string
     * @return string
     */
    protected function prepareAttlist($string)
    {

        $parts = [];
        $string = trim(Parser_Helper::removeXmlBracket($string));
        $exp = Parser_Helper::breakOnNewLine($string);

        foreach ($exp as $e) {
            $parts[] = Parser_Helper::singularWhiteSpace($e);
        }

        return $parts;
    }

    /**
     * checkIfAttlist
     *
     * @param string $line
     * @return bool
     */
    protected function checkIfAttlist($line)
    {
        return strstr($line, 'ATTLIST');
    }

    /**
     * declareAttribute
     *
     * @param string $string
     * @return array
     */
    protected function declareAttribute($string)
    {

        $attribute = [];

        $aType = Parser_Helper::returnBrackets($string);


        if (empty($aType)) {

            $attr = explode(" ", $string);

            $attribute['name'] = $attr[0];
            $attribute['type'] = $attr[1];
            $attribute['cardinality'] = $attr[2];
        } else {

            $string = str_replace($aType, "", $string);

            $attr = explode(" ", Parser_Helper::singularWhiteSpace($string));

            $attribute['name'] = $attr[0];
            $attribute['type'] = $aType;
            $attribute['cardinality'] = Parser_Helper::removeDoubleQuotes($attr[1]);
        }

        return $attribute;
    }

    /**
     * getBelongsToElement
     *
     * @param string $string
     * @return string
     */
    protected function getBelongsToElement($string)
    {

        $exp = explode(" ", $string);
        return $exp[1];
    }


    /**
     * unifyTableStructure
     *
     * @param array $prevTableStructure
     * @return array
     */
    public function unifyTableStructure($prevTableStructure = [])
    {

        $workingStructure = $this->getTableStructure();

        foreach ($workingStructure as $name => $stucture) {

            if (isset($prevTableStructure["$name"])) {

                foreach ($stucture['field'] as $val) {

                    if (!in_array($val, $prevTableStructure["$name"]['field'])) {
                        $prevTableStructure["$name"]['field'][] = $val;
                    }
                }

                $attListNames = Parser_Helper::getSubArrayValueArray($prevTableStructure["$name"]['attlist'], 'name');


                foreach ($stucture['attlist'] as $arr) {

                    if (!in_array($arr['name'], $attListNames)) {
                        $prevTableStructure["$name"]['attlist'][] = $arr;
                    }
                }

                unset($workingStructure["$name"]);

            }
        }

        return array_merge($prevTableStructure, $workingStructure);
    }

    /**
     * getFields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * getStructure
     *
     * @return array
     */
    public function getStructure()
    {
        return $this->structure;
    }

    /**
     * getTableStructure
     *
     * @return array
     */
    public function getTableStructure()
    {
        return $this->dbStructure['table'];
    }

    /**
     * getTableStructure
     *
     * @return array
     */
    public function getDatabaseStructure()
    {
        return $this->dbStructure;
    }

}