<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Serializable stand-in for a CI_DB_result, so model functions that return
 * the query object itself can be cached transparently. Consumers only use
 * result()/result_array()/row()/num_rows() on these grouped-stat queries.
 */
class Stats_cached_result {

	private $rows;

	/**
	 * @param array $rows array of row objects (query->result())
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;
	}

	/**
	 * @return array array of row objects
	 */
	public function result() {
		return $this->rows;
	}

	/**
	 * @return array array of associative arrays
	 */
	public function result_array() {
		$out = [];
		foreach ($this->rows as $row) {
			$out[] = (array)$row;
		}
		return $out;
	}

	/**
	 * @param  int $n row index
	 * @return object|null
	 */
	public function row($n = 0) {
		return $this->rows[$n] ?? null;
	}

	/**
	 * @return int
	 */
	public function num_rows() {
		return count($this->rows);
	}
}

/**
 * Read-through cache for QSO statistics (dashboard, DXCC awards, statistics pages).
 *
 * Works on any CI cache adapter (file, apcu, redis, memcached) - no Redis-specific
 * code. Correctness model:
 *  - Entries are keyed by scope + filter args + sorted station ids + a global epoch.
 *  - Every write to the QSO table marks the affected station(s) "dirty" (timestamp).
 *  - A cached entry is served while it is newer than the newest dirty mark of its
 *    stations. If it is older it is recomputed, unless the dirty mark is younger
 *    than DEBOUNCE seconds (bulk imports / LoTW syncs then serve slightly stale
 *    data instead of triggering a recompute per page view).
 *  - Single QSO inserts additionally patch the cached "counts" and "dash" entries
 *    in place (fast-path), so the dashboard stays exact during active logging.
 *  - Date-sensitive scopes (today/month/year buckets) recompute on day rollover.
 *  - Multi-node installs should use redis/memcached adapters (file/apcu are
 *    per-node; the nightly prewarm cron heals missed invalidations).
 *
 * Missed write paths self-heal via the entry TTL (7 days) and the nightly
 * prewarm cron (recomputes entries of users active in the last 30 days).
 */
class Stats_cache {

	const PREFIX = 'wvl_stats_';
	const TTL = 604800;         // 7 days
	const DIRTY_TTL = 691200;   // 8 days (> entry TTL)
	const DEBOUNCE = 30;        // seconds to serve stale data during write bursts

	private static $date_sensitive = ['counts', 'dash'];
	private static $ci;
	private static $scope_stations = [];

	/**
	 * Get the CI super object (lazily) and make sure the cache driver is loaded.
	 *
	 * @return CI_Controller
	 */
	private static function ci() {
		if (self::$ci === null) {
			self::$ci = get_instance();
		}
		$ci = self::$ci;
		$ci->load->is_loaded('cache') ?: $ci->load->driver('cache', [
			'adapter' => $ci->config->item('cache_adapter') ?? 'file',
			'backup' => $ci->config->item('cache_backup') ?? 'file',
			'key_prefix' => $ci->config->item('cache_key_prefix') ?? ''
		]);
		return $ci;
	}

	/**
	 * Return the CI cache driver.
	 *
	 * @return CI_Cache
	 */
	private static function cache() {
		return self::ci()->cache;
	}

	/**
	 * Normalize a station id set to a sorted, de-duplicated array of positive ints.
 * When null, resolve the station ids of the session's active station logbook
	 * (same resolution the calling model functions use internally).
	 *
	 * @param  array|int|string|null $stations station ids or null for session scope
	 * @return array                            empty array = nothing cacheable
	 */
	private function normalize_stations($stations) {
		if ($stations === null) {
			$ci = self::ci();
			$lbid = $ci->session->userdata('active_station_logbook');
			if (!isset(self::$scope_stations[$lbid])) {
				$ci->load->model('logbooks_model');
				self::$scope_stations[$lbid] = $ci->logbooks_model->list_logbook_relationships($lbid);
			}
			$stations = self::$scope_stations[$lbid];
		}
		if (!is_array($stations)) {
			$stations = [$stations];
		}
		$stations = array_map('intval', $stations);
		$stations = array_values(array_unique(array_filter($stations, function ($s) {
			return $s > 0;
		})));
		sort($stations);
		return $stations;
	}

