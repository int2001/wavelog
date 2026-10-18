<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/*
        Register the nightly stats cache prewarm cron job.
        Pure DML: inserts one row into the existing cron table.
*/

class Migration_stats_prewarm_cron extends CI_Migration {

    public function up()
    {
        // Skip if the job is already registered (e.g. re-run of migrations)
        $exists = $this->db->where('id', 'statistics_cache_prewarm')->get('cron')->num_rows() > 0;
        if (!$exists) {
            $this->db->insert('cron', array(
                'id' => 'statistics_cache_prewarm',
                'enabled' => '1',
                'status' => 'pending',
                'description' => 'Prewarm stats cache for active users',
                'function' => 'index.php/statistics_cache/prewarm',
                'expression' => '0 3 * * *',
                'last_run' => null,
                'next_run' => null
            ));
        }
    }

    public function down()
    {
        $this->db->where('id', 'statistics_cache_prewarm');
        $this->db->delete('cron');
    }

}
