<?php
/**
 * Cycle Service
 *
 * Business logic for Cycle entity management.
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cycle_Service {

    private $cycle_repo;

    public function __construct() {
        $this->cycle_repo = new HL_Cycle_Repository();
    }

    /**
     * Get all cycles for a partnership, ordered by cycle_number.
     *
     * @param int $partnership_id
     * @return HL_Cycle[]
     */
    public function get_cycles_for_partnership($partnership_id) {
        return $this->cycle_repo->get_by_partnership(absint($partnership_id));
    }

    /**
     * Get the active cycle for a partnership.
     *
     * @param int $partnership_id
     * @return HL_Cycle|null
     */
    public function get_active_cycle($partnership_id) {
        return $this->cycle_repo->get_active_cycle(absint($partnership_id));
    }

    /**
     * Get the default (first) cycle for a partnership.
     *
     * @param int $partnership_id
     * @return HL_Cycle|null
     */
    public function get_default_cycle($partnership_id) {
        return $this->cycle_repo->get_default_cycle(absint($partnership_id));
    }

    /**
     * Get a single cycle by ID.
     *
     * @param int $cycle_id
     * @return HL_Cycle|null
     */
    public function get_cycle($cycle_id) {
        return $this->cycle_repo->get_by_id(absint($cycle_id));
    }

    /**
     * Create a new cycle.
     *
     * @param array $data Must include partnership_id, cycle_name. cycle_number auto-increments if not set.
     * @return int|WP_Error Cycle ID on success.
     */
    public function create_cycle($data) {
        if (empty($data['partnership_id']) || empty($data['cycle_name'])) {
            return new WP_Error('missing_fields', __('Partnership and cycle name are required.', 'hl-core'));
        }

        $partnership_id = absint($data['partnership_id']);

        // Auto-assign cycle_number if not provided.
        if (empty($data['cycle_number'])) {
            $existing = $this->cycle_repo->get_by_partnership($partnership_id);
            $data['cycle_number'] = count($existing) + 1;
        }

        // Validate unique cycle_number per partnership.
        $existing_cycles = $this->cycle_repo->get_by_partnership($partnership_id);
        foreach ($existing_cycles as $cycle) {
            if ((int) $cycle->cycle_number === (int) $data['cycle_number']) {
                return new WP_Error('duplicate_number', sprintf(
                    __('Cycle number %d already exists for this partnership.', 'hl-core'),
                    $data['cycle_number']
                ));
            }
        }

        $cycle_id = $this->cycle_repo->create($data);

        if ($cycle_id && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'cycle_created',
                get_current_user_id(),
                $partnership_id,
                null,
                $cycle_id,
                sprintf('Cycle "%s" created for partnership #%d', $data['cycle_name'], $partnership_id)
            );
        }

        return $cycle_id;
    }

    /**
     * Update a cycle.
     *
     * @param int   $cycle_id
     * @param array $data
     * @return HL_Cycle|WP_Error
     */
    public function update_cycle($cycle_id, $data) {
        $cycle_id = absint($cycle_id);
        $existing = $this->cycle_repo->get_by_id($cycle_id);

        if (!$existing) {
            return new WP_Error('not_found', __('Cycle not found.', 'hl-core'));
        }

        // If changing cycle_number, validate uniqueness.
        if (isset($data['cycle_number']) && (int) $data['cycle_number'] !== (int) $existing->cycle_number) {
            $siblings = $this->cycle_repo->get_by_partnership($existing->partnership_id);
            foreach ($siblings as $sibling) {
                if ((int) $sibling->cycle_id !== $cycle_id && (int) $sibling->cycle_number === (int) $data['cycle_number']) {
                    return new WP_Error('duplicate_number', sprintf(
                        __('Cycle number %d already exists for this partnership.', 'hl-core'),
                        $data['cycle_number']
                    ));
                }
            }
        }

        $result = $this->cycle_repo->update($cycle_id, $data);

        if ($result && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'cycle_updated',
                get_current_user_id(),
                $existing->partnership_id,
                null,
                $cycle_id,
                sprintf('Cycle #%d updated', $cycle_id)
            );
        }

        return $result;
    }

    /**
     * Delete a cycle. Fails if pathways are still linked.
     *
     * @param int $cycle_id
     * @return bool|WP_Error
     */
    public function delete_cycle($cycle_id) {
        $cycle_id = absint($cycle_id);
        $existing = $this->cycle_repo->get_by_id($cycle_id);

        if (!$existing) {
            return new WP_Error('not_found', __('Cycle not found.', 'hl-core'));
        }

        $pathway_count = $this->cycle_repo->count_pathways($cycle_id);
        if ($pathway_count > 0) {
            return new WP_Error('has_pathways', sprintf(
                __('Cannot delete cycle: %d pathway(s) are still linked. Move or delete them first.', 'hl-core'),
                $pathway_count
            ));
        }

        $this->cycle_repo->delete($cycle_id);

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'cycle_deleted',
                get_current_user_id(),
                $existing->partnership_id,
                null,
                $cycle_id,
                sprintf('Cycle "%s" deleted from partnership #%d', $existing->cycle_name, $existing->partnership_id)
            );
        }

        return true;
    }

    /**
     * Auto-create a single Cycle + Pathway + Component for course-type partnerships.
     *
     * @param int    $partnership_id
     * @param string $course_name
     * @return int|WP_Error Cycle ID on success.
     */
    public function auto_create_for_course_partnership($partnership_id, $course_name = '') {
        // TODO: Implement when course-type partnership UI is built.
        // Creates: Cycle 1 (active) → single Pathway → single LD course Component
        return $this->create_cycle(array(
            'partnership_id'     => absint($partnership_id),
            'cycle_name'   => 'Cycle 1',
            'cycle_number' => 1,
            'status'       => 'active',
        ));
    }
}
