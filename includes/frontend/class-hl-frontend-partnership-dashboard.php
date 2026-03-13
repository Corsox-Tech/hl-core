<?php
if (!defined('ABSPATH')) exit;

/**
 * Track Dashboard shortcode renderer.
 *
 * Renders the [hl_track_dashboard] shortcode for School Leaders,
 * District Leaders, and Staff to view track-level participant overview.
 *
 * @package HL_Core
 */
class HL_Frontend_Track_Dashboard {

    /**
     * @var HL_Track_Repository
     */
    private $track_repo;

    /**
     * @var HL_Enrollment_Repository
     */
    private $enrollment_repo;

    /**
     * @var HL_OrgUnit_Repository
     */
    private $orgunit_repo;

    public function __construct() {
        $this->track_repo     = new HL_Track_Repository();
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->orgunit_repo    = new HL_OrgUnit_Repository();
    }

    public function render($atts) {
        $user_id  = get_current_user_id();
        $is_staff = HL_Security::is_staff();
        $accessible_tracks = $this->get_accessible_tracks($user_id, $is_staff);
        if (empty($accessible_tracks)) {
            return $this->render_access_denied();
        }
        $selected_track_id = $this->resolve_track_id($atts, $accessible_tracks);
        if (!$selected_track_id) {
            return $this->render_access_denied();
        }
        $track = $this->track_repo->get_by_id($selected_track_id);
        if (!$track) {
            return $this->render_notice('The selected track could not be found.', 'warning');
        }
        $scope = $this->determine_scope($user_id, $track, $is_staff);
        if ($scope === false) {
            return $this->render_access_denied();
        }
        $participants  = $this->get_participants($track, $scope);
        $metrics       = $this->calculate_metrics($participants);
        $school_map    = $this->build_school_map($participants);
        $scope_schools = $this->get_scope_schools($scope);
        ob_start();
        ?>
        <div class="hl-dashboard hl-track-dashboard">
            <?php $this->render_header($accessible_tracks, $selected_track_id); ?>
            <?php $this->render_metrics_row($metrics); ?>
            <?php $this->render_participant_table($participants, $scope_schools, $school_map); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_accessible_tracks($user_id, $is_staff) {
        $all_tracks = $this->track_repo->get_all();
        if ($is_staff) {
            $map = array();
            foreach ($all_tracks as $p) {
                $map[(int) $p->track_id] = $p;
            }
            return $map;
        }
        $enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
        $track_ids = array();
        foreach ($enrollments as $enrollment) {
            if ((int) $enrollment->user_id !== $user_id) {
                continue;
            }
            $roles = $enrollment->get_roles_array();
            if (in_array('School Leader', $roles, true) || in_array('District Leader', $roles, true)) {
                $track_ids[] = (int) $enrollment->track_id;
            }
        }
        if (empty($track_ids)) {
            return array();
        }
        $map = array();
        foreach ($all_tracks as $p) {
            if (in_array((int) $p->track_id, $track_ids, true)) {
                $map[(int) $p->track_id] = $p;
            }
        }
        return $map;
    }

    private function resolve_track_id($atts, $accessible_tracks) {
        if (!empty($atts['track_id'])) {
            $id = (int) $atts['track_id'];
            if (isset($accessible_tracks[$id])) {
                return $id;
            }
            return null;
        }
        if (!empty($_GET['hl_track_id'])) {
            $id = (int) $_GET['hl_track_id'];
            if (isset($accessible_tracks[$id])) {
                return $id;
            }
        }
        reset($accessible_tracks);
        return key($accessible_tracks);
    }

    private function determine_scope($user_id, $track, $is_staff) {
        if ($is_staff) {
            return array('type' => 'staff', 'school_ids' => array(), 'district_id' => null);
        }
        $enrollment = $this->enrollment_repo->get_by_track_and_user($track->track_id, $user_id);
        if (!$enrollment) {
            return false;
        }
        $roles = $enrollment->get_roles_array();
        if (in_array('District Leader', $roles, true)) {
            $district_id = !empty($enrollment->district_id) ? (int) $enrollment->district_id : (!empty($track->district_id) ? (int) $track->district_id : null);
            $school_ids = array();
            if ($district_id) {
                $schools = $this->orgunit_repo->get_schools($district_id);
                foreach ($schools as $s) {
                    $school_ids[] = (int) $s->orgunit_id;
                }
            }
            return array('type' => 'district', 'school_ids' => $school_ids, 'district_id' => $district_id);
        }
        if (in_array('School Leader', $roles, true)) {
            $school_id = !empty($enrollment->school_id) ? (int) $enrollment->school_id : null;
            return array('type' => 'school', 'school_ids' => $school_id ? array($school_id) : array(), 'district_id' => null);
        }
        return false;
    }

    private function get_participants($track, $scope) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $sql = $wpdb->prepare(
            "SELECT cr.track_completion_percent, e.enrollment_id, e.user_id, e.roles, e.school_id, u.display_name, u.user_email
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.track_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $track->track_id
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) { $rows = array(); }
        if ($scope['type'] !== 'staff' && !empty($scope['school_ids'])) {
            $allowed = $scope['school_ids'];
            $rows = array_filter($rows, function ($row) use ($allowed) {
                return in_array((int) $row['school_id'], $allowed, true);
            });
            $rows = array_values($rows);
        } elseif ($scope['type'] === 'school' && empty($scope['school_ids'])) {
            $rows = array();
        }
        foreach ($rows as &$row) {
            $row['roles_array'] = is_array($row['roles']) ? $row['roles'] : (array) json_decode($row['roles'], true);
            if (!is_array($row['roles_array'])) { $row['roles_array'] = array(); }
            $row['completion'] = $row['track_completion_percent'] !== null ? round((float) $row['track_completion_percent']) : 0;
        }
        unset($row);
        return $rows;
    }

