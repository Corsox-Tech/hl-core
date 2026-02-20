<?php
if (!defined('ABSPATH')) exit;

class HL_Enrollment_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_enrollment';
    }

    public function get_all($filters = array()) {
        global $wpdb;
        $sql = "SELECT e.*, u.user_email, u.display_name FROM {$this->table()} e
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID";
        $where = array();
        $values = array();

        if (!empty($filters['cohort_id'])) {
            $where[] = 'e.cohort_id = %d';
            $values[] = $filters['cohort_id'];
        }
        if (!empty($filters['center_id'])) {
            $where[] = 'e.center_id = %d';
            $values[] = $filters['center_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = %s';
            $values[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.enrolled_at DESC';

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($row) { return new HL_Enrollment($row); }, $rows ?: array());
    }

    public function get_by_id($enrollment_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.user_email, u.display_name FROM {$this->table()} e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d", $enrollment_id
        ), ARRAY_A);
        return $row ? new HL_Enrollment($row) : null;
    }

    public function get_by_cohort_and_user($cohort_id, $user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE cohort_id = %d AND user_id = %d",
            $cohort_id, $user_id
        ), ARRAY_A);
        return $row ? new HL_Enrollment($row) : null;
    }

    public function get_by_cohort($cohort_id, $role = null) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT e.*, u.user_email, u.display_name FROM {$this->table()} e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d ORDER BY e.enrolled_at DESC",
            $cohort_id
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $enrollments = array_map(function($row) { return new HL_Enrollment($row); }, $rows ?: array());

        if ($role) {
            $enrollments = array_filter($enrollments, function($e) use ($role) {
                return $e->has_role($role);
            });
        }
        return array_values($enrollments);
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['enrollment_uuid'])) {
            $data['enrollment_uuid'] = HL_DB_Utils::generate_uuid();
        }
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['roles'] = HL_DB_Utils::json_encode($data['roles']);
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($enrollment_id, $data) {
        global $wpdb;
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['roles'] = HL_DB_Utils::json_encode($data['roles']);
        }
        $wpdb->update($this->table(), $data, array('enrollment_id' => $enrollment_id));
        return $this->get_by_id($enrollment_id);
    }

    public function delete($enrollment_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('enrollment_id' => $enrollment_id));
    }

    public function get_by_user_id($user_id, $status = 'active') {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT e.*, u.user_email, u.display_name FROM {$this->table()} e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.user_id = %d AND e.status = %s
             ORDER BY e.enrolled_at DESC",
            $user_id, $status
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($row) { return new HL_Enrollment($row); }, $rows ?: array());
    }

    public function count_by_cohort($cohort_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE cohort_id = %d", $cohort_id
        ));
    }
}
