<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Messages_model
 *
 * Minimal message store. MVP: 'interface_disabled' messages explaining why a
 * 3rd-party interface was auto-disabled (ban protection). One message per
 * type/context/scope/ref — a new message replaces the previous one.
 */
class Messages_model extends CI_Model {

	/**
	 * Store a message (replaces any existing message for the same key).
	 *
	 * @param string      $type       message type, e.g. 'interface_disabled'
	 * @param string      $context    interface identifier, e.g. 'clublog', 'eqsl_user', 'lotw'
	 * @param string      $scope      'station' or 'user'
	 * @param int         $ref_id     station_id (scope station) or user_id (scope user)
	 * @param string      $text       human readable reason (sanitized here)
	 * @param string|null $last_state previous value of the disabled flag, if restorable
	 *
	 * @return void
	 */
	public function add_message($type, $context, $scope, $ref_id, $text, $last_state = null) {
		$sql = "DELETE FROM messages WHERE message_type = ? AND message_context = ? AND message_scope = ? AND message_ref_id = ?";
		$this->db->query($sql, [$type, $context, $scope, (int)$ref_id]);

		$sql = "INSERT INTO messages (message_type, message_context, message_scope, message_ref_id, message_text, message_last_state, message_datetime)
			VALUES (?, ?, ?, ?, ?, ?, NOW())";
		$this->db->query($sql, [
			$type,
			$context,
			$scope,
			(int)$ref_id,
			$this->security->xss_clean((string)$text),
			$last_state !== null ? $this->security->xss_clean((string)$last_state) : null,
		]);
	}

	/**
	 * Fetch all messages of a type for one station or user.
	 *
	 * @param string $type   message type, e.g. 'interface_disabled'
	 * @param string $scope  'station' or 'user'
	 * @param int    $ref_id station_id (scope station) or user_id (scope user)
	 *
	 * @return array rows indexed by message_context
	 */
	public function get_messages($type, $scope, $ref_id) {
		$sql = "SELECT * FROM messages WHERE message_type = ? AND message_scope = ? AND message_ref_id = ?";
		$query = $this->db->query($sql, [$type, $scope, (int)$ref_id]);

		$result = [];
		foreach ($query->result() as $row) {
			$result[$row->message_context] = $row;
		}
		return $result;
	}

	/**
	 * Remove a message (called when the user re-enables the interface).
	 *
	 * @param string $type    message type, e.g. 'interface_disabled'
	 * @param string $context interface identifier, e.g. 'clublog'
	 * @param string $scope   'station' or 'user'
	 * @param int    $ref_id  station_id (scope station) or user_id (scope user)
	 *
	 * @return void
	 */
	public function clear_message($type, $context, $scope, $ref_id) {
		$sql = "DELETE FROM messages WHERE message_type = ? AND message_context = ? AND message_scope = ? AND message_ref_id = ?";
		$this->db->query($sql, [$type, $context, $scope, (int)$ref_id]);
	}

}
