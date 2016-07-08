<?php

class Cache implements Iterator, ArrayAccess, Countable {
	
	private $tmp = null;
	private $file = null;
	private $handler = null;
	private $counter = 0;
	private $internal = 0;
	private $cleanup = true;
	
	private $key = null;
	
	public function __construct($tmp = '/tmp') {
		
		if (!file_exists($tmp) || !is_dir($tmp)) {
			
			throw new Exception('Temporary directory is not exists');
		}
		
		$this->tmp = $tmp;
		
		$this->file = tempnam($this->tmp, 'big-cache-');
		
		if (!($this->handler = dba_open($this->file, 'c', 'db4'))) {
			
			throw new Exception('Unable to open cache file');
		}
	}
	
	public function __destruct() {
		
		if (is_resource($this->handler)) {
			
			dba_close($this->handler);
		}
		
		if ($this->cleanup) {
			
			@unlink($this->file);
		}
	}
	
	public function save($filename) {
		
		$dir = dirname($filename);
		
		if (!file_exists($dir) || !is_dir($dir)) {
			
			throw new Exception('Target directory is not exists');
		}
		
		if (is_resource($this->handler)) {
			
			dba_close($this->handler);
		}
		
		if (!copy($this->file, $filename)) {
			
			$this->handler = dba_open($this->file, 'w', 'db4');
			
			throw new Exception('Unable to save cache file as: ' . $filename);
		}
		else {
			
			if (!($new = dba_open($filename, 'w', 'db4'))) {
				
				$this->handler = dba_open($this->file, 'w', 'db4');
				
				throw new Exception('Unable to open saved file: ' . $filename);
			}
			
			$this->handler = $new;
		}
		
		if ($this->cleanup) {
			
			@unlink($this->file);
		}
		
		$this->file = $filename;
		$this->cleanup = false;
	}
	
	public function load($filename) {
		
		if (!file_exists($filename) || !is_file($filename)) {
			
			throw new Exception('File is not exist: ' . $filename);
		}
		
		if (!($new = dba_open($filename, 'w', 'db4'))) {
			
			throw new Exception('Unable to load file: ' . $filename);
		}
		
		if (is_resource($this->handler)) {
			
			dba_close($this->handler);
		}
		
		if ($this->cleanup) {
			
			@unlink($this->file);
		}
		
		$this->handler = $new;
		$this->file = $filename;
		$this->cleanup = false;
	}
	
	/***************************************************************************
	 * Iterator interface implementation
	 */
	
	public function current() {
		
		if (is_null($this->key) || !dba_exists($this->key, $this->handler)) {
			
			return null;
		}
		
		$result = dba_fetch($this->key, $this->handler);
		$result = @unserialize($result);
		
		return $result;
	}
	
	public function key() {
		
		return $this->key;
	}
	
	public function next() {
		
		if (($this->key = dba_nextkey($this->handler)) === false) {
			
			$this->key = null;
		}
	}
	
	public function rewind() {
		
		$this->key = dba_firstkey($this->handler);
	}
	
	public function valid() {
		
		if (is_null($this->key)) {
			
			return false;
		}
		
		return dba_exists($this->key, $this->handler);
	}
	
	/***************************************************************************
	 * ArrayAccess interface implementation
	 */
	
	public function offsetExists($offset) {
		
		return dba_exists($offset, $this->handler);
	}
	
	public function offsetGet($offset) {
		
		$result = dba_fetch($offset, $this->handler);
		$result = @unserialize($result);
		
		return $result;
	}
	
	public function offsetSet($offset, $value) {
		
		if (is_null($offset)) {
			
			$offset = $this->internal++;
		}
		
		if (dba_exists($offset, $this->handler)) {
			
			dba_replace($offset, serialize($value), $this->handler);
		}
		else {
			
			dba_insert($offset, serialize($value), $this->handler);
			
			$this->counter++;
		}
	}
	
	public function offsetUnset($offset) {
		
		if (dba_exists($offset, $this->handler)) {
			
			dba_delete($offset, $this->handler);
			
			$this->counter--;
		}
	}
	
	/***************************************************************************
	 * Countable interface implementation
	 */
	
	public function count() {
		
		return $this->counter;
	}
}

?>