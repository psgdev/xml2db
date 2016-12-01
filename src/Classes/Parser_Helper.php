<?php

/**
 * Parser_Helper
 * helper for parsers
 *
 * @author Tibor(tibor@planetsg.com)
 * @version aa-v1.0
 */

namespace Psgdev\Xml2db;

class Parser_Helper
{

    /**
     * nicePrint
     *
     * @param array $array
     * @return string
     */
    public static function nicePrint($array = [])
    {
        echo '<pre>' . print_r($array, 1) . '</pre>';
    }


    /**
     * nicePrintXml
     *
     * @param string $string
     * @return string
     */
    public static function nicePrintXml($string = '')
    {
        echo htmlspecialchars($string);
    }


    /**
     * returnBracketsContent
     *
     * @param string $string
     * @return string
     */
    public static function returnBracketsContent($string = '')
    {
        //preg_match_all('/\((.*?)\)/', $string, $match);
        preg_match('/\(([^()]|(?R))*\)[\*\?\+]?/', $string, $match);
        return trim($match[0]);
    }


    /**
     *
     * @param string $string
     * @return string
     */
    public static function returnBrackets($string = '')
    {
        preg_match('/\(.*?\)/', $string, $match);
        return trim($match[0]);
    }


    /**
     * breakOnNewLine
     *
     * @param string $string
     * @return string
     */
    public static function breakOnNewLine($string = '')
    {
        return preg_split("/\r\n|\n|\r/", $string);
    }


    /**
     * removeXmlBracket
     *
     * @param string $string
     * @return string
     */
    public static function removeXmlBracket($string = '')
    {
        $ff = "<!"; //array("<!",">");
        $rr = "";
        return str_replace($ff, $rr, $string);
    }


    /**
     *
     * @param string $string
     * @return string
     */
    public static function removeInvalidChar($string = '')
    {
        return preg_replace("/[^A-Za-z0-9_\-]/", '', $string);
    }


    /**
     * removeInvalidCharExceptComma
     *
     * @param string $string
     * @return string
     */
    public static function removeInvalidCharExceptComma($string = '')
    {
        return preg_replace("/[^A-Za-z0-9_\-\,]/", '', $string);
    }


    /**
     * removeInvalidCharExceptRule
     *
     * @param string $string
     * @return string
     */
    public static function removeInvalidCharExceptRule($string = '')
    {

        $string = str_replace('|', ',', $string);
        return preg_replace("/[^A-Za-z0-9_\-\,\*\?\+]/", '', $string);
    }


    /**
     * singularWhiteSpace
     *
     * @param string $string
     * @return string
     */
    public static function singularWhiteSpace($string = '')
    {
        return preg_replace('/\s+/S', ' ', trim($string));
    }


    /**
     *
     * @param string $string
     * @return string
     */
    public static function singularHorizontalSpace($string = '')
    {
        return preg_replace('/\h+/', ' ', trim($string));
    }


    /**
     * removeDoubleQuotes
     *
     * @param string $string
     * @return string
     */
    public static function removeDoubleQuotes($string = '')
    {
        return str_replace('"', '', $string);
    }


    /**
     * checkPossibleMultiOptionalValue
     *
     * @param string $string
     * @return boolean
     */
    public static function checkPossibleMultiOptionalValue($string)
    {
        $ff = array("(", ")"); //array("<!",">");
        $rr = "";
        $string = trim(str_replace($ff, $rr, $string));

        if (!strstr($string, ',') && strstr($string, '|'))
            return true;

        return false;
    }


    /**
     * isMultiple
     *
     * @param string $string
     * @return boolean
     */
    public static function isMultiple($string = '')
    {
        return (strstr($string, '*') || strstr($string, '+'));
    }

    /**
     * getSubArrayValueArray
     *
     * @param array $array
     * @param string $fieldName
     * @return array
     */
    public static function getSubArrayValueArray($array = [], $fieldName = '')
    {
        $ret = [];
        foreach ($array as $key => $val) {
            $ret[] = $val["$fieldName"];
        }

        return $ret;
    }


}
