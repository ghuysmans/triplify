<?php
/**
 * Use this script to manually register your Triplify installation at the
 * Triplify registry.
 *
 * @version $Id$
 * @copyright 2008 Sören Auer (soeren.auer@gmail.com)
 */

include('config.inc.php');

$baseURI='http://'.$_SERVER['SERVER_NAME'].substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'/triplify')+9).'/';

$url='http://triplify.org/register/?url='.urlencode($baseURI).'&type='.urlencode($triplify['namespaces']['vocabulary']);
if($f=fopen($url,'r')) {
	echo fread($f,1000);
	fclose($f);
} else
	echo 'Please <a href="'.$url.'">register manually</a>!';
?>