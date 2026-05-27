<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Error_collector {

	private static $registered = false;
	private static $handling = false;

	public function register() {
		if (self::$registered) {
			return;
		}
		self::$registered = true;

		set_error_handler(array($this, 'handle_error'));
		set_exception_handler(array($this, 'handle_exception'));
		register_shutdown_function(array($this, 'handle_fatal'));
	}

	public function handle_error($severity, $message, $file, $line) {
		$level_map = array(
			E_ERROR             => 'error',
			E_WARNING           => 'warning',
			E_PARSE             => 'error',
			E_NOTICE            => 'notice',
			E_CORE_ERROR        => 'error',
			E_CORE_WARNING      => 'warning',
			E_COMPILE_ERROR     => 'error',
			E_COMPILE_WARNING   => 'warning',
			E_USER_ERROR        => 'error',
			E_USER_WARNING      => 'warning',
			E_USER_NOTICE       => 'notice',
			E_RECOVERABLE_ERROR => 'error',
			E_DEPRECATED        => 'notice',
			E_USER_DEPRECATED   => 'notice',
		);

		$level = isset($level_map[$severity]) ? $level_map[$severity] : 'error';

		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
		$backtrace = $this->compact_backtrace($backtrace);

		$this->persist($level, $message, $file, $line, $backtrace);

		return false;
	}

	public function handle_exception($exception) {
		$backtrace = $exception->getTrace();
		$backtrace = $this->compact_backtrace($backtrace);

		$this->persist(
			'exception',
			get_class($exception) . ': ' . $exception->getMessage(),
			$exception->getFile(),
			$exception->getLine(),
			$backtrace
		);
	}

	public function handle_fatal() {
		$error = error_get_last();
		if ($error === null) {
			return;
		}

		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
		if (!($error['type'] & $fatal_types)) {
			return;
		}

		$this->persist('fatal', $error['message'], $error['file'], $error['line'], null);
	}

	private function persist($level, $message, $file, $line, $backtrace) {
		if (self::$handling) {
			return;
		}
		self::$handling = true;

		try {
			$user_id = null;
			$request_url = null;

			if (isset($_SERVER['REQUEST_URI'])) {
				$request_url = substr($_SERVER['REQUEST_URI'], 0, 512);
			}

			$CI = &get_instance();
			if ($CI && isset($CI->session)) {
				$uid = $CI->session->userdata('user_id');
				if ($uid !== null && $uid !== '') {
					$user_id = (int) $uid;
				}
			}

			$data = array(
				'timestamp'   => date('Y-m-d H:i:s'),
				'level'       => $level,
				'source'      => 'php',
				'message'     => $message,
				'file'        => $file,
				'line'        => $line,
				'user_id'     => $user_id,
				'request_url' => $request_url,
				'backtrace'   => $backtrace,
			);

			if ($CI && isset($CI->db) && $CI->db->conn_id) {
				$CI->db->insert('php_error_log', $data);
			} else {
				log_message('error', 'Error_collector: ' . $level . ' - ' . $message . ' in ' . $file . ':' . $line);
			}
		} catch (\Throwable $e) {
			log_message('error', 'Error_collector failed: ' . $e->getMessage());
		}

		self::$handling = false;
	}

	private function compact_backtrace($backtrace) {
		if (empty($backtrace)) {
			return null;
		}

		$compact = array();
		foreach (array_slice($backtrace, 0, 10) as $frame) {
			$compact[] = array(
				'file' => isset($frame['file']) ? $frame['file'] : null,
				'line' => isset($frame['line']) ? $frame['line'] : null,
				'function' => isset($frame['function']) ? $frame['function'] : null,
				'class' => isset($frame['class']) ? $frame['class'] : null,
			);
		}

		return json_encode($compact);
	}
}
