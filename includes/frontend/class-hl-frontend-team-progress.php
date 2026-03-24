<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_team_progress] shortcode.
 *
 * Shows a logged-in mentor an overview of their team members' learning
 * progress, including per-member completion percentages and expandable
 * per-component detail.
 *
 * @package HL_Core
 */
class HL_Frontend_Team_Progress {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cycle_Repository */
    private $cycle_repo;

    /** @var HL_Team_Repository */
    private $team_repo;

    /** @var HL_Pathway_Repository */
    private $pathway_repo;

    /** @var HL_Component_Repository */
    private $component_repo;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cycle_repo    = new HL_Cycle_Repository();
        $this->team_repo      = new HL_Team_Repository();
        $this->pathway_repo   = new HL_Pathway_Repository();
        $this->component_repo = new HL_Component_Repository();
        $this->orgunit_repo   = new HL_OrgUnit_Repository();
    }

    /**
     * Render the Team Progress shortcode.
     *
     * @param array $atts Shortcode attributes. Optional key: cycle_id.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        // -- Fetch all active enrollments and find those where the current
        //    user holds the Mentor role. --
        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));

        $mentor_enrollments = array_filter($all_enrollments, function ($enrollment) use ($user_id, $atts) {
            if ((int) $enrollment->user_id !== $user_id) {
                return false;
            }
            if (!$enrollment->has_role('Mentor')) {
                return false;
            }
            if (!empty($atts['cycle_id']) && (int) $enrollment->cycle_id !== absint($atts['cycle_id'])) {
                return false;
            }
            return true;
        });
        $mentor_enrollments = array_values($mentor_enrollments);

        // -- Empty state: user has no Mentor enrollments --
        if (empty($mentor_enrollments)) {
            $this->render_empty_state();
            return ob_get_clean();
        }

        // -- Build per-track team blocks --
        $track_blocks = array();

        foreach ($mentor_enrollments as $mentor_enrollment) {
            $block = $this->build_cycle_block($mentor_enrollment);
            if ($block !== null) {
                $track_blocks[] = $block;
            }
        }

        if (empty($track_blocks)) {
            $this->render_empty_state();
            return ob_get_clean();
        }

        // -- Render HTML --
        ?>
        <div class="hl-dashboard hl-team-progress">
        <?php if (count($track_blocks) > 1) : ?>
            <div class="hl-track-tabs">
            <?php foreach ($track_blocks as $idx => $block) : ?>
                <button class="hl-tab<?php echo $idx === 0 ? ' active' : ''; ?>"
                        data-cycle="<?php echo esc_attr($block[  'cycle']->cycle_id); ?>">
                    <?php echo esc_html($block[  'cycle']->cycle_code . ' - ' . $this->format_year($block[  'cycle']->start_date)); ?>
                </button>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($track_blocks as $block) :
            $this->render_cycle_block($block);
        endforeach; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    // --- Data helpers --------------------------------------------------------

    /**
     * Build data for a single track block from a mentor enrollment.
     *
     * @param HL_Enrollment $mentor_enrollment
     * @return array|null Null if the mentor is not on a team in this track.
     */
    private function build_cycle_block($mentor_enrollment) {
        global $wpdb;

        $cycle = $this->cycle_repo->get_by_id($mentor_enrollment->cycle_id);
        if (!$cycle) {
            return null;
        }

        // Find the team this mentor belongs to.
        $membership_row = $wpdb->get_row($wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id = %d",
            $mentor_enrollment->enrollment_id
        ), ARRAY_A);

        if (!$membership_row) {
            // Mentor is not assigned to a team; we still return a block so we
            // can show an informational message.
            return array(
                  'cycle'       => $cycle,
                'team'          => null,
                'school_name'   => '',
                'members'       => array(),
                'team_avg'      => 0,
            );
        }

        $team = $this->team_repo->get_by_id($membership_row['team_id']);
        if (!$team) {
            return null;
        }

        // Resolve school name.
        $school_name = '';
        if (!empty($team->school_id)) {
            $school = $this->orgunit_repo->get_by_id($team->school_id);
            if ($school) {
                $school_name = $school->name;
            }
        }

        // Get all team members (includes the mentor row as well).
        $raw_members = $this->team_repo->get_members($team->team_id);

        // Build enriched member data (skip the mentor themselves).
        $members         = array();
        $completion_sum  = 0;
        $member_count    = 0;

        foreach ($raw_members as $member_row) {
            // Skip mentors -- we only show team members' progress.
            if ($member_row['membership_type'] === 'mentor') {
                continue;
            }

            $member_data = $this->build_member_data($member_row);
            $members[]   = $member_data;

            $completion_sum += $member_data['completion_percent'];
            $member_count++;
        }

        $team_avg = ($member_count > 0) ? round($completion_sum / $member_count) : 0;

        return array(
              'cycle'      => $cycle,
            'team'        => $team,
            'school_name' => $school_name,
            'members'     => $members,
            'team_avg'    => $team_avg,
        );
    }

    /**
     * Build enriched data for a single team member.
     *
     * @param array $member_row Row from HL_Team_Repository::get_members().
     * @return array
     */
    private function build_member_data($member_row) {
        global $wpdb;

        $enrollment_id = (int) $member_row['enrollment_id'];
        $display_name  = isset($member_row['display_name']) ? $member_row['display_name'] : '';
        $user_email    = isset($member_row['user_email']) ? $member_row['user_email'] : '';
        $roles_raw     = isset($member_row['roles']) ? $member_row['roles'] : '[]';

        // Decode roles.
        $roles_array = is_array($roles_raw) ? $roles_raw : HL_DB_Utils::json_decode($roles_raw);
        $role_label  = !empty($roles_array)
            ? implode(', ', array_map('ucfirst', $roles_array))
            : __('Participant', 'hl-core');

        // Get the full enrollment to find the assigned pathway.
        $enrollment = $this->enrollment_repo->get_by_id($enrollment_id);

        $pathway          = null;
        $components_data  = array();
        $complete_count   = 0;
        $total_count      = 0;

        if ($enrollment && !empty($enrollment->assigned_pathway_id)) {
            $pathway = $this->pathway_repo->get_by_id($enrollment->assigned_pathway_id);
        }

        if ($pathway) {
            $components = $this->component_repo->get_by_pathway($pathway->pathway_id);

            // Filter out staff-only components.
            $components = array_filter($components, function ($act) {
                return $act->visibility !== 'staff_only';
            });
            $components = array_values($components);

            $total_count = count($components);

            foreach ($components as $component) {
                $state = $this->get_component_state($enrollment_id, $component->component_id);

                $act_percent = $state ? (int) $state['completion_percent'] : 0;
                $act_status  = $state ? $state['completion_status'] : 'not_started';

                if ($act_status === 'complete' || $act_percent >= 100) {
                    $complete_count++;
                    $act_percent = 100;
                    $act_status  = 'complete';
                }

                $components_data[] = array(
                    'component'          => $component,
                    'completion_percent' => $act_percent,
                    'completion_status'  => $act_status,
                );
            }
        }

        // Overall completion for this member.
        $completion_percent = $this->get_member_completion($enrollment_id);

        return array(
            'enrollment_id'      => $enrollment_id,
            'display_name'       => $display_name,
            'user_email'         => $user_email,
            'role_label'         => $role_label,
            'initials'           => $this->get_initials($display_name),
            'completion_percent' => $completion_percent,
            'components'         => $components_data,
            'complete_count'     => $complete_count,
            'total_count'        => $total_count,
        );
    }

    /**
     * Get overall completion percentage for a member enrollment.
     *
     * First checks `hl_completion_rollup`, then falls back to the average
     * of `hl_component_state` rows, and finally defaults to 0.
     *
     * @param int $enrollment_id
     * @return int 0-100
     */
    private function get_member_completion($enrollment_id) {
        global $wpdb;

        // Try the pre-computed rollup first.
        $rollup = $wpdb->get_var($wpdb->prepare(
            "SELECT track_completion_percent FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id = %d",
            $enrollment_id
        ));

        if ($rollup !== null) {
            return max(0, min(100, (int) round((float) $rollup)));
        }

        // Fall back to average of component states.
        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(completion_percent) as avg_pct FROM {$wpdb->prefix}hl_component_state WHERE enrollment_id = %d",
            $enrollment_id
        ));

        if ($avg !== null) {
            return max(0, min(100, (int) round((float) $avg)));
        }

        return 0;
    }

    /**
     * Query the hl_component_state table for a single enrollment + component.
     *
     * @param int $enrollment_id
     * @param int $component_id
     * @return array|null Row data or null.
     */
    private function get_component_state($enrollment_id, $component_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component_state';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id,
                $component_id
            ),
            ARRAY_A
        );
    }

    // --- Rendering helpers ---------------------------------------------------

    /**
     * Render a single track block (team header + member cards).
     *
     * @param array $block
     */
    private function render_cycle_block($block) {
        $cycle      = $block['cycle'];
        $team        = $block['team'];
        $school_name = $block['school_name'];
        $members     = $block['members'];
        $team_avg    = $block['team_avg'];
        ?>
        <div class="hl-cycle-block" data-cycle-id="<?php echo esc_attr($cycle->cycle_id); ?>">
        <?php if (!$team) : ?>
            <div class="hl-notice hl-notice-info">
                <?php esc_html_e('You are not assigned to a team in this track.', 'hl-core'); ?>
            </div>
        <?php else : ?>
            <?php // -- Team header -- ?>
            <div class="hl-team-header">
                <div class="hl-team-header-info">
                    <h2 class="hl-team-title"><?php echo esc_html($team->team_name); ?></h2>
                    <div class="hl-track-meta">
                        <span class="hl-meta-item"><strong><?php esc_html_e('Cycle:', 'hl-core'); ?></strong> <?php echo esc_html($cycle->cycle_code . ' - ' . $this->format_year($cycle->start_date)); ?></span>
                        <?php if (!empty($school_name)) : ?>
                        <span class="hl-meta-item"><strong><?php esc_html_e('School:', 'hl-core'); ?></strong> <?php echo esc_html($school_name); ?></span>
                        <?php endif; ?>
                        <span class="hl-meta-item"><strong><?php esc_html_e('Members:', 'hl-core'); ?></strong> <?php echo esc_html(count($members)); ?></span>
                    </div>
                </div>
                <?php $this->render_progress_ring($team_avg, __('Team Avg', 'hl-core')); ?>
            </div>

            <?php if (empty($members)) : ?>
                <div class="hl-notice hl-notice-info">
                    <?php esc_html_e('This team has no members yet.', 'hl-core'); ?>
                </div>
            <?php else : ?>
                <div class="hl-section-title"><?php esc_html_e('Team Members', 'hl-core'); ?></div>
                <div class="hl-team-members">
                    <?php foreach ($members as $member) :
                        $this->render_member_card($member);
                    endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single team member card with expandable activity detail.
     *
     * @param array $member Enriched member data from build_member_data().
     */
    private function render_member_card($member) {
        $percent = (int) $member['completion_percent'];

        // Choose progress bar modifier.
        if ($percent >= 100) {
            $bar_class = 'hl-progress-complete';
        } elseif ($percent > 0) {
            $bar_class = 'hl-progress-active';
        } else {
            $bar_class = '';
        }
        ?>
        <div class="hl-member-card">
            <div class="hl-member-header">
                <div class="hl-member-avatar"><?php echo esc_html($member['initials']); ?></div>
                <div class="hl-member-info">
                    <h4 class="hl-member-name"><?php echo esc_html($member['display_name']); ?></h4>
                    <span class="hl-member-email"><?php echo esc_html($member['user_email']); ?></span>
                    <span class="hl-member-role"><?php echo esc_html($member['role_label']); ?></span>
                </div>
                <div class="hl-member-completion">
                    <span class="hl-completion-value"><?php echo esc_html($percent . '%'); ?></span>
                </div>
            </div>
            <div class="hl-progress-bar-container">
                <div class="hl-progress-bar <?php echo esc_attr($bar_class); ?>" style="width: <?php echo esc_attr($percent); ?>%"></div>
            </div>
            <?php if (!empty($member['components'])) : ?>
            <details class="hl-member-details">
                <summary><?php
                    /* translators: 1: number of completed activities, 2: total activities */
                    printf(
                        esc_html__('View Activities (%1$d of %2$d complete)', 'hl-core'),
                        $member['complete_count'],
                        $member['total_count']
                    );
                ?></summary>
                <div class="hl-member-components">
                    <?php foreach ($member['components'] as $ad) :
                        $this->render_mini_component($ad);
                    endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single mini component row inside a member's expandable detail.
     *
     * @param array $ad Component data with keys: component, completion_percent,
     *                  completion_status.
     */
    private function render_mini_component($ad) {
        $component = $ad['component'];
        $percent  = (int) $ad['completion_percent'];
        $status   = $ad['completion_status'];

        if ($status === 'complete') {
            $row_class  = 'hl-mini-complete';
            $icon       = '&#10003;';
            $pct_label  = '100%';
        } elseif ($status === 'in_progress' || $percent > 0) {
            $row_class  = 'hl-mini-progress';
            $icon       = '&#9654;';
            $pct_label  = $percent . '%';
        } else {
            $row_class  = 'hl-mini-locked';
            $icon       = '&#128274;';
            $pct_label  = __('Not Started', 'hl-core');
        }
        ?>
        <div class="hl-mini-component <?php echo esc_attr($row_class); ?>">
            <span class="hl-mini-status"><?php echo $icon; ?></span>
            <span class="hl-mini-title"><?php echo esc_html($component->title); ?></span>
            <span class="hl-mini-percent"><?php echo esc_html($pct_label); ?></span>
        </div>
        <?php
    }

    /**
     * Render the SVG progress ring.
     *
     * @param int    $percent 0-100
     * @param string $label   Text below the percentage (e.g. "Team Avg").
     */
    private function render_progress_ring($percent, $label = '') {
        $percent       = max(0, min(100, (int) $percent));
        $circumference = 2 * M_PI * 52; // ~326.73
        $offset        = $circumference * (1 - $percent / 100);
        ?>
        <div class="hl-team-avg">
            <div class="hl-progress-ring" data-percent="<?php echo esc_attr($percent); ?>">
                <svg viewBox="0 0 120 120">
                    <circle class="hl-ring-bg" cx="60" cy="60" r="52" />
                    <circle class="hl-ring-fill" cx="60" cy="60" r="52"
                            stroke-dasharray="<?php echo esc_attr(round($circumference, 2)); ?>"
                            stroke-dashoffset="<?php echo esc_attr(round($offset, 2)); ?>" />
                </svg>
                <div class="hl-ring-text">
                    <span class="hl-ring-percent"><?php echo esc_html($percent . '%'); ?></span>
                    <?php if (!empty($label)) : ?>
                    <span class="hl-ring-label"><?php echo esc_html($label); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the empty state when the user has no Mentor enrollments.
     */
    private function render_empty_state() {
        ?>
        <div class="hl-dashboard hl-team-progress">
            <div class="hl-empty-state">
                <h3><?php esc_html_e('No Team Assignments', 'hl-core'); ?></h3>
                <p><?php esc_html_e('You do not have any active Mentor enrollments. If you believe this is an error, please contact your Program Manager.', 'hl-core'); ?></p>
            </div>
        </div>
        <?php
    }

    // --- Utility helpers -----------------------------------------------------

    /**
     * Get display initials from a full name.
     *
     * Takes the first letter of the first word and the first letter of the
     * last word. Falls back to "?" for empty names.
     *
     * @param string $display_name
     * @return string One or two uppercase letters.
     */
    private function get_initials($display_name) {
        $name = trim($display_name);
        if (empty($name)) {
            return '?';
        }

        $parts = preg_split('/\s+/', $name);
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));

        if (count($parts) > 1) {
            $last = mb_strtoupper(mb_substr(end($parts), 0, 1));
            return $first . $last;
        }

        return $first;
    }

    /**
     * Extract a 4-digit year from a date string, with fallback.
     *
     * @param string|null $date_string
     * @return string Year or current year if unparseable.
     */
    private function format_year($date_string) {
        if (empty($date_string)) {
            return date_i18n('Y');
        }
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return date_i18n('Y');
        }
        return date_i18n('Y', $timestamp);
    }
}
