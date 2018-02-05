<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

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

// $addresses = file('addresses.txt');
// $addresses = array_filter(array_map('trim', $addresses));


// $hashes = $fpdo->from('hashes')
//                     ->select('id')
//                     ->where('hash', $addresses);
// $hash_ids = $hashes->fetchAll();
//
// $hash_ids = array_map(function ($hash) {
//     return $hash['id'];
// }, $hash_ids);
//
// $heights = $fpdo->from('block_hash_index')
//                     ->select('height')
//                     ->where('hash_id', $hash_ids)
//                     ->orderBy('height asc');
// $blocks_to_get = $heights->fetchAll();
//
// $blocks = array_map(function ($hash) {
//     return $hash['height'];
// }, $blocks_to_get);
//
$res = $client->request('GET', getenv('SIAD').'/consensus');
$consensus = json_decode($res->getBody());

$requests = function () use ($consensus, $memcache) {
    $last_block = $memcache->get('last_block_balance');
    echo "start from: {$last_block}".PHP_EOL;
    for ($i=($last_block) ? $last_block-100:1; $i <= $consensus->height; $i++) {
        $cache = $memcache->get('block_'.$i);
        if (!$cache) {
            yield new Request('GET', getenv('SIAD').'/consensus/blocks/'.$i);
        } else {
            process_block($cache);
        }
    }
};

$times = []; //Some stats
$slowest = [];
$fastest = [];

$tx_pool = [];
$spent_pool = [];
$processed = [];
$address_ids = [];
$tx_ids = [];

function process_block($block)
{
    global $fpdo, $tx_pool, $processed, $spent_pool, $address_ids, $tx_ids, $memcache;

    if (isset($processed[$block->blockheight])) {
        return false;
    }

    echo "process ".$block->blockheight.PHP_EOL;
    $processed[$block->blockheight] = 1;

    foreach ($block->minerpayouts as $scoutputid => $scoutput) {
        $fpdo->update('hashes')
            ->set([
                'amount' => $scoutput->value
            ])
            ->where('hash', $scoutputid)
            ->execute();

        if (!isset($address_ids[$scoutputid])) {
            $tx = $fpdo->from('hashes')
                ->select('id')
                ->where('hash', $scoutputid)
                ->limit(1);
            $tx = $tx->fetch();
            $tx_id = (!empty($tx['id'])) ? $tx['id']:false;
            $address_ids[$scoutputid] = $tx_id;
        }

        if (!isset($address_ids[$scoutput->unlockhash])) {
            $address = $fpdo->from('hashes')
                ->select('id')
                ->where('hash', $scoutput->unlockhash)
                ->limit(1);
            $address = $address->fetch();
            $address_id = (!empty($address['id'])) ? $address['id']:false;
            $address_ids[$scoutput->unlockhash] = $address_id;
        }

        if ($address_ids[$scoutput->unlockhash] && $address_ids[$scoutputid]) {
            $tx_pool[] = ['tx_id' => $address_ids[$scoutputid], 'address_id' => $address_ids[$scoutput->unlockhash]];

            if (count($tx_pool) >= 10000) {
                $fpdo->insertInto('tx_index', $tx_pool)->ignore()->execute();
                $tx_pool = [];
            }
        }
    }

    foreach ($block->transactions as $transactionid => $transaction) {
        foreach ($transaction->siacoininputs as $scinoputid => $scinoput) {
            $spent_pool[] = $scinoputid;
        }

        foreach ($transaction->siacoinoutputs as $scoutputid => $scoutput) {
            $fpdo->update('hashes')
                 ->set([
                     'amount' => $scoutput->value
                 ])
                 ->where('hash', $scoutputid)
                 ->execute();

            if (!isset($address_ids[$scoutputid])) {
                $tx = $fpdo->from('hashes')
                            ->select('id')
                            ->where('hash', $scoutputid)
                            ->limit(1);
                $tx = $tx->fetch();
                $tx_id = (!empty($tx['id'])) ? $tx['id']:false;
                $address_ids[$scoutputid] = $tx_id;
            }

            if (!isset($address_ids[$scoutput->unlockhash])) {
                $address = $fpdo->from('hashes')
                                ->select('id')
                                ->where('hash', $scoutput->unlockhash)
                                ->limit(1);
                $address = $address->fetch();
                $address_id = (!empty($address['id'])) ? $address['id']:false;
                $address_ids[$scoutput->unlockhash] = $address_id;
            }

            if ($address_ids[$scoutput->unlockhash] && $address_ids[$scoutputid]) {
                $tx_pool[] = ['tx_id' => $address_ids[$scoutputid], 'address_id' => $address_ids[$scoutput->unlockhash]];

                if (count($tx_pool) >= 10000) {
                    $fpdo->insertInto('tx_index', $tx_pool)->ignore()->execute();
                    $tx_pool = [];
                }
            }
        }
    }

    if (count($spent_pool) >= 10000) {
        $fpdo->update('hashes')
             ->set([
                 'spent' => 1
             ])
             ->where('hash', $spent_pool)
             ->execute();
        $spent_pool = [];
    }

    $memcache->set('last_block_balance', $block->blockheight);
}

