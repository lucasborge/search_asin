<?php

class AmazonXmlWrapper {
	
	private $xml = null;
	private $root = null;
	private $xpath = null;
	
	public function __construct($data, $encoding = 'UTF-8') {
		
		if (!extension_loaded('dom')) {
			
			throw new Exception('DOM XML extension required by Amazon XML Wrapper');
		}
		
		$this->xml = new DOMDocument('1.0', $encoding);
		$this->root = $this->xml->createElement('Root');
		$this->root = $this->xml->appendChild($this->root);
		$this->xpath = new DOMXPath($this->xml);
		
		foreach ($data as $path => $value) {
			
			$this->create($path, $value);
		}
		
		$list = $this->xpath->evaluate('//*[@_ignore_me_]');
		for ($i = 0; $i < $list->length; $i++) {
			
			$list->item($i)->removeAttribute('_ignore_me_');
		}
		
		$list = $this->xpath->evaluate('//*[@_cdata_]');
		for ($i = 0; $i < $list->length; $i++) {
			
			$list->item($i)->removeAttribute('_cdata_');
		}
	}
	
	public function __destruct() {
		
		unset($this->xml);
	}
	
	public function __toString() {
		
		$result = '';
		for ($i = 0; $i < $this->root->childNodes->length; $i++) {
			
			if ($this->root->childNodes->item($i)->nodeType !== XML_ELEMENT_NODE) {
				
				continue;
			}
			
			$result .= $this->xml->saveXML($this->root->childNodes->item($i));
		}
		
		return $result;
	}
	
	public static function escape($str) {
		
		$result = str_replace('&', '&amp;', $str);
		$result = htmlspecialchars($result, ENT_QUOTES);
		$result = preg_replace(
			'/([^\x20-\x7E])/esi',
			'\'&#x\' . sprintf(\'%02x\', ord(\'$1\')) . \';\'',
			$result
		);
		return $result;
	}
	
	private function create($path, $data) {
		
		$list = explode('/', trim($path, '/'));
		$tmp = './';
		$prev = $this->root;
		
		while (count($list) > 0) {
			
			$node = array_shift($list);
			$tmp .= '/' . $node;
			$res = $this->xpath->evaluate($tmp, $this->root);
			
			if ($res->length == 0) {
				
				$attrs = array();
				if (($pos = strpos($node, '[')) !== false) {
					
					$attrs = trim(substr($node, $pos), ' []');
					$attrs = $this->getAttrList($attrs);
					$node = substr($node, 0, $pos);
				}
				
				$new = $prev->appendChild(
					$this->xml->createElement($node)
				);
				
				$cdata = false;
				
				foreach ($attrs as $name => $value) {
					
					if ($name === '_cdata_') {
						
						$cdata = true;
					}
					
					$new->setAttribute($name, $value);
				}
				
				if (count($list) === 0) {
					
					if ($cdata) {
						
						$content = $this->xml->createCDATASection($data);
					}
					else {
						
						$content = $this->xml->createTextNode($data);
					}
					
					$new->appendChild($content);
				}
				else {
					
					$prev = $new;
				}
			}
			else {
				
				for ($i = 0; $i < $res->length; $i++) {
					
					if (count($list) === 0) {
						
						$this->appendTextData($res->item(0), $data);
					}
				}
				
				$prev = $res->item(0);
			}
		}
	}
	
	private function getAttrList($string) {
		
		$result = array();
		
		$string = ltrim($string, ' @');
		$string = preg_replace('/,[\s]*@/si', ',@', $string);
		$parts = explode(',@', $string);
		
		foreach ($parts as $part) {
			
			list($name, $value) = explode('=', $part, 2);
			
			$value = trim($value, ' "');
			$value = str_replace('\"', '"', $value);
			
			$result[$name] = $value;
		}
		
		return $result;
	}
	
	private function appendTextData($node, $text) {
		
		$content = null;
		
		if ($node->attributes->getNamedItem('_cdata_') === null) {
			
			for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
				
				if ($node->childNodes->item($i)->nodeType !== XML_TEXT_NODE) {
					
					continue;
				}
				
				$node->removeChild($node->childNodes->item($i));
			}
			
			$content = $this->xml->createTextNode($text);
		}
		else {
			
			for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
				
				if ($node->childNodes->item($i)->nodeType !== XML_CDATA_SECTION_NODE) {
					
					continue;
				}
				
				$node->removeChild($node->childNodes->item($i));
			}
			
			$content = $this->xml->createCDATASection($text);
		}
		
		$node->appendChild($content);
	}
}

?>