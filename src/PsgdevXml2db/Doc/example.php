<?php
/**
 * Example of usage  - Abstract archives; 3 different XMLs parsed to create tables in one database that holds all of the abstract archive's data
 * User: Tibor
 * Date: 11/28/2016
 * Time: 4:29 PM
 */

namespace PsgdevXml2db;

use PsgdevXml2db\DTD_Parser;
use PsgdevXml2db\Xml_Parser;
use PsgdevXml2db\Parser_Helper;
use PsgdevMusqlidb\Musqlidb;

error_reporting(E_COMPILE_ERROR|E_RECOVERABLE_ERROR|E_ERROR|E_CORE_ERROR);

//ini_set('memory_limit','2040M');
ini_set('memory_limit', '-1');
set_time_limit(0);
ini_set("max_execution_time", 0);
ini_set("max_input_time", 0);

$appConnection = config('database.connections.mysql');
$xml2dbConnection = config('xml2db.databaseConnections.xml2db');

$abstractDir = storage_path('xml2db/abstract/');
$sessionDir = storage_path('xml2db/session/');
$personDir = storage_path('xml2db/person/');

/**
 *  ABSTRACT
 */

// parse abstract xml from zip - unzip the file, open the xml and find the related dtd file name and site url tag value
$getPath = glob($abstractDir . "*.zip");
print "Abstract Zip File:".$getPath[0];
if (!file_exists($getPath[0])) {
    die('Missing Abstract Zip.');
}

$unzip = shell_exec('cd ' . $abstractDir . ' && unzip ' . $getPath[0] . ' -d ' . $abstractDir);

// get the xml file
$getPath = glob($abstractDir . "*.xml");
$xmlFilePath = $getPath[0];
print "Abstract Xml File:".$xmlFilePath;
if (!file_exists($xmlFilePath)) {
    die('Missing Abstract Xml File.');
}

$ff = fopen($xmlFilePath, 'r');
$data = fread($ff, 1000);
fclose($ff);

preg_match('/<!DOCTYPE(.*?)>/', $data, $matched);
preg_match('/"([^"]+)"/', $matched[1], $doctype);
preg_match('/\<SITE_URL\>\<!\[CDATA\[(.*?)\]\]\><\/SITE_URL\>/', $data, $acDataMatch);

if (empty($doctype[1]) || empty($acDataMatch[1])) {
    die('Missing doctype or site url tag value.');
}

$dtdFilePath = $abstractDir . $doctype[1];

if (!file_exists($dtdFilePath)) {
    die('Missing dtd file.');
}

$databaseCode = $acDataMatch[1];
$newDbName = config('xml2db.parsedXmlDbNameFixPart') . $databaseCode;

// create database and add app user
$createDb = Musqlidb::getInstance($xml2dbConnection);

$sql = "DROP DATABASE IF EXISTS `$newDbName`";
$createDb->run($sql);
$sqlCDB = "CREATE DATABASE `$newDbName` CHARACTER SET utf8 COLLATE utf8_unicode_ci"; //IF NOT EXISTS
$createDb->run($sqlCDB);
if($createDb->isError()) {
    die($createDb->getError());
}
$sqlGrant = "GRANT CREATE, ALTER, DELETE, DROP, INDEX, INSERT, SELECT, UPDATE ON `$newDbName`.* TO '" . $appConnection['user'] . "'@'".$appConnection['host']."' IDENTIFIED BY '" . $appConnection['password'] . "'";
$createDb->run($sqlGrant);
if($createDb->isError()) {
    die($createDb->getError());
}
$createDb->run("FLUSH PRIVILEGES");

$sql = "CREATE TABLE IF NOT EXISTS `AC_DATA` (
		    `z_PRIMARY_KEY` int(10) unsigned NOT NULL AUTO_INCREMENT,
		    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		    `created_at` datetime DEFAULT NULL,
		    `SITE_URL` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
		    PRIMARY KEY (`z_PRIMARY_KEY`),
		    UNIQUE KEY `site_url_unique_X` (`SITE_URL`)
		  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

$createDb->selectDB($newDbName);
$createDb->run($sql);

if ($createDb->isError()) {
    die($newDbName . ' AC_DATA not created. ' . $createDb->getError());
}

// prepare database structure built from dtd relations
$prs = new DTD_Parser($dtdFilePath);
$prs->setIgnoredTable(array('AC_DATA'));
//	$prs->setMergedNodeRelatedFields(["SESSION_TRACK2USER_ROLE" => "ROLE_CLOSES"]); //, "SESSION" => array("SESSION_CREATOR", "SESSION_LOCATION")
$prs->run();

// parse xml
$xml = new Xml_Parser($xmlFilePath, $prs->getDatabaseStructure(), $newDbName);
$xml->setUTF8mb4Table(['BODY']);
$xml->setFieldTypeText(["IMAGES" => array("CAPTION", "FILE_LOCATION"), "BODY" => "TEXT", "SESSIONS" => array("SYMPOSIA_NAME", "SESSION_NOTES", "SESSION_NOTES_ADMIN"), "PRESENTATIONS" => array("TITLE", "DESC"), "SESSION_DETAILS" => "DATA", "AFFILIATIONS" => "DEPT"]);
$xml->systemTableFields = ["updated_at" => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "created_at" => "datetime DEFAULT NULL"];
$xml->systemTableFieldsDefaultValue = [];
$xml->createTable();

$xml->addTableIndex([
    //"ABSTRACTS" => ["STATUS"],
    "AUTHORS" => ["z_ABSTRACTS_ID"],
    "BODY" => ["z_ABSTRACTS_ID"],
    "KEYWORDS" => ["z_ABSTRACTS_ID"]
]);

$sql = "ALTER TABLE `BODY` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
$createDb->run($sql);

if ($createDb->IsError()) {
    die($newDbName . ' BODY collation alter error. ' . $createDb->getError());
}

$xml->parse();
print "end";

/**
 *  SESSION
 */