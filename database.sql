CREATE TABLE `block_hash_index` (
  `hash_id` int(11) DEFAULT NULL,
  `height` int(11) DEFAULT NULL,
  UNIQUE KEY `idx_name` (`hash_id`,`height`),
  KEY `blocks_hash_id_index` (`hash_id`),
  KEY `block_hash_index_height_index` (`height`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `hashes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(100) NOT NULL,
  `type` enum('blockid','transactionid','unlockhash','siacoinoutputid','filecontractid','siafundoutputid') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hashes_hash_uindex` (`hash`),
  KEY `hashes_type_index` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `filecontract_proof_index` (
  `fc_hash_id` int(11) DEFAULT NULL,
  `proof_hash_id` int(11) DEFAULT NULL,
  UNIQUE KEY `idx_name` (`fc_hash_id`,`proof_hash_id`),
  KEY `fc_hash_id_index` (`fc_hash_id`),
  KEY `proof_hash_id_index` (`proof_hash_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
