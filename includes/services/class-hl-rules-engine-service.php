<?php
if (!defined('ABSPATH')) exit;

class HL_Rules_Engine_Service {

    /**
     * Compute activity availability for a given enrollment + activity
     *
     * @return array {availability_status, locked_reason, blockers, next_available_at}
     */
    public function compute_availability($enrollment_id, $activity_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Check if already completed
        $state = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_activity_state WHERE enrollment_id = %d AND activity_id = %d",
            $enrollment_id, $activity_id
        ), ARRAY_A);

        if ($state && $state['completion_status'] === 'complete') {
            return array(
                'availability_status' => 'completed',
                'locked_reason' => null,
                'blockers' => array(),
                'next_available_at' => null,
            );
        }

        // Check overrides (exempt = completed, manual_unlock = bypass drip)
        $override = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_activity_override WHERE enrollment_id = %d AND activity_id = %d ORDER BY created_at DESC LIMIT 1",
            $enrollment_id, $activity_id
        ), ARRAY_A);

        if ($override && $override['override_type'] === 'exempt') {
            return array(
                'availability_status' => 'completed',
                'locked_reason' => null,
                'blockers' => array(),
                'next_available_at' => null,
            );
        }

        $bypass_drip = ($override && $override['override_type'] === 'manual_unlock');

        // Check prerequisites
        $prereq_result = $this->check_prerequisites($enrollment_id, $activity_id);
        if (!$prereq_result['satisfied']) {
            return array(
                'availability_status' => 'locked',
                'locked_reason' => 'prereq',
                'blockers' => $prereq_result['blockers'],
                'next_available_at' => null,
            );
        }

        // Check drip rules (unless bypassed by manual_unlock)
        if (!$bypass_drip) {
            $drip_result = $this->check_drip_rules($enrollment_id, $activity_id);
            if (!$drip_result['satisfied']) {
                return array(
                    'availability_status' => 'locked',
                    'locked_reason' => 'drip',
                    'blockers' => array(),
                    'next_available_at' => $drip_result['next_available_at'],
                );
            }
        }

        return array(
            'availability_status' => 'available',
            'locked_reason' => null,
            'blockers' => array(),
            'next_available_at' => null,
        );
    }

    private function check_prerequisites($enrollment_id, $activity_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_activity_prereq_group WHERE activity_id = %d",
            $activity_id
        ), ARRAY_A);

        if (empty($groups)) {
            return array('satisfied' => true, 'blockers' => array());
        }

        $blockers = array();
        foreach ($groups as $group) {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT prerequisite_activity_id FROM {$prefix}hl_activity_prereq_item WHERE group_id = %d",
                $group['group_id']
            ), ARRAY_A);

            foreach ($items as $item) {
                $prereq_state = $wpdb->get_row($wpdb->prepare(
                    "SELECT completion_status FROM {$prefix}hl_activity_state WHERE enrollment_id = %d AND activity_id = %d",
                    $enrollment_id, $item['prerequisite_activity_id']
                ), ARRAY_A);

                if (!$prereq_state || $prereq_state['completion_status'] !== 'complete') {
                    $blockers[] = $item['prerequisite_activity_id'];
                }
            }

            // For ALL_OF: all must pass. If any blocker, gate fails.
            if ($group['prereq_type'] === 'all_of' && !empty($blockers)) {
                return array('satisfied' => false, 'blockers' => $blockers);
            }
        }

        return array('satisfied' => empty($blockers), 'blockers' => $blockers);
    }

    private function check_drip_rules($enrollment_id, $activity_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}hl_activity_drip_rule WHERE activity_id = %d",
            $activity_id
        ), ARRAY_A);

        if (empty($rules)) {
            return array('satisfied' => true, 'next_available_at' => null);
        }

        $now = current_time('mysql');
        $latest_available = null;

        foreach ($rules as $rule) {
            if ($rule['drip_type'] === 'fixed_date' && $rule['release_at_date']) {
                if ($now < $rule['release_at_date']) {
                    $latest_available = max($latest_available, $rule['release_at_date']);
                }
            }

            if ($rule['drip_type'] === 'after_completion_delay' && $rule['base_activity_id']) {
                $base_state = $wpdb->get_row($wpdb->prepare(
                    "SELECT completed_at FROM {$prefix}hl_activity_state WHERE enrollment_id = %d AND activity_id = %d AND completion_status = 'complete'",
                    $enrollment_id, $rule['base_activity_id']
                ), ARRAY_A);

                if (!$base_state || !$base_state['completed_at']) {
                    return array('satisfied' => false, 'next_available_at' => null);
                }

                $available_at = date('Y-m-d H:i:s', strtotime($base_state['completed_at'] . ' + ' . intval($rule['delay_days']) . ' days'));
                if ($now < $available_at) {
                    $latest_available = max($latest_available, $available_at);
                }
            }
        }

        if ($latest_available !== null) {
            return array('satisfied' => false, 'next_available_at' => $latest_available);
        }

        return array('satisfied' => true, 'next_available_at' => null);
    }
}
