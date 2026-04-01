<?php
/**
 * WP-CLI command: wp hl-core setup-elcpb-y2
 *
 * Creates the ELCPB Year 2 (2026) cycle and all 8 pathways with components.
 * Uses the existing Partnership (ELCPB-B2E-2025) and links to the same schools.
 *
 * Phase 1 pathways (for new participants): Teacher, Mentor, Streamlined.
 * Phase 2 pathways (for returning participants): Teacher, Mentor, Mentor Transition, Mentor Completion, Streamlined.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Setup_ELCPB_Y2 {

	/** Cycle code for Year 2. */
	const CYCLE_CODE = 'ELCPB-Y2-2026';

	/** Partnership code (existing container). */
	const PARTNERSHIP_CODE = 'ELCPB-B2E-2025';

	/** Year 1 cycle code (to find district + schools). */
	const Y1_CYCLE_CODE = 'ELCPB-Y1-2025';

	// ------------------------------------------------------------------
	// LearnDash Course IDs
	// ------------------------------------------------------------------

	// Mastery versions.
	const TC0 = 31037;
	const TC1 = 30280;
	const TC2 = 30284;
	const TC3 = 30286;
	const TC4 = 30288;
	const TC5 = 39724;
	const TC6 = 39726;
	const TC7 = 39728;
	const TC8 = 39730;
	const MC1 = 30293;
	const MC2 = 30295;
	const MC3 = 39732;
	const MC4 = 39734;

	// Streamlined versions.
	const TC1_S = 31332;
	const TC2_S = 31333;
	const TC3_S = 31334;
	const TC4_S = 31335;
	const TC5_S = 31336;
	const TC6_S = 31337;
	const TC7_S = 31338;
	const TC8_S = 31339;
	const MC1_S = 31387;
	const MC2_S = 31388;
	const MC3_S = 31389;
	const MC4_S = 31390;

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core setup-elcpb-y2', array( new self(), 'run' ) );
	}

	/**
	 * Create ELCPB Year 2 cycle with all pathways and components.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove Year 2 cycle and all its pathways/components before creating.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core setup-elcpb-y2
	 *     wp hl-core setup-elcpb-y2 --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'ELCPB Year 2 data cleaned.' );
			return;
		}

		if ( $this->cycle_exists() ) {
			WP_CLI::warning( 'ELCPB Year 2 cycle already exists. Run with --clean first to re-create.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core ELCPB Year 2 Setup ===' );
		WP_CLI::line( '' );

		// Step 1: Resolve partnership + Year 1 context.
		$context = $this->resolve_context();
		if ( ! $context ) {
			return;
		}

		// Step 2: Create Year 2 cycle.
		$cycle_id = $this->create_cycle( $context );

		// Step 3: Create all 8 pathways with components.
		$pathways = $this->create_all_pathways( $cycle_id );

		WP_CLI::line( '' );
		WP_CLI::success( 'ELCPB Year 2 setup complete!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Cycle:    {$cycle_id} (code: " . self::CYCLE_CODE . ')' );
		WP_CLI::line( '  Pathways: ' . count( $pathways ) );
		foreach ( $pathways as $name => $info ) {
			WP_CLI::line( "    {$name}: pathway_id={$info['pathway_id']}, {$info['component_count']} components" );
		}
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Idempotency
	// ------------------------------------------------------------------

	private function cycle_exists() {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);
	}

	private function clean() {
		global $wpdb;
		$t = $wpdb->prefix;

		$cycle_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);

		if ( ! $cycle_id ) {
			WP_CLI::log( 'No Year 2 cycle found — nothing to clean.' );
			return;
		}

		// Remove components, pathways, cycle_school links, and cycle.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

		WP_CLI::log( "  Cleaned Year 2 cycle (id={$cycle_id}) + pathways + components." );
	}

	// ------------------------------------------------------------------
	// Step 1: Resolve context from Year 1
	// ------------------------------------------------------------------

	private function resolve_context() {
		global $wpdb;
		$t = $wpdb->prefix;

		// Find partnership.
		$partnership_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT partnership_id FROM {$t}hl_partnership WHERE partnership_code = %s LIMIT 1",
				self::PARTNERSHIP_CODE
			)
		);
		if ( ! $partnership_id ) {
			WP_CLI::error( 'Partnership ' . self::PARTNERSHIP_CODE . ' not found. Run import-elcpb first.' );
			return null;
		}

		// Find Year 1 cycle to get district_id.
		$y1 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cycle_id, district_id FROM {$t}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::Y1_CYCLE_CODE
			)
		);
		if ( ! $y1 ) {
			WP_CLI::error( 'Year 1 cycle ' . self::Y1_CYCLE_CODE . ' not found.' );
			return null;
		}

		// Get schools linked to Year 1.
		$school_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT school_id FROM {$t}hl_cycle_school WHERE cycle_id = %d",
				$y1->cycle_id
			)
		);

		WP_CLI::log( "  [1] Context: partnership={$partnership_id}, district={$y1->district_id}, schools=" . count( $school_ids ) );

		return array(
			'partnership_id' => (int) $partnership_id,
			'district_id'    => (int) $y1->district_id,
			'school_ids'     => array_map( 'intval', $school_ids ),
		);
	}

	// ------------------------------------------------------------------
	// Step 2: Create Year 2 Cycle
	// ------------------------------------------------------------------

	private function create_cycle( $context ) {
		global $wpdb;
		$repo = new HL_Cycle_Repository();

		$cycle_id = $repo->create( array(
			'cycle_name'     => 'ELCPB Mastery - Year 2 (2026)',
			'cycle_code'     => self::CYCLE_CODE,
			'partnership_id' => $context['partnership_id'],
			'cycle_type'     => 'program',
			'district_id'    => $context['district_id'],
			'status'         => 'active',
			'start_date'     => '2026-03-30',
			'end_date'       => '2026-09-12',
		) );

		// Link same schools.
		foreach ( $context['school_ids'] as $school_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_cycle_school', array(
				'cycle_id'  => $cycle_id,
				'school_id' => $school_id,
			) );
		}

		WP_CLI::log( "  [2] Cycle created: id={$cycle_id}, code=" . self::CYCLE_CODE );
		return $cycle_id;
	}

	// ------------------------------------------------------------------
	// Step 3: Create all pathways + components
	// ------------------------------------------------------------------

	private function create_all_pathways( $cycle_id ) {
		$svc      = new HL_Pathway_Service();
		$pathways = array();

		// --- Phase 1 pathways (for new participants) ---

		$pathways['Teacher Phase 1']      = $this->create_teacher_phase1( $svc, $cycle_id );
		$pathways['Teacher Phase 2']      = $this->create_teacher_phase2( $svc, $cycle_id );
		$pathways['Mentor Phase 1']       = $this->create_mentor_phase1( $svc, $cycle_id );
		$pathways['Mentor Phase 2']       = $this->create_mentor_phase2( $svc, $cycle_id );
		$pathways['Mentor Transition']    = $this->create_mentor_transition( $svc, $cycle_id );
		$pathways['Mentor Completion']    = $this->create_mentor_completion( $svc, $cycle_id );
		$pathways['Streamlined Phase 1']  = $this->create_streamlined_phase1( $svc, $cycle_id );
		$pathways['Streamlined Phase 2']  = $this->create_streamlined_phase2( $svc, $cycle_id );

		WP_CLI::log( '  [3] All 8 pathways created.' );
		return $pathways;
	}

	// ------------------------------------------------------------------
	// Helper: create a component (shorthand)
	// ------------------------------------------------------------------

	private function cmp( $svc, $pathway_id, $cycle_id, $title, $type, $order, $ext_ref = array() ) {
		return $svc->create_component( array(
			'title'          => $title,
			'pathway_id'     => $pathway_id,
			'cycle_id'       => $cycle_id,
			'component_type' => $type,
			'weight'         => 1.0,
			'ordering_hint'  => $order,
			'external_ref'   => wp_json_encode( $ext_ref ?: (object) array() ),
		) );
	}

	// ------------------------------------------------------------------
	// Teacher Phase 1: New teachers — TC0-4 + Classroom Visits + RP
	// ------------------------------------------------------------------

	private function create_teacher_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Teacher Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',         'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                          'learndash_course',             ++$n, array( 'course_id' => self::TC0 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',          'learndash_course',             ++$n, array( 'course_id' => self::TC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #1',  'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #1',                         'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',            'learndash_course',             ++$n, array( 'course_id' => self::TC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #2',  'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #2',                         'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',          'learndash_course',             ++$n, array( 'course_id' => self::TC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #3',  'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #3',                         'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment', 'learndash_course',            ++$n, array( 'course_id' => self::TC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #4',  'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #4',                         'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',         'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Teacher Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Teacher Phase 2: Returning teachers — TC5-8 + Classroom Visits + RP
	// ------------------------------------------------------------------

	private function create_teacher_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Teacher Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                 'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',    'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #1',          'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #1',                                 'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course',            ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #2',          'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #2',                                 'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',   'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #3',          'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #3',                                 'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',          'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit & Self-Reflection #4',          'classroom_visit',                  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #4',                                 'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                 'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Teacher Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Phase 1: New mentors — TC0-4, MC1-2 (same structure as Year 1)
	// ------------------------------------------------------------------

	private function create_mentor_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                     'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                      'learndash_course',             ++$n, array( 'course_id' => self::TC0 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',                      'learndash_course',             ++$n, array( 'course_id' => self::TC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',          'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',                        'learndash_course',             ++$n, array( 'course_id' => self::TC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',                      'learndash_course',             ++$n, array( 'course_id' => self::TC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice',       'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment',            'learndash_course',             ++$n, array( 'course_id' => self::TC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                     'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Mentor Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Phase 2: Returning mentors — TC5-8, MC3-4 + Coaching + RP
	// ------------------------------------------------------------------

	private function create_mentor_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                      'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',         'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers', 'learndash_course',             ++$n, array( 'course_id' => self::MC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #1',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',     'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #2',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',        'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',   'learndash_course',             ++$n, array( 'course_id' => self::MC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #3',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',               'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #4',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                      'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Mentor Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Transition: Teacher→Mentor promotion — TC5-8, MC1-2 + Coaching + RP
	// ------------------------------------------------------------------

	private function create_mentor_transition( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Transition',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                      'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',         'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',           'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #1',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',     'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #2',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',        'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice',        'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #3',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',               'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'RP Session #4',                                      'coaching_session_attendance',  ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                      'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Mentor Transition: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Completion: Mentors finishing MC training — MC3-4 only
	// ------------------------------------------------------------------

	private function create_mentor_completion( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Completion',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers', 'learndash_course', ++$n, array( 'course_id' => self::MC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',   'learndash_course', ++$n, array( 'course_id' => self::MC4 ) );

		WP_CLI::log( "    Mentor Completion: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Streamlined Phase 1: New leaders — TC0-4, MC1-2 (streamlined)
	// Same structure as Year 1 Streamlined pathway.
	// ------------------------------------------------------------------

	private function create_streamlined_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Streamlined Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'school_leader' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                             'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                              'learndash_course',        ++$n, array( 'course_id' => self::TC0 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro (Streamlined)',                                   'learndash_course',        ++$n, array( 'course_id' => self::TC1_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality (Streamlined)',                   'learndash_course',        ++$n, array( 'course_id' => self::TC2_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion (Streamlined)',                 'learndash_course',        ++$n, array( 'course_id' => self::TC3_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment (Streamlined)',       'learndash_course',        ++$n, array( 'course_id' => self::TC4_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC1: Intro to Reflective Practice (Streamlined)',            'learndash_course',        ++$n, array( 'course_id' => self::MC1_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC2: Deeper Dive Reflective Practice (Streamlined)',         'learndash_course',        ++$n, array( 'course_id' => self::MC2_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                             'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );

		WP_CLI::log( "    Streamlined Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Streamlined Phase 2: Returning leaders — TC5-8 (streamlined) + Classroom Visits
	// ------------------------------------------------------------------

	private function create_streamlined_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Streamlined Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'school_leader' ),
			'active_status' => 1,
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning (Streamlined)',       'learndash_course', ++$n, array( 'course_id' => self::TC5_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #1',                                             'classroom_visit',      ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Inclusivity & Prosocial Behaviors (Streamlined)',  'learndash_course', ++$n, array( 'course_id' => self::TC6_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #2',                                             'classroom_visit',      ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed (Streamlined)',      'learndash_course', ++$n, array( 'course_id' => self::TC7_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #3',                                             'classroom_visit',      ++$n );
		$this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom (Streamlined)',             'learndash_course', ++$n, array( 'course_id' => self::TC8_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #4',                                             'classroom_visit',      ++$n );

		WP_CLI::log( "    Streamlined Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}
}
