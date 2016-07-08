<?php

$item_name = "";
$sku = "";
$image_url = "";
$price = "";
$open_date = "";
$qty = "";
$marketplace = "";
$str = "";
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
        	foreach ($order as $key => $value) {
        		$str .= $key . " : " . $value . "<br>";
        	}
        	$image_url = $order['image-url'];
            /*$item_name = $order['item-name'];
            $sku = $order['seller-sku'];
            $desc = $order['item-description'];
            $listing_id = $order['listing-id'];
            $qty =$order['quantity'];
            $open_date = $order['open-date'];
            $image_url = $order['image-url'];
            $marketplace = $order['item-is-marketplace'];
            $type = $order['product-id-type'];
            //zshop-shipping-fee,item-note,item-condition,zshop-category1,zshop-browse-path,zshop-storefront-feature,asin1,asin2,asin3,will-ship-internationally,expedited-shipping,zshop-boldface,product-id,bid-for-featured-placement,add-delete,pending-quantity,fulfillment-channel,business-price,"quantity price type","quantity lower bound 1","quantity price 1","quantity lower bound 2","quantity price 2","quantity lower bound 3","quantity price 3","quantity lower bound 4","quantity price 4","quantity lower bound 5","quantity price 5",merchant-shipping-group
            $price = $order['price'];*/
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
                    /*echo "SKU : " . $sku . "<br>";
                    echo "PRICE : " . $price . "<br>";
                    echo "QUANTITY : " . $qty . "<br>";
                    echo "Open Date : " . $open_date . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "<br>";
                    echo "PRODUCT NAME : " . $item_name;
                    echo "DESCRIPTION : " . $desc . "<br>";
                    echo "zshop-shipping-fee : " . $marketplace . "<br>";
                    echo "item-note : " . $marketplace . "<br>";
                    echo "item-condition : " . $marketplace . "<br>";
                    echo "zshop-category1 : " . $marketplace . "<br>";
                    echo "zshop-browse-path : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";
                    echo "MarketPlace : " . $marketplace . "<br>";*/
                    echo $str;
                ?>
            </div>
            <img src="<?php echo $image_url;?>" style="width:200px;">
        </form>
    </body>
</html>