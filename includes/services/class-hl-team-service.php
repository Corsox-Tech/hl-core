<?php
if (!defined('ABSPATH')) exit;

class HL_Team_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Team_Repository();
    }

    public function get_teams($filters = array()) {
        return $this->repository->get_all($filters);
    }

    public function get_team($team_id) {
        return $this->repository->get_by_id($team_id);
    }

    public function create_team($data) {
        if (empty($data['team_name']) || empty($data['cohort_id']) || empty($data['center_id'])) {
            return new WP_Error('missing_fields', __('Team name, cohort, and center are required.', 'hl-core'));
        }
        $team_id = $this->repository->create($data);
        do_action('hl_team_created', $team_id, $data);
        return $team_id;
    }

    public function update_team($team_id, $data) {
        return $this->repository->update($team_id, $data);
    }

    public function delete_team($team_id) {
        return $this->repository->delete($team_id);
    }

    /**
     * Add a member to a team.
     *
     * Enforces:
     * - Hard constraint: 1 team per enrollment per cohort
     * - Soft constraint: max 2 mentors per team (returns WP_Error unless $force_override = true)
     *
     * @param int    $team_id
     * @param int    $enrollment_id
     * @param string $membership_type 'mentor' or 'member'
     * @param bool   $force_override  If true, bypass the soft mentor cap
     * @return int|WP_Error membership_id on success
     */
    public function add_member($team_id, $enrollment_id, $membership_type = 'member', $force_override = false) {
        global $wpdb;

        // Get the team's cohort_id
        $team = $this->repository->get_by_id($team_id);
        if (!$team) {
            return new WP_Error('team_not_found', __('Team not found.', 'hl-core'));
        }

        // Hard constraint: 1 team per enrollment per cohort
        $existing_team_id = $wpdb->get_var($wpdb->prepare(
            "SELECT tm.team_id FROM {$wpdb->prefix}hl_team_membership tm
             JOIN {$wpdb->prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cohort_id = %d",
            $enrollment_id, $team->cohort_id
        ));

        if ($existing_team_id) {
            if ((int) $existing_team_id === (int) $team_id) {
                return new WP_Error('already_member', __('This participant is already a member of this team.', 'hl-core'));
            }
            return new WP_Error('one_team_per_cohort', __('This participant is already assigned to another team in this cohort. Remove them from the other team first.', 'hl-core'));
        }

        // Soft constraint: max 2 mentors per team
        if ($membership_type === 'mentor' && !$force_override) {
            $mentor_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_team_membership
                 WHERE team_id = %d AND membership_type = 'mentor'",
                $team_id
            ));

            if ($mentor_count >= 2) {
                return new WP_Error('max_mentors', __('This team already has 2 mentors. Use the override option to add a third.', 'hl-core'));
            }
        }

        return $this->repository->add_member($team_id, $enrollment_id, $membership_type);
    }

    public function get_members($team_id) {
        return $this->repository->get_members($team_id);
    }
}