    private function calculate_metrics($participants) {
        $total = count($participants);
        $completions = array();
        $role_counts = array('Teacher' => 0, 'Mentor' => 0, 'School Leader' => 0, 'District Leader' => 0);
        foreach ($participants as $p) {
            $completions[] = (int) $p['completion'];
            foreach ($p['roles_array'] as $role) {
                if (isset($role_counts[$role])) { $role_counts[$role]++; }
            }
        }
        $avg_completion = $total > 0 ? round(array_sum($completions) / $total) : 0;
        return array('total' => $total, 'avg_completion' => $avg_completion, 'role_counts' => $role_counts);
    }

    private function build_school_map($participants) {
        $school_ids = array();
        foreach ($participants as $p) {
            if (!empty($p['school_id'])) { $school_ids[(int) $p['school_id']] = true; }
        }
        $map = array();
        foreach (array_keys($school_ids) as $sid) {
            $unit = $this->orgunit_repo->get_by_id($sid);
            $map[$sid] = $unit ? $unit->name : __('Unknown School', 'hl-core');
        }
        return $map;
    }

    private function get_scope_schools($scope) {
        if ($scope['type'] === 'staff') { return $this->orgunit_repo->get_schools(); }
        if ($scope['type'] === 'district' && !empty($scope['district_id'])) { return $this->orgunit_repo->get_schools($scope['district_id']); }
        if ($scope['type'] === 'school' && !empty($scope['school_ids'])) {
            $schools = array();
            foreach ($scope['school_ids'] as $sid) {
                $unit = $this->orgunit_repo->get_by_id($sid);
                if ($unit) { $schools[] = $unit; }
            }
            return $schools;
        }
        return array();
    }

    private function render_header($tracks, $selected_track_id) {
        $show_selector = count($tracks) > 1;
        ?>
        <div class="hl-dashboard-header">
            <h2 class="hl-dashboard-title"><?php echo esc_html__('Track Dashboard', 'hl-core'); ?></h2>
            <?php if ($show_selector) : ?>
                <div class="hl-track-selector">
                    <label for="hl-track-select"><?php echo esc_html__('Track:', 'hl-core'); ?></label>
                    <select id="hl-track-select" class="hl-select" onchange="if(this.value){var u=new URL(window.location.href);u.searchParams.set('hl_track_id',this.value);window.location.href=u.toString();}">
                        <?php foreach ($tracks as $pid => $prog) : ?>
                            <option value="<?php echo esc_attr($pid); ?>"<?php selected($pid, $selected_track_id); ?>>
                                <?php echo esc_html($prog->track_name); ?>
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

    private function render_participant_table($participants, $scope_schools, $school_map) {
        $show_school_filter = count($scope_schools) > 1;
        ?>
        <div class="hl-table-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php echo esc_html__('Participants', 'hl-core'); ?></h3>
                <?php if ($show_school_filter) : ?>
                    <div class="hl-table-filters">
                        <select class="hl-select hl-filter-school" onchange="hlFilterSchool(this.value)">
                            <option value=""><?php echo esc_html__('All Schools', 'hl-core'); ?></option>
                            <?php foreach ($scope_schools as $school) : ?>
                                <option value="<?php echo esc_attr($school->orgunit_id); ?>">
                                    <?php echo esc_html($school->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (empty($participants)) : ?>
                <div class="hl-notice hl-notice-info">
                    <?php echo esc_html__('No active participants found for this track.', 'hl-core'); ?>
                </div>
            <?php else : ?>
                <table class="hl-table">
                    <thead><tr>
                        <th><?php echo esc_html__('Name', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Email', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Role(s)', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('School', 'hl-core'); ?></th>
                        <th><?php echo esc_html__('Completion', 'hl-core'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($participants as $p) :
                            $school_name = '';
                            if (!empty($p['school_id']) && isset($school_map[(int) $p['school_id']])) {
                                $school_name = $school_map[(int) $p['school_id']];
                            }
                            $completion = (int) $p['completion'];
                        ?>
                            <tr data-school-id="<?php echo esc_attr($p['school_id']); ?>">
                                <td class="hl-td-name"><?php echo esc_html($p['display_name']); ?></td>
                                <td class="hl-td-email"><?php echo esc_html($p['user_email']); ?></td>
                                <td class="hl-td-roles">
                                    <?php foreach ($p['roles_array'] as $role) : ?>
                                        <span class="hl-badge hl-badge-role"><?php echo esc_html($role); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="hl-td-school"><?php echo esc_html($school_name); ?></td>
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
                <?php if ($show_school_filter) : ?>
                    <script>
                    function hlFilterSchool(schoolId) {
                        var rows = document.querySelectorAll('.hl-track-dashboard .hl-table tbody tr');
                        for (var i = 0; i < rows.length; i++) {
                            if (!schoolId || rows[i].getAttribute('data-school-id') === schoolId) {
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
        return '<div class="hl-dashboard hl-track-dashboard">'
            . '<div class="hl-notice hl-notice-error">'
            . esc_html__('You do not have permission to view the track dashboard. This view is available to School Leaders, District Leaders, and Staff.', 'hl-core')
            . '</div></div>';
    }

    private function render_notice($message, $type = 'info') {
        return '<div class="hl-dashboard hl-track-dashboard">'
            . '<div class="hl-notice hl-notice-' . esc_attr($type) . '">'
            . esc_html($message)
            . '</div></div>';
    }
}
