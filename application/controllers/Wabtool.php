<?php

if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Wabtool extends CI_Controller {

	// square name => ['ring' => [[lng, lat], ...], 'bbox' => [minLng, minLat, maxLng, maxLat]]
	private $wabIndex = null;
	private $globalBbox = null;
	private $gridCache = array(); // uppercased grid => resolveGrid() result
	private $bins = null; // 'latCell:lngCell' bin key => [square names]

	// ADIF DXCC entity numbers valid for WAB (G, GI, GJ, GM, GD, GU, GW,
	// including their regional call areas M/2E, MM, MW, ...)
	private $wabDxccIds = array(223, 265, 122, 279, 114, 106, 294);

	// adjacent rings in the source data leave slivers a few metres wide;
	// points in a sliver snap to the nearest ring within this distance
	const SNAP_METERS = 50;
	const SNAP_BBOX_DEGREES = 0.001;

	// spatial bin size (degrees); a point lookup only tests the squares
	// registered in the point's own bin
	const BIN_LNG = 0.25;
	const BIN_LAT = 0.25;

	function __construct() {
		parent::__construct();

		if(!$this->user_model->authorize(2)) { $this->session->set_flashdata('error', __("You're not allowed to do that!")); redirect('dashboard'); }
	}

	public function index() {
		$data['station_profile'] = $this->stations->all_of_user();

		$footerData = [];
		$footerData['scripts'] = [
			'assets/js/sections/wab.js', // showWabMapModal() + the shared WAB map
			'assets/js/sections/wabtool.js',
		];

		$data['page_title'] = __("WAB from Gridsquare");
		$this->load->view('interface_assets/header', $data);
		$this->load->view('wabtool/index');
		$this->load->view('interface_assets/footer', $footerData);
	}

	/*
	 * AJAX: one page of gridsquare candidates as DataTables server-side JSON.
	 * only_full limits rows to "100% matches" (single-square grids);
	 * wabtool_summary (initial scan) adds a whole-log summary per grid.
	 */
	public function scan() {
		set_time_limit(3600);
		header('Content-Type: application/json');

		$station_id = $this->postStationId();
		$search = $this->postSearchTerm();

		// DataTables server-side parameters
		$draw = (int)$this->input->post('draw', true);
		$start = max(0, (int)$this->input->post('start', true));
		$length = (int)$this->input->post('length', true);
		if ($length <= 0) {
			$length = 25;
		}
		$length = min($length, 200); // keep one page bounded no matter what the client asks for

		$order = $this->input->post('order', true);
		$orderCol = is_array($order) && isset($order[0]['column']) ? (int)$order[0]['column'] : 1;
		$orderDir = is_array($order) && isset($order[0]['dir']) ? strtolower($order[0]['dir']) : 'desc';

		$this->load->model('wab');

		// "100% matches": grids whose center and corners stay in one square
		$full_grids = null;
		if ((string)$this->input->post('only_full', true) === '1') {
			$full_grids = $this->fullMatchGrids($station_id);
		}

		$filtered = $this->wab->count_wab_candidates($station_id, $this->wabDxccIds, $search, $full_grids);
		$total = ($search === '') ? $filtered : $this->wab->count_wab_candidates($station_id, $this->wabDxccIds, '', $full_grids);

		$query = $this->wab->get_wab_candidates($station_id, $this->wabDxccIds, $search, $orderCol, $orderDir, $length, $start, $full_grids);

		// Get Date format
		if ($this->session->userdata('user_date_format')) {
			$custom_date_format = $this->session->userdata('user_date_format');
		} else {
			$custom_date_format = $this->config->item('qso_date_format');
		}

		$rows = array();
		foreach ($query->result() as $qso) {
			$grid = strtoupper(substr(trim($qso->col_gridsquare), 0, 8));

			$resolved = $this->resolveGrid($qso->col_gridsquare);

			$square = null;
			$ambiguous = false;
			$cornerSquares = array();
			$lat = null;
			$lng = null;
			if ($resolved !== null) {
				$square = $resolved['square'];
				$ambiguous = $resolved['ambiguous'];
				$cornerSquares = $resolved['corner_squares'];
				$lat = $resolved['lat'];
				$lng = $resolved['lng'];
			}

			// confirmation letters per QSL system (Q/L/E/Z/C)
			$letters = $this->wab->confirmation_letters($qso);

			$rows[] = array(
				'id' => (int)$qso->col_primary_key,
				'callsign' => $qso->col_call,
				'datetime' => date($custom_date_format, strtotime($qso->col_time_on)) . date(' H:i', strtotime($qso->col_time_on)),
				'band' => $qso->col_band,
				'sat' => $qso->col_sat_name,
				'grid' => $grid,
				'lat' => $lat,
				'lng' => $lng,
				'square' => $square,
				'ambiguous' => $ambiguous,
				'corner_squares' => array_values($cornerSquares),
				'station' => $qso->station_profile_name,
				'confirmed' => $letters,
			);
		}

		$response = array(
			'draw' => $draw,
			'recordsTotal' => $total,
			'recordsFiltered' => $filtered,
			'data' => $rows,
		);

		if ($this->input->post('wabtool_summary') !== null) {
			$summary = array('qsos_scanned' => $total, 'unique_grids' => 0, 'matched' => 0, 'unmatched' => 0, 'ambiguous' => 0);
			foreach ($this->wab->get_wab_candidate_grids($station_id, $this->wabDxccIds) as $grid) {
				$summary['unique_grids']++;

				$resolved = $this->resolveGrid($grid);

				if ($resolved === null || $resolved['square'] === null) {
					$summary['unmatched']++;
				} else {
					$summary['matched']++;
					if ($resolved['ambiguous']) {
						$summary['ambiguous']++;
					}
				}
			}
			$response['summary'] = $summary;
		}

		echo json_encode($response);
	}

	/*
	 * AJAX: write the WAB square into the selected QSOs. Squares are always
	 * recomputed server side; ownership, confirmation state and the empty-SIG
	 * policy are re-checked. ids is a JSON array of primary keys or the
	 * literal 'ALL' (candidates enumerated server side, mirroring the scan
	 * filters, so the client never ships thousands of ids).
	 */
	public function apply() {
		set_time_limit(3600);
		header('Content-Type: application/json');

		$this->load->model('wab');
		$user_station_ids = array_filter(array_map('intval', explode(',', (string)$this->stations->all_station_ids_of_user())));
		if (count($user_station_ids) === 0) {
			echo json_encode(array('error' => __("No station locations found")));
			return;
		}

		$ids = $this->input->post('ids');
		if (is_string($ids) && $ids !== 'ALL') {
			$ids = json_decode($ids, true);
		}

		$skipped = 0;

		if ($ids === 'ALL') {
			// the candidates query itself enforces ownership, the DXCC
			// whitelist and the empty-SIG policy
			$station_id = $this->postStationId();
			$search = $this->postSearchTerm();
			$only_full = (string)$this->input->post('only_full', true) === '1';

			$qsos = $this->wab->get_wab_candidates($station_id, $this->wabDxccIds, $search);

			$idsBySquare = $this->groupIdsBySquare($qsos->result(), $only_full, $skipped);
		} elseif (is_array($ids) && count($ids) > 0) {
			$ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
			if (count($ids) === 0) {
				echo json_encode(array('error' => __("No QSOs selected")));
				return;
			}

			$qsos = $this->wab->get_wab_candidates_by_ids($ids, $user_station_ids, $this->wabDxccIds);
			$skipped = count($ids) - count($qsos);

			$idsBySquare = $this->groupIdsBySquare($qsos, false, $skipped);
		} else {
			echo json_encode(array('error' => __("No QSOs selected")));
			return;
		}

		$updated = 0;
		$squares = array();
		foreach ($idsBySquare as $square => $squareIds) {
			// chunk so a huge log never produces one giant IN (...) statement
			foreach (array_chunk($squareIds, 1000) as $chunk) {
				$updated += $this->wab->apply_wab_square($square, $chunk, $user_station_ids);
			}
			$squares[$square] = count($squareIds);
		}

		echo json_encode(array('updated' => $updated, 'skipped' => $skipped, 'squares' => $squares));
	}

	// search term: DataTables posts search[value] (array), bulk apply a string
	private function postSearchTerm() {
		$search = $this->input->post('search');
		if (is_array($search)) {
			$search = (string)($search['value'] ?? '');
		}
		return trim((string)$search);
	}

	// station location filter: a station id, or null for "all locations"
	private function postStationId() {
		$station_id = $this->input->post('station_id', true);
		return ($station_id !== null && $station_id !== 'all') ? $station_id : null;
	}

	/**
	 * Group candidate QSOs by their resolved WAB square, skipping confirmed
	 * QSOs and unresolvable grids (each skip increments $skipped).
	 *
	 * @param array $qsos Candidate rows from the Wab model
	 * @param bool $only_full Additionally skip grids straddling two squares
	 * @param int $skipped Skip counter, incremented in place
	 * @return array WAB square => array of QSO primary keys
	 */
	private function groupIdsBySquare($qsos, $only_full, &$skipped) {
		$idsBySquare = array();
		foreach ($qsos as $qso) {
			if ($this->wab->confirmation_letters($qso) !== '') {
				$skipped++; // already confirmed QSOs are never touched
				continue;
			}
			$resolved = $this->resolveGrid($qso->col_gridsquare);
			if ($resolved === null || $resolved['square'] === null || ($only_full && $resolved['ambiguous'])) {
				$skipped++;
				continue;
			}
			$idsBySquare[$resolved['square']][] = (int)$qso->col_primary_key;
		}
		return $idsBySquare;
	}

	/*
	 * Distinct candidate grids resolving to a single WAB square (center and
	 * corners): the only_full set. Ambiguity is a grid property, so this is
	 * computed per distinct grid, never per QSO.
	 */
	private function fullMatchGrids($station_id) {
		$full = array();
		foreach ($this->wab->get_wab_candidate_grids($station_id, $this->wabDxccIds) as $grid) {
			$resolved = $this->resolveGrid($grid);
			if ($resolved !== null && $resolved['square'] !== null && !$resolved['ambiguous']) {
				$full[] = $grid;
			}
		}
		return $full;
	}

	/*
	 * Lookup index from the WAB square outlines in wab_geojson.js: per square
	 * the ring and bbox, plus lat/lng bins for point lookups (centroid Point
	 * features are skipped). Rebuilt per request; the file decodes in ~20 ms.
	 */
	private function loadWabIndex() {
		if ($this->wabIndex !== null) {
			return;
		}

		$this->wabIndex = array();
		$this->bins = array();
		$this->globalBbox = null;

		$this->load->library('geojson');
		$geojson = $this->geojson->loadGeoJsonFile('assets/js/sections/wab_geojson.js');

		if (!is_array($geojson) || !isset($geojson['features'])) {
			return;
		}

		foreach ($geojson['features'] as $feature) {
			if (($feature['geometry']['type'] ?? '') !== 'MultiLineString') {
				continue;
			}

			$name = $feature['properties']['name'] ?? null;
			$ring = $feature['geometry']['coordinates'][0] ?? null;
			if ($name === null || !is_array($ring)) {
				continue;
			}

			$bbox = array(INF, INF, -INF, -INF);
			foreach ($ring as $point) {
				$bbox[0] = min($bbox[0], $point[0]);
				$bbox[1] = min($bbox[1], $point[1]);
				$bbox[2] = max($bbox[2], $point[0]);
				$bbox[3] = max($bbox[3], $point[1]);
			}

			$this->wabIndex[$name] = array('ring' => $ring, 'bbox' => $bbox);

			if ($this->globalBbox === null) {
				$this->globalBbox = $bbox;
			} else {
				$this->globalBbox = array(
					min($this->globalBbox[0], $bbox[0]),
					min($this->globalBbox[1], $bbox[1]),
					max($this->globalBbox[2], $bbox[2]),
					max($this->globalBbox[3], $bbox[3]),
				);
			}
		}

		unset($geojson); // the decoded source is large; drop it early

		if ($this->globalBbox === null) {
			return; // nothing usable parsed
		}

		// register each square in every bin its padded bbox overlaps
		foreach ($this->wabIndex as $name => $square) {
			$b = $square['bbox'];
			for ($cy = (int)floor(($b[1] - self::SNAP_BBOX_DEGREES) / self::BIN_LAT); $cy <= (int)floor(($b[3] + self::SNAP_BBOX_DEGREES) / self::BIN_LAT); $cy++) {
				for ($cx = (int)floor(($b[0] - self::SNAP_BBOX_DEGREES) / self::BIN_LNG); $cx <= (int)floor(($b[2] + self::SNAP_BBOX_DEGREES) / self::BIN_LNG); $cx++) {
					$this->bins[$cy . ':' . $cx][] = $name;
				}
			}
		}
	}

	// name of the WAB square containing the point, or null (sliver points
	// snap to the nearest ring)
	private function squareForPoint($lat, $lng) {
		$this->loadWabIndex();

		if ($this->globalBbox !== null &&
			($lng < $this->globalBbox[0] - self::SNAP_BBOX_DEGREES || $lat < $this->globalBbox[1] - self::SNAP_BBOX_DEGREES ||
			$lng > $this->globalBbox[2] + self::SNAP_BBOX_DEGREES || $lat > $this->globalBbox[3] + self::SNAP_BBOX_DEGREES)) {
			return null;
		}

		$candidates = array();
		$bin = $this->bins[(int)floor($lat / self::BIN_LAT) . ':' . (int)floor($lng / self::BIN_LNG)] ?? array();
		foreach ($bin as $name) {
			$square = $this->wabIndex[$name];
			$bbox = $square['bbox'];
			if ($lng < $bbox[0] - self::SNAP_BBOX_DEGREES || $lat < $bbox[1] - self::SNAP_BBOX_DEGREES ||
				$lng > $bbox[2] + self::SNAP_BBOX_DEGREES || $lat > $bbox[3] + self::SNAP_BBOX_DEGREES) {
				continue;
			}
			if ($this->geojson->isPointInPolygon($lat, $lng, $square['ring'])) {
				return $name;
			}
			$candidates[$name] = $square['ring'];
		}

		// inside no ring: the closest nearby ring wins if close enough
		$bestName = null;
		$bestDist = self::SNAP_METERS;
		foreach ($candidates as $name => $ring) {
			$dist = $this->distanceToRingMeters($lat, $lng, $ring);
			if ($dist < $bestDist) {
				$bestDist = $dist;
				$bestName = $name;
			}
		}

		return $bestName;
	}

	// approximate distance in metres from a point to a ring (point-to-segment
	// minimum, local equirectangular projection)
	private function distanceToRingMeters($lat, $lng, $ring) {
		$mLat = 111132.0;
		$mLng = 111320.0 * cos(deg2rad($lat));

		$px = $lng * $mLng;
		$py = $lat * $mLat;

		$best = INF;
		$count = count($ring);
		for ($i = 0; $i < $count - 1; $i++) {
			$ax = $ring[$i][0] * $mLng;
			$ay = $ring[$i][1] * $mLat;
			$bx = $ring[$i + 1][0] * $mLng;
			$by = $ring[$i + 1][1] * $mLat;

			$dx = $bx - $ax;
			$dy = $by - $ay;
			$len2 = $dx * $dx + $dy * $dy;
			$t = $len2 > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0;

			$dist = sqrt(pow($px - ($ax + $t * $dx), 2) + pow($py - ($ay + $t * $dy), 2));
			if ($dist < $best) {
				$best = $dist;
			}
		}

		return $best;
	}

	/*
	 * Resolve a gridsquare to its WAB square: null for invalid grids, else an
	 * array with grid, lat, lng, square, ambiguous, corner_squares. A 6-char
	 * subsquare spans 5' lon x 2.5' lat; grids whose corners fall into more
	 * than one square are flagged ambiguous.
	 */
	private function resolveGrid($grid) {
		$grid = strtoupper(substr(trim((string)$grid), 0, 8));

		if (!preg_match('/^[A-R]{2}[0-9]{2}[A-X]{2}([0-9]{2})?$/', $grid)) {
			return null;
		}

		if (array_key_exists($grid, $this->gridCache)) {
			return $this->gridCache[$grid];
		}

		$this->load->library('geojson');

		$result = array(
			'grid' => $grid,
			'lat' => null,
			'lng' => null,
			'square' => null,
			'ambiguous' => false,
			'corner_squares' => array(),
		);

		$coords = $this->geojson->gridsquareToLatLng($grid);
		if ($coords === null) {
			$this->gridCache[$grid] = $result;
			return $result;
		}

		$result['lat'] = $coords['lat'];
		$result['lng'] = $coords['lng'];
		$result['square'] = $this->squareForPoint($coords['lat'], $coords['lng']);

		if ($result['square'] !== null && strlen($grid) === 6) {
			$dLat = 1.25 / 60;
			$dLng = 2.5 / 60;
			$cornerSquares = array();
			foreach (array(-1, 1) as $latSign) {
				foreach (array(-1, 1) as $lngSign) {
					$cornerSquare = $this->squareForPoint($coords['lat'] + $latSign * $dLat, $coords['lng'] + $lngSign * $dLng);
					if ($cornerSquare !== null) {
						$cornerSquares[$cornerSquare] = true;
					}
				}
			}

			$result['corner_squares'] = array_keys($cornerSquares);
			$result['ambiguous'] = count($result['corner_squares']) > 1;
		}

		$this->gridCache[$grid] = $result;
		return $result;
	}

}
