<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Messages_model
 *
 * Minimal message store. MVP: 'interface_disabled' messages explaining why a
 * 3rd-party interface was auto-disabled (ban protection). One message per
 * type/context/scope/ref — a new message replaces the previous one.
 *
 * Scope mapping (public API stays scope-based, storage is column-based):
 *   'station' -> message_station_id = ref_id, message_user_id = NULL
 *   'user'    -> message_user_id    = ref_id, message_station_id = NULL
 */
class Messages_model extends CI_Model {

	/**
	 * Map a scope to its storage column and the sibling NULL column.
	 *
	 * @param string $scope 'station' or 'user'
	 *
	 * @return array|null [own_column, null_column] or null for unknown scope
	 */
	private function scope_columns($scope) {
		switch ($scope) {
			case 'station':
				return ['message_station_id', 'message_user_id'];
			case 'user':
				return ['message_user_id', 'message_station_id'];
			default:
				return null;
		}
	}

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
		$cols = $this->scope_columns($scope);
		if ($cols === null) {
			return;
		}
		list($own_col, $null_col) = $cols;

		$sql = "DELETE FROM messages WHERE message_type = ? AND message_context = ? AND {$own_col} = ? AND {$null_col} IS NULL";
		$this->db->query($sql, [$type, $context, (int)$ref_id]);

		$sql = "INSERT INTO messages (message_type, message_context, {$own_col}, {$null_col}, message_text, message_last_state, message_datetime)
			VALUES (?, ?, ?, NULL, ?, ?, NOW())";
		$this->db->query($sql, [
			$type,
			$context,
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
	 * @return array rows indexed by message_context (empty for unknown scope)
	 */
	public function get_messages($type, $scope, $ref_id) {
		$cols = $this->scope_columns($scope);
		if ($cols === null) {
			return [];
		}
		list($own_col, $null_col) = $cols;

		$sql = "SELECT * FROM messages WHERE message_type = ? AND {$own_col} = ? AND {$null_col} IS NULL";
		$query = $this->db->query($sql, [$type, (int)$ref_id]);

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
		$cols = $this->scope_columns($scope);
		if ($cols === null) {
			return;
		}
		list($own_col, $null_col) = $cols;

		$sql = "DELETE FROM messages WHERE message_type = ? AND message_context = ? AND {$own_col} = ? AND {$null_col} IS NULL";
		$this->db->query($sql, [$type, $context, (int)$ref_id]);
	}

}
