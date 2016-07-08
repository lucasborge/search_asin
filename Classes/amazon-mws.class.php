<?php

class AmazonMws {
	
	const LOCALE_US = 'ATVPDKIKX0DER';
	const LOCALE_CA = 'A2EUQ1WTGCTBG2';
	const LOCALE_JP = 'A1VC38T7YXB528';
	
	const API_VERSION = '2009-01-01';
	const API_ENDPOINT = 'https://mws.amazonservices.com/';
	const API_EOL = "\r\n";
	const API_ENCODING = 'ISO-8859-1';
	const API_HASH_ALORITHM = 'HmacSHA256';
	const API_PRODUCT_ENDPOINT = '/Products/2011-10-01';
	const API_PRODUCT_BATCH = 20;
	const API_DETAILS_BATCH = 10;
	const API_REPORT_TIMEOUT = 15;
	const API_FBA_REPORT_TIMEOUT = 30;
	
	private static $tmp = null;
	
	private $locales = array(
		'ATVPDKIKX0DER' => 'https://mws.amazonservices.com',
		'A2EUQ1WTGCTBG2' => 'https://mws.amazonservices.ca',
		'A1VC38T7YXB528' => 'https://mws.amazonservices.jp'
	);
	private $mapping = array(
		'Product'             => '_POST_PRODUCT_DATA_',
		'Inventory'           => '_POST_INVENTORY_AVAILABILITY_DATA_',
		'Override'            => '_POST_PRODUCT_OVERRIDES_DATA_',
		'Price'               => '_POST_PRODUCT_PRICING_DATA_',
		'ProductImage'        => '_POST_PRODUCT_IMAGE_DATA_',
		'Relationship'        => '_POST_PRODUCT_RELATIONSHIP_DATA_',
		'OrderAcknowledgment' => '_POST_ORDER_ACKNOWLEDGEMENT_DATA_',
		'OrderFulfillment'    => '_POST_ORDER_FULFILLMENT_DATA_',
		'OrderAdjustment'     => '_POST_PAYMENT_ADJUSTMENT_DATA_'
	);
	
	private $endpoint = null;
	private $merchant = null;
	private $marketplace = null;
	private $aws_id = null;
	private $aws_key = null;
	private $token = null;
	
	private $logger = null;
	private $curl = null;
	private $delta = 0;
	private $feeds = array(
		'Product'             => null,
		'Inventory'           => null,
		'Override'            => null,
		'Price'               => null,
		'ProductImage'        => null,
		'Relationship'        => null,
		'OrderAcknowledgment' => null,
		'OrderFulfillment'    => null,
		'OrderAdjustment'     => null
	);
	private $counter = 1;
	private $memory = true;
	
	private $http_in = 0;
	private $http_out = 0;
	private $stat = 0;
	
	private $xml_report = null;
	private $xml_type = null;
	private $xml_row = null;
	private $xml_value = null;
	private $xml_stack = array();
	
	private $last_request = null;
	private $last_products_request = null;
	private $last_details_request = null;
	private $last_cancel = null;
	
	private $cat_cache = array();
	
	private $api_delay = 2;
	
	private $issues = false;
	
	public function __construct($merchant, $marketplace, $aws_id, $aws_key, $token = null) {
		
		if (!extension_loaded('curl')) {
			
			throw new Exception('cURL extension required by Amazon MWS API');
		}
		
		if (!class_exists('Cache')) {
			
			throw new Exception('Cache class required by Amazon MWS API');
		}
		
		if (!class_exists('AmazonXmlWrapper')) {
			
			throw new Exception('Amazon XML Wrapper class required by Amazon MWS API');
		}
		
		if (is_null(self::$tmp)) {
			
			if (defined('TMP_ROOT') && file_exists(TMP_ROOT) && is_dir(TMP_ROOT)) {
				
				self::$tmp = TMP_ROOT;
			}
			elseif ($tmp = @tmpfile()) {
				
				$md = stream_get_meta_data($tmp);
				
				fclose($tmp);
				
				if (isset($md['uri'])) {
					
					$tmp = dirname($md['uri']);
					
					if (file_exists($tmp) && is_dir($tmp)) {
						
						self::$tmp = $tmp;
					}
				}
			}
			
			if (is_null(self::$tmp)) {
				
				throw new Exception(
					'Unable to find temporary directory or temporary ' .
					'directory is not set. Please, define TMP_ROOT constant'
				);
			}
		}
		
		if (empty($merchant)) {
			
			throw new Exception('Merchant ID should be defined');
		}
		
		if (empty($marketplace)) {
			
			throw new Exception('Marketplace ID should be defined');
		}
		
		if (empty($aws_id)) {
			
			throw new Exception('AWS Access Key ID should be defined');
		}
		
		if (empty($aws_key)) {
			
			throw new Exception('AWS Seecret Key should be defined');
		}
		
		$this->merchant = $merchant;
		$this->marketplace = $marketplace;
		$this->aws_id = $aws_id;
		$this->aws_key = $aws_key;
		$this->token = empty($token)? null: $token;
		
		if (isset($this->locales[$marketplace])) {
			
			$this->endpoint = $this->locales[$marketplace];
			
			$this->info('Selected locale: ' . $this->endpoint);
		}
		else {
			
			$this->endpoint = $this->locales[self::LOCALE_US];
			
			$this->info('Using default locale: ' . $this->endpoint);
		}
		
		$this->curl = curl_init();
		
		$opt = array(
			CURLOPT_URL            => $this->endpoint . '/',
			CURLOPT_HEADER         => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT      => 'MWSPHPClient/1.0 (Language=PHP/' . phpversion() . ')',
			CURLOPT_TIMEOUT        => 300,
		);
		
		curl_setopt_array($this->curl, $opt);
		
		$date = curl_exec($this->curl);
		
		if (intval(curl_getinfo($this->curl, CURLINFO_HTTP_CODE)) === 200) {
			
			$date = @simplexml_load_string($date);
			
			if ($date instanceof SimpleXMLElement) {
				
				$date = (string) $date->Timestamp['timestamp'];
				
				if (($date = strtotime($date)) > 0) {
					
					$this->delta = $date - time();
				}
			}
		}
	}
	
	public function __destruct() {
		
		if (is_resource($this->curl)) {
			
			curl_close($this->curl);
		}
		
		foreach ($this->feeds as $type => $cache) {
			
			unset($this->feeds[$type], $cache);
		}
	}
	
