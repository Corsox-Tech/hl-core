<?php
if (!defined('ABSPATH')) exit;

/**
 * Cohort Dashboard shortcode renderer.
 *
 * Renders the [hl_cohort_dashboard] shortcode for Center Leaders,
 * District Leaders, and Staff to view cohort-level participant overview.
 *
 * @package HL_Core
 */
class HL_Frontend_Cohort_Dashboard {

    /**
     * @var HL_Cohort_Repository
     */
    private $cohort_repo;

    /**
     * @var HL_Enrollment_Repository
     */
    private $enrollment_repo;

    /**
     * @var HL_OrgUnit_Repository
     */
    private $orgunit_repo;

    public function __construct() {
        $this->cohort_repo     = new HL_Cohort_Repository();
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->orgunit_repo    = new HL_OrgUnit_Repository();
    }

    public function render($atts) {
        $user_id  = get_current_user_id();
        $is_staff = HL_Security::is_staff();
        $accessible_cohorts = $this->get_accessible_cohorts($user_id, $is_staff);
        if (empty($accessible_cohorts)) {
            return $this->render_access_denied();
        }
        $selected_cohort_id = $this->resolve_cohort_id($atts, $accessible_cohorts);
        if (!$selected_cohort_id) {
            return $this->render_access_denied();
        }
        $cohort = $this->cohort_repo->get_by_id($selected_cohort_id);
        if (!$cohort) {
            return $this->render_notice('The selected cohort could not be found.', 'warning');
        }
        $scope = $this->determine_scope($user_id, $cohort, $is_staff);
        if ($scope === false) {
            return $this->render_access_denied();
        }
        $participants = $this->get_participants($cohort, $scope);
        $metrics       = $this->calculate_metrics($participants);
        $center_map    = $this->build_center_map($participants);
        $scope_centers = $this->get_scope_centers($scope);
        ob_start();
        ?>
        <div class="hl-dashboard hl-cohort-dashboard">
            <?php $this->render_header($accessible_cohorts, $selected_cohort_id); ?>
            <?php $this->render_metrics_row($metrics); ?>
            <?php $this->render_participant_table($participants, $scope_centers, $center_map); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_accessible_cohorts($user_id, $is_staff) {
        $all_cohorts = $this->cohort_repo->get_all();
        if ($is_staff) {
            $map = array();
            foreach ($all_cohorts as $p) {
                $map[(int) $p->cohort_id] = $p;
            }
            return $map;
        }
        $enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
        $cohort_ids = array();
        foreach ($enrollments as $enrollment) {
            if ((int) $enrollment->user_id !== $user_id) {
                continue;
            }
            $roles = $enrollment->get_roles_array();
            if (in_array('Center Leader', $roles, true) || in_array('District Leader', $roles, true)) {
                $cohort_ids[] = (int) $enrollment->cohort_id;
            }
        }
        if (empty($cohort_ids)) {
            return array();
        }
        $map = array();
        foreach ($all_cohorts as $p) {
            if (in_array((int) $p->cohort_id, $cohort_ids, true)) {
                $map[(int) $p->cohort_id] = $p;
            }
        }
        return $map;
    }

    private function resolve_cohort_id($atts, $accessible_cohorts) {
        if (!empty($atts['cohort_id'])) {
            $id = (int) $atts['cohort_id'];
            if (isset($accessible_cohorts[$id])) {
                return $id;
            }
            return null;
        }
        if (!empty($_GET['hl_cohort_id'])) {
            $id = (int) $_GET['hl_cohort_id'];
            if (isset($accessible_cohorts[$id])) {
                return $id;
            }
        }
        reset($accessible_cohorts);
        return key($accessible_cohorts);
    }

    private function determine_scope($user_id, $cohort, $is_staff) {
        if ($is_staff) {
            return array('type' => 'staff', 'center_ids' => array(), 'district_id' => null);
        }
        $enrollment = $this->enrollment_repo->get_by_cohort_and_user($cohort->cohort_id, $user_id);
        if (!$enrollment) {
            return false;
        }
        $roles = $enrollment->get_roles_array();
        if (in_array('District Leader', $roles, true)) {
            $district_id = !empty($enrollment->district_id) ? (int) $enrollment->district_id : (!empty($cohort->district_id) ? (int) $cohort->district_id : null);
            $center_ids = array();
            if ($district_id) {
                $centers = $this->orgunit_repo->get_centers($district_id);
                foreach ($centers as $c) {
                    $center_ids[] = (int) $c->orgunit_id;
                }
            }
            return array('type' => 'district', 'center_ids' => $center_ids, 'district_id' => $district_id);
        }
        if (in_array('Center Leader', $roles, true)) {
            $center_id = !empty($enrollment->center_id) ? (int) $enrollment->center_id : null;
            return array('type' => 'center', 'center_ids' => $center_id ? array($center_id) : array(), 'district_id' => null);
        }
        return false;
    }

    private function get_participants($cohort, $scope) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $sql = $wpdb->prepare(
            "SELECT cr.cohort_completion_percent, e.enrollment_id, e.user_id, e.roles, e.center_id, u.display_name, u.user_email
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $cohort->cohort_id
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) { $rows = array(); }
        if ($scope['type'] !== 'staff' && !empty($scope['center_ids'])) {
            $allowed = $scope['center_ids'];
            $rows = array_filter($rows, function ($row) use ($allowed) {
                return in_array((int) $row['center_id'], $allowed, true);
            });
            $rows = array_values($rows);
        } elseif ($scope['type'] === 'center' && empty($scope['center_ids'])) {
            $rows = array();
        }
        foreach ($rows as &$row) {
            $row['roles_array'] = is_array($row['roles']) ? $row['roles'] : (array) json_decode($row['roles'], true);
            if (!is_array($row['roles_array'])) { $row['roles_array'] = array(); }
            $row['completion'] = $row['cohort_completion_percent'] !== null ? round((float) $row['cohort_completion_percent']) : 0;
        }
        unset($row);
        return $rows;
    }

