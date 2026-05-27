<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Error_log_model extends CI_Model {

	public function log_error($data) {
		try {
			$sql = "INSERT INTO php_error_log (timestamp, level, source, message, file, line, user_id, request_url, backtrace) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
			return $this->db->query($sql, array(
				$data['timestamp'],
				$data['level'],
				$data['source'],
				$data['message'],
				$data['file'],
				$data['line'],
				$data['user_id'],
				$data['request_url'],
				$data['backtrace'],
			));
		} catch (Exception $e) {
			log_message("error", "Error_log_model::log_error failed: " . $e->getMessage());
			return false;
		}
	}

	public function get_errors($filters = array(), $limit = 100, $offset = 0) {
		try {
			$where = "1=1";
			$binds = array();

			if (!empty($filters['user_id'])) {
				$where .= " AND user_id = ?";
				$binds[] = $filters['user_id'];
			}
			if (!empty($filters['level'])) {
				$where .= " AND level = ?";
				$binds[] = $filters['level'];
			}
			if (!empty($filters['source'])) {
				$where .= " AND source = ?";
				$binds[] = $filters['source'];
			}
			if (!empty($filters['date_from'])) {
				$where .= " AND timestamp >= ?";
				$binds[] = $filters['date_from'];
			}
			if (!empty($filters['date_to'])) {
				$where .= " AND timestamp <= ?";
				$binds[] = $filters['date_to'];
			}

			$binds[] = (int) $limit;
			$binds[] = (int) $offset;

			$sql = "SELECT * FROM php_error_log WHERE {$where} ORDER BY timestamp DESC LIMIT ? OFFSET ?";
			return $this->db->query($sql, $binds)->result();
		} catch (Exception $e) {
			log_message("error", "Error_log_model::get_errors failed: " . $e->getMessage());
			return array();
		}
	}

	public function clear_errors($user_id = null) {
		try {
			if ($user_id !== null) {
				$sql = "DELETE FROM php_error_log WHERE user_id = ?";
				return $this->db->query($sql, array($user_id));
			}
			return $this->db->query("DELETE FROM php_error_log");
		} catch (Exception $e) {
			log_message("error", "Error_log_model::clear_errors failed: " . $e->getMessage());
			return false;
		}
	}

	public function purge_old_errors($days = 30) {
		try {
			$sql = "DELETE FROM php_error_log WHERE timestamp < ?";
			$this->db->query($sql, array(date('Y-m-d H:i:s', strtotime("-{$days} days"))));
			return $this->db->affected_rows();
		} catch (Exception $e) {
			log_message("error", "Error_log_model::purge_old_errors failed: " . $e->getMessage());
			return false;
		}
	}
}
