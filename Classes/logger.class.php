<?php

class Logger {
	
	private static $instance = null;
	
	private static $methods = array(
		1 => 'fatal', 2 => 'error', 3 => 'warn', 4 => 'info', 5 => 'debug'
	);
	private static $log_dir = '/tmp';
	private static $prefix = 'log';
	private static $level = 0;
	
	private $pid = 0;
	private $backtrace_exists = false;
	private $first_line = false;
	private $handler = null;
	private $last_mem = 0;
	private $last_time = 0;
	private $parallel = array();
	
	private function __construct() {
		
		$this->backtrace_exists = function_exists('debug_backtrace');
		$this->pid = getmypid();
		
		$log = self::$log_dir . '/' . self::$prefix . '_' . date('Ymd') . '.txt';
		if (!($this->handler = fopen($log, 'a'))) {
			
			user_error('Unable to open log file: ' . $log, E_USER_NOTICE);
			$this->handler = null;
		}
		
		$this->last_mem = memory_get_usage(true);
		$this->last_time = microtime(true);
	}
	
	public static function &getInstance() {
		
		if (!self::$instance) {
			
			self::$instance = new Logger();
		}
		
		return self::$instance;
	}
	
	private function addLogMessage($level, $file, $line, $caller, $message) {
		
		list($this->last_mem, $mdelta) = array(memory_get_usage(true), $this->last_mem);
		list($this->last_time, $tdelta) = array(microtime(true), $this->last_time);
		$mdelta =  $this->last_mem - $mdelta;
		$tdelta = sprintf('%.03f', $this->last_time - $tdelta);
		
		$l = '[' . date('H:i:s') . ']';
		if ($file !== false && $line !== false) {
			
			$l .= ' at ' . $file . ':' . $line;
		}
		if ($caller !== false) {
			
			$l .= ' called from ' . $caller;
		}
		
		$l .= ' [' . $this->pid . '/' . number_format($mdelta, 0, '.', ',') . 'b/' . $tdelta . 's] '
			. strtoupper($level) . ' - ';
		
		if (is_array($message)) {
			
			$l .= print_r($message, true);
		}
		elseif (is_object($message)) {
			
			$l .= 'Object ' . print_r($message, true);
		}
		elseif (is_bool($message)) {
			
			$l .= 'Boolean ' . var_export($message, true);
		}
		elseif (is_scalar($message)) {
			
			$l .= $message;
		}
		elseif (is_resource($message)) {
			
			if (get_resource_type($message) === 'stream') {
				
				rewind($message);
				
				$l .= stream_get_contents($message);
			}
			else {
				
				$l .= 'Resource ' . var_export($message, true);
			}
		}
		else {
			
			$l .= var_export($message, true);
		}
		
		if (!$this->first_line) {
			
			$l = "\n" . $l;
			$this->first_line = true;
		}
		
		if (!is_resource($this->handler)) {
			
			return false;
		}
		
		@fwrite($this->handler, $l . "\n");
		
		foreach ($this->parallel as $stream) {
			
			@fwrite($stream, $l . "\n");
		}
		
		return true;
	}
	
	public function __call($method, $argvs) {
		
		if ($this->pid !== getmypid()) {
			
			return false;
		}
		
		if (count($argvs) == 0) {
			
			return false;
		}
		
		if (!in_array(strtolower($method), self::$methods)) {
			
			return false;
		}
		
		$mid = array_search($method, self::$methods);
		if ($mid > self::$level) {
			
			return true;
		}
		
		$file = false;
		$line = false;
		$caller = false;
		
		if ($this->backtrace_exists) {
			
			$tmp = debug_backtrace();
			
			$index = 1;
			if (count($argvs) >= 2) {
				
				if (is_bool($argvs[1]) && $argvs[1]) {
					
					$index = 2;
				}
				elseif (is_int($argvs[1]) && $argvs[1] > 0) {
					
					$index += $argvs[1];
				}
			}
			
			if (count($tmp) >= 1 + $index) {
				
				$file = $tmp[$index]["file"];
				$line = $tmp[$index]["line"];
				
				if (count($tmp) >= 2 + $index) {
					
					$caller = (isset($tmp[$index + 1]['class']))?
						$tmp[$index + 1]['class'] . $tmp[$index + 1]['type']:
						'';
					$caller .= $tmp[$index + 1]['function'];
				}
			}
			
			unset($tmp, $index);
		}
		
		return $this->addLogMessage(
			$method, $file, $line, $caller, array_shift($argvs)
		);
	}
	
