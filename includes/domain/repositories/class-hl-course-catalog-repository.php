<?php
if (!defined('ABSPATH')) exit;

class HL_Course_Catalog_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_course_catalog';
    }

    /**
     * Get all catalog entries, optionally filtered by status.
     *
     * @param string|null $status
     * @return HL_Course_Catalog[]
     */
    public function get_all($status = null) {
        global $wpdb;

        if ($status) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY catalog_code ASC",
                $status
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} ORDER BY catalog_code ASC",
                ARRAY_A
            );
        }
        return array_map(function($row) { return new HL_Course_Catalog($row); }, $rows ?: array());
    }

    /**
     * Get a single catalog entry by primary key.
     *
     * @param int $catalog_id
     * @return HL_Course_Catalog|null
     */
    public function get_by_id($catalog_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE catalog_id = %d", $catalog_id
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    /**
     * Get a single catalog entry by catalog_code.
     *
     * @param string $catalog_code
     * @return HL_Course_Catalog|null
     */
    public function get_by_code($catalog_code) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE catalog_code = %s", $catalog_code
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    /**
     * Reverse lookup: find catalog entry by any LD course ID column.
     *
     * @param int $course_id
     * @return HL_Course_Catalog|null
     */
    public function find_by_ld_course_id($course_id) {
        global $wpdb;
        $course_id = absint($course_id);
        if ($course_id === 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE ld_course_en = %d OR ld_course_es = %d OR ld_course_pt = %d LIMIT 1",
            $course_id, $course_id, $course_id
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    /**
     * Get all catalog entries indexed by catalog_code.
     *
     * @return array Associative array keyed by catalog_code.
     */
    public function get_all_indexed_by_code() {
        $entries = $this->get_all();
        $indexed = array();
        foreach ($entries as $entry) {
            $indexed[$entry->catalog_code] = $entry;
        }
        return $indexed;
    }

    /**
     * Create a new catalog entry.
     *
     * @param array $data
     * @return int|WP_Error Insert ID on success, WP_Error on failure.
     */
    public function create($data) {
        global $wpdb;
        if (empty($data['catalog_uuid'])) {
            $data['catalog_uuid'] = HL_DB_Utils::generate_uuid();
        }
        // wpdb->insert() converts PHP null to empty string, not SQL NULL.
        // Strip null language columns so the DB defaults to SQL NULL instead.
        foreach (array('ld_course_en', 'ld_course_es', 'ld_course_pt') as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                unset($data[$col]);
            }
        }
        $result = $wpdb->insert($this->table(), $data);
        if ($result === false) {
            return new WP_Error('db_insert_error', 'Failed to insert course catalog entry: ' . $wpdb->last_error);
        }
        return $wpdb->insert_id;
    }

    /**
     * Update an existing catalog entry.
     *
     * @param int   $catalog_id
     * @param array $data
     * @return HL_Course_Catalog|WP_Error Updated object on success, WP_Error on failure.
     */
    public function update($catalog_id, $data) {
        global $wpdb;

        // For language columns, PHP null must become SQL NULL (not empty string).
        // wpdb->update() can't do this, so use a raw query for null-setting columns.
        $null_cols = array();
        foreach (array('ld_course_en', 'ld_course_es', 'ld_course_pt') as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                $null_cols[] = $col;
                unset($data[$col]);
            }
        }

        if (!empty($null_cols)) {
            $set_clauses = array_map(function($col) { return "{$col} = NULL"; }, $null_cols);
            $null_result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()} SET " . implode(', ', $set_clauses) . " WHERE catalog_id = %d",
                $catalog_id
            ));
            if ($null_result === false) {
                return new WP_Error('db_update_error', 'Failed to set NULL columns: ' . $wpdb->last_error);
            }
        }

        if (!empty($data)) {
            $result = $wpdb->update($this->table(), $data, array('catalog_id' => $catalog_id));
            if ($result === false) {
                return new WP_Error('db_update_error', 'Failed to update course catalog entry: ' . $wpdb->last_error);
            }
        }

        return $this->get_by_id($catalog_id);
    }

    /**
     * Archive a catalog entry (soft-delete).
     *
     * @param int $catalog_id
     * @return HL_Course_Catalog|WP_Error Updated object on success, WP_Error on failure.
     */
    public function archive($catalog_id) {
        return $this->update($catalog_id, array('status' => 'archived'));
    }

    /**
     * Check if a LD course ID is already used by any language column in another catalog entry.
     * Checks all three language columns — per spec, a LD course ID can only belong to ONE catalog entry.
     *
     * @param int $course_id
     * @param int $exclude_catalog_id Catalog ID to exclude from the check.
     * @return int|null Conflicting catalog_id or null.
     */
    public function find_duplicate_course_id($course_id, $exclude_catalog_id = 0) {
        global $wpdb;

        $course_id = absint($course_id);
        if ($course_id === 0) {
            return null;
        }
        $exclude_catalog_id = absint($exclude_catalog_id);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT catalog_id FROM {$this->table()} WHERE (ld_course_en = %d OR ld_course_es = %d OR ld_course_pt = %d) AND catalog_id != %d LIMIT 1",
            $course_id, $course_id, $course_id, $exclude_catalog_id
        ), ARRAY_A);

        return $row ? (int) $row['catalog_id'] : null;
    }

    /**
     * Count active components referencing this catalog entry.
     *
     * @param int $catalog_id
     * @return int
     */
    public function count_active_components($catalog_id) {
        global $wpdb;
        $component_table = $wpdb->prefix . 'hl_component';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$component_table} WHERE catalog_id = %d AND status = 'active'",
            $catalog_id
        ));
    }

    /**
     * Check if the course catalog table exists (with static caching).
     *
     * @return bool
     */
    public function table_exists() {
        // Only cache the true case — false may become stale if table is
        // created later in the same request (e.g. during plugin activation).
        static $exists = false;
        if ($exists) {
            return true;
        }

        global $wpdb;
        $table = $this->table();
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ));
        $exists = ($result === $table);
        return $exists;
    }
}
