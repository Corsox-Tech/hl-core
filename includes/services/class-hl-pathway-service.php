<?php
if (!defined('ABSPATH')) exit;

class HL_Pathway_Service {

    private $pathway_repo;
    private $activity_repo;

    public function __construct() {
        $this->pathway_repo = new HL_Pathway_Repository();
        $this->activity_repo = new HL_Activity_Repository();
    }

    /**
     * Get pathways, optionally filtered by track and/or phase.
     *
     * @param int|null $track_id
     * @param int|null $phase_id
     * @return HL_Pathway[]
     */
    public function get_pathways($track_id = null, $phase_id = null) {
        return $this->pathway_repo->get_all($track_id, $phase_id);
    }

    public function get_pathway($pathway_id) {
        return $this->pathway_repo->get_by_id($pathway_id);
    }

    /**
     * Create a pathway. Accepts phase_id; auto-resolves track_id from phase if needed.
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function create_pathway($data) {
        // If phase_id is provided but track_id is missing, resolve track_id from the phase.
        if (!empty($data['phase_id']) && empty($data['track_id'])) {
            $phase_repo = new HL_Phase_Repository();
            $phase = $phase_repo->get_by_id(absint($data['phase_id']));
            if ($phase) {
                $data['track_id'] = $phase->track_id;
            }
        }

        if (empty($data['pathway_name']) || empty($data['track_id'])) {
            return new WP_Error('missing_fields', __('Pathway name and track are required.', 'hl-core'));
        }

        // If track_id provided but no phase_id, auto-resolve to default phase.
        if (empty($data['phase_id']) && !empty($data['track_id'])) {
            $phase_repo = new HL_Phase_Repository();
            $default_phase = $phase_repo->get_default_phase(absint($data['track_id']));
            if ($default_phase) {
                $data['phase_id'] = $default_phase->phase_id;
            }
        }

        return $this->pathway_repo->create($data);
    }

    public function update_pathway($pathway_id, $data) {
        return $this->pathway_repo->update($pathway_id, $data);
    }

    public function delete_pathway($pathway_id) {
        return $this->pathway_repo->delete($pathway_id);
    }

    public function get_activities($pathway_id) {
        return $this->activity_repo->get_by_pathway($pathway_id);
    }

    public function create_activity($data) {
        if (empty($data['title']) || empty($data['pathway_id']) || empty($data['activity_type'])) {
            return new WP_Error('missing_fields', __('Title, pathway, and type are required.', 'hl-core'));
        }

        // Auto-resolve track_id from pathway's phase if not provided.
        if (empty($data['track_id']) && !empty($data['pathway_id'])) {
            $pathway = $this->pathway_repo->get_by_id(absint($data['pathway_id']));
            if ($pathway) {
                $data['track_id'] = $pathway->track_id;
            }
        }

        return $this->activity_repo->create($data);
    }

    public function update_activity($activity_id, $data) {
        return $this->activity_repo->update($activity_id, $data);
    }

    public function delete_activity($activity_id) {
        return $this->activity_repo->delete($activity_id);
    }

    /**
     * Mark a pathway as a template (or unmark).
     *
     * @param int  $pathway_id
     * @param bool $is_template
     * @return HL_Pathway|null
     */
    public function set_template($pathway_id, $is_template = true) {
        return $this->pathway_repo->update($pathway_id, array(
            'is_template' => $is_template ? 1 : 0,
        ));
    }

