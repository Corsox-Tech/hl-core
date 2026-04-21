<?php
/**
 * bin/seed-chris-workflows.php
 *
 * One-off seeder for Chris's 14 email workflows (sheet: "Updated - LMS Master",
 * rows 4-16, 22, 24 of data/LMS Email Notification List - reorganized.xlsx).
 *
 * Idempotent: re-runs UPDATE existing rows by their unique anchors
 * (template_key for templates, exact name for workflows). Safe to re-run.
 *
 * All workflows ship as status='draft'. Mateo activates in admin after review.
 *
 * USAGE
 * -----
 *   # Dry run — prints every intended INSERT/UPDATE without touching DB.
 *   HL_SEED_DRY_RUN=1 wp eval-file wp-content/plugins/hl-core/bin/seed-chris-workflows.php
 *
 *   # Real run.
 *   wp eval-file wp-content/plugins/hl-core/bin/seed-chris-workflows.php
 *
 * WHY THIS EXISTS
 * ---------------
 * Chris asked Mateo to create these workflows. Building them via Playwright
 * was ruled out. Building via a PHP seeder keeps them:
 *   - Idempotent (re-runnable from any environment as copy gets tweaked).
 *   - Auditable (every create/update lands in hl_audit_log).
 *   - Safe (calls the same HL_Admin_Emails::validate_workflow_payload()
 *     security boundary that the admin UI uses — no bypass).
 *
 * Plan: docs/superpowers/plans/2026-04-20-email-registry-cleanup.md §6.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// HL_Admin_Emails is normally only loaded inside is_admin(), which is false
// during WP-CLI. Lazy-load it here so the seeder works under wp eval-file.
// Same pattern used by bin/test-email-v2-track1.php.
if ( ! class_exists( 'HL_Admin_Emails' ) ) {
	if ( defined( 'HL_CORE_INCLUDES_DIR' ) ) {
		$candidate = HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-emails.php';
		if ( file_exists( $candidate ) ) {
			require_once $candidate;
		}
	}
	if ( ! class_exists( 'HL_Admin_Emails' ) ) {
		fwrite( STDERR, "HL_Admin_Emails class not loaded and HL_CORE_INCLUDES_DIR fallback failed. Aborting.\n" );
		return;
	}
}

$dry_run     = getenv( 'HL_SEED_DRY_RUN' ) === '1';
$name_prefix = '[Chris] ';
$key_prefix  = 'chris_';

global $wpdb;
$tpl_table = "{$wpdb->prefix}hl_email_template";
$wf_table  = "{$wpdb->prefix}hl_email_workflow";

// =========================================================================
// Merge-tag translation map (Chris bracket syntax -> registry double-curly)
// =========================================================================

// Chris uses [bracket] placeholders in the spreadsheet. The block renderer
// only substitutes {{double_curly}} tags declared in HL_Email_Merge_Tag_Registry.
// This map is the authoritative translation for every bracket we expect in the
// 14 source bodies.
//
// Entries mapping to a bare string (no {{...}}) are LITERAL TEXT FALLBACKS —
// no corresponding merge tag is registered yet. Adding them is a registry +
// context-builder change we intentionally kept OUT of this seeder PR.
$merge_tag_map = array(
	// --- Registry-backed tags ---
	'[user_first_name]' => '{{recipient_first_name}}',
	'[User_name]'       => '{{recipient_first_name}}', // row 16 uses this form
	'[user_last_name]'  => '{{recipient_full_name}}',
	'[pathway_name]'    => '{{pathway_name}}',
	'[course_name]'     => '{{course_title}}',
	'[session_date]'    => '{{session_date}}', // resolves to full datetime — carries time too.
	'[Coach email]'     => '{{coach_email}}',
	'[Coach Email]'     => '{{coach_email}}',
	'[coach email]'     => '{{coach_email}}',
	'[school_district]' => '{{school_district}}',

	// --- Literal fallbacks (no registered tag) ---
	'[classroom_visit_name]' => 'your Classroom Visit',
	'[classroom_visit_release_date]-[classroom_visit_due_date]' => 'the visit window',
	'[self_reflection_name]'     => 'your Self-Reflection',
	'[self_reflection_due_date]' => 'the due date',
	'[reflective_practice_session_name]' => 'your Reflective Practice Session',
	'[reflective_practice_session_release_date]-[reflective_practice_session_due_date]' => 'the session window',
	'[coaching_session_name]' => 'your coaching session',
	'[session_time]'          => '', // {{session_date}} already carries full datetime.
	'[assessment_name]'       => 'Pre-Assessment',
);

// =========================================================================
// Helpers
// =========================================================================

/**
 * Translate Chris's [bracket] tags into registry {{double_curly}} form
 * (or literal fallback text) using strtr — which matches the LONGEST key
 * first, so combined keys like `[cv_release]-[cv_due]` beat their standalone
 * variants.
 */
