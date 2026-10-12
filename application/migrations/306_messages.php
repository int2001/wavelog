<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Generic message store (base for a future message center).
 * MVP: single 'interface_disabled' message per interface/scope/ref holding the
 * last reason why a 3rd-party interface (Clublog, eQSL, HRDLog, QRZ, LoTW) was
 * auto-disabled, plus the previous state of the disabled flag where restorable.
 */
class Migration_messages extends CI_Migration {

	public function up()
	{
		if (!$this->db->table_exists('messages')) {
			$this->dbforge->add_field(array(
				'message_id' => array(
					'type' => 'INT',
					'constraint' => 11,
					'unsigned' => TRUE,
					'auto_increment' => TRUE,
					'null' => FALSE
				),
				'message_type' => array(
					'type' => 'VARCHAR',
					'constraint' => 30,
					'null' => FALSE,
					'default' => 'interface_disabled'
				),
				'message_context' => array(
					'type' => 'VARCHAR',
					'constraint' => 20,
					'null' => FALSE
				),
				'message_scope' => array(
					'type' => 'VARCHAR',
					'constraint' => 10,
					'null' => FALSE
				),
				'message_ref_id' => array(
					'type' => 'INT',
					'constraint' => 11,
					'unsigned' => TRUE,
					'null' => FALSE
				),
				'message_text' => array(
					'type' => 'TEXT',
					'null' => FALSE
				),
				'message_last_state' => array(
					'type' => 'TEXT',
					'null' => TRUE
				),
				'message_datetime' => array(
					'type' => 'DATETIME',
					'null' => FALSE
				),
			));
			$this->dbforge->add_key('message_id', TRUE);
			$this->dbforge->create_table('messages');
			// one message per type/context/scope/ref = "last reason only"
			$this->db->query("ALTER TABLE messages ADD UNIQUE KEY uq_msg (message_type, message_context, message_scope, message_ref_id)");
		}
	}

	public function down()
	{
		$this->dbforge->drop_table('messages');
	}

}
