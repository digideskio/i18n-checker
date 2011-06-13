<?php
require_once('class.N11n.php');
require_once('class.Parser.php');
require_once('class.Information.php');
require_once('class.Report.php');

class Checker {

	private static $logger;
	private $curl_info;
	private $markup;
	private $doc;
	
	public static function init() {
		self::$logger = Logger::getLogger('Checker');
	}
	
	public function __construct($curl_info, $markup) {
		$this->markup = $markup;
		$this->curl_info = $curl_info;
	}
	
	public function checkDocument() {
		
		// Do that first !
		$this->addInfoCharsetBom();
		
		// Instantiate parser
		try {
			$this->doc = Parser::getParser($this->markup, isset($this->curl_info['content_type']) ? $this->curl_info['content_type'] : null);
		} catch (Exception $e) {
			Message::addMessage(MSG_LEVEL_ERROR, 'Exception: '.$e->getMessage());
			self::$logger->error('Exception raised for URI: '.$this->curl_info['url'], $e);
			return false;
		}
		
		// Gather information
		$this->addInfoDTDMimetype();
		$this->addInfoCharsetHTTP();
		
		// TODO: how about issuing a warning/comment if xml:lang is found in a non-xml doc?
		if ($this->doc->isXML() || $this->doc->mimetypeFromHTTP() == 'application/xhtml+xml')
			$this->addInfoCharsetXMLDeclaration();
		$this->addInfoCharsetMeta();
		$this->addInfoLangAttr();
		//if ($this->doc->isXML())
			$this->addInfoXMLLangAttr();
		$this->addInfoLangHTTP();
		$this->addInfoLangMeta();
		$this->addInfoDirHTML();
		$this->addInfoClassId();
		$this->addInfoRequestHeaders();
		
		// Generate report
		$this->addReportCharsets();
		return true;
	}
	
	private function addInfoDTDMimetype() {
		if ($this->doc->isXHTML()) {
			$dtd = 'XHTML';
		} elseif ($this->doc->isHTML()) {
			$dtd = 'HTML';
		} elseif ($this->doc->isXHTML5()) {
			$dtd = 'XHTML5';
		} elseif ($this->doc->isHTML5()) {
			$dtd = 'HTML5';
		} else {
			$dtd = 'NA';
		}
		Information::addInfo(null, 'dtd', null, $dtd);
		Information::addInfo(null, 'mimetype', null, $this->doc->mimetypeFromHTTP());
	}
	