function hl_seed_translate( $str, array $map ) {
	return strtr( (string) $str, $map );
}

/**
 * Reject any leftover [snake_case] placeholder — that means we forgot to map
 * it, and shipping it would render as literal garbage in the email. Fail loud.
 */
function hl_seed_assert_no_brackets( $str, $context_label ) {
	if ( preg_match( '/\[[a-zA-Z_][a-zA-Z0-9_]*\]/', (string) $str, $m ) ) {
		throw new RuntimeException(
			"Unresolved placeholder {$m[0]} in {$context_label}. Update merge_tag_map in seed-chris-workflows.php."
		);
	}
}

/**
 * Translate merge tags in every renderable string inside a block array
 * (text.content, button.label, button.url, image.src/alt/link). Strips any
 * block with unresolved brackets by throwing.
 */
function hl_seed_translate_blocks( array $blocks, array $map ) {
	$out = array();
	foreach ( $blocks as $i => $block ) {
		if ( ! is_array( $block ) || empty( $block['type'] ) ) {
			continue;
		}
		foreach ( array( 'content', 'label', 'url', 'src', 'alt', 'link' ) as $field ) {
			if ( isset( $block[ $field ] ) && is_string( $block[ $field ] ) ) {
				$block[ $field ] = hl_seed_translate( $block[ $field ], $map );
				hl_seed_assert_no_brackets( $block[ $field ], "block[{$i}].{$field}" );
			}
		}
		$out[] = $block;
	}
	return $out;
}

// =========================================================================
// Workflow definitions (14 rows from Chris's spreadsheet)
// =========================================================================

// Copy is transcribed from data/LMS Email Notification List - reorganized.xlsx,
// sheet "Updated - LMS Master". Smart quotes, bullets, and em-dashes in the
// source have been normalized to ASCII equivalents for email-client compat.
//
// Each entry drives both a template row (wp_hl_email_template) and a workflow
// row (wp_hl_email_workflow) linked by template_id.