    /**
     * Get all template pathways.
     *
     * @return HL_Pathway[]
     */
    public function get_templates() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_pathway WHERE is_template = 1 ORDER BY pathway_name ASC",
            ARRAY_A
        );
        return array_map(function ($row) { return new HL_Pathway($row); }, $rows ?: array());
    }

    /**
     * Clone a pathway (with activities, prereq groups/items, drip rules) into a target phase/track.
     *
     * @param int      $source_pathway_id Source pathway to clone from.
     * @param int      $target_track_id   Target track for the new pathway.
     * @param string   $name_suffix       Suffix appended to the cloned pathway name.
     * @param int|null $target_phase_id   Target phase (overrides track_id resolution).
     * @return int|WP_Error New pathway ID on success.
     */
    public function clone_pathway($source_pathway_id, $target_track_id, $name_suffix = ' (Copy)', $target_phase_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // 1. Load source pathway.
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_pathway WHERE pathway_id = %d",
            $source_pathway_id
        ), ARRAY_A);

        if (!$source) {
            return new WP_Error('not_found', __('Source pathway not found.', 'hl-core'));
        }

        // Resolve phase_id: explicit param > target track's default phase > source's phase.
        $resolved_phase_id = $target_phase_id;
        if (!$resolved_phase_id) {
            $phase_repo = new HL_Phase_Repository();
            $default_phase = $phase_repo->get_default_phase(absint($target_track_id));
            $resolved_phase_id = $default_phase ? $default_phase->phase_id : $source['phase_id'];
        }

        // 2. Create new pathway.
        $new_pathway_data = array(
            'pathway_uuid'        => HL_DB_Utils::generate_uuid(),
            'track_id'           => absint($target_track_id),
            'phase_id'           => $resolved_phase_id ? absint($resolved_phase_id) : null,
            'pathway_name'        => $source['pathway_name'] . $name_suffix,
            'pathway_code'        => HL_Normalization::generate_code($source['pathway_name'] . $name_suffix),
            'description'         => $source['description'],
            'objectives'          => $source['objectives'],
            'syllabus_url'        => $source['syllabus_url'],
            'featured_image_id'   => $source['featured_image_id'],
            'avg_completion_time' => $source['avg_completion_time'],
            'target_roles'        => $source['target_roles'],
            'is_template'         => 0,
            'active_status'       => 1,
        );

        $wpdb->insert("{$prefix}hl_pathway", $new_pathway_data);
        $new_pathway_id = $wpdb->insert_id;

        if (!$new_pathway_id) {
            return new WP_Error('insert_failed', __('Failed to create cloned pathway.', 'hl-core'));
        }

        // 3. Clone activities — build old→new activity ID map.
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_activity WHERE pathway_id = %d ORDER BY ordering_hint ASC, activity_id ASC",
            $source_pathway_id
        ), ARRAY_A);

        $activity_id_map = array(); // old_id => new_id

        foreach ($activities as $act) {
            $old_id = $act['activity_id'];

            $new_act = array(
                'activity_uuid' => HL_DB_Utils::generate_uuid(),
                'track_id'     => absint($target_track_id),
                'pathway_id'    => $new_pathway_id,
                'activity_type' => $act['activity_type'],
                'title'         => $act['title'],
                'description'   => $act['description'],
                'ordering_hint' => $act['ordering_hint'],
                'weight'        => $act['weight'],
                'external_ref'  => $act['external_ref'],
                'visibility'    => $act['visibility'],
                'status'        => 'active',
            );

            $wpdb->insert("{$prefix}hl_activity", $new_act);
            $activity_id_map[$old_id] = $wpdb->insert_id;
        }

        // 4. Clone prerequisite groups and items (remapping activity IDs).
        foreach ($activity_id_map as $old_act_id => $new_act_id) {
            $groups = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}hl_activity_prereq_group WHERE activity_id = %d",
                $old_act_id
            ), ARRAY_A);

            foreach ($groups as $grp) {
                $wpdb->insert("{$prefix}hl_activity_prereq_group", array(
                    'activity_id' => $new_act_id,
                    'prereq_type' => $grp['prereq_type'],
                    'n_required'  => $grp['n_required'],
                ));
                $new_group_id = $wpdb->insert_id;

                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$prefix}hl_activity_prereq_item WHERE group_id = %d",
                    $grp['group_id']
                ), ARRAY_A);

                foreach ($items as $item) {
                    $old_prereq_id = $item['prerequisite_activity_id'];
                    $new_prereq_id = isset($activity_id_map[$old_prereq_id]) ? $activity_id_map[$old_prereq_id] : 0;

                    if ($new_prereq_id) {
                        $wpdb->insert("{$prefix}hl_activity_prereq_item", array(
                            'group_id'                 => $new_group_id,
                            'prerequisite_activity_id' => $new_prereq_id,
                        ));
                    }
                }
            }
        }

        // 5. Clone drip rules (remapping activity IDs, nulling fixed_date).
        foreach ($activity_id_map as $old_act_id => $new_act_id) {
            $rules = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}hl_activity_drip_rule WHERE activity_id = %d",
                $old_act_id
            ), ARRAY_A);

            foreach ($rules as $rule) {
                $new_rule = array(
                    'activity_id' => $new_act_id,
                    'drip_type'   => $rule['drip_type'],
                );

                if ($rule['drip_type'] === 'fixed_date') {
                    // NULL the date so admin must set new dates.
                    $new_rule['release_at_date'] = null;
                } elseif ($rule['drip_type'] === 'after_completion_delay') {
                    $old_base = absint($rule['base_activity_id']);
                    $new_rule['base_activity_id'] = isset($activity_id_map[$old_base]) ? $activity_id_map[$old_base] : null;
                    $new_rule['delay_days'] = $rule['delay_days'];
                }

                $wpdb->insert("{$prefix}hl_activity_drip_rule", $new_rule);
            }
        }

        // 6. Audit log.
        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'pathway_cloned',
                get_current_user_id(),
                absint($target_track_id),
                null,
                $new_pathway_id,
                sprintf('Pathway cloned from #%d to track #%d', $source_pathway_id, $target_track_id)
            );
        }

        return $new_pathway_id;
    }
}