	/**
	 * Build the cache key for a scope. Includes the filter args, the sorted
	 * station ids and the global epoch (bumping the epoch invalidates everything).
	 *
	 * @param  string $scope    short scope name, e.g. 'counts', 'dash'
	 * @param  mixed  $filter   filter args of the wrapped query (serialized into the key)
	 * @param  array  $stations normalized station ids
	 * @return string
	 */
	private function key($scope, $filter, $stations) {
		$epoch = $this->cache()->get(self::PREFIX . 'epoch');
		return self::PREFIX . 'e_' . $scope . '_' . md5($scope . '|' . serialize($filter) . '|' . implode(',', $stations) . '|' . (is_string($epoch) || is_numeric($epoch) ? $epoch : '0'));
	}

	/**
	 * Read a cached result. Returns null on miss, on day rollover for
	 * date-sensitive scopes, and when a recompute is due (stale beyond the
	 * debounce window). During the debounce window stale data is served.
	 *
	 * @param  string      $scope    scope name
	 * @param  mixed       $filter   filter args used in the key
	 * @param  array|int   $stations station ids (null = session's active logbook)
	 * @return mixed|null             cached data or null
	 */
	public function read($scope, $filter = [], $stations = null) {
		$stations = $this->normalize_stations($stations);
		if (!$stations) {
			return null;
		}
		$entry = $this->cache()->get($this->key($scope, $filter, $stations));
		if (!is_array($entry) || !isset($entry['ts'], $entry['data'])) {
			return null;
		}
		if (in_array($scope, self::$date_sensitive, true) && ($entry['day'] ?? '') != date('Y-m-d')) {
			return null;
		}
		$dirty = 0;
		foreach ($stations as $sid) {
			$t = $this->cache()->get(self::PREFIX . 'w_' . $sid);
			if (is_numeric($t) && $t > $dirty) {
				$dirty = (int)$t;
			}
		}
		if ($dirty > $entry['ts']) {
			if ((time() - $dirty) < self::DEBOUNCE) {
				return $entry['data']; // serve stale during a write burst
			}
			return null; // due for recompute
		}
		return $entry['data'];
	}

	/**
	 * Store a computed result and return it, so call sites can
	 * "return $this->stats_cache->write(...)". Empty scopes and null data
	 * are not cached.
	 *
	 * @param  string    $scope    scope name
	 * @param  mixed     $filter   filter args used in the key
	 * @param  mixed     $data     result of the wrapped query
	 * @param  array|int $stations station ids (null = session's active logbook)
	 * @return mixed               $data unchanged
	 */
	public function write($scope, $filter, $data, $stations = null) {
		$stations = $this->normalize_stations($stations);
		if (!$stations || $data === null) {
			return $data;
		}
		$this->cache()->save($this->key($scope, $filter, $stations), [
			'ts' => time(),
			'day' => date('Y-m-d'),
			'data' => $data,
		], self::TTL);
		return $data;
	}

	/**
	 * Mark stations dirty (their stat entries must be recomputed).
	 *
	 * @param  int|array|string $station_ids single id, comma string or array of ids
	 * @return void
	 */
	public function invalidate_stations($station_ids) {
		if (!is_array($station_ids)) {
			$station_ids = is_string($station_ids) ? explode(',', $station_ids) : [$station_ids];
		}
		foreach ($station_ids as $sid) {
			$sid = (int)$sid;
			if ($sid > 0) {
				$this->cache()->save(self::PREFIX . 'w_' . $sid, time(), self::DIRTY_TTL);
			}
		}
	}