    private function calculate_metrics($participants) {
        $total = count($participants);
        $completions = array();
        $role_counts = array('Teacher' => 0, 'Mentor' => 0, 'Center Leader' => 0, 'District Leader' => 0);
        foreach ($participants as $p) {
            $completions[] = (int) $p['completion'];
            foreach ($p['roles_array'] as $role) {
                if (isset($role_counts[$role])) { $role_counts[$role]++; }
            }
        }
        $avg_completion = $total > 0 ? round(array_sum($completions) / $total) : 0;
        return array('total' => $total, 'avg_completion' => $avg_completion, 'role_counts' => $role_counts);
    }

    private function build_center_map($participants) {
        $center_ids = array();
        foreach ($participants as $p) {
            if (!empty($p['center_id'])) { $center_ids[(int) $p['center_id']] = true; }
        }
        $map = array();
        foreach (array_keys($center_ids) as $cid) {
            $unit = $this->orgunit_repo->get_by_id($cid);
            $map[$cid] = $unit ? $unit->name : __('Unknown Center', 'hl-core');
        }
        return $map;
    }

    private function get_scope_centers($scope) {
        if ($scope['type'] === 'staff') { return $this->orgunit_repo->get_centers(); }
        if ($scope['type'] === 'district' && !empty($scope['district_id'])) { return $this->orgunit_repo->get_centers($scope['district_id']); }
        if ($scope['type'] === 'center' && !empty($scope['center_ids'])) {
            $centers = array();
            foreach ($scope['center_ids'] as $cid) {
                $unit = $this->orgunit_repo->get_by_id($cid);
                if ($unit) { $centers[] = $unit; }
            }
            return $centers;
        }
        return array();
    }