	// INFO: CHARSET FROM HTTP CONTENT-TYPE HEADER
	private function addInfoCharsetHTTP() { 
		$category = 'charset_category';
		$title = 'charset_http';
		$_code = 'Content-Type: '.$this->curl_info['content_type'];
		$_val = strtoupper($this->doc->charsetFromHTTP());
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'charset_none_found';
		if ($_code == null && $_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: BYTE ORDER MARK.
	private function addInfoCharsetBom() {
		$category = 'charset_category';
		$title = 'charset_bom';
		$value = null;
		$display_value = null;
		$filestart = substr($this->markup,0,3);
		if (ord($filestart{0})== 239 && ord($filestart{1})== 187 && ord($filestart{2})== 191) 
			$bom = 'UTF-8';
		else { 
			$filestart = substr($this->markup,0,2);
			if (ord($filestart{0})== 254 && ord($filestart{1})== 255)
				$bom = 'UTF-16BE';
			elseif (ord($filestart{0})== 255 && ord($filestart{1})== 254)
				$bom = 'UTF-16LE';
		}
		if (isset($bom)) {
			// Convert to UTF-8
			if ($bom == 'UTF-16LE')
				$this->markup = mb_convert_encoding($this->markup, 'UTF-8', 'UTF-16LE');
			elseif ($bom == 'UTF-16BE')
				$this->markup = mb_convert_encoding($this->markup, 'UTF-8', 'UTF-16BE');
			$value = array ('code' => "Byte-order mark: {$bom}", 'values' => $bom);
		} else {
			$display_value = 'val_no';
		}
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: CHARSET FROM XML DECLARATION
	private function addInfoCharsetXMLDeclaration() {
		$category = 'charset_category';
		$title = 'charset_xml';
		$_code = $this->doc->XMLDeclaration();
		$_val = $this->doc->charsetFromXML();
		$value = null;
		if ($_code != null && $_val != null)
			$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'charset_val_none';
		if ($_code == null && $_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: CHARSET FROM META CONTENT-TYPE OR META CHARSET (HTML5)
	private function addInfoCharsetMeta() {
		$category = 'charset_category';
		$title = 'charset_meta';
		if ($this->doc->isHTML5() || $this->doc->isXHTML5())
			$title = 'charset_meta_html5';
		$value = $this->doc->charsetsFromHTML();
		$display_value = null;
		$vals = Utils::valuesFromValArray($value);
		if (empty($vals)) {
			$codes = Utils::codesFromValArray($value);
			if (empty($codes))
				$display_value = 'val_none_found';
			else
				$display_value = 'charset_none_found';
		}
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM HTML LANG ATTRIBUTE
	private function addInfoLangAttr() {
		$category = 'lang_category';
		$title = 'lang_attr_lang';
		$_code = $this->doc->HTMLTag();
		$_val = $this->doc->langFromHTML();
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'val_none';
		if ($_code == null && $_val == null)
			$display_value = 'no_html_tag_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM HTML XML:LANG ATTRIBUTE
	private function addInfoXMLLangAttr() {
		$category = 'lang_category';
		$title = 'lang_attr_xmllang';
		$_code = $this->doc->HTMLTag();
		$_val = $this->doc->xmlLangFromHTML();
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'val_none';
		if ($_code == null && $_val == null)
			$display_value = 'no_html_tag_found';
		if ($this->doc->isXML() || $_val != null) // If no xml:lang is null add the line only if doc is xml to begin with
			Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM HTTP CONTENT-LANGUAGE
	private function addInfoLangHTTP() {
		$category = 'lang_category';
		$title = 'lang_http';
		$_code = isset($this->curl_info['content_language']) ? 'Content-Language: '.$this->curl_info['content_language'] : null;
		$_val = isset($this->curl_info['content_language']) ? $this->curl_info['content_language'] : null;
		$value = array(
			'code' => $_code,
			'values' => $_val
		);		
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM META CONTENT-LANGUAGE
	// XXX: HTML5 content-language deprecated (http://www.w3.org/TR/html-markup/meta.http-equiv.content-language.html), consider adding a warning if used?
	private function addInfoLangMeta() {
		$category = 'lang_category';
		$title = 'lang_meta';
		$value = $this->doc->langsFromMeta();
		$display_value = null;
		if ($value == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);		
	}
	
	// INFO: TEXT DIRECTION FROM HTML TAGS
	private function addInfoDirHTML() {
		$category = 'dir_category';
		$title = 'dir_default';
		$_code = $this->doc->HTMLTag();
		$_val = $this->doc->dirFromHTML();
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null)
			$value = lang('dir_default_ltr');
		Information::addInfo($category, $title, $value, $display_value);	
	}
	
	// INFO: NON ASCII AND NFC CLASSES AND IDS
	private function addInfoClassId() {
		$classes = $this->doc->getNodesWithClass();
		$ids = $this->doc->getNodesWithID();
		$nodes = array_merge((array) $classes,(array) $ids);
		
		// Remove nodes for which all class names are ASCII
		//self::$logger->error(print_r($nodes, true));
		if (count($nodes) > 0)
			array_walk(&$nodes, function (&$valArray, $key) use (&$nodes) {
				$valArray['values'] = preg_filter('/[^\x20-\x7E]/', '$0', $valArray['values']);
				if (count($valArray['values']) == 0)
					unset($nodes[$key]);
			});
		
		$category = 'classId_category';
		$title = 'classId_non_ascii';
		$value = array_values($nodes); // we use array_values() to reindex the array
		$display_value = count($value) == 0 ? 'val_none' : null;
		Information::addInfo($category, $title, $value, $display_value);
		
		// Remove nodes for which all class names are NFC
		if (count($nodes) > 0)
			array_walk(&$nodes, function (&$valArray, $key) use (&$nodes) {
				$classStr = implode('', $valArray['values']);
				if (N11n::nfc($classStr) == $classStr)
					unset($nodes[$key]);
			});
		$title = 'classId_non_nfc';
		$value = array_values($nodes);
		
		//array_unique(Utils::arrayFlatten(array_values($nodes)));
		$display_value = count($value) == 0 ? 'val_none' : null;
		//$code = array_keys($nodes);
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: REQUEST HEADERS
	private function addInfoRequestHeaders() {
		$category = 'headers_category';
		$title = 'headers_accept_language';
		$_val = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? Utils::parseHeader($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null;
		$_code = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? 'Accept-Language: '.$_SERVER['HTTP_ACCEPT_LANGUAGE'] : null;
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
		
		$title = 'headers_accept_charset';
		$_val = isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? array_map('strtoupper', Utils::parseHeader($_SERVER['HTTP_ACCEPT_CHARSET'])) : null;
		$_code = isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? 'Accept-Charset: '.$_SERVER['HTTP_ACCEPT_CHARSET'] : null;
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	private function addReportCharsets() {
		$category = 'charset_category';
		
		// Get all the charsets found
		$charsets = array_merge(
			(array) Information::getValues('charset_http'),
			(array) Information::getValues('charset_bom'),
			(array) Information::getValues('charset_xml'),
			(array) Information::getValues('charset_meta')
		);
		
		$charsetVals = Utils::valuesFromValArray($charsets);
		//$charsetVals = null;
		$charsetVals = $charsetVals == null ? array() : $charsetVals;
		//self::$logger->error(print_r($charsetVals, true));
		
		$charsetCodes = Utils::codesFromValArray(
			array_filter($charsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			})
		);
		//self::$logger->error(print_r($charsetCodes, true));
		
		// WARNING: No character encoding information
		if (empty($charsetVals)) {
			self::$logger->debug('No charset information found for this document.');
			Report::addReport($category, 
				REPORT_LEVEL_WARNING, 
				lang('rep_charset_none'),
				lang('rep_charset_none_expl'),
				lang('rep_charset_none_todo'),
				lang('rep_charset_none_link')
			);
			return;
		} else {
			//self::$logger->debug('List of all charsets found: '.print_r($charsets, true));
		}
		
		// INFO: Non UTF-8 charset declared
		if (!in_array("UTF-8", $charsetVals) || count(array_unique($charsetVals)) > 1) {
			//self::$logger->error(print_r($charsets, true));
			$nonUTF8CharsetCodes = Utils::codesFromValArray(
				array_filter($charsets, function ($array) {
					if ($array['values'] != null 
						&& (!in_array("UTF-8", (array) $array['values'])))
						//|| count(array_unique((array) $array['values'])) > 1))
						return true;
					return false;
				})
			);
			Report::addReport(
				$category, REPORT_LEVEL_INFO, 
				lang('rep_charset_no_utf8'),
				lang('rep_charset_no_utf8_expl', Language::format($nonUTF8CharsetCodes, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_no_utf8_todo'),
				lang('rep_charset_no_utf8_link')
			);
		}
		
		// ERROR: Conflicting character encoding declarations
		if (count(array_unique($charsetVals)) != 1) {
			// $http_conflict_msg
			Report::addReport(
				$category, REPORT_LEVEL_ERROR, 
				lang('rep_charset_conflict'),
				lang('rep_charset_conflict_expl', Language::format($charsetCodes, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_conflict_todo'),
				lang('rep_charset_conflict_link')
			);
		}
		
		// WARNING: Multiple encoding declarations using the meta tag
		if (count(Information::getValues('charset_meta')) > 1) {
			Report::addReport(
				$category, REPORT_LEVEL_WARNING, 
				lang('rep_charset_multiple_meta'),
				lang('rep_charset_multiple_meta_expl', Language::format(Utils::codesFromValArray(Information::get('charset_meta')->values), LANG_FORMAT_OL_CODE)),
				lang('rep_charset_multiple_meta_todo'),
				lang('rep_charset_multiple_meta_link')
			);
		}
		
		// TODO: desambiguate ValuesArray against Values
		// WARNING: UTF-8 BOM found at start of file
		//self::$logger->error(print_r(Information::getValues('charset_bom'), true));
		if (($boms = Information::getValues('charset_bom')) != null 
			&& $boms[0]['values'] == "UTF-8") {
			Report::addReport(
				$category, REPORT_LEVEL_WARNING, 
				lang('rep_charset_bom_found'),
				lang('rep_charset_bom_found_expl'),
				lang('rep_charset_bom_found_todo'),
				lang('rep_charset_bom_found_link')
			);
		}
		
		// WARNING: No charset declaration in the document
		$inDocCharsets = array_merge(
			(array) Information::getValues('charset_bom'),
			(array) Information::getValues('charset_xml'),
			(array) Information::getValues('charset_meta')
		);
		$inDocCharsets = Utils::codesFromValArray(
			array_filter($inDocCharsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			})
		);
		//$inDocCharsets = array();
		//self::$logger->error("In Doc: ".print_r($inDocCharsets, true));
		if (!empty($charsetVals) && empty($inDocCharsets)) {
			Report::addReport(
				$category, REPORT_LEVEL_WARNING, 
				lang('rep_charset_no_in_doc'),
				lang('rep_charset_no_in_doc_expl', Information::get('charset_http')->values[0]['code']),
				lang('rep_charset_no_in_doc_todo'),
				lang('rep_charset_no_in_doc_link')
			);
		}
		
		// WARNING: BOM in content TODO: Shouldn't the message warn that it could be intentional like in this very message
		if (preg_match('/ï»¿/',$this->markup)) {
			Report::addReport(
				$category, REPORT_LEVEL_WARNING, 
				lang('rep_charset_bom_in_content'),
				lang('rep_charset_bom_in_content_expl'),
				lang('rep_charset_bom_in_content_todo'),
				lang('rep_charset_bom_in_content_link')
			);
		}
		
	}
	
	private function addReportLanguages() {
		// The html tag has no language attribute
		// The lang attribute and the xml:lang attribute in the html tag have different values
		// This HTML file contains xml:lang attributes
		// A lang attribute value did not match an xml:lang value when they appeared together on the same tag.
		// A language attribute value was incorrectly formed.
		// check that lang and xml:lang come in pairs in xhtml & check for non-welformed values
			// A tag uses a lang attribute without an associated xml:lang attribute.
			// A tag uses an xml:lang attribute without an associated lang attribute.
		// check that xhtml files served as XML have xml:lang
		
	}
	
	private function addReportDirValues() {
		// Incorrect values used for dir attribute
	}
	
	private function addReportMisc() {
		// are there non-NFC class or id names?
		// <b> tags found in source
		// <i> tags found in source
	}
}

Checker::init();