	/**
	 * Mark all stations of a user dirty (for user-scoped bulk SQL that has no
	 * station ids at hand).
	 *
	 * @param  int $user_id
	 * @return void
	 */
	public function invalidate_user($user_id) {
		$user_id = (int)$user_id;
		if ($user_id <= 0) {
			return;
		}
		$rows = self::ci()->db->query('SELECT station_id FROM station_profile WHERE user_id = ?', [$user_id])->result();
		$this->invalidate_stations(array_column($rows, 'station_id'));
	}

	/**
	 * Mark the stations of the given QSO primary keys dirty (resolves the
	 * station ids with one indexed query per chunk).
	 *
	 * @param  int|array $pks single COL_PRIMARY_KEY or array of them
	 * @return void
	 */
	public function invalidate_for_qsos($pks) {
		if (!is_array($pks)) {
			$pks = [$pks];
		}
		$pks = array_filter(array_map('intval', $pks));
		if (!$pks) {
			return;
		}
		foreach (array_chunk($pks, 1000) as $chunk) {
			$ph = implode(',', array_fill(0, count($chunk), '?'));
			$rows = self::ci()->db->query(
				'SELECT DISTINCT station_id FROM `' . self::ci()->config->item('table_name') . '` WHERE COL_PRIMARY_KEY IN (' . $ph . ')',
				$chunk
			)->result();
			$this->invalidate_stations(array_column($rows, 'station_id'));
		}
	}

	/**
	 * Bump the global epoch: all existing entries become unreachable and
	 * expire via TTL. Use after instance-wide data operations.
	 *
	 * @return void
	 */
	public function bump_epoch() {
		$this->cache()->save(self::PREFIX . 'epoch', (string)time(), 2592000);
	}

	/**
	 * Fast-path for single QSO inserts: mark the station dirty (everything
	 * except dashboard/counts recomputes after the debounce window) and patch
	 * the cached "counts" and "dash" entries in place so the dashboard stays
	 * exact while logging. Skipped when the inserted QSO already carries
	 * confirmation flags (rare; the debounce recompute covers those).
	 *
	 * @param  array $data   insert data array (COL_TIME_ON, COL_CALL, COL_BAND, COL_DXCC, COL_PROP_MODE, station_id, ...)
	 * @param  int   $qso_id COL_PRIMARY_KEY of the inserted QSO
	 * @return void
	 */
	public function insert_delta($data, $qso_id) {
		$ci = self::ci();
		$station_id = (int)($data['station_id'] ?? 0);
		if ($station_id <= 0 || empty($qso_id)) {
			return;
		}
		$this->invalidate_stations($station_id);
		foreach (['COL_QSL_RCVD', 'COL_LOTW_QSL_RCVD', 'COL_EQSL_QSL_RCVD', 'COL_QRZCOM_QSO_DOWNLOAD_STATUS', 'COL_CLUBLOG_QSO_DOWNLOAD_STATUS'] as $col) {
			if (strtoupper((string)($data[$col] ?? '')) === 'Y') {
				return;
			}
		}

		$logbooks = $ci->db->query(
			'SELECT DISTINCT station_logbook_id FROM station_logbooks_relationship WHERE station_location_id = ?',
			[$station_id]
		)->result_array();
		if (!$logbooks) {
			return;
		}

		// dash filter variants: null (API/visitor) and the session user's DXCC award bands (dashboard)
		$dash_filters = [null];
		if ($ci->session->userdata('user_id')) {
			$ci->load->model('bands');
			$dash_filters[] = $ci->bands->get_user_bands('dxcc');
		}

		foreach ($logbooks as $lb) {
			$st = $this->normalize_stations($this->stations_of_logbook($lb['station_logbook_id']));
			if (!$st) {
				continue;
			}
			$this->patch_counts($st, $data);
			foreach ($dash_filters as $f) {
				$this->patch_dash($st, $data, $qso_id, $f);
			}
		}
	}

	/**
	 * Station location ids of a station logbook.
	 *
	 * @param  int $logbook_id
	 * @return array
	 */
	private function stations_of_logbook($logbook_id) {
		$rows = self::ci()->db->query(
			'SELECT station_location_id FROM station_logbooks_relationship WHERE station_logbook_id = ?',
			[(int)$logbook_id]
		)->result();
		return array_column($rows, 'station_location_id');
	}

