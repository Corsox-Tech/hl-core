<?php
/**
 * Phase Service
 *
 * Business logic for Phase entity management.
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Phase_Service {

    private $phase_repo;

    public function __construct() {
        $this->phase_repo = new HL_Phase_Repository();
    }

    /**
     * Get all phases for a track, ordered by phase_number.
     *
     * @param int $track_id
     * @return HL_Phase[]
     */
    public function get_phases_for_track($track_id) {
        return $this->phase_repo->get_by_track(absint($track_id));
    }

    /**
     * Get the active phase for a track.
     *
     * @param int $track_id
     * @return HL_Phase|null
     */
    public function get_active_phase($track_id) {
        return $this->phase_repo->get_active_phase(absint($track_id));
    }

    /**
     * Get the default (first) phase for a track.
     *
     * @param int $track_id
     * @return HL_Phase|null
     */
    public function get_default_phase($track_id) {
        return $this->phase_repo->get_default_phase(absint($track_id));
    }

    /**
     * Get a single phase by ID.
     *
     * @param int $phase_id
     * @return HL_Phase|null
     */
    public function get_phase($phase_id) {
        return $this->phase_repo->get_by_id(absint($phase_id));
    }

    /**
     * Create a new phase.
     *
     * @param array $data Must include track_id, phase_name. phase_number auto-increments if not set.
     * @return int|WP_Error Phase ID on success.
     */
    public function create_phase($data) {
        if (empty($data['track_id']) || empty($data['phase_name'])) {
            return new WP_Error('missing_fields', __('Track and phase name are required.', 'hl-core'));
        }

        $track_id = absint($data['track_id']);

        // Auto-assign phase_number if not provided.
        if (empty($data['phase_number'])) {
            $existing = $this->phase_repo->get_by_track($track_id);
            $data['phase_number'] = count($existing) + 1;
        }

        // Validate unique phase_number per track.
        $existing_phases = $this->phase_repo->get_by_track($track_id);
        foreach ($existing_phases as $phase) {
            if ((int) $phase->phase_number === (int) $data['phase_number']) {
                return new WP_Error('duplicate_number', sprintf(
                    __('Phase number %d already exists for this track.', 'hl-core'),
                    $data['phase_number']
                ));
            }
        }

        $phase_id = $this->phase_repo->create($data);

        if ($phase_id && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'phase_created',
                get_current_user_id(),
                $track_id,
                null,
                $phase_id,
                sprintf('Phase "%s" created for track #%d', $data['phase_name'], $track_id)
            );
        }

        return $phase_id;
    }

    /**
     * Update a phase.
     *
     * @param int   $phase_id
     * @param array $data
     * @return HL_Phase|WP_Error
     */
    public function update_phase($phase_id, $data) {
        $phase_id = absint($phase_id);
        $existing = $this->phase_repo->get_by_id($phase_id);

        if (!$existing) {
            return new WP_Error('not_found', __('Phase not found.', 'hl-core'));
        }

        // If changing phase_number, validate uniqueness.
        if (isset($data['phase_number']) && (int) $data['phase_number'] !== (int) $existing->phase_number) {
            $siblings = $this->phase_repo->get_by_track($existing->track_id);
            foreach ($siblings as $sibling) {
                if ((int) $sibling->phase_id !== $phase_id && (int) $sibling->phase_number === (int) $data['phase_number']) {
                    return new WP_Error('duplicate_number', sprintf(
                        __('Phase number %d already exists for this track.', 'hl-core'),
                        $data['phase_number']
                    ));
                }
            }
        }

        $result = $this->phase_repo->update($phase_id, $data);

        if ($result && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'phase_updated',
                get_current_user_id(),
                $existing->track_id,
                null,
                $phase_id,
                sprintf('Phase #%d updated', $phase_id)
            );
        }

        return $result;
    }

    /**
     * Delete a phase. Fails if pathways are still linked.
     *
     * @param int $phase_id
     * @return bool|WP_Error
     */
    public function delete_phase($phase_id) {
        $phase_id = absint($phase_id);
        $existing = $this->phase_repo->get_by_id($phase_id);

        if (!$existing) {
            return new WP_Error('not_found', __('Phase not found.', 'hl-core'));
        }

        $pathway_count = $this->phase_repo->count_pathways($phase_id);
        if ($pathway_count > 0) {
            return new WP_Error('has_pathways', sprintf(
                __('Cannot delete phase: %d pathway(s) are still linked. Move or delete them first.', 'hl-core'),
                $pathway_count
            ));
        }

        $this->phase_repo->delete($phase_id);

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'phase_deleted',
                get_current_user_id(),
                $existing->track_id,
                null,
                $phase_id,
                sprintf('Phase "%s" deleted from track #%d', $existing->phase_name, $existing->track_id)
            );
        }

        return true;
    }

    /**
     * Auto-create a single Phase + Pathway + Activity for course-type tracks.
     *
     * @param int    $track_id
     * @param string $course_name
     * @return int|WP_Error Phase ID on success.
     */
    public function auto_create_for_course_track($track_id, $course_name = '') {
        // TODO: Implement when course-type track UI is built.
        // Creates: Phase 1 (active) → single Pathway → single LD course Activity
        return $this->create_phase(array(
            'track_id'     => absint($track_id),
            'phase_name'   => 'Phase 1',
            'phase_number' => 1,
            'status'       => 'active',
        ));
    }
}
