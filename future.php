<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use App\Updater;

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

class MyDB extends SQLite3
{
    function __construct()
    {
        $this->open('mysqlitedb.db');
    }
}

$db = new MyDB();

$requests = function () use ($consensus, $db) {
    for ($i=$consensus->height; $i < $consensus->height+300; $i++) {
        $data = $db->query("select * from payouts where block = {$i}");
        $data = $data->fetchArray();


        if(empty($data)) {
            echo 'added request '.$i.PHP_EOL;
            yield $i => new Request('GET', getenv('SIAD').'/consensus/future/'.$i);
        }
    }
};


$db->exec('CREATE TABLE IF NOT EXISTS payouts (tx STRING, unlockhash STRING, value STRING, block INTEGER)');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS MyUniqueIndexName ON payouts (tx, unlockhash, block)');


$times = []; //Some stats
$slowest = [];
$fastest = [];

$pool = new Pool($client, $requests(), [
    'concurrency' => 4,
    'fulfilled' => function ($response, $index) use ($db) {
        echo 'Completed request '.$index.PHP_EOL;
        $block_start = microtime(true);
        $json = json_decode($response->getBody());

        if(count($json)) {
            foreach($json as $sco) {
    //            echo $sco->SiacoinOutput->unlockhash;

                $db->exec("INSERT INTO payouts (tx, unlockhash, value, block) VALUES ('{$sco->ID}', '{$sco->SiacoinOutput->unlockhash}', '{$sco->SiacoinOutput->value}', {$index})");

            }
        }
    },
    'rejected' => function ($reason, $index) {
        //var_dump('rejected', (string)$reason, $index);
    },
]);

$promise = $pool->promise();
$promise->wait();