$pool = new Pool($client, $requests(), [
    'concurrency' => 20,
    'fulfilled' => function ($response, $index) use ($memcache, &$times, &$slowest, &$fastest) {
        //echo 'Completed request '.$index.PHP_EOL;
        $block_start = microtime(true);
        $json = json_decode($response->getBody());

        $memcache->set('block_'.$json->blockheight, $json);

        $height = $json->blockheight;
        $prev_height = $json->blockheight-1;
        $prev_block_hash = $json->blockheader->parentid;

        process_block($json);

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


if (count($tx_pool)) {
    $fpdo->insertInto('tx_index', $tx_pool)->ignore()->execute();
    $tx_pool = [];
}

if (count($spent_pool)) {
    $fpdo->update('hashes')
         ->set([
             'spent' => 1
         ])
         ->where('hash', $spent_pool)
         ->execute();
    $spent_pool = [];
}

$avg = round(array_sum($times)/count($times), 3);
echo "Avg. process time: {$avg}s".PHP_EOL;
echo "Slowest: {$slowest['height']} -> {$slowest['time']}s".PHP_EOL;
echo "Fastest: {$fastest['height']} -> {$fastest['time']}s".PHP_EOL;

//
// $flat_start = microtime(true);
//
// $flat_simple = [];
// foreach ($flatdata as $serachout) {
//     $flat_simple[$serachout['type']][] = $serachout;
// }
//
// foreach ($flatdata as $key => $value) {
//     if ($value['type'] == "in") {
//         // foreach ($flat_simple['out'] as $serachout) {
//         //     if ($serachout['id'] == $value['parent_id']) {
//         //         $out = $serachout['raw'];
//         //         break;
//         //     }
//         // }
//         $key = array_search($value['parent_id'], array_column($flat_simple['out'], 'id'));
//         if ($key !== false) {
//             $out = $flat_simple['out'][$key]['raw'];
//             if (is_array($out)) {
//                 if (!isset($counted[$value['parent_id']])) {
//                     $counted[$value['parent_id']] = true;
//                     if (!isset($ins[$out['unlockhash']])) {
//                         $ins[$out['unlockhash']] = 0;
//                     }
//                     $ins[$out['unlockhash']] += $out['value']/1e24;
//
//                     $fpdo->update('hashes')
//                          ->set([
//                              'amount' => $out['value']
//                          ])
//                          ->where('hash', $value['parent_id'])
//                          ->execute();
//                 }
//             } else {
//                 //var_dump('not array, but key: '.$key);
//             }
//         }
//     }
//
//     if ($value['type'] == "out") {
//         if (!isset($ocounted[$value['id']])) {
//             // $spent = false;
//             // foreach ($flat_simple['in'] as $serachout) {
//             //     if ($serachout['parent_id'] == $value['id']) {
//             //         $spent = true;
//             //         break;
//             //     }
//             // }
//
//             $key = array_search($value['id'], array_column($flat_simple['in'], 'parent_id'));
//             $spent = ($key !== false) ? true:false;
//
//             $ocounted[$value['id']] = true;
//             if ($spent) {
//                 if (!isset($spend[$value['raw']['unlockhash']])) {
//                     $spend[$value['raw']['unlockhash']] = 0;
//                 }
//
//                 $spend[$value['raw']['unlockhash']] += $value['raw']['value']/1e24;
//             }
//
//             if (!isset($outs[$value['raw']['unlockhash']])) {
//                 $outs[$value['raw']['unlockhash']] = 0;
//             }
//       //if(!$spent) {
//         $outs[$value['raw']['unlockhash']] += $value['raw']['value']/1e24;
//       //}
//         $fpdo->update('hashes')
//              ->set([
//                  'amount' => $value['raw']['value'],
//                  'spent' => $spent
//              ])
//              ->where('hash', $value['id'])
//              ->execute();
//         }
//     }
// }
//
// $flat_end = microtime(true);
// $flat_process_time = $flat_end-$flat_start;
//
// $outs_start = microtime(true);
// $totalbalance = 0;
// foreach ($outs as $addr => $out) {
//     $in = @$ins[$addr];
//     $spent = @$spend[$addr];
//
//     $in_array = in_array($addr, $addresses);
//     //echo "(".(($in_array) ? "+++":"---")."){$addr} - Out: {$out} SC ---- in: {$in} SC ---- Spent: {$spent} SC<br>";
//
//     if ($in_array && empty($spent)) {
//         $balance = $out;
//         $totalbalance += $balance;
//         //echo "<h3>current balance: {$balance} SC</h3>";
//     }
// }
//
// $outs_end = microtime(true);
// $outs_process_time = $outs_end-$outs_start;
//
// echo "<h3>current total balance: {$totalbalance} SC</h3>";
//
//
// $total_end = microtime(true);
// $total_process_time = $total_end-$total_start;
//
// echo "Flat: {$flat_process_time}s".PHP_EOL;
// echo "Outs: {$outs_process_time}s".PHP_EOL;
// echo "Total: {$total_process_time}s".PHP_EOL;
