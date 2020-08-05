<?php
/*
 * This is the main Triplify script.
 *
 * @version $Id:$
 * @license LGPL
 * @copyright 2008 S�ren Auer (soeren.auer@gmail.com)
 */
error_reporting(E_ALL ^ E_NOTICE);

include('config.inc.php');

$serverURI='http://'.$_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT']!=80?':'.$_SERVER['SERVER_PORT']:'');
$baseURI=$serverURI.substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'/triplify')+9).'/';

if($_SERVER['REDIRECT_URL']) {
	$r=explode('/',rtrim(substr(strstr($_SERVER['REDIRECT_URL'],'/triplify/'),10),'/'));
	if(count($r)==2) {
		$instance=array_pop($r);
		$class=array_pop($r);
	} else if(count($r)==1)
		$class=array_pop($r);
	if(!$class || !$triplify['queries'][$class]) {
		header("HTTP/1.0 404 Not Found");
		echo("<h1>Error 404</h1>Resource not found!");
		exit;
	}
}

if($_REQUEST['t-output']=='json')
	header('Content-Type: text/javascript');
else
	header('Content-Type: text/turtle; charset=utf-8');

$cacheFile=$triplify['cachedir'].md5($_SERVER['REQUEST_URI']);
if(file_exists($cacheFile) && filemtime($cacheFile)>time()-$triplify['TTL']) {
	echo file_get_contents($cacheFile);
	exit;
}

if(!file_exists($triplify['cachedir'].'registered') && $triplify['register']) {
	$url='http://triplify.org/register/?url='.urlencode($baseURI).'&type='.urlencode($triplify['namespaces']['vocabulary']);
	if($f=fopen($url,'r'))
		fclose($f);
	touch($triplify['cachedir'].'registered');
}

$t=new tripleizer($triplify);
if($triplify['TTL'])
	ob_start();

$t->tripleize($triplify['queries'],$class,$instance);
if($_REQUEST['t-output']=='json')
	echo json_encode($t->json);

if($triplify['TTL'])
	file_put_contents($cacheFile,ob_get_contents());

/*
 *
 */