	public function setLocale($locale) {
		
		$locale = strtoupper(trim($locale));
		
		if (!isset($this->locales[$locale])) {
			
			$this->endpoint = $this->locales[$locale];
			
			$this->info('Switching locale to: ' . $this->endpoint);
			
			if (is_resource($this->curl)) {
				
				curl_close($this->curl);
			}
			
			$this->curl = curl_init();
			
			$opt = array(
				CURLOPT_URL            => $this->endpoint . '/',
				CURLOPT_HEADER         => false,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_USERAGENT      => 'MWSPHPClient/1.0 (Language=PHP/' . phpversion() . ')',
				CURLOPT_TIMEOUT        => 300,
			);
			
			curl_setopt_array($this->curl, $opt);
			
			$date = curl_exec($this->curl);
			
			if (intval(curl_getinfo($this->curl, CURLINFO_HTTP_CODE)) === 200) {
				
				$date = @simplexml_load_string($date);
				
				if ($date instanceof SimpleXMLElement) {
					
					$date = (string) $date->Timestamp['timestamp'];
					
					if (($date = strtotime($date)) > 0) {
						
						$this->delta = $date - time();
					}
				}
			}
			
			return $locale;
		}
		
		return false;
	}
	
	public function stat($reset = false) {
		
		$result = array($this->http_in, $this->http_out);
		
		if ($reset) {
			
			$this->http_in = 0;
			$this->http_out = 0;
		}
		
		return $result;
	}
	
	public function addItem($type, $data) {
		
		if (!array_key_exists($type, $this->feeds)) {
			
			throw new Exception('Unsupported type of data feed: ' . $type);
		}
		
		$plain = array();
		$plain['Message/MessageID'] = $id = $this->counter++;
		$plain['Message/OperationType'] = 'Update';
		
		foreach ($data as $path => $value) {
			
			$path = trim(trim(trim($path), '/'));
			
			$plain['Message/' . $type . '/' . $path] = $value;
		}
		
		if (is_null($this->feeds[$type])) {
			
			$this->debug('Creating new datafeed of type: ' . $type);
			
			$this->feeds[$type] = array();
		}
		
		$this->feeds[$type][$id] = $plain;
	}
	
	public function submitFeed($type, $wait = true) {
		
		if (!array_key_exists($type, $this->feeds)) {
			
			throw new Exception('Unsupported type of data feed: ' . $type);
		}
		
		if (is_null($this->feeds[$type])) {
			
			throw new Exception('Datafeed \'' . $type . '\' is empty');
		}
		
		if (!($stream = fopen('php://temp', 'wb+'))) {
			
			throw new Exception('Unable to open temporary stream');
		}
		
		$this->debug('Creating datafeed of type: ' . $type);
		
		fwrite(
			$stream,
			'<?xml version="1.0" encoding="' . self::API_ENCODING . '"?>' .
			self::API_EOL
		);
		fwrite(
			$stream,
			'<AmazonEnvelope ' .
			'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ' .
			'xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">' .
			self::API_EOL
		);
		fwrite(
			$stream,
			'<Header>' .
			'<DocumentVersion>1.01</DocumentVersion>' .
			'<MerchantIdentifier>' . $this->token . '</MerchantIdentifier>' .
			'</Header>' . self::API_EOL
		);
		fwrite(
			$stream,
			'<MessageType>' . $type . '</MessageType>' . self::API_EOL
		);
/*		fwrite(
			$stream,
			'<PurgeAndReplace>false</PurgeAndReplace>' . self::API_EOL
		);
		
		fwrite(
			$stream,
			'<OperationType>Update</OperationType>' . self::API_EOL
		);*/
		
		$count = 0;
		
		$list = $this->feeds[$type];
		
		foreach ($list as $id => $data) {
			
			$tmp = new AmazonXmlWrapper($data, self::API_ENCODING);
			print_r($data);
			$xml = (string) $tmp;
			unset($tmp);
			echo $xml . PHP_EOL;
			
			fwrite($stream, $xml . self::API_EOL);
			
			$count++;
		}
		
		unset($list);
		
		fwrite($stream, '</AmazonEnvelope>');
		
		if ($count == 0) {
			
			unset($this->feeds[$type]);
			$this->feeds[$type] = null;
			
			fclose($stream);
			
			throw new Exception(
				'There is no items in \'' . $type . '\' data feed'
			);
		}
		
		$exception = $response = null;
		
		try {
			
			$this->debug('Submitting feed: ' . $type);
			
			$args = array();
			$args['Action'] = 'SubmitFeed';
			$args['FeedType'] = $this->mapping[$type];
			
			$response = $this->call($args, $stream);
			echo "class response " . PHP_EOL;
			print_r($response);
			
			if (is_resource($stream)) {
				
				fclose($stream);
			}
			
			$xml = $this->streamToXML($response);
			
			$id = (string) $xml->SubmitFeedResult->FeedSubmissionInfo->FeedSubmissionId;
			echo "submission ID : " . $id . PHP_EOL;
			
			if (empty($id)) {
				
				throw new Exception('Amazon does not return Feed Submission ID');
			}
			
			$this->debug('Submission ID is: ' . $id);
			
			if (!$wait) {
				
				unset($this->feeds[$type]);
				$this->feeds[$type] = null;
				
				return $id;
			}
			
			$this->debug('Waiting for processing report...');
			
			$delay = 30;
			
			do {
				
				sleep($delay);
				
				$args = array();
				$args['Action'] = 'GetFeedSubmissionList';
				$args['FeedSubmissionIdList.Id.1'] = $id;
				
				try {
					
					$response = $this->call($args);
					
					$xml = $this->streamToXML($response);
					echo "class xml".PHP_EOL;
					print_r($xml);
					
					$status = (string) $xml->GetFeedSubmissionListResult->FeedSubmissionInfo->FeedProcessingStatus;
					$status = trim($status);
					
					$delay = 30;
					
					$this->debug('Processing status is: ' . $status);
				}
				catch (Exception $e) {
					
					if ($e->getCode() == 503) {
						
						$delay = $delay * 2;
						
						$this->warn($e->getMessage());
						$this->debug(
							'Increasing delay up to ' . $delay . ' sec...'
						);
					}
					else {
						
						throw $e;
					}
				}
				
			} while (!in_array($status, array('_DONE_', '_CANCELED_')));
			
			$this->debug('Receiving report...');
			
			$args = array();
			$args['Action'] = 'GetFeedSubmissionResult';
			$args['FeedSubmissionId'] = $id;
			
			$this->memory = false;
			
			$report = $this->call($args);
		}
		catch (Exception $e) {
			
			$exception = $e;
			$this->warn($e->getMessage());
			$this->debug($e->getTraceAsString());
		}
		
		if (is_resource($response)) {
			
			fclose($response);
		}
		
		if (is_resource($stream)) {
			
			fclose($stream);
		}
		
		if (!is_null($exception)) {
			
			throw $exception;
		}
		
		$result = $this->parseXML($type, $report);
		
		unset($this->feeds[$type]);
		$this->feeds[$type] = null;
		
		return $result;
	}
	
