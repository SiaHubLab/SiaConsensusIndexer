<?php
namespace App;

/*
 * Blockchain indexer
 *
 **/
class Updater
{
    public static $db = false;
    public static $hash_pool = [];
    public static $block_pool = [];
    public static $proof_pool = [];
    public static $proof_prepare_queue = [];
    public static $ready_proof_pool = [];

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
     * Add storage proof reference to file contract
     *
     * @param string $proof_output_hash Hash
     * @param string $file_contract_hash
     * @param int $height block height to make sure that hashes already in DB
     */
    public static function addProof($proof_output_hash, $file_contract_hash, $height)
    {
        self::$proof_pool[$height][] = [
            'fc_hash_id' => $proof_output_hash,
            'proof_hash_id' => $file_contract_hash,
        ];
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
                    unset(self::$hash_pool[$pool_height]);
                    self::cleanProofPool($pool_height);
                    foreach ($hash_ids as $id => $hash_data) {
                        self::addHashBlock($id, $pool_height);
                    }
                }
            }
            self::$hash_pool = [];
        }

        if (count(self::$block_pool)) {
            self::$db->insertInto('block_hash_index', self::$block_pool)->ignore()->execute();
            self::$block_pool = [];
        }
    }

    /**
     * Prepare proofs to dump into DB
     *
     * Convert proofs hashes to ids before insert into DBs
     *
     */
    public static function cleanProofPool($height = false)
    {
        if ($height) {
            self::$proof_prepare_queue[] = $height;
        }

        if (count(self::$proof_pool)) {
            $dump_proofs = [];
            if ($height && count(self::$proof_prepare_queue) > 10) {
                foreach (self::$proof_prepare_queue as $prepare_height) {
                    if (!isset(self::$proof_pool[$prepare_height])) {
                        return false;
                    }
                    $dump_proofs[$prepare_height] = self::$proof_pool[$prepare_height];
                    unset(self::$proof_pool[$prepare_height]);
                }
                self::$proof_prepare_queue = [];
            }

            if (!$height) {
                $dump_proofs = self::$proof_pool;
                self::$proof_pool = [];
            }

            if (count($dump_proofs)) {
                foreach ($dump_proofs as $pool_height => $proofs) {
                    $select = [];
                    foreach ($proofs as $_hash) {
                        $select[] = $_hash['fc_hash_id'];
                        $select[] = $_hash['proof_hash_id'];
                    }
                    $hash_id = self::$db->from('hashes')
                                ->where('hash', $select)
                                ->select('id, hash');
                    $hash_ids = $hash_id->fetchAll('id', 'hash');
                    if (count($hash_ids)) {
                        $hash_to_id = [];
                        foreach ($hash_ids as $id => $hash_data) {
                            $hash_to_id[$hash_data['hash']] = $id;
                        }

                        $proof_to_db = [];
                        foreach ($proofs as $_hash) {
                            $fc_hash_id = $hash_to_id[$_hash['fc_hash_id']];
                            $proof_hash_id = $hash_to_id[$_hash['proof_hash_id']];
                            if (!empty($fc_hash_id) && !empty($proof_hash_id)) {
                                self::$ready_proof_pool[] = [
                                    'fc_hash_id' => $fc_hash_id,
                                    'proof_hash_id' => $proof_hash_id,
                                ];
                                if (count(self::$ready_proof_pool) > 100) {
                                    self::dumpProofs();
                                }
                            } else {
                                echo "Pair not found {$_hash['fc_hash_id']} || {$_hash['proof_hash_id']}".PHP_EOL;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Insert ready storage proof pool data into DB
     *
     * Insert collected data into database and clean pool
     *
     */
    public static function dumpProofs()
    {
        if (count(self::$ready_proof_pool)) {
            self::$db->insertInto('filecontract_proof_index', self::$ready_proof_pool)->ignore()->execute();
            self::$ready_proof_pool = [];
        }
    }
}
