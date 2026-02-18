<?php
if (!defined('ABSPATH')) exit;

class HL_Team_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_team';
    }

    private function membership_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_team_membership';
    }

    public function get_all($filters = array()) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()}";
        $where = array();
        $values = array();

        if (!empty($filters['cohort_id'])) {
            $where[] = 'cohort_id = %d';
            $values[] = $filters['cohort_id'];
        }
        if (!empty($filters['center_id'])) {
            $where[] = 'center_id = %d';
            $values[] = $filters['center_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY team_name ASC';

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($row) { return new HL_Team($row); }, $rows ?: array());
    }

    public function get_by_id($team_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE team_id = %d", $team_id
        ), ARRAY_A);
        return $row ? new HL_Team($row) : null;
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['team_uuid'])) {
            $data['team_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($team_id, $data) {
        global $wpdb;
        $wpdb->update($this->table(), $data, array('team_id' => $team_id));
        return $this->get_by_id($team_id);
    }

    public function delete($team_id) {
        global $wpdb;
        $wpdb->delete($this->membership_table(), array('team_id' => $team_id));
        return $wpdb->delete($this->table(), array('team_id' => $team_id));
    }

    public function add_member($team_id, $enrollment_id, $membership_type = 'member') {
        global $wpdb;
        $wpdb->insert($this->membership_table(), array(
            'team_id' => $team_id,
            'enrollment_id' => $enrollment_id,
            'membership_type' => $membership_type,
        ));
        return $wpdb->insert_id;
    }

    public function remove_member($team_id, $enrollment_id) {
        global $wpdb;
        return $wpdb->delete($this->membership_table(), array(
            'team_id' => $team_id,
            'enrollment_id' => $enrollment_id,
        ));
    }

    public function get_members($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT tm.*, e.user_id, e.roles, u.display_name, u.user_email
             FROM {$this->membership_table()} tm
             JOIN {$wpdb->prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE tm.team_id = %d ORDER BY tm.membership_type ASC",
            $team_id
        ), ARRAY_A) ?: array();
    }
}
