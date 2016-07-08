<?php

class Mailer {
	
	private static $instance = null;
	private static $enc = null;
	private static $file = null;
	private static $handler = null;
	private static $logger = null;
	
	private $files = array();
	
	private function __construct() {
	}
	
	public function __destruct() {
		
		foreach ($this->files as $file) {
			
			@unlink($file);
		}
	}
	
	private function addFile() {
		
		if (!in_array(self::$file, $this->files)) {
			
			$this->files[] = self::$file;
		}
	}
	
	public static function send($from, $to, $subject, $template, $data, $attach = array(), $bcc = null) {
		
		if (is_null(self::$instance)) {
			
			$class = __CLASS__;
			self::$instance =& new $class();
		}
		
		if (!defined('SUPPLIER') || !defined('MAIL_SERVICE')) {
			
			self::debug('Incorrect settings');
			return false;
		}
		
		self::$file = (defined('TMP_ROOT')? TMP_ROOT: '/tmp') . '/'
			. date('Ymd') . '-' . md5(time() . microtime()) . '.xml';
		if (!(self::$handler = @fopen(self::$file, 'w+'))) {
			
			self::debug('Can\'t open file: ' . self::$file);
			return false;
		}
		
		self::$instance->addFile();
		
		$cc = null;
		
		if (strpos($to, ',') !== false) {
			
			$to = str_replace(',', ';', $to);
		}
		
		if (strpos($to, ';') !== false) {
			
			list($to, $cc) = explode(';', $to, 2);
			$to = trim($to);
			$cc = trim($cc);
		}
		
		fwrite(self::$handler, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
		fwrite(self::$handler, '<message subject="' . self::q($subject) . '">' . "\n");
		fwrite(self::$handler, '<head>' . "\n");
		fwrite(self::$handler, '<from>' . self::q($from) . '</from>' . "\n");
		fwrite(self::$handler, '<to>' . self::q($to) . '</to>' . "\n");
		
		if (!empty($cc)) {
			
			fwrite(self::$handler, '<cc>' . self::q($cc) . '</cc>' . "\n");
		}
		
		if (!empty($bcc)) {
			
			fwrite(self::$handler, '<bcc>' . self::q($bcc) . '</bcc>' . "\n");
		}
		
		fwrite(self::$handler, '</head>' . "\n");
		fwrite(self::$handler, '<body tpl="' . self::q($template) . '" prefix="' . self::q(SUPPLIER) . '">' . "\n");
		fwrite(self::$handler, self::wrap($data) . "\n");
		fwrite(self::$handler, '</body>' . "\n");
		if (!empty($attach)) {
			
			fwrite(self::$handler, '<attachments>' . "\n");
			foreach ($attach as $file) {
				
				fwrite(
					self::$handler,
					'<attachment ' .
					'filename="' . self::q(basename($file)) . '" ' .
					'encoding="base64">'
				);
				fwrite(self::$handler, base64_encode(@file_get_contents($file)));
				fwrite(self::$handler, '</attachment>' . "\n");
			}
			fwrite(self::$handler, '</attachments>' . "\n");
		}
		fwrite(self::$handler, '</message>');
		fflush(self::$handler);
		rewind(self::$handler);
		self::debug('Size: ' . filesize(self::$file));
		self::debug(stream_get_contents(self::$handler));
		
		$tmp = parse_url(MAIL_SERVICE);
		
		switch ($tmp['scheme']) {
			
			case 'file':
				
				fclose(self::$handler);
				
				if (!copy(self::$file, $tmp['path'] . '/' . basename(self::$file))) {
					
					self::debug(
						'Can\'t copy to ' . $tmp['path'] . '/' .
						basename(self::$file)
					);
					return false;
				}
				
				break;
			
			case 'ftp':
				
				fseek(self::$handler, 0, SEEK_SET);
				
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt(
					$curl,
					CURLOPT_URL,
					'ftp://' . $tmp['host'] . '/' . $tmp['path'] . '/' .
					basename(self::$file)
				);
				curl_setopt(
					$curl,
					CURLOPT_USERPWD,
					$tmp['user'] . ':' . $tmp['pass']
				);
				curl_setopt($curl, CURLOPT_INFILE, self::$handler);
				//curl_setopt($curl, CURLOPT_INFILESIZE, filesize(self::$file));
				
				curl_exec($curl);
				$size = curl_getinfo($curl, CURLINFO_SIZE_UPLOAD);
				curl_close($curl);
				
				fclose(self::$handler);
				
				if (filesize(self::$file) != $size) {
					
					self::debug('FTP upload failed');
					return false;
				}
				
				break;
				
			case 'https':
			case 'http':
				
				fseek(self::$handler, 0, SEEK_SET);
				
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_URL, MAIL_SERVICE);
				curl_setopt($curl, CURLOPT_HTTPHEADER, array(
					'Content-Length: ' . filesize(self::$file)
				));
				curl_setopt($curl, CURLOPT_INFILE, self::$handler);
				
				curl_exec($curl);
				$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				curl_close($curl);
				
				fclose(self::$handler);
				
				if ($code != 200) {
					
					self::debug('HTTP(S) upload failed');
					return false;
				}
				break;
			
			case 'scp':
				
				fclose(self::$handler);
				
				if (
					!defined('ROOT') ||
					!isset($tmp['pass']) ||
					!file_exists(ROOT . '/cfg/' . $tmp['pass'])
				) {
					
					self::debug('Access key was not found');
					return false;
				}
				
				$cmd = '/usr/bin/scp -i "' . ROOT . '/cfg/' . $tmp['pass'] . '" '
					. '"' . self::$file . '" '
					. $tmp['user'] . '@' . $tmp['host'] . ':' . $tmp['path'] . ' '
					. '1>/dev/null 2>/dev/null';
				$ret = 0;
				system($cmd, $ret);
				
				if ($ret != 0) {
					
					self::debug('SCP upload failed');
					return false;
				}
				
				break;
			
			default:
				
				fclose(self::$handler);
				self::debug('Unknown transport protocol: ' . $tmp['scheme']);
				return false;
		}
		
		return true;
	}
	
	private static function q($str) {
		
		if (is_null(self::$enc)) {
			
			self::$enc = ini_get('iconv.internal_encoding');
		}
		
		$str = iconv(self::$enc, 'UTF-8', $str);
		$str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
		$str = preg_replace(
			'/([^\x20-\x7E])/esi',
			'\'&#x\' . sprintf(\'%02x\', ord(\'$1\')) . \';\'',
			$str
		);
		
		return $str;
	}
	
	private static function wrap($data) {
		
		$result = '';
		
		foreach ($data as $name => $value) {
			
			$result .= '<param name="' . self::q($name) . '">';
			
			if (is_array($value)) {
				
				$result .= self::wrap($value);
			}
			else {
				
				$result .= self::q($value);
			}
			
			$result .= '</param>';
		}
		
		return $result;
	}
	
	private static function debug($msg) {
		
		if (is_null(self::$logger)) {
			
			if (class_exists('Logger')) {
				
				self::$logger = Logger::getInstance();
			}
			else {
				
				self::$logger = false;
				
				return;
			}
		}
		elseif (!is_object(self::$logger)) {
			
			return;
		}
		
		self::$logger->debug($msg, true);
	}
}

?>