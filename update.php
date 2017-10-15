<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use App\Updater;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').'', getenv('DB_USER'), getenv('DB_PASSWORD'));
$fpdo = new FluentPDO($pdo);
$fpdo->debug = false;

Updater::init($fpdo);

$client = new Client([
    'headers' => [
       'User-Agent' => 'Sia-Agent',
   ]
 ]);

$res = $client->request('GET', getenv('SIAD').'/consensus');
$consensus = json_decode($res->getBody());

$last_height = $fpdo->from('block_hash_index')
                    ->select('height')->orderBy('height desc')->limit(1);
$last_height = $last_height->fetch();
if (empty($last_height['height'])) {
    $last_height = 1;
} else {
    $last_height = $last_height['height'];
}

$requests = function () use ($consensus, $last_height) {
    for ($i=$last_height-10; $i <= $consensus->height; $i++) {
        yield new Request('GET', getenv('SIAD').'/consensus/blocks/'.$i);
    }
};

$times = []; //Some stats
$slowest = [];
$fastest = [];

$pool = new Pool($client, $requests(), [
    'concurrency' => 20,
    'fulfilled' => function ($response, $index) use ($fpdo, &$times, &$slowest, &$fastest) {
        //echo 'Completed request '.$index.PHP_EOL;
        $block_start = microtime(true);
        $json = json_decode($response->getBody());

        $height = $json->blockheight;
        $prev_height = $json->blockheight-1;
        $prev_block_hash = $json->blockheader->parentid;

        Updater::addHash($prev_block_hash, 'blockid', $prev_height);

        foreach ($json->minerpayouts as $outputid => $output) {
            Updater::addHash($outputid, 'siacoinoutputid', $height, $output->value);
            Updater::addHash($output->unlockhash, 'unlockhash', $height);
        }

        foreach ($json->transactions as $transactionid => $transaction) {
            Updater::addHash($transactionid, 'transactionid', $height);

            foreach ($transaction->siacoininputs as $scinoputid => $scinoput) {
                Updater::addSpent($scinoputid);
                Updater::addHash($scinoputid, 'siacoinoutputid', $height);
            }

            foreach ($transaction->siacoinoutputs as $scoutputid => $scoutput) {
                Updater::addHash($scoutputid, 'siacoinoutputid', $height, $scoutput->value);
                Updater::addHash($scoutput->unlockhash, 'unlockhash', $height);
            }

            foreach ($transaction->filecontracts as $filecontractid => $fc) {
                Updater::addHash($filecontractid, 'filecontractid', $height);
                Updater::addHash($fc->unlockhash, 'unlockhash', $height);

                foreach ($fc->validproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height, $value->value);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                    Updater::addProof($key, $filecontractid, $height);
                }
                foreach ($fc->missedproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height, $value->value);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                    Updater::addProof($key, $filecontractid, $height);
                }
            }

            foreach ($transaction->filecontractrevisions as $filecontractid => $fc) {
                Updater::addHash($fc->parentid, 'filecontractid', $height);
                Updater::addHash($fc->newunlockhash, 'unlockhash', $height);

                foreach ($fc->newvalidproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height, $value->value);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                    Updater::addProof($key, $fc->parentid, $height);
                }
                foreach ($fc->newmissedproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height, $value->value);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                    Updater::addProof($key, $fc->parentid, $height);
                }
            }

            foreach ($transaction->storageproofs as $scinoputid => $scinoput) {
                Updater::addHash($scinoput->parentid, 'filecontractid', $height);
            }

            foreach ($transaction->siafundinputs as $scinoputid => $scinoput) {
                Updater::addSpent($scinoputid);
                Updater::addHash($scinoputid, 'siafundoutputid', $height, $scinoput->value);
            }

            foreach ($transaction->siafundoutputs as $scoutputid => $scoutput) {
                Updater::addHash($scoutputid, 'siafundoutputid', $height, $scoutput->value);
                Updater::addHash($scoutput->unlockhash, 'unlockhash', $height);
            }
        }

        $block_end = microtime(true);
        $process_time = $block_end-$block_start;
        echo "Block#{$height} processed in ".round($process_time, 4)."s".PHP_EOL;
        $times[] = $process_time;

        if (empty($slowest['height']) || $process_time > $slowest['time']) {
            $slowest = ['height' => $height, 'time' => round($process_time, 8)];
        }

        if (empty($fastest['height']) || $process_time < $fastest['time']) {
            $fastest = ['height' => $height, 'time' => round($process_time, 8)];
        }
    },
    'rejected' => function ($reason, $index) {
    },
]);

$promise = $pool->promise();
$promise->wait();

Updater::cleanPool();
Updater::cleanProofPool();
Updater::dumpProofs();

$avg = round(array_sum($times)/count($times), 3);
echo "Avg. process time: {$avg}s".PHP_EOL;
echo "Slowest: {$slowest['height']} -> {$slowest['time']}s".PHP_EOL;
echo "Fastest: {$fastest['height']} -> {$fastest['time']}s".PHP_EOL;