	/**
	 * Apply a single insert to a cached "counts" entry (total/today/month/year).
	 *
	 * @param  array $st   normalized station ids of one logbook
	 * @param  array $data insert data array
	 * @return void
	 */
	private function patch_counts($st, $data) {
		$key = $this->key('counts', [], $st);
		$e = $this->cache()->get($key);
		if (!is_array($e) || !isset($e['data']['total'])) {
			return;
		}
		if (($e['day'] ?? '') !== date('Y-m-d')) {
			return; // entry crossed midnight; recompute on next read instead
		}
		$day = substr((string)($data['COL_TIME_ON'] ?? ''), 0, 10);
		$e['data']['total'] = (int)$e['data']['total'] + 1;
		if ($day === date('Y-m-d')) {
			$e['data']['today'] = (int)$e['data']['today'] + 1;
		}
		if (substr($day, 0, 7) === date('Y-m')) {
			$e['data']['month'] = (int)$e['data']['month'] + 1;
		}
		if (substr($day, 0, 4) === date('Y')) {
			$e['data']['year'] = (int)$e['data']['year'] + 1;
		}
		$e['ts'] = time();
		$e['day'] = date('Y-m-d');
		$this->cache()->save($key, $e, self::TTL);
	}

	/**
	 * Apply a single insert to a cached "dash" entry. Distinct-counters are
	 * patched via indexed membership queries (is there another QSO with this
	 * call / dxcc / group?). Confirmation counters are unchanged because
	 * insert_delta skips QSOs that carry confirmation flags.
	 *
	 * @param  array      $st      normalized station ids of one logbook
	 * @param  array      $data    insert data array
	 * @param  int        $qso_id  COL_PRIMARY_KEY of the inserted QSO
	 * @param  array|null $f       dash filter variant (null or dxcc award bands)
	 * @return void
	 */
	private function patch_dash($st, $data, $qso_id, $f) {
		$ci = self::ci();
		$key = $this->key('dash', $f, $st);
		$e = $this->cache()->get($key);
		if (!is_array($e) || !isset($e['data']['Unique_Callsigns'])) {
			return;
		}
		$d = $e['data'];
		if (($e['day'] ?? '') !== date('Y-m-d')) {
			return; // entry crossed midnight; recompute on next read instead
		}
		$t = $ci->config->item('table_name');
		$stlist = implode(',', $st);

		$call = trim((string)($data['COL_CALL'] ?? ''));
		if ($call !== '' && !$ci->db->query(
			"SELECT 1 FROM `{$t}` WHERE station_id IN ({$stlist}) AND COL_CALL = ? AND COL_PRIMARY_KEY != ? LIMIT 1",
			[$call, $qso_id]
		)->row()) {
			$d['Unique_Callsigns'] = (int)$d['Unique_Callsigns'] + 1;
		}

		// group of the new QSO, mirroring the dashboard CASE
		$g = null;
		if (($data['COL_PROP_MODE'] ?? '') === 'SAT') {
			$g = 'sat';
		} else {
			$bg = $ci->db->query('SELECT bandgroup FROM bands WHERE band = ?', [(string)($data['COL_BAND'] ?? '')])->row();
			if ($bg && in_array($bg->bandgroup, ['vhf', 'uhf', 'shf'], true)) {
				$g = 'vhf';
			} elseif (is_array($f) && in_array(strtoupper((string)($data['COL_BAND'] ?? '')), array_map('strtoupper', $f), true)) {
				$g = 'hf';
			}
		}
		if ($g !== null && isset($d['DXCC_Groups'][$g]['qsos'])) {
			$d['DXCC_Groups'][$g]['qsos'] = (int)$d['DXCC_Groups'][$g]['qsos'] + 1;
		}

		$dxcc = (int)($data['COL_DXCC'] ?? 0);
		if ($dxcc > 0 && ($data['COL_COUNTRY'] ?? '') != 'Invalid') {
			$hf_case = '';
			$bind = [];
			if (is_array($f) && $f) {
				$hf_case = 'WHEN UPPER(t.COL_BAND) IN (' . implode(',', array_fill(0, count($f), '?')) . ") THEN 'hf' ";
				$bind = array_map('strtoupper', $f);
			}
			$bind[] = $dxcc;
			$bind[] = $qso_id;
			$rows = $ci->db->query(
				"SELECT (d.end IS NULL) AS cur, CASE WHEN t.COL_PROP_MODE = 'SAT' THEN 'sat' WHEN b.bandgroup IN ('vhf','uhf','shf') THEN 'vhf' {$hf_case}ELSE NULL END AS grp, COUNT(*) AS c
				FROM `{$t}` t
				LEFT JOIN dxcc_entities d ON d.adif = t.col_dxcc
				LEFT JOIN bands b ON b.band = t.COL_BAND
				WHERE t.COL_DXCC = ? AND t.COL_COUNTRY != 'Invalid' AND t.station_id IN ({$stlist}) AND t.COL_PRIMARY_KEY != ?
				GROUP BY cur, grp",
				$bind
			)->result();
			$cur_count = 0;
			$del_count = 0;
			$grp_counts = [];
			foreach ($rows as $r) {
				if ($r->cur) {
					$cur_count += (int)$r->c;
					if ($r->grp) {
						$grp_counts[$r->grp] = ($grp_counts[$r->grp] ?? 0) + (int)$r->c;
					}
				} else {
					$del_count += (int)$r->c;
				}
			}
			if ($cur_count === 0) {
				$d['Countries_Worked'] = (int)$d['Countries_Worked'] + 1;
				$d['Countries_Current'] = (int)$d['Countries_Current'] + 1;
			}
			if ($del_count === 0) {
				$d['Countries_Deleted_Worked'] = (int)$d['Countries_Deleted_Worked'] + 1;
			}
			if ($g !== null && isset($d['DXCC_Groups'][$g]['worked']) && empty($grp_counts[$g])) {
				$d['DXCC_Groups'][$g]['worked'] = (int)$d['DXCC_Groups'][$g]['worked'] + 1;
			}
		}

		$e['ts'] = time();
		$e['day'] = date('Y-m-d');
		$e['data'] = $d;
		$this->cache()->save($key, $e, self::TTL);
	}