	public function getInventory($bysku) {
		
		$bysku = (boolean) $bysku;
		
		$this->debug('Inventory data will be indexes by ' . ($bysku? 'SKU': 'ASIN'));
		
		$stream = $this->getInventoryStream();
		
		if ($stream === false) {
			
			$this->info('There is no data in the inventory');
			
			return array();
		}
		
		$this->debug('Parsing datafeed...');
		
		$exception = $result = null;
		
		try {
			
			$result = new Cache();
			
			if (($header = fgetcsv($stream, null, "\t")) === false) {
				
				throw new Exception('Invalid format of header of inventory report');
			}
			
			foreach ($header as $id => $field) {
				
				$header[$id] = strtolower(trim($field));
			}
			
			if (!in_array('sku', $header)) {
				
				$this->warn('SKU field is not exist:');
				$this->debug($header);
				
				throw new Exception('SKU field is not exist in header of report');
			}
			
			if (!in_array('asin', $header)) {
				
				$this->warn('ASIN field is not exist:');
				$this->debug($header);
				
				throw new Exception('ASIN field is not exist in header of report');
			}
			
			$len = count($header);
			
			while (($row = fgetcsv($stream, null, "\t")) !== false) {
				
				while (count($row) > $len) {
					
					array_pop($row);
				}
				
				while (count($row) < $len) {
					
					$row[] = '';
				}
				
				$row = array_combine($header, $row);
				
				$key = $bysku? $row['sku']: $row['asin'];
				
				$result[$key] = $row;
			}
			
			$this->debug(count($result) . ' item(s) in result set');
		}
		catch (Exception $e) {
			
			$exception = $e;
		}
		
		if (is_resource($stream)) {
			
			fclose($stream);
		}
		
		if (!is_null($exception)) {
			
			unset($result);
			
			throw $exception;
		}
		
		return $result;
	}
	
