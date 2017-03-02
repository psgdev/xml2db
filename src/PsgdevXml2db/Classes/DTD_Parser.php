<?php

/**
 * DTD_Parser
 * parse DTD and prepare structure for tables and parsing xml file with XML_Parser
 *
 * @author Tibor(tibor@planetsg.com)
 * @version aa-v1.0
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
    protected $ignoredFields = [];
    protected $hasIgnored = false;
    protected $checkMerged = false;
    protected $rootElement = '';
    protected $dbStructure = [];
    protected $dtdTable = [];
    protected $multipleParent = [];
    protected $tempMulti = [];
    protected $ignoredTable = []; //ignore tables - tables will not be filled, be carefull about relations between tables

    /**
     * public var
     */
    public $optimisation = true;

    /**
     * constructor
     *
     * loads the dtd file and breaks to lines
     *
     * @param string $filePath
     */
    public function __construct($filePath)
    {

        $string = file_get_contents($filePath);
        //print htmlspecialchars($string);

        $exp = explode(">", $string);

        $this->lines = array_filter($exp, array($this, 'checkEmpty'));
        //echo Parser_Helper::nicePrint(htmlspecialchars($this->lines[0]));
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
    public function checkIgnoredTable($table)
    {
        if (empty($table) || empty($this->ignoredTable)) return false;

        if (in_array($table, $this->ignoredTable)) return true;

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
                $this->fields[$exp[1]]['type'] = $this->checkSpecification($elementDeclaration);
                $this->fields[$exp[1]]['spec'] = Parser_Helper::removeInvalidCharExceptRule(str_replace(" | ", ",", $elementDeclaration)); // don't care about possible options, you need all anyway
                $this->fields[$exp[1]]['desc'] = $elementDeclaration;
            }
        }

        $this->rootElement = key($this->fields);

        $this->checkForTable($this->rootElement);
        $this->dtdTable = array_unique($this->dtdTable);

        $this->buildStructure($this->rootElement);
        $this->correctStructure();

        //if(!$this->checkIgnored($this->rootElement)) {
        $this->prepareTableStructure($this->rootElement);
        //echo Parser_Helper::nicePrint($this->dbStructure);
        $this->relationalTableStructure($this->rootElement);