$workflows = array(

	// ---------------------------------------------------------------------
	// Row 4 — New Pathway Enrollment (Non-Control-Group)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_pathway_assigned_non_control_v1',
		'tpl_name'    => '[Chris] New Pathway Enrollment',
		'subject'     => 'New Enrollment on Housman Learning Academy',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>You have been enrolled in <strong>[pathway_name]</strong> on Housman Learning Academy. Log in to start your learning journey!</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
			array( 'type' => 'text', 'content' => '<p>If you have trouble logging in, please use the password reset option or contact your program coordinator.</p>' ),
		),
		'wf_name'                => '[Chris] New Pathway Enrollment (Non-Control-Group)',
		'trigger_key'            => 'hl_pathway_assigned',
		'conditions'             => array(
			array( 'field' => 'cycle.is_control_group', 'op' => 'eq', 'value' => false ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 5 — Pathway Enrollment (Control Group)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_pathway_assigned_control_v1',
		'tpl_name'    => '[Chris] New Pathway Enrollment (Control Group)',
		'subject'     => 'New Enrollment on Housman Learning Academy',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>You have been enrolled in a control group study through Housman Learning Academy as part of the partnership with <strong>[school_district]</strong>.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Your assessment activities are ready for you. Please log in to get started.</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
			array( 'type' => 'text', 'content' => '<p>If you have trouble logging in, please use the password reset option or contact your program coordinator.</p>' ),
		),
		'wf_name'                => '[Chris] New Pathway Enrollment (Control Group)',
		'trigger_key'            => 'hl_pathway_assigned',
		'conditions'             => array(
			array( 'field' => 'cycle.is_control_group', 'op' => 'eq', 'value' => true ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 6 — Course Completion with Certificate
	// ---------------------------------------------------------------------
	// No certificate_url merge tag exists yet, and no course_url either —
	// dropping the CTA button entirely per Mateo's decision (2026-04-21).
	// Body rewritten to reference the course page without a broken link.
	array(
		'tpl_key'     => 'chris_course_completion_v1',
		'tpl_name'    => '[Chris] Course Completion with Certificate',
		'subject'     => 'Congratulations on Completing Your Course!',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>Congratulations on successfully completing <strong>[course_name]</strong>! This is a meaningful milestone, and we\'re excited to recognize your hard work.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Your certificate is available on the course page in Housman Learning Academy.</p>' ),
		),
		'wf_name'                => '[Chris] Course Completion with Certificate',
		'trigger_key'            => 'hl_learndash_course_completed',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 7 — Pathway Completion
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_pathway_completion_v1',
		'tpl_name'    => '[Chris] Pathway Completion',
		'subject'     => 'Congratulations on Completing Your Program!',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>Congratulations on successfully completing <strong>[pathway_name]</strong>! This is a meaningful milestone, and we\'re excited to recognize your hard work and commitment.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Feel free to log in and revisit your courses and resources anytime!</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
		),
		'wf_name'                => '[Chris] Pathway Completion',
		'trigger_key'            => 'hl_pathway_completed',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 8 — Pre-Assessment Documentation (B2E only)
	// ---------------------------------------------------------------------
	// Scoped to program (B2E) via cycle.cycle_type — per docs, 'program'
	// cycles are B2E and 'course' cycles are short-course institutional
	// access. Short-course users don't need pre-assessment reminders.
	array(
		'tpl_key'     => 'chris_pre_assessment_documentation_v1',
		'tpl_name'    => '[Chris] Pre-Assessment Documentation Reminder',
		'subject'     => 'Action Needed: [assessment_name] Now Available',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that the [assessment_name] of your enrolled program <strong>[pathway_name]</strong> is now open and ready to be filled out. Please log in and complete the assessments on Housman Learning Academy.</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
		),
		'wf_name'                => '[Chris] Pre-Assessment Documentation Reminder (B2E)',
		'trigger_key'            => 'hl_pathway_assigned',
		'conditions'             => array(
			array( 'field' => 'cycle.cycle_type', 'op' => 'eq', 'value' => 'program' ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 9 — Classroom Visit Window Opens (1 week before)
	// ---------------------------------------------------------------------
	// cron:component_upcoming fans out to every enrollment that owns the
	// upcoming component — for classroom_visit, that includes both the
	// teacher's and the leader's enrollment. Chris's copy targets the
	// "Visitor" (observer). If the teacher also receives this, Mateo will
	// see in review and decide whether a visitor-only token is needed.
	array(
		'tpl_key'     => 'chris_classroom_visit_window_7d_v1',
		'tpl_name'    => '[Chris] Classroom Visit Window Opens (7d)',
		'subject'     => 'Reminder: Classroom Visit is opening soon',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that your next Begin to ECSEL Classroom Visits will take place next week. Please be prepared to visit your assigned classrooms and submit the Classroom Visit forms on Housman Learning Academy.</p>' ),
			array( 'type' => 'text', 'content' => '<p><strong>Details:</strong><br>- Program: [pathway_name]<br>- Classroom Visit: [classroom_visit_name]<br>- Window: [classroom_visit_release_date]-[classroom_visit_due_date]</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
		),
		'wf_name'                => '[Chris] Classroom Visit Window Opens (7d)',
		'trigger_key'            => 'cron:component_upcoming',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => 10080, // 7 days
		'component_type_filter'  => 'classroom_visit',
	),

	// ---------------------------------------------------------------------
	// Row 10 — Self-Reflection Prompt After Visit Submitted
	// ---------------------------------------------------------------------
	// Primary is observed_teacher (NOT triggering_user): the visitor
	// submits the form, but the teacher they observed is the one who
	// needs to complete the follow-up self-reflection.
	array(
		'tpl_key'     => 'chris_self_reflection_prompt_v1',
		'tpl_name'    => '[Chris] Self-Reflection Prompt After Visit Submitted',
		'subject'     => 'Action Needed: Complete the Self-Reflection Form',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that your Classroom Visitor has completed their visit and submitted the Classroom Visit form. Now it\'s your turn to complete the Self-Reflection.</p>' ),
			array( 'type' => 'text', 'content' => '<p><strong>Details:</strong><br>- Program: [pathway_name]<br>- Classroom Visit: [self_reflection_name]<br>- Complete by: [self_reflection_due_date]</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
		),
		'wf_name'                => '[Chris] Self-Reflection Prompt After Visit Submitted',
		'trigger_key'            => 'hl_classroom_visit_submitted',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'observed_teacher' ), 'cc' => array() ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 12 — RP Session Window Opens (1 week before)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_rp_session_window_7d_v1',
		'tpl_name'    => '[Chris] RP Session Window Opens (7d)',
		'subject'     => 'Upcoming Reflective Practice Sessions',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that your Begin to ECSEL Reflective Practice Session window is opening next week. Mentors and Teachers should be looking to book a date and time for which this reflective practice session will take place.</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
			array( 'type' => 'text', 'content' => '<p><strong>Details:</strong><br>- Program: [pathway_name]<br>- Session: [reflective_practice_session_name]<br>- Window: [reflective_practice_session_release_date]-[reflective_practice_session_due_date]</p>' ),
		),
		'wf_name'                => '[Chris] RP Session Window Opens (7d)',
		'trigger_key'            => 'cron:component_upcoming',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => 10080,
		'component_type_filter'  => 'reflective_practice_session',
	),

	// ---------------------------------------------------------------------
	// Row 13 — RP Window Now Open (same day, offset 0)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_rp_session_window_open_v1',
		'tpl_name'    => '[Chris] RP Window Now Open',
		'subject'     => 'Time to Schedule Reflective Practice Sessions',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that your Begin to ECSEL Reflective Practice Session window is now open. Please book a time with each Teacher you support and be prepared to lead the Reflective Practice Session with them if you have not already done so.</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
			array( 'type' => 'text', 'content' => '<p><strong>Details:</strong><br>- Program: [pathway_name]<br>- Session: [reflective_practice_session_name]<br>- Window: [reflective_practice_session_release_date]-[reflective_practice_session_due_date]</p>' ),
		),
		'wf_name'                => '[Chris] RP Window Now Open',
		'trigger_key'            => 'cron:component_upcoming',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => 0,
		'component_type_filter'  => 'reflective_practice_session',
	),

	// ---------------------------------------------------------------------
	// Row 14 — Coaching Reminder: 1 Week Before (if not scheduled)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_coaching_reminder_7d_not_scheduled_v1',
		'tpl_name'    => '[Chris] Coaching Reminder 1 Week Before (If Not Scheduled)',
		'subject'     => 'Time to Schedule Your Coaching Session',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>It\'s almost time to schedule your next Begin to ECSEL coaching session. Begin finding a time to meet with your assigned ECSEL Coach.</p>' ),
			array( 'type' => 'text', 'content' => '<p>As a mentor, you have access to one or more coaching sessions during your program: <strong>[pathway_name]</strong>.</p>' ),
			array( 'type' => 'text', 'content' => '<p><strong>How to Schedule:</strong><br>- Log into your Housman Learning Academy account<br>- From your dashboard, select "My Coaching"<br>- Choose "[coaching_session_name]" to select an available date and time<br>- Select "Book Session" to complete</p>' ),
			array( 'type' => 'text', 'content' => '<p>Once scheduled, you will receive an email and a calendar invite with the meeting link.</p>' ),
			array( 'type' => 'text', 'content' => '<p>If you need support, please contact your ECSEL coach: [Coach Email]</p>' ),
		),
		'wf_name'                => '[Chris] Coaching Reminder 1 Week Before (If Not Scheduled)',
		'trigger_key'            => 'cron:component_upcoming',
		'conditions'             => array(
			array( 'field' => 'coaching.session_status', 'op' => 'in', 'value' => array( 'not_scheduled' ) ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => 10080,
		'component_type_filter'  => 'coaching_session_attendance',
	),

	// ---------------------------------------------------------------------
	// Row 15 — Coaching Reminder: 2 Days Before Cycle Close (if not scheduled)
	// ---------------------------------------------------------------------
	// NOTE: The anchor here is the COACHING COMPONENT'S complete_by date, not
	// the cycle's end date. In practice complete_by is set near cycle close
	// so the semantic intent holds ("2 days before cycle closes" ~ "2 days
	// before the coaching component is due"). If Chris needs strict
	// cycle-end-relative firing, that's a separate backend change.
	array(
		'tpl_key'     => 'chris_coaching_reminder_2d_not_scheduled_v1',
		'tpl_name'    => '[Chris] Coaching Reminder 2 Days Before Close (If Not Scheduled)',
		'subject'     => 'Action Needed: Schedule Your Coaching Session',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>It\'s time to schedule your next Begin to ECSEL coaching session. Don\'t miss your chance — the window to meet will be closing in 2 days.</p>' ),
			array( 'type' => 'text', 'content' => '<p>As a mentor, you have access to one or more coaching sessions during your program: <strong>[pathway_name]</strong>.</p>' ),
			array( 'type' => 'text', 'content' => '<p><strong>How to Schedule:</strong><br>- Log into your Housman Learning Academy account<br>- From your dashboard, select "My Coaching"<br>- Choose "[coaching_session_name]" to select an available date and time<br>- Select "Book Session" to complete</p>' ),
			array( 'type' => 'text', 'content' => '<p>Once scheduled, you will receive an email and a calendar invite with the meeting link.</p>' ),
			array( 'type' => 'text', 'content' => '<p>If you need support, please contact your ECSEL coach: [Coach Email]</p>' ),
		),
		'wf_name'                => '[Chris] Coaching Reminder 2 Days Before Close (If Not Scheduled)',
		'trigger_key'            => 'cron:component_upcoming',
		'conditions'             => array(
			array( 'field' => 'coaching.session_status', 'op' => 'in', 'value' => array( 'not_scheduled' ) ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array() ),
		'trigger_offset_minutes' => 2880, // 2 days
		'component_type_filter'  => 'coaching_session_attendance',
	),

	// ---------------------------------------------------------------------
	// Row 16 — Coaching Session Scheduled Confirmation
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_coaching_scheduled_confirmation_v1',
		'tpl_name'    => '[Chris] Coaching Session Scheduled Confirmation',
		'subject'     => 'Your Mentor Coaching Session Is Scheduled',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [User_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>Your mentor coaching session with your ECSEL Coach has been officially scheduled for <strong>[session_date]</strong>.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Please log in to your Housman Learning account for any additional session details and access information.</p>' ),
			array( 'type' => 'text', 'content' => '<p>We look forward to connecting with you.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Thank you,<br>Housman Learning Academy</p>' ),
		),
		'wf_name'                => '[Chris] Coaching Session Scheduled Confirmation',
		'trigger_key'            => 'hl_coaching_session_created',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array( 'assigned_coach' ) ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 22 — Coaching No-Show Follow-Up
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_coaching_no_show_v1',
		'tpl_name'    => '[Chris] Coaching No-Show Follow-Up',
		'subject'     => 'Missed Coaching Meeting',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a follow-up regarding the coaching session scheduled for <strong>[session_date]</strong>.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Scheduling changes are understandable, and a new session can be arranged at a more convenient time, should you like to re-schedule. Please click the link below to take you to the re-scheduling feature. Contact your coach with any questions: [Coach email]</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
		),
		'wf_name'                => '[Chris] Coaching No-Show Follow-Up',
		'trigger_key'            => 'hl_coaching_session_status_changed',
		'conditions'             => array(
			array( 'field' => 'session.new_status', 'op' => 'eq', 'value' => 'missed' ),
		),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array( 'school_director' ) ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),

	// ---------------------------------------------------------------------
	// Row 24 — Low Engagement (14 days)
	// ---------------------------------------------------------------------
	array(
		'tpl_key'     => 'chris_low_engagement_14d_v1',
		'tpl_name'    => '[Chris] Low Engagement (14 days)',
		'subject'     => 'Checking In — Continue Your Learning',
		'body_blocks' => array(
			array( 'type' => 'text', 'content' => '<p>Hello [user_first_name],</p>' ),
			array( 'type' => 'text', 'content' => '<p>This is a friendly reminder that it has been about two weeks since your last activity in the Housman Learning Academy.</p>' ),
			array( 'type' => 'text', 'content' => '<p>Consistent engagement helps support progress through the program and ensures access to the tools, resources, and coaching available along the way.</p>' ),
			array( 'type' => 'text', 'content' => '<p>When you are ready, please log in to continue your next steps:</p>' ),
			array( 'type' => 'button', 'label' => 'Log In Now', 'url' => '{{login_url}}', 'bg_color' => '#2C7BE5', 'text_color' => '#FFFFFF' ),
			array( 'type' => 'text', 'content' => '<p>If you have any questions or need support, please reach out to your coach.</p>' ),
		),
		'wf_name'                => '[Chris] Low Engagement (14 days)',
		'trigger_key'            => 'cron:low_engagement_14d',
		'conditions'             => array(),
		'recipients'             => array( 'primary' => array( 'triggering_user' ), 'cc' => array( 'assigned_coach' ) ),
		'trigger_offset_minutes' => null,
		'component_type_filter'  => null,
	),
);