    private function render_header($cohorts, $selected_cohort_id) {
        $show_selector = count($cohorts) > 1;
        ?>
        <div class="hl-dashboard-header">
            <h2 class="hl-dashboard-title"><?php echo esc_html__('Cohort Dashboard', 'hl-core'); ?></h2>
            <?php if ($show_selector) : ?>
                <div class="hl-cohort-selector">
                    <label for="hl-cohort-select"><?php echo esc_html__('Cohort:', 'hl-core'); ?></label>
                    <select id="hl-cohort-select" class="hl-select" onchange="if(this.value){var u=new URL(window.location.href);u.searchParams.set('hl_cohort_id',this.value);window.location.href=u.toString();}">
                        <?php foreach ($cohorts as $pid => $prog) : ?>
                            <option value="<?php echo esc_attr($pid); ?>"<?php selected($pid, $selected_cohort_id); ?>>
                                <?php echo esc_html($prog->cohort_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_metrics_row($metrics) {
        $role_counts = $metrics['role_counts'];
        $cards = array(
            array('value' => (int) $metrics['total'], 'label' => __('Total Participants', 'hl-core')),
            array('value' => $metrics['avg_completion'] . '%', 'label' => __('Avg Completion', 'hl-core')),
        );
        foreach ($role_counts as $role => $count) {
            if ($count > 0) { $cards[] = array('value' => $count, 'label' => $role . 's'); }
        }
        ?>
        <div class="hl-metrics-row">
            <?php foreach ($cards as $card) : ?>
                <div class="hl-metric-card">
                    <div class="hl-metric-value"><?php echo esc_html($card['value']); ?></div>
                    <div class="hl-metric-label"><?php echo esc_html($card['label']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_participant_table($participants, $scope_centers, $center_map) {
        $show_center_filter = count($scope_centers) > 1;
        ?>
        <div class="hl-table-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php echo esc_html__('Participants', 'hl-core'); ?></h3>
                <?php if ($show_center_filter) : ?>
                    <div class="hl-table-filters">
                        <select class="hl-select hl-filter-center" onchange="hlFilterCenter(this.value)">
                            <option value=""><?php echo esc_html__('All Centers', 'hl-core'); ?></option>
                            <?php foreach ($scope_centers as $center) : ?>
                                <option value="<?php echo esc_attr($center->orgunit_id); ?>">
                                    <?php echo esc_html($center->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (empty($participants)) : ?>
                <div class="hl-notice hl-notice-info">
                    <?php echo esc_html__('No active participants found for this cohort.', 'hl-core'); ?>
                </div>
            <?php else : ?>
                <table class="hl-table">
                    <thead><tr>
                        <th><?php echo esc_html__('Name', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Email', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Role(s)', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Center', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Completion', 'hl-core'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($participants as $p) :
                            $center_name = '';
                            if (!empty($p['center_id']) && isset($center_map[(int) $p['center_id']])) {
                                $center_name = $center_map[(int) $p['center_id']];
                            }
                            $completion = (int) $p['completion'];
                        ?>
                            <tr data-center-id="<?php echo esc_attr($p['center_id']); ?>">
                                <td class="hl-td-name"><?php echo esc_html($p['display_name']); ?></td>
                                <td class="hl-td-email"><?php echo esc_html($p['user_email']); ?></td>
                                <td class="hl-td-roles">
                                    <?php foreach ($p['roles_array'] as $role) : ?>
                                        <span class="hl-badge hl-badge-role"><?php echo esc_html($role); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="hl-td-center"><?php echo esc_html($center_name); ?></td>
                                <td class="hl-td-completion">
                                    <div class="hl-inline-progress">
                                        <div class="hl-progress-bar-container hl-progress-inline">
                                            <div class="hl-progress-bar hl-progress-active" style="width: <?php echo esc_attr($completion); ?>%"></div>
                                        </div>
                                        <span class="hl-progress-text"><?php echo esc_html($completion . '%'); ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($show_center_filter) : ?>
                    <script>
                    function hlFilterCenter(centerId) {
                        var rows = document.querySelectorAll('.hl-cohort-dashboard .hl-table tbody tr');
                        for (var i = 0; i < rows.length; i++) {
                            if (!centerId || rows[i].getAttribute('data-center-id') === centerId) {
                                rows[i].style.display = '';
                            } else {
                                rows[i].style.display = 'none';
                            }
                        }
                    }
                    </script>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_access_denied() {
        return '<div class="hl-dashboard hl-cohort-dashboard">'
            . '<div class="hl-notice hl-notice-error">'
            . esc_html__('You do not have permission to view the cohort dashboard. This view is available to Center Leaders, District Leaders, and Staff.', 'hl-core')
            . '</div></div>';
    }

    private function render_notice($message, $type = 'info') {
        return '<div class="hl-dashboard hl-cohort-dashboard">'
            . '<div class="hl-notice hl-notice-' . esc_attr($type) . '">'
            . esc_html($message)
            . '</div></div>';
    }
}
