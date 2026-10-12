<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Generic message store (base for a future message center).
 * MVP: single 'interface_disabled' message per interface/scope/ref holding the
 * last reason why a 3rd-party interface (Clublog, eQSL, HRDLog, QRZ, LoTW) was
 * auto-disabled, plus the previous state of the disabled flag where restorable.
 *
 * Two reference columns instead of a polymorphic ref_id, so both can carry a
 * real foreign key with ON DELETE CASCADE (station_profile.station_id is
 * INT UNSIGNED; users.user_id is signed in install.sql but unsigned on drifted
 * instances — the column type is matched to the live one, else the FK fails).
 */
class Migration_messages extends CI_Migration {

	public function up()
	{
		if (!$this->db->table_exists('messages')) {
			$auth_table = (string)$this->config->item('auth_table');

			if (preg_match('/^[A-Za-z0-9_]+$/', $auth_table)) {
				$row = $this->db->query(
					"SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'user_id'",
					[$auth_table]
				)->row();
				$user_id_unsigned = str_contains($row->column_type ?? '', 'unsigned') ? 'UNSIGNED' : '';
			} else {
				$auth_table = null; // no valid auth table name: skip the user FK
			}

			// One message per type/context/ref. NOTE: MySQL/MariaDB treat NULLs as
			// distinct in unique indexes, so the model's delete-then-insert stays
			// the authoritative "last message only" guard.
			$this->dbtry("CREATE TABLE IF NOT EXISTS messages (
				message_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				message_type VARCHAR(30) NOT NULL DEFAULT 'interface_disabled',
				message_context VARCHAR(20) NOT NULL,
				message_station_id INT UNSIGNED DEFAULT NULL,
				message_user_id INT {$user_id_unsigned} DEFAULT NULL,
				message_text TEXT NOT NULL,
				message_last_state TEXT DEFAULT NULL,
				message_datetime DATETIME NOT NULL,
				PRIMARY KEY (message_id),
				UNIQUE KEY uq_msg (message_type, message_context, message_station_id, message_user_id),
				KEY fk_msg_station (message_station_id),
				KEY fk_msg_user (message_user_id),
				CONSTRAINT fk_msg_station FOREIGN KEY (message_station_id) REFERENCES station_profile (station_id) ON DELETE CASCADE" .
				($auth_table !== null ? ",
				CONSTRAINT fk_msg_user FOREIGN KEY (message_user_id) REFERENCES `{$auth_table}` (user_id) ON DELETE CASCADE" : "") . "
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		}
	}

	public function down()
	{
		$this->dbtry("DROP TABLE IF EXISTS messages");
	}

	function dbtry($what) {
		try {
			$this->db->query($what);
		} catch (Exception $e) {
			log_message("error", "Mig 306: Something gone wrong while creating the messages table: ".$e." // Executing: ".$this->db->last_query());
		}
	}
}