// =========================================================================
// Pre-flight: confirm every trigger_key is wired (reject stubs + unknowns)
// =========================================================================

$valid_triggers = HL_Admin_Emails::get_valid_trigger_keys();
foreach ( $workflows as $wf ) {
	if ( ! in_array( $wf['trigger_key'], $valid_triggers, true ) ) {
		fwrite( STDERR, "ABORT: trigger_key '{$wf['trigger_key']}' for workflow '{$wf['wf_name']}' is not in get_valid_trigger_keys(). Stubs/unknown keys are rejected.\n" );
		return;
	}
}

// =========================================================================
// Seed loop
// =========================================================================

$mode_banner = $dry_run ? '[DRY RUN] ' : '';
$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

$counts = array(
	'tpl_insert' => 0,
	'tpl_update' => 0,
	'wf_insert'  => 0,
	'wf_update'  => 0,
	'skipped'    => 0,
);

echo $mode_banner . "Seeding " . count( $workflows ) . " Chris workflows...\n";

foreach ( $workflows as $wf ) {
	$label = $wf['wf_name'];

	try {
		$translated_blocks = hl_seed_translate_blocks( $wf['body_blocks'], $merge_tag_map );
		$translated_subject = hl_seed_translate( $wf['subject'], $merge_tag_map );
		hl_seed_assert_no_brackets( $translated_subject, "workflow '{$label}' subject" );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, $mode_banner . "FAIL '{$label}': " . $e->getMessage() . "\n" );
		$counts['skipped']++;
		continue;
	}

	// ---- Security-boundary validation (same check the admin save handler runs) ----
	$valid = HL_Admin_Emails::validate_workflow_payload( $wf['conditions'], $wf['recipients'] );
	if ( is_wp_error( $valid ) ) {
		fwrite( STDERR, $mode_banner . "FAIL '{$label}': validate_workflow_payload => " . $valid->get_error_message() . "\n" );
		$counts['skipped']++;
		continue;
	}

	// ---- Template upsert ----
	$tpl_row = array(
		'template_key' => $wf['tpl_key'],
		'name'         => $wf['tpl_name'],
		'subject'      => $translated_subject,
		'blocks_json'  => wp_json_encode( $translated_blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		'category'     => 'automated',
		'merge_tags'   => null, // let admin save-handler compute when Mateo edits in UI
		'status'       => 'draft',
	);

	$existing_tpl_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT template_id FROM {$tpl_table} WHERE template_key = %s LIMIT 1",
		$wf['tpl_key']
	) );

	if ( $existing_tpl_id > 0 ) {
		echo $mode_banner . "UPDATE template #{$existing_tpl_id} key={$wf['tpl_key']}\n";
		if ( ! $dry_run ) {
			$wpdb->update( $tpl_table, $tpl_row, array( 'template_id' => $existing_tpl_id ) );
		}
		$template_id = $existing_tpl_id;
		$counts['tpl_update']++;
	} else {
		echo $mode_banner . "INSERT template key={$wf['tpl_key']}\n";
		$template_id = 0;
		if ( ! $dry_run ) {
			$tpl_row['created_by'] = $current_user_id;
			$wpdb->insert( $tpl_table, $tpl_row );
			$template_id = (int) $wpdb->insert_id;
		}
		$counts['tpl_insert']++;
	}

	// ---- Workflow upsert ----
	$conditions_json = wp_json_encode( $wf['conditions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$recipients_json = wp_json_encode( $wf['recipients'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	$wf_row = array(
		'name'                   => $wf['wf_name'],
		'trigger_key'            => $wf['trigger_key'],
		'conditions'             => $conditions_json,
		'recipients'             => $recipients_json,
		'template_id'            => $template_id ?: null,
		'delay_minutes'          => 0,
		'send_window_start'      => null,
		'send_window_end'        => null,
		'send_window_days'       => null,
		'status'                 => 'draft', // ALWAYS draft — Mateo activates after review.
		'trigger_offset_minutes' => $wf['trigger_offset_minutes'],
		'component_type_filter'  => $wf['component_type_filter'],
	);

	$existing_wf_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT workflow_id FROM {$wf_table} WHERE name = %s LIMIT 1",
		$wf['wf_name']
	) );

	if ( $existing_wf_id > 0 ) {
		echo $mode_banner . "UPDATE workflow #{$existing_wf_id} name='{$wf['wf_name']}' trigger='{$wf['trigger_key']}'\n";
		if ( ! $dry_run ) {
			$wpdb->update( $wf_table, $wf_row, array( 'workflow_id' => $existing_wf_id ) );
		}
		$workflow_id = $existing_wf_id;
		$counts['wf_update']++;
	} else {
		echo $mode_banner . "INSERT workflow name='{$wf['wf_name']}' trigger='{$wf['trigger_key']}'\n";
		$workflow_id = 0;
		if ( ! $dry_run ) {
			$wpdb->insert( $wf_table, $wf_row );
			$workflow_id = (int) $wpdb->insert_id;
		}
		$counts['wf_insert']++;
	}

	// ---- Audit log (real run only — dry-run is silent to audit) ----
	if ( ! $dry_run && class_exists( 'HL_Audit_Service' ) ) {
		HL_Audit_Service::log( 'email_workflow_seeded', array(
			'entity_type' => 'email_workflow',
			'entity_id'   => $workflow_id,
			'reason'      => "Chris seeder: tpl_key={$wf['tpl_key']}, trigger={$wf['trigger_key']}",
		) );
	}
}

echo "\n" . $mode_banner . "Done.\n";
echo "  Templates inserted: {$counts['tpl_insert']}\n";
echo "  Templates updated:  {$counts['tpl_update']}\n";
echo "  Workflows inserted: {$counts['wf_insert']}\n";
echo "  Workflows updated:  {$counts['wf_update']}\n";
echo "  Skipped (errors):   {$counts['skipped']}\n";

if ( $dry_run ) {
	echo "\n[DRY RUN] No DB writes occurred. Re-run without HL_SEED_DRY_RUN=1 to commit.\n";
}
