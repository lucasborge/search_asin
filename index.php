<?php

$item_name = "";
$sku = "";
if (isset($_GET['asin'])) {
    $asin = $_GET['asin'];

$ch = fopen('./reports/report.csv', 'r');

if (($header = fgetcsv($ch, null, ",")) === false) {
    throw new Exception('Invalid format of header of inventory report');
}

foreach ($header as $id => $field) {
    $header[$id] = strtolower(trim($field));
}

$len = count($header);

while (($row = fgetcsv($ch, null, ",")) !== false) {
    while (count($row) > $len) {
        array_pop ($row);
    }

    while (count($row) < $len) {
        $row[] = '';
    }

    $data = array_combine ($header, $row);

    $orders[] = $data;
}

fclose ($ch);

    foreach ($orders as $order) {
        $asin1 = $order['asin1'];
        $asin2 = $order['asin2'];
        $asin3 = $order['asin3'];

        if ($asin == $asin1 || $asin == $asin2 || $asin == $asin3) {
            $item_name = $order['item-name'];
            $sku = $order['seller-sku'];
            break;
        }
    }

}

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>Get ASIN from AMAZON</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0, initial-scale=1">

        <script>
            function get_asin () {
                var asin = document.getElementById('asin').value;

                var frm = document.getElementById('frm');
                frm.action = "./index.php?action=search";
                frm.submit();
            }
        </script>
    </head>
    <body>
        <form type="POST" name="frm" id="frm">
            <input name="asin" id="asin" style="width:200px;padding: 5px;">
            <input type="button" value="search" onclick="get_asin()">
            <div id="result" style="border:1px solid #ddd;width:500px; height: 400px; overflow: auto;">
                <?php
                    echo "SKU : " . $sku;
                    echo "<br>";
                    echo "PRODUCT NAME : " . $item_name;
                ?>
            </div>
        </form>
    </body>
</html>