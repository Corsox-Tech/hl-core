<?php
if (!defined('ABSPATH')) exit;

class HL_Pathway_Service {

    private $pathway_repo;
    private $component_repo;

    public function __construct() {
        $this->pathway_repo = new HL_Pathway_Repository();
        $this->component_repo = new HL_Component_Repository();
    }

    /**
     * Get pathways, optionally filtered by cycle.
     *
     * @param int|null $cycle_id
     * @return HL_Pathway[]
     */
    public function get_pathways($cycle_id = null) {
        return $this->pathway_repo->get_all($cycle_id);
    }

    public function get_pathway($pathway_id) {
        return $this->pathway_repo->get_by_id($pathway_id);
    }

    /**
     * Create a pathway.
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function create_pathway($data) {
        if (empty($data['pathway_name']) || empty($data['cycle_id'])) {
            return new WP_Error('missing_fields', __('Pathway name and cycle are required.', 'hl-core'));
        }

        return $this->pathway_repo->create($data);
    }

    public function update_pathway($pathway_id, $data) {
        return $this->pathway_repo->update($pathway_id, $data);
    }

    public function delete_pathway($pathway_id) {
        return $this->pathway_repo->delete($pathway_id);
    }

    public function get_components($pathway_id) {
        return $this->component_repo->get_by_pathway($pathway_id);
    }

    public function create_component($data) {
        if (empty($data['title']) || empty($data['pathway_id']) || empty($data['component_type'])) {
            return new WP_Error('missing_fields', __('Title, pathway, and type are required.', 'hl-core'));
        }

        // Auto-resolve cycle_id from pathway's cycle if not provided.
        if (empty($data['cycle_id']) && !empty($data['pathway_id'])) {
            $pathway = $this->pathway_repo->get_by_id(absint($data['pathway_id']));
            if ($pathway) {
                $data['cycle_id'] = $pathway->cycle_id;
            }
        }

        return $this->component_repo->create($data);
    }

    public function update_component($component_id, $data) {
        return $this->component_repo->update($component_id, $data);
    }

    public function delete_component($component_id) {
        return $this->component_repo->delete($component_id);
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
     * Clone a pathway (with activities, prereq groups/items, drip rules) into a target cycle/cycle.
     *
     * @param int      $source_pathway_id Source pathway to clone from.
     * @param int      $target_cycle_id   Target cycle for the new pathway.
     * @param string   $name_suffix       Suffix appended to the cloned pathway name.
     * @return int|WP_Error New pathway ID on success.
     */
    public function clone_pathway($source_pathway_id, $target_cycle_id, $name_suffix = ' (Copy)') {
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

        // 2. Create new pathway.
        $new_pathway_data = array(
            'pathway_uuid'        => HL_DB_Utils::generate_uuid(),
            'cycle_id'           => absint($target_cycle_id),
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

        // 3. Clone components — build old→new component ID map.
        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_component WHERE pathway_id = %d ORDER BY ordering_hint ASC, component_id ASC",
            $source_pathway_id
        ), ARRAY_A);

        $component_id_map = array(); // old_id => new_id

        foreach ($components as $comp) {
            $old_id = $comp['component_id'];

            $new_comp = array(
                'component_uuid' => HL_DB_Utils::generate_uuid(),
                'cycle_id'     => absint($target_cycle_id),
                'pathway_id'    => $new_pathway_id,
                'component_type' => $comp['component_type'],
                'title'         => $comp['title'],
                'description'   => $comp['description'],
                'ordering_hint' => $comp['ordering_hint'],
                'weight'        => $comp['weight'],
                'external_ref'  => $comp['external_ref'],
                'visibility'    => $comp['visibility'],
                'status'        => 'active',
            );

            $wpdb->insert("{$prefix}hl_component", $new_comp);
            $component_id_map[$old_id] = $wpdb->insert_id;
        }

        // 4. Clone prerequisite groups and items (remapping component IDs).
        foreach ($component_id_map as $old_comp_id => $new_comp_id) {
            $groups = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}hl_component_prereq_group WHERE component_id = %d",
                $old_comp_id
            ), ARRAY_A);

            foreach ($groups as $grp) {
                $wpdb->insert("{$prefix}hl_component_prereq_group", array(
                    'component_id' => $new_comp_id,
                    'prereq_type' => $grp['prereq_type'],
                    'n_required'  => $grp['n_required'],
                ));
                $new_group_id = $wpdb->insert_id;

                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$prefix}hl_component_prereq_item WHERE group_id = %d",
                    $grp['group_id']
                ), ARRAY_A);

                foreach ($items as $item) {
                    $old_prereq_id = $item['prerequisite_component_id'];
                    $new_prereq_id = isset($component_id_map[$old_prereq_id]) ? $component_id_map[$old_prereq_id] : 0;

                    if ($new_prereq_id) {
                        $wpdb->insert("{$prefix}hl_component_prereq_item", array(
                            'group_id'                 => $new_group_id,
                            'prerequisite_component_id' => $new_prereq_id,
                        ));
                    }
                }
            }
        }

        // 5. Clone drip rules (remapping component IDs, nulling fixed_date).
        foreach ($component_id_map as $old_comp_id => $new_comp_id) {
            $rules = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}hl_component_drip_rule WHERE component_id = %d",
                $old_comp_id
            ), ARRAY_A);

            foreach ($rules as $rule) {
                $new_rule = array(
                    'component_id' => $new_comp_id,
                    'drip_type'   => $rule['drip_type'],
                );

                if ($rule['drip_type'] === 'fixed_date') {
                    // NULL the date so admin must set new dates.
                    $new_rule['release_at_date'] = null;
                } elseif ($rule['drip_type'] === 'after_completion_delay') {
                    $old_base = absint($rule['base_component_id']);
                    $new_rule['base_component_id'] = isset($component_id_map[$old_base]) ? $component_id_map[$old_base] : null;
                    $new_rule['delay_days'] = $rule['delay_days'];
                }

                $wpdb->insert("{$prefix}hl_component_drip_rule", $new_rule);
            }
        }

        // 6. Audit log.
        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'pathway_cloned',
                get_current_user_id(),
                absint($target_cycle_id),
                null,
                $new_pathway_id,
                sprintf('Pathway cloned from #%d to cycle #%d', $source_pathway_id, $target_cycle_id)
            );
        }

        return $new_pathway_id;
    }
}
