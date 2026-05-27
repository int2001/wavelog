<?php

defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('log_user_message')) {
	function log_user_message($level, $message, $user_id = null, $source = 'application') {
		log_message($level, $message);

		try {
			$CI =& get_instance();

			if ($user_id === null) {
				if ($CI && isset($CI->session)) {
					$uid = $CI->session->userdata('user_id');
					if ($uid !== null && $uid !== '') {
						$user_id = (int) $uid;
					}
				}
			} else {
				$user_id = (int) $user_id;
			}

			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			$caller_file = isset($trace[0]['file']) ? $trace[0]['file'] : null;
			$caller_line = isset($trace[0]['line']) ? $trace[0]['line'] : null;

			$request_url = null;
			if (isset($_SERVER['REQUEST_URI'])) {
				$request_url = substr($_SERVER['REQUEST_URI'], 0, 512);
			}

			if (!$CI->load->is_loaded('Error_log_model')) {
				$CI->load->model('Error_log_model');
			}

			$CI->Error_log_model->log_error(array(
				'timestamp'   => date('Y-m-d H:i:s'),
				'level'       => $level,
				'source'      => $source,
				'message'     => $message,
				'file'        => $caller_file,
				'line'        => $caller_line,
				'user_id'     => $user_id,
				'request_url' => $request_url,
				'backtrace'   => null,
			));
		} catch (\Throwable $e) {
			log_message('error', 'log_user_message failed: ' . $e->getMessage());
		}
	}
}
