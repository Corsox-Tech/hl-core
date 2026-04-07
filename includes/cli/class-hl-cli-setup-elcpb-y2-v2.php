<?php
/**
 * WP-CLI command: wp hl-core setup-elcpb-y2-v2
 *
 * Rebuilt ELCPB Year 2 (2026) CLI with correct pathway structure,
 * new component types (self_reflection, reflective_practice_session, classroom_visit),
 * instrument seeding, and demo coach provisioning.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Setup_ELCPB_Y2_V2 {

	/** Cycle code for Year 2. */
	const CYCLE_CODE = 'ELCPB-Y2-2026';

	/** Partnership code (existing container). */
	const PARTNERSHIP_CODE = 'ELCPB-B2E-2025';

	/** Year 1 cycle code (to find district + schools). */
	const Y1_CYCLE_CODE = 'ELCPB-Y1-2025';

	// ------------------------------------------------------------------
	// LearnDash Course IDs — Mastery
	// ------------------------------------------------------------------
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

	// ------------------------------------------------------------------
	// LearnDash Course IDs — Streamlined
	// ------------------------------------------------------------------
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
		WP_CLI::add_command( 'hl-core setup-elcpb-y2-v2', array( new self(), 'run' ) );
	}

	/**
	 * Create ELCPB Year 2 cycle with correct pathways, new component types,
	 * instrument seeding, and demo coach.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove Year 2 V2 cycle and all its pathways/components before creating.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core setup-elcpb-y2-v2
	 *     wp hl-core setup-elcpb-y2-v2 --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'ELCPB Year 2 V2 data cleaned.' );
			return;
		}

		if ( $this->cycle_exists() ) {
			WP_CLI::warning( 'ELCPB Year 2 cycle already exists. Run with --clean first to re-create.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core ELCPB Year 2 V2 Setup ===' );
		WP_CLI::line( '' );

		// Step 1: Resolve partnership + Year 1 context.
		$context = $this->resolve_context();
		if ( ! $context ) {
			return;
		}

		// Step 2: Create Year 2 cycle.
		$cycle_id = $this->create_cycle( $context );

		// Step 3: Create all 8 pathways with components + prerequisites.
		$pathways = $this->create_all_pathways( $cycle_id );

		// Step 4: Seed instruments.
		$this->seed_instruments();

		// Step 5: Seed demo coach.
		$this->seed_demo_coach();

		WP_CLI::line( '' );
		WP_CLI::success( 'ELCPB Year 2 V2 setup complete!' );
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

		// Get component IDs for prerequisite cleanup.
		$component_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT component_id FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id )
		);

		if ( ! empty( $component_ids ) ) {
			$in_comp = implode( ',', array_map( 'intval', $component_ids ) );
			$group_ids = $wpdb->get_col(
				"SELECT group_id FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})"
			);
			if ( ! empty( $group_ids ) ) {
				$in_grp = implode( ',', array_map( 'intval', $group_ids ) );
				$wpdb->query( "DELETE FROM {$t}hl_component_prereq_item WHERE group_id IN ({$in_grp})" );
			}
			$wpdb->query( "DELETE FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})" );
		}

		// Remove components, pathways, cycle_school links, and cycle.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

		WP_CLI::log( "  Cleaned Year 2 cycle (id={$cycle_id}) + pathways + components + prerequisites." );
	}

	// ------------------------------------------------------------------
	// Step 1: Resolve context from Year 1
	// ------------------------------------------------------------------

	private function resolve_context() {
		global $wpdb;
		$t = $wpdb->prefix;

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

		$pathways['Teacher Phase 1']     = $this->create_teacher_phase1( $svc, $cycle_id );
		$pathways['Teacher Phase 2']     = $this->create_teacher_phase2( $svc, $cycle_id );
		$pathways['Mentor Phase 1']      = $this->create_mentor_phase1( $svc, $cycle_id );
		$pathways['Mentor Phase 2']      = $this->create_mentor_phase2( $svc, $cycle_id );
		$pathways['Mentor Transition']   = $this->create_mentor_transition( $svc, $cycle_id );
		$pathways['Mentor Completion']   = $this->create_mentor_completion( $svc, $cycle_id );
		$pathways['Streamlined Phase 1'] = $this->create_streamlined_phase1( $svc, $cycle_id );
		$pathways['Streamlined Phase 2'] = $this->create_streamlined_phase2( $svc, $cycle_id );

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
	// Helper: create prerequisite (course blocked by prior course)
	// ------------------------------------------------------------------

	private function add_prereq( $component_id, $prerequisite_component_id ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'hl_component_prereq_group', array(
			'component_id' => $component_id,
			'prereq_type'  => 'all_of',
		) );
		$group_id = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_component_prereq_item', array(
			'group_id'                  => $group_id,
			'prerequisite_component_id' => $prerequisite_component_id,
		) );
	}

	// ------------------------------------------------------------------
	// Teacher Phase 1 (17 components): TSA Pre, CA Pre, TC0, TC1, SR#1,
	// RP#1, TC2, SR#2, RP#2, TC3, SR#3, RP#3, TC4, SR#4, RP#4, CA Post, TSA Post
	// ------------------------------------------------------------------

	private function create_teacher_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Teacher Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
			'routing_type'  => 'teacher_phase_1',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => 1 ) );
		$ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',          'child_assessment',        ++$n, array( 'phase' => 'pre' ) );
		$tc0     = $this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                   'learndash_course',        ++$n, array( 'course_id' => self::TC0 ) );
		$tc1     = $this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',   'learndash_course',        ++$n, array( 'course_id' => self::TC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #1',                        'self_reflection',         ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',             'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc2     = $this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',     'learndash_course',        ++$n, array( 'course_id' => self::TC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #2',                        'self_reflection',         ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',             'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc3     = $this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',   'learndash_course',        ++$n, array( 'course_id' => self::TC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #3',                        'self_reflection',         ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',             'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc4     = $this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment', 'learndash_course', ++$n, array( 'course_id' => self::TC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #4',                        'self_reflection',         ++$n, array( 'visit_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',             'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                    'child_assessment',        ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',             'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => 2 ) );

		// Prerequisites: course chain TC0→TC1→TC2→TC3→TC4, first course blocked by TSA Pre.
		$this->add_prereq( $tc0, $tsa_pre );
		$this->add_prereq( $tc1, $tc0 );
		$this->add_prereq( $tc2, $tc1 );
		$this->add_prereq( $tc3, $tc2 );
		$this->add_prereq( $tc4, $tc3 );

		WP_CLI::log( "    Teacher Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Teacher Phase 2 (16 components): TSA Pre, CA Pre, TC5, SR#1, RP#1,
	// TC6, SR#2, RP#2, TC7, SR#3, RP#3, TC8, SR#4, RP#4, CA Post, TSA Post
	// ------------------------------------------------------------------

	private function create_teacher_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Teacher Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
			'routing_type'  => 'teacher_phase_2',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => 1 ) );
		$ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',          'child_assessment',        ++$n, array( 'phase' => 'pre' ) );
		$tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning', 'learndash_course', ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #1',                        'self_reflection',         ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',             'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course', ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #2',                        'self_reflection',         ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',             'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed', 'learndash_course', ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #3',                        'self_reflection',         ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',             'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom', 'learndash_course', ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #4',                        'self_reflection',         ++$n, array( 'visit_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',             'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                    'child_assessment',        ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',             'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => 2 ) );

		// Prerequisites: course chain TC5→TC6→TC7→TC8, first course blocked by TSA Pre.
		$this->add_prereq( $tc5, $tsa_pre );
		$this->add_prereq( $tc6, $tc5 );
		$this->add_prereq( $tc7, $tc6 );
		$this->add_prereq( $tc8, $tc7 );

		WP_CLI::log( "    Teacher Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Phase 1 (19 components): TSA Pre, CA Pre, TC0, TC1, Coaching#1,
	// MC1, RP#1, TC2, Coaching#2, RP#2, TC3, Coaching#3, MC2, RP#3,
	// TC4, Coaching#4, RP#4, CA Post, TSA Post
	// ------------------------------------------------------------------

	private function create_mentor_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
			'routing_type'  => 'mentor_phase_1',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',               'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                       'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
		$tc0     = $this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                'learndash_course',             ++$n, array( 'course_id' => self::TC0 ) );
		$tc1     = $this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',                'learndash_course',             ++$n, array( 'course_id' => self::TC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
		$mc1     = $this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',    'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                          'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc2     = $this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',                  'learndash_course',             ++$n, array( 'course_id' => self::TC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                          'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc3     = $this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',                'learndash_course',             ++$n, array( 'course_id' => self::TC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
		$mc2     = $this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice', 'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                          'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc4     = $this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment',      'learndash_course',             ++$n, array( 'course_id' => self::TC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                          'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                 'child_assessment',             ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                          'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		// Prerequisites: course chains.
		$this->add_prereq( $tc0, $tsa_pre );
		$this->add_prereq( $tc1, $tc0 );
		$this->add_prereq( $mc1, $tc1 );
		$this->add_prereq( $tc2, $mc1 );
		$this->add_prereq( $tc3, $tc2 );
		$this->add_prereq( $mc2, $tc3 );
		$this->add_prereq( $tc4, $mc2 );

		WP_CLI::log( "    Mentor Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Phase 2 (18 components): TSA Pre, CA Pre, TC5, Coaching#1,
	// MC3, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC4, RP#3,
	// TC8, Coaching#4, RP#4, CA Post, TSA Post
	// ------------------------------------------------------------------

	private function create_mentor_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
			'routing_type'  => 'mentor_phase_2',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                               'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
		$tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',           'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
		$mc3     = $this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers',  'learndash_course',             ++$n, array( 'course_id' => self::MC3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                                  'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',      'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                                  'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',         'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
		$mc4     = $this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',    'learndash_course',             ++$n, array( 'course_id' => self::MC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                                  'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',                'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                                  'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                         'child_assessment',             ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		// Prerequisites: course chain.
		$this->add_prereq( $tc5, $tsa_pre );
		$this->add_prereq( $mc3, $tc5 );
		$this->add_prereq( $tc6, $mc3 );
		$this->add_prereq( $tc7, $tc6 );
		$this->add_prereq( $mc4, $tc7 );
		$this->add_prereq( $tc8, $mc4 );

		WP_CLI::log( "    Mentor Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Transition (18 components): TSA Pre, CA Pre, TC5, Coaching#1,
	// MC1, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC2, RP#3,
	// TC8, Coaching#4, RP#4, CA Post, TSA Post
	// ------------------------------------------------------------------

	private function create_mentor_transition( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Transition',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
			'routing_type'  => 'mentor_transition',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
		$ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                               'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
		$tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',           'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
		$mc1     = $this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',            'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                                  'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',      'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                                  'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',         'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
		$mc2     = $this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice',         'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                                  'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',                'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                                  'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                         'child_assessment',             ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

		// Prerequisites: course chain.
		$this->add_prereq( $tc5, $tsa_pre );
		$this->add_prereq( $mc1, $tc5 );
		$this->add_prereq( $tc6, $mc1 );
		$this->add_prereq( $tc7, $tc6 );
		$this->add_prereq( $mc2, $tc7 );
		$this->add_prereq( $tc8, $mc2 );

		WP_CLI::log( "    Mentor Transition: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Mentor Completion (4 components): TSA Pre, MC3, MC4, TSA Post
	// ------------------------------------------------------------------

	private function create_mentor_completion( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Completion',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
			'routing_type'  => 'mentor_completion',
		) );

		$n = 0;
		$tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => 1 ) );
		$mc3     = $this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers',  'learndash_course',        ++$n, array( 'course_id' => self::MC3 ) );
		$mc4     = $this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',    'learndash_course',        ++$n, array( 'course_id' => self::MC4 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => 2 ) );

		// Prerequisites: TSA Pre → MC3 → MC4.
		$this->add_prereq( $mc3, $tsa_pre );
		$this->add_prereq( $mc4, $mc3 );

		WP_CLI::log( "    Mentor Completion: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Streamlined Phase 1 (11 components): TC0, TC1(S), MC1(S), CV#1,
	// TC2(S), CV#2, TC3(S), CV#3, TC4(S), MC2(S), CV#4
	// No prerequisites for Streamlined pathways.
	// ------------------------------------------------------------------

	private function create_streamlined_phase1( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Streamlined Phase 1',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'school_leader', 'district_leader' ),
			'active_status' => 1,
			'routing_type'  => 'streamlined_phase_1',
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                                    'learndash_course',   ++$n, array( 'course_id' => self::TC0 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro (Streamlined)',                                         'learndash_course',   ++$n, array( 'course_id' => self::TC1_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC1: Intro to Reflective Practice (Streamlined)',                   'learndash_course',   ++$n, array( 'course_id' => self::MC1_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #1',                                               'classroom_visit',    ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality (Streamlined)',                          'learndash_course',   ++$n, array( 'course_id' => self::TC2_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #2',                                               'classroom_visit',    ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion (Streamlined)',                        'learndash_course',   ++$n, array( 'course_id' => self::TC3_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #3',                                               'classroom_visit',    ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment (Streamlined)',              'learndash_course',   ++$n, array( 'course_id' => self::TC4_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC2: Deeper Dive Reflective Practice (Streamlined)',                'learndash_course',   ++$n, array( 'course_id' => self::MC2_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #4',                                               'classroom_visit',    ++$n, array( 'visit_number' => 4 ) );

		// No prerequisites for Streamlined pathways.

		WP_CLI::log( "    Streamlined Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Streamlined Phase 2 (10 components): TC5(S), MC3(S), CV#1, TC6(S),
	// CV#2, TC7(S), CV#3, TC8(S), MC4(S), CV#4
	// No prerequisites for Streamlined pathways.
	// ------------------------------------------------------------------

	private function create_streamlined_phase2( $svc, $cycle_id ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Streamlined Phase 2',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'school_leader', 'district_leader' ),
			'active_status' => 1,
			'routing_type'  => 'streamlined_phase_2',
		) );

		$n = 0;
		$this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning (Streamlined)',        'learndash_course',   ++$n, array( 'course_id' => self::TC5_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Co-Workers (Streamlined)',                    'learndash_course',   ++$n, array( 'course_id' => self::MC3_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #1',                                              'classroom_visit',    ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Inclusivity & Prosocial Behaviors (Streamlined)',   'learndash_course',   ++$n, array( 'course_id' => self::TC6_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #2',                                              'classroom_visit',    ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed (Streamlined)',       'learndash_course',   ++$n, array( 'course_id' => self::TC7_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #3',                                              'classroom_visit',    ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom (Streamlined)',              'learndash_course',   ++$n, array( 'course_id' => self::TC8_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Families (Streamlined)',                      'learndash_course',   ++$n, array( 'course_id' => self::MC4_S ) );
		$this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #4',                                              'classroom_visit',    ++$n, array( 'visit_number' => 4 ) );

		// No prerequisites for Streamlined pathways.

		WP_CLI::log( "    Streamlined Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// ------------------------------------------------------------------
	// Step 4: Seed 6 instruments
	// ------------------------------------------------------------------

	private function seed_instruments() {
		global $wpdb;
		$table = $wpdb->prefix . 'hl_teacher_assessment_instrument';

		$domains = array(
			array(
				'name'   => 'Emotional Climate & Teacher Presence',
				'skills' => array(
					'Demonstrate calm, emotionally regulated presence',
					'Model attentive, engaged, and supportive behavior',
				),
			),
			array(
				'name'   => 'ECSEL Language & Emotional Communication',
				'skills' => array(
					'Consistently use emotion language to label/validate feelings',
					'Use Causal Talk (CT) to connect emotions, behavior, experiences',
				),
			),
			array(
				'name'   => 'Co-Regulation & Emotional Support',
				'skills' => array(
					'Use Causal Talk in Emotional Experience (CTEE) for heightened emotions',
					'Guide children toward regulation before problem-solving',
				),
			),
			array(
				'name'   => 'Social Skills, Empathy & Inclusion',
				'skills' => array(
					'Model/encourage empathy, cooperation, respect',
					'Classroom interactions reflect inclusion and respect',
					'Guide children through conflict resolution steps',
				),
			),
			array(
				'name'   => 'Use of Developmentally-Appropriate ECSEL Tools',
				'skills' => array(
					'ECSEL tools visible, accessible, intentionally placed',
					'Use tools appropriately for emotion knowledge/conflict resolution',
				),
			),
			array(
				'name'   => 'Integration into Daily Learning',
				'skills' => array(
					'Embed tools, language, strategies in play/routines/learning',
					'Use emotional moments as learning opportunities',
				),
			),
		);

		$instruments = array(
			array(
				'key'     => 'coaching_rp_notes',
				'name'    => 'Coaching RP Notes',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Session Information', 'type' => 'auto_populated', 'fields' => array( 'coach_name', 'mentor_name', 'date', 'session_number', 'current_course' ) ),
					array( 'title' => 'Personal Notes', 'type' => 'richtext', 'visibility' => 'supervisor_only' ),
					array( 'title' => 'Session Prep Notes', 'type' => 'auto_populated', 'fields' => array( 'pathway_progress', 'previous_action_plans', 'recent_classroom_visits' ) ),
					array( 'title' => 'Classroom Visit & Self-Reflection Review', 'type' => 'auto_populated', 'fields' => array( 'recent_cv_responses' ) ),
					array( 'title' => 'RP Session Notes', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'successes', 'type' => 'richtext' ),
						array( 'key' => 'challenges', 'type' => 'richtext' ),
						array( 'key' => 'supports_needed', 'type' => 'richtext' ),
						array( 'key' => 'next_steps', 'type' => 'richtext' ),
						array( 'key' => 'next_session_date', 'type' => 'date' ),
					) ),
					array( 'title' => 'RP Steps Guide', 'type' => 'accordion', 'content' => 'rp_steps_prompts' ),
				) ),
			),
			array(
				'key'     => 'mentoring_rp_notes',
				'name'    => 'Mentoring RP Notes',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Session Information', 'type' => 'auto_populated', 'fields' => array( 'mentor_name', 'teacher_name', 'date', 'session_number', 'current_course' ) ),
					array( 'title' => 'Personal Notes', 'type' => 'richtext', 'visibility' => 'supervisor_only' ),
					array( 'title' => 'Session Prep Notes', 'type' => 'auto_populated', 'fields' => array( 'pathway_progress', 'previous_action_plans', 'recent_classroom_visits' ) ),
					array( 'title' => 'Classroom Visit & Self-Reflection Review', 'type' => 'auto_populated', 'fields' => array( 'recent_cv_responses' ) ),
					array( 'title' => 'RP Session Notes', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'successes', 'type' => 'richtext' ),
						array( 'key' => 'challenges', 'type' => 'richtext' ),
						array( 'key' => 'supports_needed', 'type' => 'richtext' ),
						array( 'key' => 'next_steps', 'type' => 'richtext' ),
						array( 'key' => 'next_session_date', 'type' => 'date' ),
					) ),
					array( 'title' => 'RP Steps Guide', 'type' => 'accordion', 'content' => 'rp_steps_prompts' ),
				) ),
			),
			array(
				'key'     => 'coaching_action_plan',
				'name'    => 'Coaching Action Plan & Results',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Planning', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'domain', 'type' => 'select', 'options' => array_column( $domains, 'name' ) ),
						array( 'key' => 'skills', 'type' => 'multiselect', 'conditional_on' => 'domain', 'options_map' => $domains ),
						array( 'key' => 'how_practice', 'type' => 'textarea', 'label' => 'Describe HOW you will practice' ),
						array( 'key' => 'what_behaviors', 'type' => 'textarea', 'label' => 'WHAT behaviors will you track' ),
					) ),
					array( 'title' => 'Results', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'practice_reflection', 'type' => 'textarea', 'label' => 'How has your practice gone?' ),
						array( 'key' => 'degree_of_success', 'type' => 'likert', 'min' => 1, 'max' => 5, 'labels' => array( 'Not at all Successful', 'Extremely Successful' ) ),
						array( 'key' => 'impact_observations', 'type' => 'textarea', 'label' => 'Observations of impact on students' ),
						array( 'key' => 'what_learned', 'type' => 'textarea', 'label' => 'What you learned' ),
						array( 'key' => 'still_wondering', 'type' => 'textarea', 'label' => 'What you\'re still wondering' ),
					) ),
				) ),
			),
			array(
				'key'     => 'mentoring_action_plan',
				'name'    => 'Mentoring Action Plan & Results',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Planning', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'domain', 'type' => 'select', 'options' => array_column( $domains, 'name' ) ),
						array( 'key' => 'skills', 'type' => 'multiselect', 'conditional_on' => 'domain', 'options_map' => $domains ),
						array( 'key' => 'how_practice', 'type' => 'textarea', 'label' => 'Describe HOW you will practice' ),
						array( 'key' => 'what_behaviors', 'type' => 'textarea', 'label' => 'WHAT behaviors will you track' ),
					) ),
					array( 'title' => 'Results', 'type' => 'editable', 'fields' => array(
						array( 'key' => 'practice_reflection', 'type' => 'textarea', 'label' => 'How has your practice gone?' ),
						array( 'key' => 'degree_of_success', 'type' => 'likert', 'min' => 1, 'max' => 5, 'labels' => array( 'Not at all Successful', 'Extremely Successful' ) ),
						array( 'key' => 'impact_observations', 'type' => 'textarea', 'label' => 'Observations of impact on students' ),
						array( 'key' => 'what_learned', 'type' => 'textarea', 'label' => 'What you learned' ),
						array( 'key' => 'still_wondering', 'type' => 'textarea', 'label' => 'What you\'re still wondering' ),
					) ),
				) ),
			),
			array(
				'key'     => 'classroom_visit_form',
				'name'    => 'Classroom Visit Form',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Header', 'type' => 'auto_populated', 'fields' => array( 'school', 'teacher_name', 'date', 'visitor_name', 'age_group' ) ),
					array( 'title' => 'Context', 'type' => 'checkboxes', 'options' => array( 'Free Play', 'Formal Group Activities', 'Transition', 'Routine' ) ),
					array( 'title' => 'Domain/Indicator Assessment', 'type' => 'domain_indicators', 'domains' => $domains ),
				) ),
			),
			array(
				'key'     => 'self_reflection_form',
				'name'    => 'Self-Reflection Form',
				'sections' => wp_json_encode( array(
					array( 'title' => 'Header', 'type' => 'auto_populated', 'fields' => array( 'school', 'teacher_name', 'date', 'age_group' ) ),
					array( 'title' => 'Context', 'type' => 'checkboxes', 'options' => array( 'Free Play', 'Formal Group Activities', 'Transition', 'Routine' ) ),
					array( 'title' => 'Domain/Indicator Self-Assessment', 'type' => 'domain_indicators', 'framing' => 'self', 'domains' => $domains ),
				) ),
			),
		);

		$seeded = 0;
		foreach ( $instruments as $inst ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT instrument_id FROM {$table} WHERE instrument_key = %s LIMIT 1",
					$inst['key']
				)
			);
			if ( $exists ) {
				WP_CLI::log( "    Instrument '{$inst['key']}' already exists (id={$exists}), skipping." );
				continue;
			}

			$wpdb->insert( $table, array(
				'instrument_name'    => $inst['name'],
				'instrument_key'     => $inst['key'],
				'instrument_version' => '1.0',
				'sections'           => $inst['sections'],
				'status'             => 'active',
			) );
			$seeded++;
			WP_CLI::log( "    Instrument '{$inst['key']}' seeded (id={$wpdb->insert_id})." );
		}

		WP_CLI::log( "  [4] Instruments: {$seeded} seeded, " . ( count( $instruments ) - $seeded ) . ' already existed.' );
	}

	// ------------------------------------------------------------------
	// Step 5: Seed demo coach
	// ------------------------------------------------------------------

	private function seed_demo_coach() {
		$email = 'lorf@housmanlearning.com';
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$existing->add_role( 'coach' );
			WP_CLI::log( "  [5] Coach: Lauren Orf (existing user id={$existing->ID}, Coach role assigned)" );
			return;
		}
		$user_id = wp_insert_user( array(
			'user_login'   => 'lorf',
			'user_email'   => $email,
			'first_name'   => 'Lauren',
			'last_name'    => 'Orf',
			'display_name' => 'Lauren Orf',
			'role'         => 'coach',
			'user_pass'    => wp_generate_password(),
		) );
		if ( ! is_wp_error( $user_id ) ) {
			WP_CLI::log( "  [5] Coach: Lauren Orf created (user_id={$user_id})" );
		} else {
			WP_CLI::warning( "  [5] Could not create coach: " . $user_id->get_error_message() );
		}
	}
}
