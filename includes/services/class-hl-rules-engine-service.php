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

        $bypass_drip   = ($override && $override['override_type'] === 'manual_unlock');
        $bypass_prereq = ($override && $override['override_type'] === 'grace_unlock');

        // Check prerequisites (unless bypassed by grace_unlock)
        if (!$bypass_prereq) {
            $prereq_result = $this->check_prerequisites($enrollment_id, $activity_id);
            if (!$prereq_result['satisfied']) {
                return array(
                    'availability_status' => 'locked',
                    'locked_reason'       => 'prereq',
                    'blockers'            => $prereq_result['blockers'],
                    'prereq_type'         => isset($prereq_result['prereq_type']) ? $prereq_result['prereq_type'] : 'all_of',
                    'n_required'          => isset($prereq_result['n_required']) ? $prereq_result['n_required'] : 0,
                    'next_available_at'   => null,
                );
            }
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

        // All groups must be satisfied (AND across groups).
        foreach ($groups as $group) {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT prerequisite_activity_id FROM {$prefix}hl_activity_prereq_item WHERE group_id = %d",
                $group['group_id']
            ), ARRAY_A);

            if (empty($items)) {
                continue;
            }

            $total_items    = count($items);
            $completed_count = 0;
            $group_blockers  = array();

            foreach ($items as $item) {
                $prereq_state = $wpdb->get_row($wpdb->prepare(
                    "SELECT completion_status FROM {$prefix}hl_activity_state WHERE enrollment_id = %d AND activity_id = %d",
                    $enrollment_id, $item['prerequisite_activity_id']
                ), ARRAY_A);

                if ($prereq_state && $prereq_state['completion_status'] === 'complete') {
                    $completed_count++;
                } else {
                    $group_blockers[] = $item['prerequisite_activity_id'];
                }
            }

            $prereq_type = $group['prereq_type'];
            $n_required  = isset($group['n_required']) ? (int) $group['n_required'] : $total_items;
            $satisfied   = false;

            switch ($prereq_type) {
                case 'any_of':
                    $satisfied = ($completed_count >= 1);
                    break;
                case 'n_of_m':
                    $satisfied = ($completed_count >= $n_required);
                    break;
                case 'all_of':
                default:
                    $satisfied = ($completed_count === $total_items);
                    break;
            }

            if (!$satisfied) {
                return array(
                    'satisfied'   => false,
                    'blockers'    => $group_blockers,
                    'prereq_type' => $prereq_type,
                    'n_required'  => $n_required,
                );
            }
        }

        return array('satisfied' => true, 'blockers' => array());
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

    /**
     * Validate that adding proposed prerequisites would not create a cycle.
     *
     * Builds a dependency adjacency list from all prereqs in the pathway,
     * replaces the target activity's prereqs with the proposed ones,
     * then runs DFS cycle detection.
     *
     * @param int   $pathway_id         The pathway to scope the check to.
     * @param int   $activity_id        The activity being edited.
     * @param int[] $proposed_prereq_ids All prerequisite activity IDs proposed for this activity.
     * @return array { 'valid' => bool, 'cycle' => int[]|null }
     */
    public function validate_no_cycles($pathway_id, $activity_id, $proposed_prereq_ids) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get all activities in this pathway.
        $activity_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT activity_id FROM {$prefix}hl_activity WHERE pathway_id = %d",
            $pathway_id
        ));

        if (empty($activity_ids)) {
            return array('valid' => true, 'cycle' => null);
        }

        $in_ids = implode(',', array_map('intval', $activity_ids));

        // Build adjacency list: activity_id => [prerequisite_activity_ids]
        // This means: activity_id depends on (has edges TO) its prerequisites.
        // A cycle means A depends on B depends on ... depends on A.
        $adj = array();
        foreach ($activity_ids as $aid) {
            $adj[(int) $aid] = array();
        }

        // Load all existing prereq items for activities in this pathway.
        $rows = $wpdb->get_results(
            "SELECT g.activity_id, i.prerequisite_activity_id
             FROM {$prefix}hl_activity_prereq_group g
             INNER JOIN {$prefix}hl_activity_prereq_item i ON g.group_id = i.group_id
             WHERE g.activity_id IN ({$in_ids})",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $from = (int) $row['activity_id'];
            $to   = (int) $row['prerequisite_activity_id'];
            // Skip edges for the activity being edited (we'll replace them).
            if ($from === (int) $activity_id) {
                continue;
            }
            $adj[$from][] = $to;
        }

        // Set the proposed edges for the target activity.
        $adj[(int) $activity_id] = array_map('intval', $proposed_prereq_ids);

        // Ensure all referenced nodes exist in the adjacency list (cross-pathway prereqs are leaf nodes).
        foreach ($proposed_prereq_ids as $pid) {
            $pid = (int) $pid;
            if (!isset($adj[$pid])) {
                $adj[$pid] = array();
            }
        }

        // Run DFS cycle detection.
        $cycle = $this->dfs_find_cycle($adj);

        return array(
            'valid' => ($cycle === null),
            'cycle' => $cycle,
        );
    }

    /**
     * Iterative DFS with 3-color marking to detect cycles in a directed graph.
     *
     * WHITE (0) = unvisited, GRAY (1) = in current path, BLACK (2) = fully processed.
     *
     * @param array $adj Adjacency list: node => [dependsOn nodes].
     * @return int[]|null Cycle path array if found, null if no cycles.
     */
    private function dfs_find_cycle(&$adj) {
        $WHITE = 0;
        $GRAY  = 1;
        $BLACK = 2;

        $color  = array();
        $parent = array();
        foreach (array_keys($adj) as $node) {
            $color[$node]  = $WHITE;
            $parent[$node] = null;
        }

        foreach (array_keys($adj) as $start) {
            if ($color[$start] !== $WHITE) {
                continue;
            }

            $stack = array($start);
            // Track iterator position per node for iterative DFS.
            $iter = array($start => 0);

            $color[$start] = $GRAY;

            while (!empty($stack)) {
                $node = end($stack);
                $neighbors = $adj[$node];

                if ($iter[$node] < count($neighbors)) {
                    $next = $neighbors[$iter[$node]];
                    $iter[$node]++;

                    if (!isset($color[$next])) {
                        // Node outside the graph (e.g., cross-pathway), treat as BLACK.
                        continue;
                    }

                    if ($color[$next] === $GRAY) {
                        // Cycle found. Reconstruct the cycle path.
                        $cycle = array($next);
                        for ($i = count($stack) - 1; $i >= 0; $i--) {
                            $cycle[] = $stack[$i];
                            if ($stack[$i] === $next) {
                                break;
                            }
                        }
                        return array_reverse($cycle);
                    }

                    if ($color[$next] === $WHITE) {
                        $color[$next]  = $GRAY;
                        $parent[$next] = $node;
                        $stack[]       = $next;
                        $iter[$next]   = 0;
                    }
                } else {
                    // All neighbors processed.
                    $color[$node] = $BLACK;
                    array_pop($stack);
                }
            }
        }

        return null;
    }
}