class tripleizer {
	var $maxResults;
	var $json=array();
	var $version='V0.3';
	/* Constructor
	 *
	 * @param	array	$config	Array of configuration parameters, which are explained in config.inc.php
	 */
	function tripleizer($config=array()) {
		if(is_object($config['db'])) {
			$this->pdo=$config['db'];
			$this->pdo->setAttribute(eval('return PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;'),true);
		}
		$this->config=$config;
		$this->ns=$config['namespaces'];
		$this->objectProperties=$config['objectProperties'];
		$this->classMap=$config['classMap'];
	}
	/*
	 * Transforms an SQL query (result) into NTriples
	 * The first column of the query result is used as instance identifier.
	 *
	 * @param       string  $query  an SQL query
	 * @param       string  $class  RDF class URI of the class the instances belong to
	 * @return      string  $id	Id of a specific entry
	 */
	function tripleize($queries,$c=NULL,$id=NULL) {
		static $typed;
		$self=$GLOBALS['serverURI'].$_SERVER['REQUEST_URI'];
		$this->writeTriple($self,$this->uri('owl:imports'),$this->ns['vocabulary']);
		$this->writeTriple($self,$this->uri('rdfs:comment'),'Generated by Triplify '.$this->version.' (http://Triplify.org)',true);
		if($this->config['license'])
			$this->writeTriple($self,'http://creativecommons.org/ns#license',$this->config['license']);
		if(is_array($this->config['metadata']))
			foreach($this->config['metadata'] as $property=>$value) if($value)
				$this->writeTriple($self,$this->uri($property),$value,true);
		foreach($queries as $class=>$q) if(!$c || $class==$c) {
			foreach(is_array($q)?$q:array($q) as $query) {
				$cols=($this->config['LinkedDataDepth']==1&&!$c)||($this->config['LinkedDataDepth']==2&&!$id)?'id':'*';
				$query="SELECT $cols FROM ($query) t WHERE 1";
				if($class && !$id && $_GET) {
					foreach($_GET as $key=>$v)
						if(substr($key,0,2)!='t-' && !strpos($key,'`'))
							$query.=' AND `'.$key.'`='.$this->dbQuote($v);
				}
				$start=is_numeric($_GET['t-start'])?$_GET['t-start']:0;
				$erg=is_numeric($_GET['t-results'])?($this->maxResults?min($_GET['t-results'],$this->maxResults):$_GET['t-results']):$this->maxResults;
				$query=$query.($id?' AND id='.$this->dbQuote($id):'').
					(preg_match('/^[A-Za-z0-9: ,]+?$/',$_GET['t-order'])?' ORDER BY '.$_GET['t-order']:'').
					($start||$erg?' LIMIT '.($start?$start.','.($erg?$erg:20):$erg):'');
				if($res=$this->dbQuery($query)) {
					$dtype=$this->dbDtypes($res);
					while($cl=$this->dbFetch($res))
						$this->makeTriples($cl,$class,$dtype);
				}
				$this->typed[$class]=true;
				if($cols=='id')
					break;
			}
		}
	}
	/**
	 * makeTriples creates a number of triples from a database row
	 *
	 * @param array $cl
	 * @param string $class
	 * @param array $dtypes
	 * @return
	 */
	function makeTriples($cl,$class,$dtypes) {
		$rdf_ns='http://www.w3.org/1999/02/22-rdf-syntax-ns#';
		$ipref=$this->uri($class.'/');
		$uri=array_shift($cl);
		$subject=$this->uri($uri,$ipref);
		if(!$uri)
			$uri=md5(join($cl));
		if($class && !$this->typed[$class])
			$this->writeTriple($subject,$rdf_ns.'type',$this->uri($this->classMap[$class]?$this->classMap[$class]:$class,$this->ns['vocabulary']));
		foreach($cl as $p=>$val) if($val && ($dtypes[$p]!='xsd:dateTime'||$val!='0000-00-00 00:00:00')) {
			if(strpos($p,'^^')) {
				$dtype=$this->uri(substr($p,strpos($p,'^^')+2));
				$p=substr($p,0,strpos($p,'^'));
			} else if($dtypes[$p]) {
				$dtype=$this->uri($dtypes[$p]);
			} else if(strpos($p,'@')) {
				$lang=strstr($p,'@');
				$p=substr($p,0,strpos($p,'@'));
			} else
				unset($dtype,$lang);

			if(strpos($p,'->')) {
				$objectProperty=substr(strstr($p,'->'),2);
				$p=substr($p,0,strpos($p,'->'));
			} else if(isset($this->objectProperties[$p])) {
				$objectProperty=$this->objectProperties[$p];
			} else
				unset($objectProperty);

			if($this->config['CallbackFunctions'][$p] && is_callable($this->config['CallbackFunctions'][$p]))
				$val=call_user_func($this->config['CallbackFunctions'][$p],$val);

			$prop=$this->uri($p,$this->ns['vocabulary']);
			if(isset($objectProperty)) {
				$isLiteral=false;
				$object=$this->uri($objectProperty.($objectProperty&&substr($objectProperty,-1,1)!='/'?'/':'').$val);
			} else {
				$isLiteral=true;
				$object=($dtypes[$p]=='xsd:dateTime'?str_replace(' ','T',$val):$val);
			}
			$this->writeTriple($subject,$prop,$object,$isLiteral,$dtype,$lang);
		}
		return $ret;
	}
	/**
	 * writeTriple - writes a triple in a certain output format
	 *
	 * @param string $subject	subject of the triple to be written
	 * @param string $predicate	predicate of the triple to be written
	 * @param string $object	object of the triple to be written
	 * @param boolean $isLiteral	boolean flag whether object is a literal, defaults to false
	 * @param string $dtype	datatype of a literal object
	 * @param string $lang	language of the literal object
	 * @return
	 */
	function writeTriple($subject,$predicate,$object,$isLiteral=false,$dtype=NULL,$lang=NULL) {
		if($_REQUEST['t-output']=='json') {
			$oa=array('value'=>$object,'type'=>($isLiteral?'literal':'uri'));
			if($isLiteral && $dtype)
				$oa['datatype']=$dtype;
			else if($isLiteral && $lang)
				$oa['language']=$lang;
			$this->json[$subject][$predicate]=($this->json[$subject][$predicate]?array_merge($this->json[$subject][$predicate],$oa):$oa);
		} else {
			if($isLiteral)
				$object='"'.str_replace(array('\\',"\r","\n",'"'),array('\\\\','\r','\n','\"'),$object).'"'.($dtype?'^^<'.$dtype.'>':$lang);
			else
				$object='<'.$object.'>';
			echo '<'.$subject.'> <'.$predicate.'> '.$object." .\n";
		}
	}
	/**
	 * tripleizer::uri()
	 *
	 * @param mixed $name
	 * @param mixed $default
	 * @return
	 */
	function uri($name,$default=NULL) {
		if(strstr($name,'://'))
			return $name;
		return (strpos($name,':')?$this->ns[substr($name,0,strpos($name,':'))].substr($name,strpos($name,':')+1):
			($default?$default:$GLOBALS['baseURI']).$name);
	}
	function dbQuote($string) {
		return $this->pdo?$this->pdo->quote($string):mysql_real_escape_string($string);
	}
	function dbQuery($query) {
		return $this->pdo?$this->pdo->query($query,eval('return PDO::FETCH_ASSOC;')):mysql_query($query);
	}
	function dbFetch($res) {
		return $this->pdo?$res->fetch():mysql_fetch_assoc($res);
	}
	function dbDtypes($res){
		if(method_exists($res,'getColumnMeta'))
			for($i=0;$i<$res->columnCount();$i++) {
				$meta=$res->getColumnMeta($i);
				if($meta['native_type']=='TIMESTAMP' || $meta['native_type']=='DATETIME')
					$dtype[$meta['name']]='xsd:dateTime';
			}
		else if(!$this->pdo) {
			for($i=0;$i<mysql_num_fields($res);$i++) {
				$type=mysql_field_type($res,$i);
				if(!strcasecmp($type,'timestamp') || !strcasecmp($type,'datetime'))
					$dtype[mysql_field_name($res,$i)]='xsd:dateTime';
			}
		}
		return $dtype;
	}
}
?>
