<?php
require('vendor/autoload.php');
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/*
 * Blockchain indexer
 *
 **/
class Updater
{
    public static $db = false;
    public static $hash_pool = [];
    public static $block_pool = [];

    /**
     * Init indexer
     *
     * Initiate indexer with FluentPDO DB instance
     *
     * @param FluentPDO $db FluentPDO instance
     */
    public static function init($db)
    {
        self::$db = $db;
    }

    /**
     * Add blockchain hash to database
     *
     * Add hash to database with specified type and height
     *
     * @param string $hash Hash
     * @param string $type Type 'blockid', 'transactionid', 'unlockhash', 'siacoinoutputid', 'filecontractid', 'siafundoutputid'
     * @param string $height Block height where hash appears
     */
    public static function addHash($hash, $type, $height)
    {
        self::$hash_pool[$height][] = [
            'hash' => $hash,
            'type' => $type,
        ];

        if (count(self::$hash_pool) > 10) {
            self::cleanPool();
        }
    }

    /**
     * Add hash reference to block height
     *
     * Add hash_id reference to specified height
     *
     * @param int $hash_id Hash
     * @param string $height Block height where hash appears
     */
    public static function addHashBlock($hash_id, $height)
    {
        if ($hash_id <= 0) {
            return false;
        }

        self::$block_pool[] = [
            'hash_id' => $hash_id,
            'height' => $height,
        ];

        if (count(self::$block_pool) > 5000) {
            self::cleanPool();
        }
    }

    /**
     * Insert pool data into DB
     *
     * Insert collected data into database and clean pools
     *
     */
    public static function cleanPool()
    {
        if (count(self::$hash_pool)) {
            foreach (self::$hash_pool as $pool_height => $hashes) {
                self::$db->insertInto('hashes', $hashes)->ignore()->execute();

                $select = [];
                foreach ($hashes as $_hash) {
                    $select[] = $_hash['hash'];
                }
                $hash_id = self::$db->from('hashes')
                            ->where('hash', $select)
                            ->select('id');
                $hash_ids = $hash_id->fetchAll('id', 'id');
                if (count($hash_ids)) {
                    foreach ($hash_ids as $id => $hash_data) {
                        self::addHashBlock($id, $pool_height);
                    }
                }
            }
            self::$hash_pool = [];
        }

        if (count(self::$block_pool)) {
            self::$db->insertInto('block_hash_index', self::$block_pool)->execute();
            self::$block_pool = [];
        }
    }
}

$pdo = new PDO('mysql:host=localhost;dbname=explorer', 'root', 'root');
$fpdo = new FluentPDO($pdo);
$fpdo->debug = false;

Updater::init($fpdo);

$client = new Client([
    'headers' => [
       'User-Agent' => 'Sia-Agent',
   ]
 ]);

$res = $client->request('GET', 'http://localhost:9980/consensus');
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
    for ($i=$last_height-10; $i < $consensus->height; $i++) {
        yield new Request('GET', 'http://localhost:9980/consensus/blocks/'.$i);
    }
};

$pool = new Pool($client, $requests(), [
    'concurrency' => 20,
    'fulfilled' => function ($response, $index) use ($fpdo) {
        echo 'Completed request '.$index.PHP_EOL;
        $json = json_decode($response->getBody());

        $height = $json->blockheight;
        $prev_height = $json->blockheight-1;
        $prev_block_hash = $json->blockheader->parentid;

        Updater::addHash($prev_block_hash, 'blockid', $prev_height);

        foreach ($json->minerpayouts as $outputid => $output) {
            Updater::addHash($outputid, 'siacoinoutputid', $height);
            Updater::addHash($output->unlockhash, 'unlockhash', $height);
        }

        foreach ($json->transactions as $transactionid => $transaction) {
            Updater::addHash($transactionid, 'transactionid', $height);

            foreach ($transaction->siacoininputs as $scinoputid => $scinoput) {
                Updater::addHash($scinoputid, 'siacoinoutputid', $height);
            }

            foreach ($transaction->siacoinoutputs as $scoutputid => $scoutput) {
                Updater::addHash($scoutputid, 'siacoinoutputid', $height);
                Updater::addHash($scoutput->unlockhash, 'unlockhash', $height);
            }

            foreach ($transaction->filecontracts as $filecontractid => $fc) {
                Updater::addHash($filecontractid, 'filecontractid', $height);
                Updater::addHash($fc->unlockhash, 'unlockhash', $height);

                foreach ($fc->validproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                }
                foreach ($fc->missedproofoutputs as $key => $value) {
                    Updater::addHash($key, 'siacoinoutputid', $height);
                    Updater::addHash($value->unlockhash, 'unlockhash', $height);
                }
            }

            foreach ($transaction->siafundinputs as $scinoputid => $scinoput) {
                Updater::addHash($scinoputid, 'siafundoutputid', $height);
            }

            foreach ($transaction->siafundoutputs as $scoutputid => $scoutput) {
                Updater::addHash($scoutputid, 'siafundoutputid', $height);
                Updater::addHash($scoutput->unlockhash, 'unlockhash', $height);
            }
        }
    },
    'rejected' => function ($reason, $index) {
    },
]);

$promise = $pool->promise();
$promise->wait();

Updater::cleanPool();
