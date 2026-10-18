<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Nightly prewarm of the stats cache for active users (login within the
 * last 30 days). Doubles as the drift healer: entries are recomputed when
 * stale, so write paths that missed an invalidation self-heal within a day.
 * Time-boxed; resumes at the stored cursor on the next run.
 */
class Statistics_cache extends CI_Controller {

	/**
	 * Cron endpoint: warm hot stat entries of recently active users.
	 * Requires master-cron authentication (X-Wavelog-Auth header) or an
	 * admin session, like the other cron endpoints.
	 *
	 * @return void
	 */
	public function prewarm() {
		$this->load->helper('cronauth');
		if (!cronauth_allowed(3)) {
			$this->output->set_status_header(403);
			exit();
		}

		$this->load->is_loaded('cache') ?: $this->load->driver('cache', [
			'adapter' => $this->config->item('cache_adapter') ?? 'file',
			'backup' => $this->config->item('cache_backup') ?? 'file',
			'key_prefix' => $this->config->item('cache_key_prefix') ?? ''
		]);

		$this->load->model('cron_model');
		$this->cron_model->set_last_run($this->router->class . '_' . $this->router->method);

		$start = microtime(true);
		$cursor = (int)($this->cache->get('wvl_stats_prewarm_cursor') ?: 0);

		// one batch per run; the daily cron advances the cursor until the
		// active-user list is exhausted, then wraps around
		$users = $this->db->query(
			'SELECT user_id FROM users WHERE last_login_date >= (NOW() - INTERVAL 30 DAY) AND user_id > ? ORDER BY user_id LIMIT 500',
			[$cursor]
		)->result();

		foreach ($users as $u) {
			$this->stats_cache->warm_user($u->user_id);
			$cursor = (int)$u->user_id;
			if ((microtime(true) - $start) > 280) {
				break;
			}
		}

		if (count($users) < 500) {
			$cursor = 0; // all active users done; restart next run
		}
		$this->cache->save('wvl_stats_prewarm_cursor', $cursor, 172800);
	}
}
