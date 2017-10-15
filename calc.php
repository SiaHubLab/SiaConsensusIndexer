<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

function process_block($block, $hash)
{
    global $fpdo;

    foreach ($block->minerpayouts as $outputid => $output) {
        if ($outputid == $hash) {
            $fpdo->update('hashes')
                 ->set([
                     'amount' => $output->value
                 ])
                 ->where('hash', $outputid)
                 ->execute();
            echo "update amount ".$output->value.PHP_EOL;
            return $output->value;
        }
    }

    foreach ($block->transactions as $transactionid => $transaction) {
        foreach ($transaction->siacoinoutputs as $scoutputid => $scoutput) {
            // $flatdata[$scoutputid] = [
            //     'id' => $scoutputid,
            //     'parent_id' => $transactionid,
            //     'raw' => (array) $scoutput,
            //     'type' => 'out'
            // ];
            if ($scoutputid == $hash) {
                $fpdo->update('hashes')
                     ->set([
                         'amount' => $scoutput->value
                     ])
                     ->where('hash', $scoutputid)
                     ->execute();
                echo "update amount ".$scoutput->value.PHP_EOL;
                return $scoutput->value;
            }
        }
    }

    return null;
}

$total_start = microtime(true);

$memcache = new Memcached;
$memcache->addServer('localhost', 11211);

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').'', getenv('DB_USER'), getenv('DB_PASSWORD'));
$fpdo = new FluentPDO($pdo);
$fpdo->debug = false;


$client = new Client([
    'headers' => [
       'User-Agent' => 'Sia-Agent',
   ]
 ]);

$addresses = file('addresses.txt');
$addresses = array_filter(array_map('trim', $addresses));


$hashes = $fpdo->from('hashes')
               ->select('id')
               ->where('hash', $addresses);
$hash_ids = $hashes->fetchAll();

$hash_ids = array_map(function ($hash) {
    return $hash['id'];
}, $hash_ids);


$hashes = $fpdo->from('tx_index')
               ->select('hashes.*')
               ->leftJoin('hashes ON hashes.id = tx_index.tx_id')
               ->where('address_id', $hash_ids);
$transactions = $hashes->fetchAll();

$client = new \GuzzleHttp\Client();

$total = 0;
$spent = 0;
foreach ($transactions as $key => $transaction) {
    echo "process: {$key}/".count($transactions).PHP_EOL;
    if ($transaction['amount'] === null) {
        echo "request block from api".PHP_EOL;
        $res = $client->request('GET', 'https://explorer.siahub.info/api/hash/'.$transaction['hash']);
        $response = $res->getBody();
        $tx = json_decode($response);
        $res = $client->request('GET', 'https://explorer.siahub.info/api/block/'.$tx->blocks[0]->height);
        $response = $res->getBody();
        $block = json_decode($response);

        $amount = process_block($block, $transaction['hash']);
    } else {
        $amount = $transaction['amount'];
    }

    if ($amount !== null) {
        $amount = $amount/1e24;
        $total += $amount;
        if ($transaction['spent'] == 1) {
            $spent += $amount;
        }
        // var_dump($transaction);
        echo $transaction['id'].PHP_EOL;
    }
}

echo "Total TXs: ".count($transactions).PHP_EOL;
echo "Total: ".$total.PHP_EOL;
echo "Spent: ".$spent.PHP_EOL;
echo "Balance: ".($total-$spent).PHP_EOL;