	public static function setLogLevel($level) {
		
		$l = 0;
		if (is_string($level) && in_array($level, self::$methods)) {
			
			$l = array_search($level, self::$methods);
		}
		elseif (is_int($level)) {
			
			$l = intval($level);
		}
		elseif (strtolower($level) === 'all') {
			
			$l = count(self::$methods);
		}
		
		self::$level = $l;
	}
	
	public static function insertLogLevel($method, $after = false) {
		
		if (in_array(strtolower($method), self::$methods)) {
			
			return false;
		}
		
		if (!preg_match('/^[a-z]+$/si', $method)) {
			
			if (is_object(self::$instance)) {
				
				self::$instance->debug(
					'Invalid log method name: ' . $method, true
				);
			}
			else {
				
				user_error('Invalid log method name: ' . $method, E_USER_NOTICE);
			}
			
			return false;
		}
		
		$a = (in_array(strtolower($after), self::$methods))?
			strtolower($after):
			false;
		
		$new = array();
		$i = 1;
		if ($a === false) {
			
			if (self::$level == 1) {
				
				self::$level++;
			}
			$new[$i] = strtolower($method);
			$i++;
		}
		
		foreach (self::$methods as $id => $name) {
			
			$new[$i] = $name;
			$i++;
			
			if ($name === $a) {
				
				if ($i <= self::$level) {
					
					self::$level++;
				}
				
				$new[$i] = strtolower($method);
				$i++;
			}
		}
		
		self::$methods = $new;
		
		return true;
	}
	
	public static function setLogDir($dir) {
		
		if (is_object(self::$instance)) {
			
			self::$instance->warn(
				'Unable change log directory: logger already initialized', true
			);
			return false;
		}
		
		$dir = DIRECTORY_SEPARATOR . trim($dir, DIRECTORY_SEPARATOR);
		
		if (!is_dir($dir) || !is_writable($dir)) {
			
			user_error('Invalid log directory: ' . $dir, E_USER_NOTICE);
			return false;
		}
		
		self::$log_dir = preg_replace('/[\/]+/si', '/', $dir);
		
		return true;
	}
	
	public static function setLogName($name) {
		
		if (is_object(self::$instance)) {
			
			self::$instance->warn(
				'Unable to change logger prefix: logger already initialized', true
			);
			return false;
		}
		
		if (!preg_match('/^[a-z0-9_\-]+$/si', $name)) {
			
			user_error('Invalod log file prefix: ' . $name, E_USER_NOTICE);
			return false;
		}
		
		self::$prefix = $name;
		
		return true;
	}
	
	public function openStream($name) {
		
		if (isset($this->parallel[$name])) {
			
			return;
		}
		
		if ($tmp = @fopen('php://temp', 'wb+')) {
			
			$this->parallel[$name] = $tmp;
		}
	}
	
	public function closeStream($name, $out = null) {
		
		if (isset($this->parallel[$name])) {
			
			if (!is_resource($this->parallel[$name])) {
				
				unset($this->parallel[$name]);
				
				return;
			}
			
			if (is_resource($out) && get_resource_type($out) === 'stream') {
				
				rewind($this->parallel[$name]);
				
				stream_copy_to_stream($this->parallel[$name], $out);
			}
			
			fclose($this->parallel[$name]);
			
			unset($this->parallel[$name]);
		}
	}
	
	public function __destruct() {
		
		if (is_resource($this->handler)) {
			
			@fclose($this->handler);
		}
		
		foreach ($this->parallel as $stream) {
			
			if (is_resource($stream)) {
				
				fclose($stream);
			}
		}
	}
}

?>