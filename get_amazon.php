<?php
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Classes/PHPMailerAutoload.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Classes/cache.class.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Classes/amazon-xml-wrapper.class.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Classes/amazon-mws.class.php');

$client1 = new AmazonMws(
    'A24NUD4IN4R6ZS',
    'A3BXB0YN3XH17H',
    'AKIAIRSRSU3YR7VQJV6Q',
    'SGUcGRuyrN+KdJazRW+RLk53Mvi7BkVgwLZMmu4/',
    'amzn.mws.0aaae62a-4cab-630e-b358-070ef2399c36'
);

$reports = $client1->getRequestList('_GET_MERCHANT_LISTINGS_DATA_');

$orders = array ();
foreach ($reports as $report) {
    $id = $report['id'];
    //$report_type = $$report['report_type'];
    $submitted = $report['submitted'];
    $status = $report['status'];
    $report_id = $report['report_id'];
    echo $report_id . PHP_EOL;

    $stream = $client1->getReport($report_id);

    if (($header = fgetcsv($stream, null, "\t")) === false) {
        throw new Exception('Invalid format of header of inventory report');
    }

    foreach ($header as $id => $field) {
        $header[$id] = strtolower(trim($field));
    }

    $len = count($header);


    while (($row = fgetcsv($stream, null, "\t")) !== false) {
        while (count($row) > $len) {
            array_pop ($row);
        }

        while (count($row) < $len) {
            $row[] = '';
        }

        $data = array_combine ($header, $row);

        if ($data['fulfillment-channel'] == 'AMAZON_NA') 
            continue;

        $orders[] = $data;
    }

}

$ch = fopen('./reports/report.csv', 'w+');

fputcsv($ch, $header, ',', '"');

foreach ($orders as $order) {
    fputcsv($ch, $order, ',', '"');
}

fclose ($ch);
