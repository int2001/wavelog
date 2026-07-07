<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/*
        Tag Wavelog as Version 3.0.1
*/

class Migration_tag_3_0_1 extends CI_Migration {

    public function up()
    {
        // Tag Wavelog New Version
        $this->db->where('option_name', 'version');
        $this->db->update('options', array('option_value' => '3.0.1'));

        // Trigger Version Info Dialog
        $this->db->where('option_type', 'version_dialog');
        $this->db->where('option_name', 'confirmed');
        $this->db->update('user_options', array('option_value' => 'false'));

        // Also set Version Dialog to "both" if only custom text is applied
        $this->db->where('option_name', 'version_dialog');
        $this->db->where('option_value', 'custom_text');
        $this->db->update('options', array('option_value' => 'both'));

        $table = $this->config->item('table_name');
        $this->db->query("ALTER TABLE `station_profile`
           MODIFY `station_pota` varchar(255) DEFAULT NULL
        ");
    }

    public function down()
    {
        $this->db->where('option_name', 'version');
        $this->db->update('options', array('option_value' => '3.0.0'));

        // Do not cut station_pota back down to 50
    }

}