	public function getInventoryStream() {
		
		$request = $report = null;
		
		try {
			
			$tasks = $this->getRequestList('_GET_FLAT_FILE_OPEN_LISTINGS_DATA_');
			
			foreach ($tasks as $task) {
				
				if (in_array($task['status'], array('_SUBMITTED_', '_IN_PROGRESS_'))) {
					
					if (
						!empty($task['started']) &&
						(time() - $task['started'] > 6 * 3600)
					) {
						
						continue;
					}
					
					$request = $task['id'];
					
					$this->info(
						'Request is in progress. Attaching watcher to ' .
						'request: ' . $request
					);
					
					break;
				}
				elseif (
					$task['status'] === '_DONE_' &&
					time() - $task['completed'] <= self::API_REPORT_TIMEOUT
				) {
					
					$request = $task['id'];
					$report = $task['report_id'];
					
					$this->info(
						'Request just was done. Reusing data: ' .
						'request = ' . $request . '; report = ' . $report
					);
					
					break;
				}
			}
		}
		catch (Exception $e) {
			
			$this->warn(
				'Unable to check existing reports: ' . $e->getMessage()
			);
			$this->debug($e->getTraceAsString());
			
			$request = $report = null;
		}
		
		if (is_null($request)) {
			
			$this->debug('Requesting inventory report');
			
			$args = array();
			$args['Action'] = 'RequestReport';
			$args['ReportType'] = '_GET_FLAT_FILE_OPEN_LISTINGS_DATA_';
			$args['MarketplaceIdList.Id.1'] = $this->marketplace;
			
			if ($this->merchant == 'A3A0CTMETG5D1S') {
				
				$this->debug($args);
			}
			
			$response = $this->call($args);
			
			$xml = $this->streamToXML($response);
			
			if (empty($xml->RequestReportResult->ReportRequestInfo->ReportRequestId)) {
				
				throw new Exception('Unable to request Open Listing report');
			}
			
			$request = trim($xml->RequestReportResult->ReportRequestInfo->ReportRequestId);
			
			unset($xml);
		}
		
		if (is_null($report)) {
			
			$this->info('Open Listing request ID is: ' . $request);
			
			$delay = 45;
			
			do {
				
				sleep($delay);
				
				$args = array();
				$args['Action'] = 'GetReportRequestList';
				$args['ReportRequestIdList.Id.1'] = $request;
				
				try {
					
					$response = $this->call($args);
					
					$delay = 45;
				}
				catch (Exception $e) {
					
					if ($e->getCode() === 503) {
						
						$delay = $delay * 2;
						
						$this->warn('Report status error: ' . $e->getMessage());
						$this->debug(
							'Increasing delay up to ' . $delay . ' sec...'
						);
						
						continue;
					}
					else {
						
						throw $e;
					}
				}
				
				$xml = $this->streamToXML($response);
				
				if (empty($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus)) {
					
					throw new Exception('Unable to receive status of report request');
				}
				
				$status = strtoupper(trim($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus));
				
				$this->info('Current status of request is: ' . $status);
				
			} while (!in_array($status, array('_CANCELLED_', '_DONE_', '_DONE_NO_DATA_')));
			
			if ($status === '_CANCELLED_') {
				
				throw new Exception('Inventory report has been canceled for some reason');
			}
			
			if ($status === '_DONE_NO_DATA_') {
				
				$this->debug('There is no data in the report');
				
				return false;
			}
			
			$this->debug('Requesting report ID by request ID: ' . $request);
			
			$args = array();
			$args['Action'] = 'GetReportList';
			$args['ReportRequestIdList.Id.1'] = $request;
			
			$response = $this->call($args);
			
			$xml = $this->streamToXML($response);
			
			$report = trim($xml->GetReportListResult->ReportInfo->ReportId);
			
			unset($xml);
		}
		
		$this->debug('Report ID is: ' . $report);
		
		$args = array();
		$args['Action'] = 'GetReport';
		$args['ReportId'] = $report;
		
		$stream = null;
		
		$delay = 60;
		
		while (true) {
			
			try {
				
				$this->memory = false;
				
				$stream = $this->call($args);
				
				break;
			}
			catch (Exception $e) {
				
				if ($e->getCode() === 503) {
					
					$this->warn('Report download error: ' . $e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
				}
				else {
					
					throw $e;
				}
			}
		}
		
		if (is_resource($stream)) {
			
			rewind($stream);
		}
		else {
			
			throw new Exception('Unable to download inventory report');
		}
		
		return $stream;
	}
	
	public function getInbound($bysku) {
		
		$bysku = (boolean) $bysku;
		
		$this->debug('Inventory data will be indexes by ' . ($bysku? 'SKU': 'ASIN'));
		
		$stream = $this->getInboundStream();
		
		if ($stream === false) {
			
			$this->info('There is no inbound inventory');
			
			return array();
		}
		
		$this->debug('Parsing datafeed...');
		
		$exception = $result = null;
		
		try {
			
			$result = new Cache();
			
			if (($header = fgetcsv($stream, null, "\t")) === false) {
				
				throw new Exception('Invalid format of header of inventory report');
			}
			
			foreach ($header as $id => $field) {
				
				$header[$id] = strtolower(trim($field));
			}
			
			if (!in_array('sku', $header)) {
				
				$this->warn('SKU field is not exist:');
				$this->debug($header);
				
				throw new Exception('SKU field is not exist in header of report');
			}
			
			if (!in_array('asin', $header)) {
				
				$this->warn('ASIN field is not exist:');
				$this->debug($header);
				
				throw new Exception('ASIN field is not exist in header of report');
			}
			
			$len = count($header);
			
			while (($row = fgetcsv($stream, null, "\t")) !== false) {
				
				while (count($row) > $len) {
					
					array_pop($row);
				}
				
				while (count($row) < $len) {
					
					$row[] = '';
				}
				
				$row = array_combine($header, $row);
				
				$key = $bysku? $row['sku']: $row['asin'];
				
				$result[$key] = $row;
			}
		}
		catch (Exception $e) {
			
			$exception = $e;
		}
		
		if (is_resource($stream)) {
			
			fclose($stream);
		}
		
		if (!is_null($exception)) {
			
			unset($result);
			
			throw $exception;
		}
		
		return $result;
	}
	
	public function getInboundStream() {
		
		$request = $report = null;
		
		try {
			
			$tasks = $this->getRequestList('_GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA_');
			
			foreach ($tasks as $task) {
				
				if (in_array($task['status'], array('_SUBMITTED_', '_IN_PROGRESS_'))) {
					
					if (
						!empty($task['started']) &&
						(time() - $task['started'] > 6 * 3600)
					) {
						
						continue;
					}
					
					$request = $task['id'];
					
					$this->info(
						'Request is in progress. Attaching watcher to ' .
						'request: ' . $request
					);
					
					break;
				}
				elseif (
					$task['status'] === '_DONE_' &&
					time() - $task['started'] <= self::API_FBA_REPORT_TIMEOUT
				) {
					
					$request = $task['id'];
					$report = $task['report_id'];
					
					$this->info(
						'Request just was done. Reusing data: ' .
						'request = ' . $request . '; report = ' . $report
					);
					
					break;
				}
			}
		}
		catch (Exception $e) {
			
			$this->warn(
				'Unable to check existing reports: ' . $e->getMessage()
			);
			$this->debug($e->getTraceAsString());
			
			$request = $report = null;
		}
		
		if (is_null($request)) {
			
			$this->debug('Requesting inbound report');
			
			$args = array();
			$args['Action'] = 'RequestReport';
			$args['ReportType'] = '_GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA_';
			$args['MarketplaceIdList.Id.1'] = $this->marketplace;
			
			$response = $this->call($args);
			
			$xml = $this->streamToXML($response);
			
			if (empty($xml->RequestReportResult->ReportRequestInfo->ReportRequestId)) {
				
				throw new Exception('Unable to request inbound report');
			}
			
			$request = trim($xml->RequestReportResult->ReportRequestInfo->ReportRequestId);
			
			unset($xml);
		}
		
		if (is_null($report)) {
			
			$this->info('Inbound report request ID is: ' . $request);
			
			$delay = 45;
			
			do {
				
				sleep($delay);
				
				$args = array();
				$args['Action'] = 'GetReportRequestList';
				$args['ReportRequestIdList.Id.1'] = $request;
				
				try {
					
					$response = $this->call($args);
					
					$delay = 45;
				}
				catch (Exception $e) {
					
					if ($e->getCode() === 503) {
						
						$delay = $delay * 2;
						
						$this->warn('Report status error: ' . $e->getMessage());
						$this->debug(
							'Increasing delay up to ' . $delay . ' sec...'
						);
						
						continue;
					}
					else {
						
						throw $e;
					}
				}
				
				$xml = $this->streamToXML($response);
				
				if (empty($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus)) {
					
					throw new Exception('Unable to receive status of report request');
				}
				
				$status = strtoupper(trim($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus));
				
				$this->info('Current status of request is: ' . $status);
				
			} while (!in_array($status, array('_CANCELLED_', '_DONE_', '_DONE_NO_DATA_')));
			
			if ($status === '_CANCELLED_') {
				
				$this->debug(
					'Inbound report has been canceled for some reason - ' .
					'trying to get latest one'
				);
				
				$repeat = false;
				
				$args = array();
				$args['Action'] = 'GetReportRequestList';
				$args['ReportTypeList.Type.1'] = '_GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA_';
				$args['ReportProcessingStatusList.Status.1'] = '_DONE_';
				$args['MaxCount'] = 1;
				$args['RequestedFromDate'] = gmdate('Y-m-d\TH:i:s\Z', strtotime('-24 hours'));
				
				do {
					
					try {
						
						$response = $this->call($args);
						
						$xml = $this->streamToXML($response);
						
						if (empty($xml->GetReportRequestListResult->ReportRequestInfo->ReportRequestId)) {
							
							throw new Exception(
								'There is no valid latest report to reuse'
							);
						}
						
						$request = trim($xml->GetReportRequestListResult->ReportRequestInfo->ReportRequestId);
						
						$repeat = false;
					}
					catch (Exception $e) {
						
						$code = $e->getCode();
						
						if ($code >= 500 && $code < 600) {
							
							sleep(45);
							
							$repeat = true;
						}
						else {
							
							$this->warn($e->getMessage());
							$this->debug($e->getTraceAsString());
							
							return false;
						}
					}
					
				} while ($repeat);
				
				$this->info('Inbound request ID to reuse: ' . $request);
			}
			
			if ($status === '_DONE_NO_DATA_') {
				
				$this->debug('There is no data in the report');
				
				return false;
			}
			
			$this->debug('Requesting report ID by request ID: ' . $request);
			
			$args = array();
			$args['Action'] = 'GetReportList';
			$args['ReportRequestIdList.Id.1'] = $request;
			
			$response = $this->call($args);
			
			$xml = $this->streamToXML($response);
			
			$report = trim($xml->GetReportListResult->ReportInfo->ReportId);
			
			unset($xml);
		}
		
		$this->debug('Report ID is: ' . $report);
		
		$args = array();
		$args['Action'] = 'GetReport';
		$args['ReportId'] = $report;
		
		$stream = null;
		
		$delay = 60;
		
		while (true) {
			
			try {
				
				$this->memory = false;
				
				$stream = $this->call($args);
				
				break;
			}
			catch (Exception $e) {
				
				if ($e->getCode() === 503) {
					
					$this->warn('Report download error: ' . $e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
				}
				else {
					
					throw $e;
				}
			}
		}
		
		rewind($stream);
		
		return $stream;
	}
	
	public function getSubmissionStatus($id, $wait = true) {
		
		$ids = $id;
		
		if (!is_array($ids)) {
			
			$ids = array($ids);
		}
		
		$result = array();
		
		$delay = 45;
		
		do {
			
			$continue = false;
			
			$args = array();
			$args['Action'] = 'GetFeedSubmissionList';
			
			$i = 1;
			
			foreach ($ids as $value) {
				
				$args['FeedSubmissionIdList.Id.' . $i] = $value;
				
				$i++;
			}
			
			try {
				
				$response = $this->call($args);
				
				$xml = $this->streamToXML($response);
				
				$result = array();
				
				foreach ($xml->GetFeedSubmissionListResult->FeedSubmissionInfo as $info) {
					
					$num = (string) $info->FeedSubmissionId;
					
					$result[$num] = (string) $info->FeedProcessingStatus;
					
					$this->debug(
						'Processing status of submission ' . $num . ' ' .
							'is: ' . $result[$num]
					);
				}
			}
			catch (Exception $e) {
				
				$code = $e->getCode();
				
				if ($wait && $code >= 500 && $code < 600) {
					
					$this->warn($e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					$continue = true;
					
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
				}
				else {
					
					throw $e;
				}
			}
			
		} while ($continue);
		
		if (!is_array($id)) {
			
			$result = isset($result[$id])? $result[$id]: null;
		}
		
		return $result;
	}
	
	public function getSubmissionReport($id) {
		
		$delay = 30;
		
		do {
			
			$continue = false;
			
			$args = array();
			$args['Action'] = 'GetFeedSubmissionResult';
			$args['FeedSubmissionId'] = $id;
			
			try {
				
				$this->memory = false;
				
				$report = $this->call($args);
			}
			catch (Exception $e) {
				
				$code = $e->getCode();
				
				if ($code >= 500 && $code < 600) {
					
					$this->warn($e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					$continue = true;
					
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
				}
				else {
					
					throw $e;
				}
			}
			
		} while ($continue);
		
		$result = $this->parseXML(null, $report);
		
		return $result;
	}
	
	public function cancelSubmission($ids) {
		
		if (!is_null($this->last_cancel)) {
			
			$delta = time() - $this->last_cancel;
			
			if ($delta < 45) {
				
				sleep(46 - $delta);
			}
		}
		
		if (!is_array($ids)) {
			
			$ids = array($ids);
		}
		
		$n = 0;
		
		$args = array();
		$args['Action'] = 'CancelFeedSubmissions';
		
		foreach ($ids as $id) {
			
			$n++;
			
			$args['FeedSubmissionIdList.Id.' . $n] = $id;
		}
		
		$stream = $this->call($args);
		
		$this->last_cancel = time();
		
		$xml = $this->streamToXML($stream);
		
		$result = array();
		
		foreach ($xml->CancelFeedSubmissionsResult->FeedSubmissionInfo as $s) {
			
			$id = (string) $s->FeedSubmissionId;
			
			$result[$id] = (string) $s->FeedProcessingStatus;
		}
		
		return $result;
	}
	
	public function cancelReport($id) {
		
		$args = array();
		$args['Action'] = 'CancelReportRequests';
		
		if (is_array($id)) {
			
			$i = 1;
			
			foreach ($id as $elm) {
				
				$args['ReportRequestIdList.Id.' . $i] = (string) $elm;
				
				$i++;
			}
		}
		else {
			
			$args['ReportRequestIdList.Id.1'] = (string) $id;
		}
		
		$stream = $this->call($args);
		
		$this->last_cancel = time();
		
		$xml = $this->streamToXML($stream);
		
		$result = array();
		
		foreach ($xml->CancelReportRequestsResult->ReportRequestInfo as $info) {
			
			$rid = (string) $info->ReportRequestId;
			
			$result[$rid] = array();
			$result[$rid]['id'] = (string) $info->ReportRequestId;
			$result[$rid]['type'] = (string) $info->ReportType;
			$result[$rid]['submitted'] = @strtotime($info->SubmittedDate);
			$result[$rid]['started'] = @strtotime($info->StartedProcessingDate);
			$result[$rid]['completed'] = @strtotime($info->CompletedDate);
			$result[$rid]['range_start'] = @strtotime($info->StartDate);
			$result[$rid]['range_end'] = @strtotime($info->EndDate);
			$result[$rid]['status'] = (string) $info->ReportProcessingStatus;
			$result[$rid]['report_id'] = (string) $info->GeneratedReportId;
		}
		
		if (!is_array($id)) {
			
			$result = array_shift($result);
		}
		
		return $result;
	}
	
	public function getRequestList($id = null) {
		
		$args = array();
		$args['Action'] = 'GetReportRequestList';
		if (!is_null($id)) {
			
			if (is_array($id)) {
				
				$i = 1;
				
				foreach ($id as $value) {
					
					$args['ReportRequestIdList.Id.' . $i] = $value;
					
					$i++;
				}
			}
			elseif (substr($id, 0, 1) == '_' && substr($id, -1, 1) == '_') {
				
				$args['ReportTypeList.Type.1'] = $id;
			}
			else {
				
				$args['ReportRequestIdList.Id.1'] = $id;
			}
		}
		else {
			
			$args['MaxCount'] = 100;
		}
		
		$delay = 10;
		
		while (true) {
			
			try {
				
				$response = $this->call($args);
			}
			catch (Exception $e) {
				
				$c = intval($e->getCode());
				
				if (($c >= 500 && $c < 600) || $c == 0) {
					
					sleep($delay);
					
					$delay = $delay * 2;
					
					$this->warn('Report status error (HTTP ' . $c . '): ' . $e->getMessage());
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
					
					continue;
				}
				else {
					
					throw $e;
				}
			}
			
			break;
		}
		
		$xml = $this->streamToXML($response);
		
		$result = array();
		
		foreach ($xml->GetReportRequestListResult->ReportRequestInfo as $info) {
			
			$data = array();
			$data['id'] = (string) $info->ReportRequestId;
			$data['type'] = (string) $info->ReportType;
			$data['submitted'] = @strtotime($info->SubmittedDate);
			$data['started'] = @strtotime($info->StartedProcessingDate);
			$data['completed'] = @strtotime($info->CompletedDate);
			$data['range_start'] = @strtotime($info->StartDate);
			$data['range_end'] = @strtotime($info->EndDate);
			$data['status'] = (string) $info->ReportProcessingStatus;
			$data['report_id'] = (string) $info->GeneratedReportId;
			
			$result[] = $data;
		}
		
		unset($xml);
		
		return $result;
	}
	
	public function getReports($type = null, $limit = 1) {
		
		$args = array();
		$args['Action'] = 'GetReportList';
		$args['MaxCount'] = $limit;
		
		if (!is_null($type)) {
			
			$args['ReportTypeList.Type.1'] = $type;
		}
		
		$list = $this->call($args);
		$xml = $this->streamToXML($list);
		
		$result = array();
		
		foreach ($xml->GetReportListResult->ReportInfo as $record) {
			
			$date = (string) $record->AvailableDate;
			
			if (empty($date) || ($date = @strtotime($date)) === false) {
				
				continue;
			}
			
			$result[] = array(
				'report_id'   => (string) $record->ReportId,
				'report_type' => (string) $record->ReportType,
				'request_id'  => (string) $record->ReportRequestId,
				'date'        => $date
			);
		}
		
		return $result;
	}
	
	public function getReport($id) {
		
		$args = array();
		$args['Action'] = 'GetReport';
		$args['ReportId'] = $id;
		
		$this->memory = false;
		
		$result = $this->call($args);
		
		rewind($result);
		
		return $result;
	}
	
	public function getOrders($from = null, $to = null) {
		
		$result = null;
		
		$month = 30 * 24 * 60 * 60 - 60;
		$f = is_null($from)? strtotime('-1 day'): $from;
		
		do {
			
			$t = is_null($to)? time(): $to;
			
			$delta = $t - $f;
			
			if ($delta > $month) {
				
				$delta = $month;
			}
			
			$list = $this->getOrdersShort($f, $f + $delta);
			
			if (is_null($result)) {
				
				$result = $list;
			}
			else {
				
				foreach ($list as $id => $order) {
					
					$result[$id] = $order;
				}
				
				unset($list);
			}
			
			$f += $delta;
			
		} while ($f < $t);
		
		if (is_null($result)) {
			
			$result = array();
		}
		
		return $result;
	}
	
	private function getOrdersShort($from = null, $to = null) {
		
		if (!is_null($from)) {
			
			if (!is_int($from)) {
				
				if (($from = @strtotime($from)) === false) {
					
					$from = strtotime('-29 days');
				}
			}
		}
		else {
			
			$from = strtotime('-29 days');
		}
		
		$args = array();
		$args['Action'] = 'RequestReport';
		$args['ReportType'] = '_GET_FLAT_FILE_ALL_ORDERS_DATA_BY_ORDER_DATE_';
		$args['StartDate'] = gmdate('Y-m-d\TH:i:s\Z', $from);
		
		if (!is_null($to)) {
			
			if (!is_int($to)) {
				
				if (($to = @strtotime($to)) === false) {
					
					$to = strtotime('+29 day', $from);
				}
			}
			
			if ($to > $from) {
				
				$args['EndDate'] = gmdate('Y-m-d\TH:i:s\Z', $to);
			}
		}
		
		$response = $this->call($args);
		
		$xml = $this->streamToXML($response);
		
		if (empty($xml->RequestReportResult->ReportRequestInfo->ReportRequestId)) {
			
			throw new Exception('Unable to request Order report');
		}
		
		$request = trim($xml->RequestReportResult->ReportRequestInfo->ReportRequestId);
		
		unset($xml);
		
		$this->info('Order request ID is: ' . $request);
		
		$delay = 120;
		
		do {
			
			sleep($delay);
			
			$args = array();
			$args['Action'] = 'GetReportRequestList';
			$args['ReportRequestIdList.Id.1'] = $request;
			
			try {
				
				$response = $this->call($args);
				
				$delay = 120;
			}
			catch (Exception $e) {
				
				if ($e->getCode() === 503) {
					
					$this->warn('Report status error: ' . $e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
					
					continue;
				}
				else {
					
					throw $e;
				}
			}
			
			$xml = $this->streamToXML($response);
			
			if (empty($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus)) {
				
				throw new Exception('Unable to receive status of report request');
			}
			
			$status = strtoupper(trim($xml->GetReportRequestListResult->ReportRequestInfo->ReportProcessingStatus));
			
			$this->debug('Current status of request is: ' . $status);
			
		} while (!in_array($status, array('_CANCELLED_', '_DONE_', '_DONE_NO_DATA_')));
		
		if ($status === '_CANCELLED_') {
			
			throw new Exception('Inventory report has been canceled for some reason');
		}
		
		if ($status === '_DONE_NO_DATA_') {
			
			$this->debug('There is no data in the report');
			
			return array();
		}
		
		$report = trim($xml->GetReportRequestListResult->ReportRequestInfo->GeneratedReportId);
		
		$this->debug('Report ID is: ' . $report);
		
		$args = array();
		$args['Action'] = 'GetReport';
		$args['ReportId'] = $report;
		
		$stream = null;
		
		$delay = 30;
		
		while (true) {
			
			try {
				
				$this->memory = false;
				
				$stream = $this->call($args);
				
				break;
			}
			catch (Exception $e) {
				
				if ($e->getCode() === 503) {
					
					$this->warn('Report download error: ' . $e->getMessage());
					
					sleep($delay);
					
					$delay = $delay * 2;
					$this->debug(
						'Increasing delay up to ' . $delay . ' sec...'
					);
				}
				else {
					
					throw $e;
				}
			}
		}
		
		$this->debug('Parsing datafeed...');
		
		rewind($stream);
		
		$exception = $result = null;
		
		try {
			
			$result = new Cache();
			
			if (($header = fgetcsv($stream, null, "\t")) === false) {
				
				throw new Exception('Invalid format of header of inventory report');
			}
			
			foreach ($header as $id => $field) {
				
				$header[$id] = strtolower(trim($field));
			}
			
			$len = count($header);
			
			while (($row = fgetcsv($stream, null, "\t")) !== false) {
				
				while (count($row) > $len) {
					
					array_pop($row);
				}
				
				while (count($row) < $len) {
					
					$row[] = '';
				}
				
				$row = array_combine($header, $row);



				$id = $row['amazon-order-id'];
				
				if (isset($result[$id])) {
					
					$order = $result[$id];
				}
				else {
					
					$order = array();
					$order['id'] = $id;
					$order['date'] = strtotime($row['purchase-date']);
					$order['fba'] = (strtolower($row['fulfillment-channel']) == 'amazon');
                    $order['status'] = $row['order-status'];
                    $order['stype'] = $row['ship-service-level'];
                    $order['city'] = $row['ship-city'];
                    $order['state'] = $row['ship-state'];
                    $order['zip'] = $row['ship-postal-code'];
                    $order['country'] = $row['ship-country'];
					$order['items'] = array();
				}
				
				$item = array();
				
				$item['asin'] = $row['asin'];
				$item['sku'] = $row['sku'];
				$item['title'] = $row['product-name'];
				$item['qty'] = intval($row['quantity']);
				$item['price'] = bcmul($row['item-price'], 1, 2);
				$item['shipping'] = bcmul($row['shipping-price'], 1, 2);
				$item['handling'] = bcmul($row['gift-wrap-price'], 1, 2);
				$item['tax'] = bcadd(
					bcadd($row['item-tax'], $row['shipping-tax'], 2),
					$row['gift-wrap-tax'],
					2
				);
				$item['discount'] = bcadd(
					$row['item-promotion-discount'],
					$row['ship-promotion-discount'],
					2
				);
				
				$order['items'][] = $item;
				
				$result[$id] = $order;
			}
		}
		catch (Exception $e) {
			
			$exception = $e;
		}
		
		if (is_resource($stream)) {
			
			fclose($stream);
		}
		
		if (!is_null($exception)) {
			
			unset($result);
			
			throw $exception;
		}
		
		return $result;
	}
	
	private function streamToXML($stream) {
		
		@rewind($stream);
		
		$xml = str_replace(
			array('xmlns=', 'xmlns:'),
			array('ns=', 'ns:'),
			stream_get_contents($stream)
		);
		
		fclose($stream);
		
		$result = @simplexml_load_string($xml);
		
		if (!($result instanceof SimpleXMLElement)) {
			
			throw new Exception('Invalid XML stream');
		}
		
		return $result;
	}
	
	private function call($args, $stream = null, $endpoint = null) {
		
		$result = 'temp/maxmemory:5242880'; // use memory for stream lower then 5 Mb
		
		if (!($result = @fopen('php://' . $result, 'wb+'))) {
			
			throw new Exception('Unable to open temporary file to receive data');
		}
		
		$this->memory = true;
		
		if (is_null($endpoint)) {
			
			$endpoint = $this->endpoint . '/';
			$args['Marketplace'] = $this->marketplace;
			$args['Merchant'] = $this->merchant;
		}
		
		if (!isset($args['Version'])) {
			
			$args['Version'] = self::API_VERSION;
		}
		
		$args['AWSAccessKeyId'] = $this->aws_id;
		$args['MWSAuthToken'] = $this->token;
		$args['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z', time() + $this->delta);
		
		$query = $this->getQuery(
			(is_resource($stream)? 'POST': 'GET'),
			$endpoint,
			$args
		);
		
		if (is_resource($stream)) {
			
			rewind($stream);
			
			$md5 = $len = null;
			
			$file = tempnam(self::$tmp, 'mws-');
			
			if ($tmp = fopen($file, 'wb')) {
				
				$len = stream_copy_to_stream($stream, $tmp);
				
				fclose($tmp);
				
				$md5 = base64_encode(md5_file($file, true));
				
				rewind($stream);
			}
			
			@unlink($file);
			
			curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_INFILE, $stream);
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
				'Content-Type: text/xml; charset=' . self::API_ENCODING,
				'Content-Length: ' . $len,
				'Content-MD5: ' . $md5
			));
		}
		else {
			
			curl_setopt($this->curl, CURLOPT_POST, false);
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, array());
		}
		
		curl_setopt($this->curl, CURLOPT_URL, $endpoint . '?' . $query);
		curl_setopt($this->curl, CURLOPT_FILE, $result);
		
		$err = null;
		
		if (false && $err = tmpfile()) {
			
			curl_setopt($this->curl, CURLOPT_VERBOSE, true);
			curl_setopt($this->curl, CURLOPT_STDERR, $err);
		}
		else {
			
			curl_setopt($this->curl, CURLOPT_VERBOSE, false);
			curl_setopt($this->curl, CURLOPT_STDERR, STDERR);
		}
		
		curl_exec($this->curl);
		
		if (is_resource($err)) {
			
			rewind($err);
			
			$tmp = stream_get_contents($err);
			
			fclose($err);
			
			$err = $tmp;
			
			unset($tmp);
		}
		
		rewind($result);
		
		$this->http_out += intval(curl_getinfo($this->curl, CURLINFO_REQUEST_SIZE));
		$this->http_in += intval(curl_getinfo($this->curl, CURLINFO_SIZE_DOWNLOAD))
			+ intval(curl_getinfo($this->curl, CURLINFO_HEADER_SIZE));
		
		$code = intval(curl_getinfo($this->curl, CURLINFO_HTTP_CODE));
		
		if ($code !== 200) {
			
			if (!empty($err)) {
				
				$this->debug($err);
			}
			
			$this->warn($result);
			
			rewind($result);
			
			$msg = 'Unexpected server response (' . $code . ')';
			
			$xml = str_replace(
				array('xmlns=', 'xmlns:'),
				array('ns=', 'ns:'),
				stream_get_contents($result)
			);
			$xml = @simplexml_load_string($xml);
			
			if ($xml instanceof SimpleXMLElement) {
				
				$num = (string) $xml->Error->Code;
				$mes = (string) $xml->Error->Message;
				
				if (empty($num) && empty($mes)) {
					
					$msg .= ' with undefined error';
				}
				else {
					
					$msg .= ': [' . $num . '] ' . $mes;
				}
			}
			unset($xml);
			
			throw new Exception($msg, $code);
		}
		
		return $result;
	}
	
	private function getQuery($method, $url, $args) {
		
		$args['SignatureMethod'] = self::API_HASH_ALORITHM;
		$args['SignatureVersion'] = 2;
		
		ksort($args);
		
		$data = (strtoupper($method) === 'POST'? 'POST': 'GET') . "\n";
		
		$data .= strtolower(parse_url($url, PHP_URL_HOST)) . "\n";
		
		$data .= parse_url($url, PHP_URL_PATH) . "\n";
		
		$result = '';
		
		foreach ($args as $name => $value) {
			
			$result .= rawurlencode($name) . '=' . rawurlencode($value) . '&';
		}
		
		$data .= substr($result, 0, -1);
		
		$algo = null;
		
		if (self::API_HASH_ALORITHM === 'HmacSHA1') {
			
			$algo = 'sha1';
		}
		elseif (self::API_HASH_ALORITHM === 'HmacSHA256') {
			
			$algo = 'sha256';
		}
		else {
			
			throw new Exception('Unsupported hash-algorithm type');
		}
		
		$result .= 'Signature=' . rawurlencode(base64_encode(
			hash_hmac($algo, $data, $this->aws_key, true)
		));
		
		return $result;
	}
	
	private function parseXML($type, $source) {
		
		$this->xml_type = $type;
		
		$exception = $parser = null;
		
		try {
			
			$this->xml_report = new Cache(self::$tmp);
			
			rewind($source);
			
			$parser = xml_parser_create();
			xml_set_object($parser, $this);
			xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
			xml_set_element_handler($parser, 'xmlElmStart', 'xmlElmEnd');
			xml_set_character_data_handler($parser, 'xmlChrData');
			
			while (!feof($source)) {
				
				$buffer = fread($source, 1024);
				if (!xml_parse($parser, $buffer, feof($source))) {
					
					$code = xml_get_error_code($parser);
					throw new Exception(
						'Invalid XML content on line ' .
						xml_get_current_line_number($parser) . ': ' .
						xml_error_string($code)
					);
				}
			}
		}
		catch (Exception $e) {
			
			unset($this->xml_report);
			
			$exception = $e;
		}
		
		if (!is_null($parser)) {
			
			xml_parser_free($parser);
		}
		
		return $this->xml_report;
	}
	
	private function xmlElmStart($parser, $name, $attrs) {
		
		if (strtolower($name) === 'result') {
			
			$this->xml_row = array();
		}
		elseif (!is_null($this->xml_row)) {
			
			$this->xml_stack[] = $this->xml_value;
			$this->xml_value = '';
		}
	}
	
	private function xmlElmEnd($parser, $name) {
		
		if (strtolower($name) === 'result') {
			
			$data = array();
			
			if (isset($this->xml_row['MessageID'])) {
				
				$id = intval($this->xml_row['MessageID']);
				
				if (
					isset($this->feeds[$this->xml_type]) &&
					isset($this->feeds[$this->xml_type][$id])
				) {
					
					$data = $this->feeds[$this->xml_type][$id];
				}
			}
			
			$this->xml_row['Original'] = $data;
			
			$this->xml_report[] = $this->xml_row;
			
			$this->xml_row = null;
		}
		elseif (is_array($this->xml_row)) {
			
			$this->xml_row[$name] = $this->xml_value;
			$this->xml_value = array_pop($this->xml_stack);
		}
	}
	
	private function xmlChrData($parser, $data) {
		
		$this->xml_value .= $data;
	}
	
	private function debug($msg) {
		
		if (is_null($this->logger)) {
			
			if (class_exists('Logger')) {
				
				$this->logger = Logger::getInstance();
			}
			else {
				
				$this->logger = false;
			}
		}
		
		if (!is_object($this->logger)) {
			
			return;
		}
		
		$this->logger->debug($msg, true);
	}
	
	private function info($msg) {
		
		if (is_null($this->logger)) {
			
			if (class_exists('Logger')) {
				
				$this->logger = Logger::getInstance();
			}
			else {
				
				$this->logger = false;
			}
		}
		
		if (!is_object($this->logger)) {
			
			return;
		}
		
		$this->logger->info($msg, true);
	}
	
	private function warn($msg) {
		
		if (is_null($this->logger)) {
			
			if (class_exists('Logger')) {
				
				$this->logger = Logger::getInstance();
			}
			else {
				
				$this->logger = false;
			}
		}
		
		if (!is_object($this->logger)) {
			
			return;
		}
		
		$this->logger->warn($msg, true);
	}
	
	private function error($msg) {
		
		if (is_null($this->logger)) {
			
			if (class_exists('Logger')) {
				
				$this->logger = Logger::getInstance();
			}
			else {
				
				$this->logger = false;
			}
		}
		
		if (!is_object($this->logger)) {
			
			return;
		}
		
		$this->logger->error($msg, true);
	}
	
	private function fatal($msg) {
		
		if (is_null($this->logger)) {
			
			if (class_exists('Logger')) {
				
				$this->logger = Logger::getInstance();
			}
			else {
				
				$this->logger = false;
			}
		}
		
		if (!is_object($this->logger)) {
			
			return;
		}
		
		$this->logger->fatal($msg, true);
	}
	
	private function sortOffers($a, $b) {
		
		if (isset($a['winner']) && $a['winner']) {
			
			return -1;
		}
		elseif (isset($b['winner']) && $b['winner']) {
			
			return 1;
		}
		
		return bccomp(
			bcadd($a['price'], $a['shipping'], 2),
			bcadd($b['price'], $b['shipping'], 2),
			2
		);
	}
}

?>
