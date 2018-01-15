<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class MyDB extends SQLite3
{
    function __construct()
    {
        $this->open('mysqlitedb.db');
    }
}


$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$client = new Client([
    'headers' => [
        'User-Agent' => 'Sia-Agent',
    ]
]);


$res = $client->request('GET', getenv('SIAD').'/consensus');
$consensus = json_decode($res->getBody());

var_dump($consensus->height);

$addresses = file_get_contents('addresses.txt');
$addresses = implode(",", array_map(function($a){return '"'.$a.'"';}, explode("\n", $addresses)));

$db = new MyDB();

$data = $db->query('select * from payouts where unlockhash in ('.$addresses.') order by block asc');


$total = 0;
while ($row = $data->fetchArray()) {
    $payout = $row['value']/1e24;
    $total += $payout;


    $time = time()+(($row['block']-$consensus->height)*1800);
    echo "Block#{$row['block']}: {$payout} SC -> ".date('Y-m-d H:i', $time).PHP_EOL;
}

echo 'Total: '.$total.' SC'.PHP_EOL;