	/**
	 * Warm the hot stat entries (counts, dash, dates) of a user's active
	 * station logbook. Used by the nightly prewarm cron, which doubles as
	 * the drift healer. Recomputation is skipped automatically when the
	 * entries are still fresh.
	 *
	 * @param  int $user_id
	 * @return void
	 */
	public function warm_user($user_id) {
		$ci = self::ci();
		$user_id = (int)$user_id;
		if ($user_id <= 0) {
			return;
		}
		$ci->load->model('logbooks_model');
		$lb = $ci->logbooks_model->find_active_station_logbook_from_userid($user_id);
		$st = $this->normalize_stations($ci->logbooks_model->list_logbook_relationships($lb));
		if (!$st) {
			return;
		}
		$ci->load->model('logbook_model');
		$ci->load->model('dayswithqso_model');
		$ci->logbook_model->get_qso_counts($st);
		$ci->logbook_model->dashboard_stats_batch($st, $this->user_dxcc_bands($user_id));
		$ci->dayswithqso_model->getDates($st);
	}

	/**
	 * DXCC award bands of a user; replicates Bands::get_user_bands('dxcc')
	 * for session-less contexts (cron). Same query shape, so the resulting
	 * dash cache key matches the one built from the session call.
	 *
	 * @param  int $user_id
	 * @return array band names
	 */
	private function user_dxcc_bands($user_id) {
		$rows = self::ci()->db->query(
			'SELECT bands.band FROM bands JOIN bandxuser ON bandxuser.bandid = bands.id WHERE bandxuser.userid = ? AND bandxuser.active = 1 AND bandxuser.dxcc = 1',
			[(int)$user_id]
		)->result();
		return array_column($rows, 'band');
	}
}
