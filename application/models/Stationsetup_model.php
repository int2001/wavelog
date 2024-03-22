<?php

class Stationsetup_model extends CI_Model {

	function getContainer($id) {
		// Clean ID
		$clean_id = $this->security->xss_clean($id);

		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', $clean_id);
		return $this->db->get('station_logbooks');
	}

	function saveContainer() {
		$data = array(
			'logbook_name' => xss_clean($this->input->post('name', true)),
		);

		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', xss_clean($this->input->post('id', true)));
		$this->db->update('station_logbooks', $data);
	}

	function remove_public_slug($logbook_id) {
		$this->db->set('public_slug', null);
		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', xss_clean($logbook_id));
		$this->db->update('station_logbooks');
	}

	function saveVisitorLink($id, $name) {
		$this->db->set('public_slug', $name);
		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', $id);
		$this->db->update('station_logbooks');
	}

	function togglePublicSearch($id, $publicSearch) {
		$data = array(
			'public_search' => ($publicSearch === 'true' ? 1 : 0)
		);

		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', $id);
		$this->db->update('station_logbooks', $data);
	}

	function unLinkLocations($logbook_id, $station_id) {

		// be sure that logbook belongs to user
		if (!$this->check_logbook_is_accessible($logbook_id)) {
			return;
		}

		// be sure that station belongs to user
		$this->load->model('Stations');
		if (!$this->Stations->check_station_is_accessible($station_id)) {
			return;
		}

		// Delete relationship
		$this->db->where('station_logbook_id', $logbook_id);
		$this->db->where('station_location_id', $station_id);
		$this->db->delete('station_logbooks_relationship');
	}

	public function check_logbook_is_accessible($id) {
		// check if logbook belongs to user
		$this->db->select('logbook_id');
		$this->db->where('user_id', $this->session->userdata('user_id'));
		$this->db->where('logbook_id', $id);
		$query = $this->db->get('station_logbooks');
		if ($query->num_rows() == 1) {
			return true;
		}
		return false;
	}

	// Creates relationship between a logbook and a station location
	function create_logbook_location_link($logbook_id, $location_id) {
		// be sure that logbook belongs to user
		if (!$this->check_logbook_is_accessible($logbook_id)) {
			return;
		}

		// be sure that station belongs to user
		$this->load->model('Stations');
		if (!$this->Stations->check_station_is_accessible($location_id)) {
			return;
		}

		// Create data array with field values
		$data = array(
			'station_logbook_id' => $logbook_id,
			'station_location_id' =>  $location_id,
		);

		// Insert Record
		$this->db->insert('station_logbooks_relationship', $data);
	}

	function relationship_exists($logbook_id, $location_id) {
		$this->db->where('station_logbook_id', $logbook_id);
		$this->db->where('station_location_id', $location_id);
		$query = $this->db->get('station_logbooks_relationship');

		if ($query->num_rows() > 0){
			return true;
		} else {
			return false;
		}
	}

	function public_slug_exists($slug) {
		$this->db->where('public_slug', $this->security->xss_clean($slug));
		$query = $this->db->get('station_logbooks');

		if ($query->num_rows() > 0){
			return true;
		} else {
			return false;
		}
	}

	function public_slug_exists_logbook_id($slug) {
		$this->db->where('public_slug', $this->security->xss_clean($slug));
		$query = $this->db->get('station_logbooks');

		if ($query->num_rows() > 0){
			foreach ($query->result() as $row) {
				return $row->logbook_id;
			}
		} else {
			return false;
		}
	}

	function is_public_slug_available($slug) {
		// Clean public_slug
		$clean_slug = $this->security->xss_clean($slug);
		$this->db->where('public_slug', $clean_slug);
		$query = $this->db->get('station_logbooks');

		if ($query->num_rows() > 0){
			return false;
		} else {
			return true;
		}
	}

	function locationInfo($id) {
		$userid = $this->session->userdata('user_id'); // Fallback to session-uid, if userid is omitted
		$this->db->select('station_profile.station_profile_name, station_profile.station_callsign, dxcc_entities.name as station_country, dxcc_entities.end as dxcc_end');
		$this->db->where('user_id', $userid);
		$this->db->where('station_id', $id);
		$this->db->join('dxcc_entities','station_profile.station_dxcc = dxcc_entities.adif','left outer');
		return $this->db->get('station_profile');
	}
}

?>
