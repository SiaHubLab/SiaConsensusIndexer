create table block_hash_index
(
	hash_id int null,
	height int null
)
;

create index blocks_hash_id_index
	on block_hash_index (hash_id)
;

create index block_hash_index_height_index
	on block_hash_index (height)
;

create table hashes
(
	id int auto_increment
		primary key,
	hash varchar(100) not null,
	type enum('blockid', 'transactionid', 'unlockhash', 'siacoinoutputid', 'filecontractid', 'siafundoutputid') null,
	constraint hashes_hash_uindex
		unique (hash)
)
;

create index hashes_type_index
	on hashes (type)
;