// 	} else {
//
// 			$this->prepareTableStructure($this->rootElement, '', self::RELATION_TYPE_ROOT);
// 			$this->relationalTableStructure($this->rootElement, '', self::RELATION_TYPE_ROOT);
// 	}

        $this->correctTableStructure();

        unset($this->dbStructure['unset_table']);
    }

    /**
     * checkSpecification
     *
     * resolve possible type after checking the element declaration
     *
     * @param string $elementDeclaration
     * @return mixed
     */
    protected function checkSpecification($elementDeclaration)
    {

        if ($this->checkPossibleTableSpecification($elementDeclaration)) {

            if (Parser_Helper::checkPossibleMultiOptionalValue($elementDeclaration)) {
                return self::RELATION_TYPE_OPTION;
            }

            if (!strstr($elementDeclaration, ',')) {
                return self::RELATION_TYPE_CONNECTOR;
            }

            return 'fieldList';
        }

        return 'value';
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


            foreach ($exp as $e) {

                $elem = Parser_Helper::removeInvalidChar($e);

                if (Parser_Helper::isMultiple($e)) {
                    //print $elem;
                    if (!in_array($elem, $this->dtdTable))
                        $this->dtdTable[] = $elem;
                }

                //if($this->fields[$elem]['type'] != 'value') {
                $this->checkForTable($elem, $node);
                //}
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

            $this->structure[$node]['attlist'] = [];
            if (isset($this->fields[$node]['attlist'])) {
                foreach ($this->fields[$node]['attlist'] as $val) {

                    $this->structure[$node]['attlist'][] = array('name' => $val['name'], 'type' => $val['type']);
                }
            }

            if (!empty($parent)) {
                $this->tempMulti[$node][] = $parent;
            }

            if ($node == $this->rootElement) {

                if (!$this->checkIgnoredTable($node)) {
                    $this->dbStructure['root_tag_table'][$node] = $node;
                    //$this->structure[$node]['type'] = $this->fields[$node]['type'];
                } else {
                    //$this->structure[$node]['type'] = self::RELATION_TYPE_ROOT;
                }

                $this->structure[$node]['type'] = self::RELATION_TYPE_ROOT;

            } else {
                $this->structure[$node]['type'] = $this->fields[$node]['type'];
            }


            if ($parent == $this->rootElement) {
                $this->structure[$node]['parent'] = $this->checkIgnoredTable($parent) ? '' : $parent;
            } else {
                $this->structure[$node]['parent'] = $parent;
            }

            $this->structure[$node]['data_type'] = self::DATA_TYPE_BLOCK;


            $exp = explode(',', $this->fields[$node]['spec']);
            $exp = array_unique($exp);

            foreach ($exp as $e) {

                $elem = Parser_Helper::removeInvalidChar($e);

                if ($this->checkIfPossibleParent($elem)) {

                    if ($this->fields[$elem]['type'] != 'value') {
                        //print $elem."::".$this->fields[$elem]['type'].'<br>';
                        if (!$this->checkIgnored($elem, $node)) {

                            $this->structure[$elem]['many'] = Parser_Helper::isMultiple($e);

                            $this->structure[$node]['relationType'][$elem] = $this->structure[$elem]['many'] ? self::RELATION_TYPE_TABLE : $this->setRelationType($elem, $node);
                            $this->structure[$node]['relation'][] = $elem;
                            $this->structure[$node]['data_type'] = self::DATA_TYPE_MIXED;
                            $this->buildStructure($elem, $node);
                        }

                    } else {

                        if ($this->fields[$node]['type'] == self::RELATION_TYPE_CONNECTOR) {
                            if (Parser_Helper::isMultiple($this->fields[$node]['spec'])) {
                                $this->structure[$node]['data_type'] = self::DATA_TYPE_MULTI_ROW;

                                if (!isset($this->structure[$node]['attlist']) || !is_array($this->structure[$node]['attlist'])) {
                                    $this->structure[$node]['attlist'] = [];
                                }
                            }
                        }


                        if (!$this->checkIgnored($elem, $node)) {

                            if (Parser_Helper::isMultiple($e)) {

                                $this->structure[$elem]['many'] = true;
                                $this->structure[$node]['relationType'][$elem] = self::RELATION_TYPE_TABLE;
                                $this->structure[$node]['relation'][] = $elem;
                                $this->structure[$node]['data_type'] = self::DATA_TYPE_MIXED;

                                $this->multipleParent[$elem][] = $node;
                            } else {

                                if (!in_array($elem, $this->dtdTable)) {

                                    $this->structure[$node]['field'][] = $elem;
                                } else {
                                    $this->structure[$elem]['many'] = false; // was true, jan2016
                                    $this->structure[$node]['relationType'][$elem] = self::RELATION_TYPE_TABLE;
                                    $this->structure[$node]['relation'][] = $elem;
                                    $this->structure[$node]['data_type'] = self::DATA_TYPE_MIXED;

                                    $this->multipleParent[$elem][] = $node;
                                }
                            }
                        }
                    }
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
    protected function correctStructure()
    {

        foreach ($this->structure as $key => $val) {

            if (!isset($val['field']) && !isset($val['type'])) {
                $this->structure[$key]['type'] = 'fieldList';
                $this->structure[$key]['field'][] = $key;
                $this->structure[$key]['data_type'] = self::DATA_TYPE_MULTI_ROW;

                if (!isset($this->structure[$key]['attlist']) || !is_array($this->structure[$key]['attlist'])) {
                    $this->structure[$key]['attlist'] = [];
                }

            }
        }

        if (count($this->multipleParent) > 0) {
            $dedup = [];
            foreach ($this->multipleParent as $key => $val) {
                $val = array_values(array_unique($val));
                $dedup[$key] = $val;
            }
            $this->multipleParent = $dedup;
        }

        $this->structure['multiParent'] = $this->multipleParent;

        foreach ($this->tempMulti as $key => $val) {

            if (count($val) > 1 && !isset($this->structure['multiParent'][$key])) {
                $this->structure['multiParent'][$key] = $val;
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
     * checkIgnored
     *
     * recursive method - check if element set to be ignored
     *
     * @param string $elemName
     * @param string $parentName
     * @return bool
     */
    protected function checkIgnored($elemName, $parentName = '')
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

                        return $this->checkIgnored($elemName);
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
    protected function prepareTableStructure($node, $parent = '', $cond = 'table')
    {

        if (!empty($node)) {


            if (!$this->checkIgnoredTable($node)) {

                switch ($cond) {

                    case "table":

                        if (!empty($parent) && $parent == $this->rootElement && !isset($this->dbStructure['root_tag_table'][$parent])) {

                            if (!$this->checkIgnoredTable($node) && !isset($this->dbStructure['root_tag_table'][$node])) {

                                if ($this->optimisation == true && isset($this->structure[$node]) && $this->structure[$node]['type'] == self::RELATION_TYPE_CONNECTOR) {
                                    $this->dbStructure['root_tag_table'][$node] = $this->structure[$node]['relation'][0];
                                } else {
                                    $this->dbStructure['root_tag_table'][$node] = $node;
                                }

                            }
                        }

                        $this->dbStructure['table'][$node]['type'] = $this->structure[$node]['type'];
                        $this->dbStructure['table'][$node]['data_type'] = $this->structure[$node]['data_type'];
                        $this->dbStructure['table'][$node]['field'] = $this->structure[$node]['field'];
                        $this->dbStructure['table'][$node]['node'] = $this->structure[$node]['field'];
                        $this->dbStructure['table'][$node]['attlist'] = $this->structure[$node]['attlist'];

                        //if (!isset($this->dbStructure['root_tag_table'][$node]))
                        if (!$this->checkIgnoredTable($node)) $this->dbStructure['tag_table'][$node] = $node;

                        if (isset($this->structure[$node]['relationType'])) {

                            foreach ($this->structure[$node]['relationType'] as $relt => $tbl) {
                                if ($tbl == self::RELATION_TYPE_TABLE) {
                                    $this->dbStructure['table'][$node]['relatedTable'][] = $relt;
                                }
                            }
                        }

                        if ($this->dbStructure['table'][$node]['data_type'] == self::DATA_TYPE_MULTI_ROW) {

                            //if (!isset($this->dbStructure['root_tag_table'][$parent]))
                            if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $parent;

                        } elseif ($this->dbStructure['table'][$node]['data_type'] == self::DATA_TYPE_BLOCK) {

                            if ($this->optimisation == true && $this->structure[$parent]['type'] == self::RELATION_TYPE_CONNECTOR && $this->structure[$parent]['data_type'] == self::DATA_TYPE_MIXED && !isset($this->fields[$parent]['attlist']) && !isset($this->structure['multiParent'][$node])) {
                                // if (!isset($this->dbStructure['root_tag_table'][$parent]))
                                if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $node;
                            } else {
                                //if (!isset($this->dbStructure['root_tag_table'][$parent]))
                                if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $parent;
                            }
                        } else {

                            if ($this->structure[$node]['type'] != self::RELATION_TYPE_CONNECTOR) {
//print $node."::".$parent.'<br>';
                                if (empty($parent)) {

                                    if (!$this->checkIgnoredTable($node)) $this->dbStructure['tag_table'][$node] = $node;

                                } elseif ($this->optimisation == true && ( $this->structure[$parent]['type'] == self::RELATION_TYPE_CONNECTOR || $this->verifyRootDataConnector($parent) ) && !isset($this->fields[$parent]['attlist']) && !isset($this->structure['multiParent'][$node])) {
                                    //if (!isset($this->dbStructure['root_tag_table'][$parent]))
                                    if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $node;

                                } else {
                                    //if (!isset($this->dbStructure['root_tag_table'][$parent]))
                                    if (!$this->checkIgnoredTable($parent)) $this->dbStructure['tag_table'][$parent] = $parent;

                                }
                            }
                        }


                        break;


                    case "mergeNode":


                        $mergeNode = [];

                        foreach ($this->structure[$node]['field'] as $val) {

                            $this->dbStructure['table'][$parent]['field'][] = $node . "_" . $val;
                            $mergeNode[] = $val;
                        }

                        //if(count($this->dbStructure['table'][$parent]['field']) > 0) {
                        if (count($mergeNode) > 0) {
                            $this->dbStructure['table'][$parent]["" . self::RELATION_TYPE_MERGE_NODE . ""][$node] = $mergeNode;
                        }
                        //}

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
    protected function relationalTableStructure($node, $parent = '', $cond = 'table')
    {


        if (!empty($node)) {


            if (!$this->checkIgnoredTable($node)) {

                switch ($cond) {

// 		case "root":
//
// 		    break;

                    case "table":


                        if ($this->structure[$node]['type'] != self::RELATION_TYPE_CONNECTOR && $this->structure[$node]['data_type'] != self::DATA_TYPE_MULTI_ROW
                            && ( $this->structure[$parent]['type'] == self::RELATION_TYPE_CONNECTOR || $this->verifyRootDataConnector($parent) )
                            && !isset($this->structure['multiParent'][$node])
                        ) {
// tables set as fieldList and dataMixed or dataBlock
//print $node.'::'.$parent.'<br>';
                            if ($this->optimisation == true && !isset($this->fields[$parent]['attlist'])) {

                                $this->dbStructure['table'][$parent] = $this->dbStructure['table'][$node];
//print 'parent: '.$this->structure[$parent]['parent'].'<br>';
                                if (!empty($this->structure[$parent]['parent'])) {
                                    $connParent = $this->structure[$parent]['parent'];

                                    $connParentParent = $this->structure[$connParent]['parent'];
                                    $connParentTop = $this->structure[$connParentParent]['parent'];

                                    if (empty($connParentParent) && empty($connParentTop)) {
                                        $pkey = "z_" . $connParent . "_ID";
                                        $this->pushToBeginning($pkey, $parent);
                                        $this->dbStructure['table'][$parent]['parent']["" . $this->structure[$parent]['parent'] . ""] = $pkey;
//print '<br>tree:'.$connParent.'::'.$connParentParent.'::'.$connParentTop.'|<br>';
                                    } elseif ($this->structure[$connParentTop]['type'] == self::RELATION_TYPE_CONNECTOR) {
                                        $pkey = "z_" . $this->structure[$connParentParent]['parent'] . "_ID";
                                        $this->pushToBeginning($pkey, $parent);
                                        $this->dbStructure['table'][$parent]['parent']["" . $this->structure[$connParentParent]['parent'] . ""] = $pkey;
                                    } else {
                                        $pkey = "z_" . $this->structure[$connParent]['parent'] . "_ID";
                                        //print 'key: '.$pkey.'<br>';
                                        $this->pushToBeginning($pkey, $parent);
                                        $this->dbStructure['table'][$parent]['parent']["" . $this->structure[$connParent]['parent'] . ""] = $pkey;
                                    }
                                }

                                $this->dbStructure['unset_table']['table'][$node] = $this->dbStructure['table'][$node];
                                $this->dbStructure['unset_table']['tag_table'][$node] = $this->dbStructure['tag_table'][$node];

                                unset($this->dbStructure['table'][$node]);
                                unset($this->dbStructure['tag_table'][$node]);
                            } else {

                                $connParent = $this->structure[$node]['parent'];

                                $pkey = "z_" . $connParent . "_ID";
                                $this->pushToBeginning($pkey, $node);
                                $this->dbStructure['table'][$node]['parent']["" . $connParent . ""] = $pkey;

                                if (!empty($this->structure[$parent]['parent'])) {
//print "<p>UUU".$node."::".$parent."</p>";
                                    $connParentParent = $this->structure[$parent]['parent'];

                                    $pkey = "z_" . $connParentParent . "_ID";
                                    $this->pushToBeginning($pkey, $parent);
                                    $this->dbStructure['table'][$parent]['parent']["" . $connParentParent . ""] = $pkey;


                                    if ($this->optimisation == true && !empty($this->structure[$connParentParent]['parent']) && $this->structure[$connParentParent]['type'] == self::RELATION_TYPE_CONNECTOR) {

                                        $connParentTop = $this->structure[$connParentParent]['parent'];

                                        if (isset($this->dbStructure['unset_table']['table']["" . $this->structure[$connParentParent]['parent'] . ""])) {

                                            $connParentTop = $this->structure["" . $this->structure[$connParentParent]['parent'] . ""]['parent'];
                                        }

                                        $pkey = "z_" . $connParentTop . "_ID";
                                        $this->pushToBeginning($pkey, $connParentParent);
                                        $this->dbStructure['table'][$connParentParent]['parent']["" . $connParentTop . ""] = $pkey;
                                    }
                                }
                            }
                        } elseif ($this->structure[$node]['type'] != self::RELATION_TYPE_CONNECTOR && $this->structure[$node]['data_type'] != self::DATA_TYPE_MULTI_ROW && !empty($this->structure[$node]['parent'])) {


                            $mParent = [];

                            if (isset($this->structure['multiParent'][$node])) {
                                $mParent = $this->structure['multiParent'][$node];
                            } elseif (isset($this->structure[$node]['parent'])) {
                                $mParent[] = $this->structure[$node]['parent'];
                            }

                            if (count($mParent) > 0) {

                                foreach ($mParent as $connParent) {

                                    if ($connParent == $parent) {

                                        $connParentParent = $this->structure[$connParent]['parent'];

                                        if ($this->optimisation == true && $this->structure[$connParentParent]['type'] == self::RELATION_TYPE_CONNECTOR) {
                                            $pkey = "z_" . $connParentParent . "_ID";
                                            $this->pushToBeginning($pkey, $node);
                                            $this->dbStructure['table'][$node]['parent']["" . $connParentParent . ""] = $pkey;
                                        } else {
                                            $pkey = "z_" . $connParent . "_ID";
                                            $this->pushToBeginning($pkey, $node);
                                            $this->dbStructure['table'][$node]['parent']["" . $connParent . ""] = $pkey;
                                        }
                                    }
                                }
                            }
                        } elseif ($this->structure[$node]['data_type'] == self::DATA_TYPE_MULTI_ROW) {

                            $mParent = [];

                            if (isset($this->structure['multiParent'][$node])) {
                                $mParent = $this->structure['multiParent'][$node];
                            } elseif (!empty($this->structure[$node]['parent'])) {
                                $mParent[] = $this->structure[$node]['parent'];
                            }

                            if (count($mParent) > 0) {

                                foreach ($mParent as $connParent) {

                                    if ($connParent == $parent) {

                                        if (isset($this->dbStructure['unset_table']['table'][$parent])) {

                                            $connParentParent = $this->structure[$parent]['parent'];
                                            $pkey = "z_" . $connParentParent . "_ID";
                                            $this->pushToBeginning($pkey, $node);
                                            $this->dbStructure['table'][$node]['parent']["" . $connParentParent . ""] = $pkey;
                                        } else {

                                            if ($this->optimisation == true && $this->structure[$parent]['type'] == self::RELATION_TYPE_CONNECTOR && count($mParent) == 1) {

                                                $this->dbStructure['table'][$parent] = $this->dbStructure['table'][$node];

                                                if (!empty($this->structure[$parent]['parent'])) {

                                                    $connParent = $this->structure[$parent]['parent'];

                                                    $connParentParent = $this->structure[$connParent]['parent'];
                                                    $connParentTop = $this->structure[$connParentParent]['parent'];

                                                    if ($this->structure[$connParentTop]['type'] == self::RELATION_TYPE_CONNECTOR) {
                                                        $pkey = "z_" . $this->structure[$connParentParent]['parent'] . "_ID";
                                                        $this->pushToBeginning($pkey, $parent);
                                                        $this->dbStructure['table'][$parent]['parent']["" . $this->structure[$connParentParent]['parent'] . ""] = $pkey;
                                                    } else {
                                                        $pkey = "z_" . $this->structure[$connParent]['parent'] . "_ID";
                                                        $this->pushToBeginning($pkey, $parent);
                                                        $this->dbStructure['table'][$parent]['parent']["" . $this->structure[$connParent]['parent'] . ""] = $pkey;
                                                    }
                                                }


                                                $this->dbStructure['unset_table']['table'][$node] = $this->dbStructure['table'][$node];
                                                $this->dbStructure['unset_table']['tag_table'][$node] = $this->dbStructure['tag_table'][$node];

                                                unset($this->dbStructure['table'][$node]);
                                                unset($this->dbStructure['tag_table'][$node]);
                                            } else {

                                                $pkey = "z_" . $connParent . "_ID";
                                                $this->pushToBeginning($pkey, $node);
                                                $this->dbStructure['table'][$node]['parent']["" . $connParent . ""] = $pkey;
                                            }
                                        }
                                    }
                                }
                            }
                        } elseif ($this->structure[$node]['type'] == self::RELATION_TYPE_CONNECTOR && !empty($this->structure[$node]['parent'])) {
//print '4st:'.$node.'::'.$parent.'<br>';
                            //print_r($this->dbStructure['tag_table']);
                            $connParent = $this->structure[$node]['parent'];
                            $connParentParent = $this->structure[$connParent]['parent'];
                            $parentPossible = array_keys($this->dbStructure['tag_table'], $connParent);
                            $parentPossibleDataConnnector = $parentPossible[0];

                            if (!empty($connParentParent) && !empty($parentPossibleDataConnnector) && $this->structure[$connParentParent]['type'] == self::RELATION_TYPE_CONNECTOR && $connParent !== $parentPossibleDataConnnector) {
                                //print "<p>$node$parentPossibleDataConnnector</p>";
                                $pkey = "z_" . $parentPossibleDataConnnector . "_ID";
                                $this->pushToBeginning($pkey, $node);
                                $this->dbStructure['table'][$node]['parent']["" . $parentPossibleDataConnnector . ""] = $pkey;

                            } else {
                                $pkey = "z_" . $connParent . "_ID";
                                $this->pushToBeginning($pkey, $node);
                                $this->dbStructure['table'][$node]['parent']["" . $connParent . ""] = $pkey;
                            }

                        }


                        break;
                }
            } else {
                unset($this->dbStructure['table']["$node"]);
            }

            foreach ($this->structure[$node]['relation'] as $val) {
                $this->relationalTableStructure($val, $node, $this->structure[$node]['relationType'][$val]);
            }
        }
    }

    /**
     * verify data type root to use as dataConnector
     *
     * @param string $parent
     * @return string
     */
    protected function verifyRootDataConnector($parent)
    {

        if( !isset($this->structure[$parent]) ) return false;
        //print_r($this->structure[$parent]['relation'][0]);
        $pName = strtolower($parent);

        if ( $this->structure[$parent]['type'] == self::RELATION_TYPE_ROOT && isset($this->structure[$parent]['relation'][0]) ) {

            $nName = strtolower($this->structure[$parent]['relation'][0]);

            if( strstr($pName, $nName) && ( strlen($pName) - strlen($nName) <= 2) ) {
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

        foreach ($this->dbStructure['table'] as $key => $val) {

            foreach ($val as $id => $par) {

                if ($id == 'field' || $id == 'node') {

                    $this->dbStructure['table'][$key][$id] = array_unique($par);
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