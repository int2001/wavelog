<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_php_error_log_table extends CI_Migration {

	public function up() {
		if (!$this->db->table_exists('php_error_log')) {
			$this->dbforge->add_field(array(
				'id' => array(
					'type' => 'INT',
					'constraint' => 11,
					'unsigned' => TRUE,
					'auto_increment' => TRUE,
				),
				'timestamp' => array(
					'type' => 'DATETIME',
					'null' => FALSE,
				),
				'level' => array(
					'type' => 'VARCHAR',
					'constraint' => 20,
					'null' => FALSE,
				),
				'source' => array(
					'type' => 'VARCHAR',
					'constraint' => 20,
					'null' => FALSE,
					'default' => 'php',
				),
				'message' => array(
					'type' => 'TEXT',
					'null' => FALSE,
				),
				'file' => array(
					'type' => 'VARCHAR',
					'constraint' => 512,
					'null' => TRUE,
				),
				'line' => array(
					'type' => 'INT',
					'constraint' => 11,
					'null' => TRUE,
				),
				'user_id' => array(
					'type' => 'INT',
					'constraint' => 11,
					'unsigned' => TRUE,
					'null' => TRUE,
				),
				'request_url' => array(
					'type' => 'VARCHAR',
					'constraint' => 512,
					'null' => TRUE,
				),
				'backtrace' => array(
					'type' => 'TEXT',
					'null' => TRUE,
				),
			));

			$this->dbforge->add_key('id', TRUE);
			$this->dbforge->create_table('php_error_log');

			$table = $this->db->escape_identifiers('php_error_log');

			$this->dbtry("ALTER TABLE {$table} ADD INDEX " . $this->db->escape_identifiers('idx_user_id') . " (" . $this->db->escape_identifiers('user_id') . ")");
			$this->dbtry("ALTER TABLE {$table} ADD INDEX " . $this->db->escape_identifiers('idx_timestamp') . " (" . $this->db->escape_identifiers('timestamp') . ")");
			$this->dbtry("ALTER TABLE {$table} ADD INDEX " . $this->db->escape_identifiers('idx_level') . " (" . $this->db->escape_identifiers('level') . ")");
			$this->dbtry("ALTER TABLE {$table} ADD INDEX " . $this->db->escape_identifiers('idx_source') . " (" . $this->db->escape_identifiers('source') . ")");
		}
	}

	public function down() {
		$this->dbforge->drop_table('php_error_log');
	}

	function dbtry($what) {
		try {
			$this->db->query($what);
		} catch (Exception $e) {
			log_message("error", "Migration 279 failed: " . $e . " // Executing: " . $this->db->last_query());
		}
	}
}
