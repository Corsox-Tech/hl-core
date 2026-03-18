<?php
/**
 * WP-CLI command: wp hl-core import-elcpb-children
 *
 * Imports ELCPB Year 1 child assessment data from WPForms entries into HL Core.
 * Creates teaching assignments, children, child instruments, and assessment records.
 * Requires: ELCPB cycle, schools, classrooms, and enrollments already exist (from import-elcpb).
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Import_ELCPB_Children {

	const CYCLE_CODE = 'ELCPB-Y1-2025';

	/**
	 * WPForms form_id → HL Core classroom_id mapping.
	 * Derived from matching form titles to classroom names.
	 */
	const FORM_CLASSROOM_MAP = array(
		// ABC Playschool.
		36169 => 34,  // Infant
		36204 => 35,  // Toddler
		36752 => 36,  // Two's → "2 year Old"
		36236 => 37,  // Preschool
		// Bright IDEAS.
		36178 => 38,  // Infant/Toddler → Infant classroom
		36763 => 39,  // Two's A → Toddler A
		36781 => 40,  // Two's B → Toddler B
		36759 => 41,  // 3's/4's
		// King's Kids.
		36182 => 42,  // Infant
		36214 => 43,  // Toddlers → Toddler
		36792 => 44,  // Two's → Twos
		36786 => 45,  // 3's/4's → Three's
		36798 => 46,  // VPK
		// Life Span.
		36186 => 47,  // Infant
		36220 => 48,  // Toddlers B → Toddler B
		36733 => 49,  // Toddlers C → Toddler C
		36737 => 50,  // Toddlers D → Toddler D
		36294 => 51,  // Three's → 3's
		// Stepping Stones.
		36190 => 52,  // Infant/Toddler
		36815 => 53,  // Two's A → Two A
		36821 => 54,  // Two's B → Two B
		36809 => 55,  // 3's/4's → 4 and 5
		// WeeCare.
		36194 => 56,  // Infant
		36232 => 57,  // Toddlers → Toddler B
		36827 => 59,  // Two's → Twos
		36287 => 60,  // Preschool
	);

	/** Admin/test user IDs to exclude from entries. */
	const SKIP_USER_IDS = array( 1, 10 );

	/** WPForms form IDs to exclude (test forms). */
	const SKIP_FORM_IDS = array( 36534, 36684, 37049, 37075, 37289, 37292 );

	/** The single assessment question text used across all age groups. */
	const ASSESSMENT_QUESTION = 'In the last month, how often did the child show that they understood, expressed, and managed their own emotions successfully in their interactions with others?';

	/** Scale labels for child assessment. */
	const SCALE_LABELS = array(
		1 => 'Never',
		2 => 'Rarely',
		3 => 'Sometimes',
		4 => 'Usually',
		5 => 'Almost Always',
	);

	public static function register() {
		WP_CLI::add_command( 'hl-core import-elcpb-children', array( new self(), 'run' ) );
	}

	/**
	 * Import ELCPB Year 1 child assessment data from WPForms.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be imported without writing.
	 *
	 * [--clean]
	 * : Remove all child assessment data for ELCPB cycle before importing.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] );
		$clean   = isset( $assoc_args['clean'] );

		// Find the ELCPB cycle.
		$cycle = $wpdb->get_row( $wpdb->prepare(
			"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s",
			self::CYCLE_CODE
		) );

		if ( ! $cycle ) {
			WP_CLI::error( 'ELCPB cycle not found. Run import-elcpb first.' );
			return;
		}

		$cycle_id = (int) $cycle->cycle_id;

		if ( $clean ) {
			$this->clean( $cycle_id );
			WP_CLI::success( 'ELCPB child data cleaned.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== ELCPB Year 1 Child Assessment Import ===' );
		WP_CLI::line( "Cycle ID: {$cycle_id}" );
		if ( $dry_run ) {
			WP_CLI::line( '** DRY RUN — no data will be written **' );
		}
		WP_CLI::line( '' );

		// Build enrollment lookup: user_id → enrollment record.
		$enrollments = $wpdb->get_results( $wpdb->prepare(
			"SELECT enrollment_id, user_id, roles FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d",
			$cycle_id
		) );
		$uid_to_enrollment = array();
		foreach ( $enrollments as $e ) {
			$uid_to_enrollment[ (int) $e->user_id ] = $e;
		}

		// Step 1: Create child assessment instruments (1 per age group).
		$instruments = $this->ensure_instruments( $dry_run );

		// Step 2: Read WPForms entries and build data structures.
		$entries = $this->read_wpforms_entries( $cycle_id, $uid_to_enrollment );

		// Step 3: Create teaching assignments.
		$this->create_teaching_assignments( $entries, $uid_to_enrollment, $dry_run );

		// Step 4: Create children and classroom assignments.
		$children = $this->create_children( $entries, $dry_run );

		// Step 5: Create child assessment instances and childrow records.
		$this->create_assessment_records( $entries, $children, $instruments, $cycle_id, $uid_to_enrollment, $dry_run );

		WP_CLI::line( '' );
		WP_CLI::success( $dry_run ? 'Dry run complete.' : 'ELCPB child assessment import complete!' );
	}

	/**
	 * Create or find child assessment instruments — one per age group.
	 */
	private function ensure_instruments( $dry_run ) {
		global $wpdb;

		$age_groups = array( 'infant', 'toddler', 'preschool' );
		$instruments = array();

		foreach ( $age_groups as $ag ) {
			$type = "children_{$ag}";
			$name = 'ELCPB ' . ucfirst( $ag ) . ' Assessment';

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT instrument_id FROM {$wpdb->prefix}hl_instrument WHERE name = %s",
				$name
			) );

			if ( $existing ) {
				$instruments[ $ag ] = (int) $existing;
				WP_CLI::log( "  [1] Instrument exists: {$name} (ID {$existing})" );
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::log( "  [1] Would create instrument: {$name}" );
				$instruments[ $ag ] = 0;
				continue;
			}

			$questions = array(
				array(
					'key'            => 'q1',
					'prompt'         => self::ASSESSMENT_QUESTION,
					'type'           => 'likert',
					'allowed_values' => array( '1', '2', '3', '4', '5' ),
					'required'       => true,
				),
			);

			$wpdb->insert( $wpdb->prefix . 'hl_instrument', array(
				'instrument_uuid' => wp_generate_uuid4(),
				'name'            => $name,
				'instrument_type' => $type,
				'version'         => '1.0',
				'questions'       => wp_json_encode( $questions ),
			) );
			$instruments[ $ag ] = (int) $wpdb->insert_id;
			WP_CLI::log( "  [1] Created instrument: {$name} (ID {$instruments[$ag]})" );
		}

		return $instruments;
	}

	/**
	 * Read all relevant WPForms child assessment entries.
	 *
	 * Returns structured data: array of entries with form_id, classroom_id,
	 * user_id, date, phase (pre/post), and children array.
	 */
	private function read_wpforms_entries( $cycle_id, $uid_to_enrollment ) {
		global $wpdb;

		$form_ids = array_keys( self::FORM_CLASSROOM_MAP );
		$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );

		$raw_entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.entry_id, e.form_id, e.user_id, e.date, e.fields
			 FROM {$wpdb->prefix}wpforms_entries e
			 WHERE e.form_id IN ({$placeholders})
			 AND e.status = ''
			 ORDER BY e.form_id, e.date",
			...$form_ids
		) );

		// Group entries by form_id to determine PRE vs POST.
		$by_form = array();
		foreach ( $raw_entries as $entry ) {
			$uid = (int) $entry->user_id;
			if ( in_array( $uid, self::SKIP_USER_IDS, true ) ) {
				continue;
			}
			$fid = (int) $entry->form_id;
			if ( in_array( $fid, self::SKIP_FORM_IDS, true ) ) {
				continue;
			}
			$by_form[ $fid ][] = $entry;
		}

		$results = array();

		foreach ( $by_form as $form_id => $form_entries ) {
			$classroom_id = self::FORM_CLASSROOM_MAP[ $form_id ] ?? null;
			if ( ! $classroom_id ) {
				continue;
			}

			// Get classroom age_band.
			$age_band = $wpdb->get_var( $wpdb->prepare(
				"SELECT age_band FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
				$classroom_id
			) );

			// Determine PRE/POST: earliest entry is PRE, latest is POST.
			// If a form has >2 entries from the same user, take earliest as PRE and latest as POST.
			// Group by user_id first.
			$by_user = array();
			foreach ( $form_entries as $entry ) {
				$uid = (int) $entry->user_id;
				$by_user[ $uid ][] = $entry;
			}

			foreach ( $by_user as $uid => $user_entries ) {
				usort( $user_entries, function( $a, $b ) {
					return strcmp( $a->date, $b->date );
				});

				// First = PRE, Last = POST (if different from first).
				$pre_entry = $user_entries[0];
				$post_entry = count( $user_entries ) > 1 ? end( $user_entries ) : null;

				// Skip if the post entry is within 1 hour of pre (likely duplicate).
				if ( $post_entry && ( strtotime( $post_entry->date ) - strtotime( $pre_entry->date ) ) < 3600 ) {
					$post_entry = null;
				}

				$children_pre = $this->parse_children_from_entry( $pre_entry, $age_band );
				$results[] = array(
					'entry_id'     => (int) $pre_entry->entry_id,
					'form_id'      => $form_id,
					'classroom_id' => $classroom_id,
					'age_band'     => $age_band,
					'user_id'      => $uid,
					'date'         => $pre_entry->date,
					'phase'        => 'pre',
					'children'     => $children_pre,
				);

				if ( $post_entry ) {
					$children_post = $this->parse_children_from_entry( $post_entry, $age_band );
					$results[] = array(
						'entry_id'     => (int) $post_entry->entry_id,
						'form_id'      => $form_id,
						'classroom_id' => $classroom_id,
						'age_band'     => $age_band,
						'user_id'      => (int) $post_entry->user_id,
						'date'         => $post_entry->date,
						'phase'        => 'post',
						'children'     => $children_post,
					);
				}
			}
		}

		$pre_count  = count( array_filter( $results, function( $r ) { return $r['phase'] === 'pre'; } ) );
		$post_count = count( array_filter( $results, function( $r ) { return $r['phase'] === 'post'; } ) );
		WP_CLI::log( "  [2] Read {$pre_count} PRE + {$post_count} POST assessment entries from WPForms" );

		return $results;
	}

	/**
	 * Parse children data from a WPForms entry's fields JSON.
	 *
	 * WPForms stores the likert_scale field with children as rows.
	 * Row labels like "Infant - 01 DOB:_8/26/2024" or "Ajay C."
	 * Value_raw has numeric scores (1-5).
	 */
	private function parse_children_from_entry( $entry, $age_band ) {
		$fields = json_decode( $entry->fields, true );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$children = array();

		foreach ( $fields as $field_id => $field_data ) {
			// Look for the likert_scale field (has value_raw with scores).
			if ( ! is_array( $field_data ) || ! isset( $field_data['value_raw'] ) ) {
				// Try parsing as JSON string.
				if ( is_string( $field_data ) ) {
					$parsed = json_decode( $field_data, true );
					if ( is_array( $parsed ) && isset( $parsed['value_raw'] ) ) {
						$field_data = $parsed;
					} else {
						continue;
					}
				} else {
					continue;
				}
			}

			$value_raw = $field_data['value_raw'];
			if ( ! is_array( $value_raw ) ) {
				continue;
			}

			// Parse the value string to get child labels.
			$value_text = $field_data['value'] ?? '';
			$lines = explode( "\n", $value_text );
			$labels = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( strpos( $line, ':' ) !== false ) {
					list( $label ) = explode( ':', $line, 2 );
					$label = trim( $label );
					if ( $label !== '' ) {
						$labels[] = $label;
					}
				}
			}

			// Map row IDs to labels and scores.
			$row_ids = array_keys( $value_raw );
			foreach ( $row_ids as $idx => $row_id ) {
				$score = (int) $value_raw[ $row_id ];
				$label = $labels[ $idx ] ?? "Child {$row_id}";

				// Parse DOB from label like "Infant - 01 DOB:_8/26/2024".
				$dob = null;
				$child_name = $label;
				if ( preg_match( '/DOB:_?(\d{1,2}\/\d{1,2}\/\d{4})/', $label, $m ) ) {
					$dob = date( 'Y-m-d', strtotime( $m[1] ) );
					// Extract a readable name: "Infant - 01" etc.
					$child_name = trim( preg_replace( '/\s*DOB:.*/', '', $label ) );
				}

				$children[] = array(
					'label'    => $label,
					'name'     => $child_name,
					'dob'      => $dob,
					'score'    => $score,
					'age_band' => $age_band,
				);
			}

			break; // Only process the first likert_scale field.
		}

		return $children;
	}

	/**
	 * Create teaching assignments from WPForms entry user IDs.
	 */
	private function create_teaching_assignments( $entries, $uid_to_enrollment, $dry_run ) {
		global $wpdb;

		$assignments = array(); // enrollment_id → classroom_id.
		$created = 0;

		foreach ( $entries as $entry ) {
			$uid = $entry['user_id'];
			if ( ! isset( $uid_to_enrollment[ $uid ] ) ) {
				continue;
			}
			$eid = (int) $uid_to_enrollment[ $uid ]->enrollment_id;
			$cid = $entry['classroom_id'];

			$key = "{$eid}:{$cid}";
			if ( isset( $assignments[ $key ] ) ) {
				continue;
			}
			$assignments[ $key ] = true;

			// Check if assignment already exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT assignment_id FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d",
				$eid,
				$cid
			) );
			if ( $exists ) {
				continue;
			}

			if ( $dry_run ) {
				$created++;
				continue;
			}

			$wpdb->insert( $wpdb->prefix . 'hl_teaching_assignment', array(
				'enrollment_id' => $eid,
				'classroom_id'  => $cid,
				'is_lead_teacher' => 1,
			) );
			$created++;
		}

		WP_CLI::log( "  [3] Teaching assignments: {$created} " . ( $dry_run ? 'would be created' : 'created' ) );
	}

	/**
	 * Create children from WPForms assessment data.
	 *
	 * Uses classroom_id + child index as fingerprint for dedup.
	 * Returns: classroom_id:child_index → child_id mapping.
	 */
	private function create_children( $entries, $dry_run ) {
		global $wpdb;

		// Collect unique children per classroom from PRE entries (most authoritative).
		$classroom_children = array();

		foreach ( $entries as $entry ) {
			if ( $entry['phase'] !== 'pre' ) {
				continue;
			}
			$cid = $entry['classroom_id'];
			if ( isset( $classroom_children[ $cid ] ) ) {
				continue; // Already have children for this classroom from another PRE entry.
			}
			$classroom_children[ $cid ] = $entry['children'];
		}

		// Also collect from POST entries for classrooms that have no PRE.
		foreach ( $entries as $entry ) {
			if ( $entry['phase'] !== 'post' ) {
				continue;
			}
			$cid = $entry['classroom_id'];
			if ( isset( $classroom_children[ $cid ] ) ) {
				continue;
			}
			$classroom_children[ $cid ] = $entry['children'];
		}

		$child_map = array();
		$created   = 0;
		$total     = 0;

		foreach ( $classroom_children as $cid => $children ) {
			foreach ( $children as $idx => $child ) {
				$total++;
				$fingerprint = md5( "elcpb-{$cid}-{$idx}-" . ( $child['dob'] ?? 'unknown' ) );

				// Check if child exists by fingerprint.
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT child_id FROM {$wpdb->prefix}hl_child WHERE child_fingerprint = %s",
					$fingerprint
				) );

				if ( $existing ) {
					$child_map[ "{$cid}:{$idx}" ] = (int) $existing;
					continue;
				}

				if ( $dry_run ) {
					$child_map[ "{$cid}:{$idx}" ] = 0;
					$created++;
					continue;
				}

				// Get school_id from classroom.
				$school_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT school_id FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
					$cid
				) );

				$metadata = array();
				if ( $child['age_band'] ) {
					$metadata['age_band'] = $child['age_band'];
				}

				$wpdb->insert( $wpdb->prefix . 'hl_child', array(
					'child_uuid'        => wp_generate_uuid4(),
					'school_id'         => $school_id,
					'first_name'        => $child['name'],
					'last_name'         => '',
					'dob'               => $child['dob'],
					'child_fingerprint' => $fingerprint,
					'metadata'          => wp_json_encode( $metadata ),
				) );
				$child_id = (int) $wpdb->insert_id;
				$child_map[ "{$cid}:{$idx}" ] = $child_id;
				$created++;

				// Assign to classroom.
				$wpdb->insert( $wpdb->prefix . 'hl_child_classroom_current', array(
					'child_id'     => $child_id,
					'classroom_id' => $cid,
					'assigned_at'  => current_time( 'mysql', true ),
				) );
			}
		}

		WP_CLI::log( "  [4] Children: {$created} created out of {$total} total" . ( $dry_run ? ' (dry run)' : '' ) );

		return $child_map;
	}

	/**
	 * Create child assessment instances and childrow records.
	 */
	private function create_assessment_records( $entries, $child_map, $instruments, $cycle_id, $uid_to_enrollment, $dry_run ) {
		global $wpdb;

		$instances_created = 0;
		$childrows_created = 0;

		// Get the child assessment component IDs from pathways.
		// Components with component_type = 'child_assessment' for this cycle.
		$ca_components = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.component_id, c.pathway_id, p.target_roles
			 FROM {$wpdb->prefix}hl_component c
			 JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
			 WHERE p.cycle_id = %d AND c.component_type = 'child_assessment'",
			$cycle_id
		) );

		// Build role → component_id map for PRE/POST.
		// The component name or external_ref should indicate pre vs post.
		$role_phase_component = array();
		foreach ( $ca_components as $comp ) {
			$roles = json_decode( $comp->target_roles, true );
			if ( ! is_array( $roles ) ) {
				continue;
			}
			// For now, map all child_assessment components. We'll sort pre/post by order.
			foreach ( $roles as $role ) {
				$role_phase_component[ $role ][] = (int) $comp->component_id;
			}
		}

		foreach ( $entries as $entry ) {
			$uid = $entry['user_id'];
			if ( ! isset( $uid_to_enrollment[ $uid ] ) ) {
				WP_CLI::warning( "No enrollment for user {$uid}, skipping entry {$entry['entry_id']}" );
				continue;
			}

			$enrollment    = $uid_to_enrollment[ $uid ];
			$enrollment_id = (int) $enrollment->enrollment_id;
			$classroom_id  = $entry['classroom_id'];
			$age_band      = $entry['age_band'];
			$phase         = $entry['phase'];
			$date          = $entry['date'];

			// Get school_id from classroom.
			$school_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT school_id FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
				$classroom_id
			) );

			// Map age_band to instrument.
			$instrument_age = $age_band;
			if ( ! isset( $instruments[ $instrument_age ] ) ) {
				$instrument_age = 'preschool';
			}
			$instrument_id = $instruments[ $instrument_age ] ?? null;

			// Check if instance already exists.
			$existing_instance = $wpdb->get_var( $wpdb->prepare(
				"SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance
				 WHERE cycle_id = %d AND enrollment_id = %d AND classroom_id = %d AND phase = %s",
				$cycle_id,
				$enrollment_id,
				$classroom_id,
				$phase
			) );

			if ( $existing_instance ) {
				continue;
			}

			if ( $dry_run ) {
				$instances_created++;
				$childrows_created += count( $entry['children'] );
				continue;
			}

			$wpdb->insert( $wpdb->prefix . 'hl_child_assessment_instance', array(
				'instance_uuid'      => wp_generate_uuid4(),
				'cycle_id'           => $cycle_id,
				'enrollment_id'      => $enrollment_id,
				'classroom_id'       => $classroom_id,
				'school_id'          => $school_id,
				'phase'              => $phase,
				'instrument_age_band' => $age_band,
				'instrument_id'      => $instrument_id,
				'status'             => 'submitted',
				'submitted_at'       => $date,
				'created_at'         => $date,
			) );
			$instance_id = (int) $wpdb->insert_id;
			$instances_created++;

			// Create childrow records.
			foreach ( $entry['children'] as $idx => $child_data ) {
				$child_key = "{$classroom_id}:{$idx}";
				$child_id  = $child_map[ $child_key ] ?? null;

				if ( ! $child_id ) {
					continue;
				}

				$answers = array( 'q1' => $child_data['score'] );

				$wpdb->insert( $wpdb->prefix . 'hl_child_assessment_childrow', array(
					'instance_id'      => $instance_id,
					'child_id'         => $child_id,
					'frozen_age_group' => $child_data['age_band'],
					'instrument_id'    => $instrument_id,
					'answers_json'     => wp_json_encode( $answers ),
					'status'           => 'active',
				) );
				$childrows_created++;
			}
		}

		WP_CLI::log( "  [5] Assessment instances: {$instances_created}, childrows: {$childrows_created}" . ( $dry_run ? ' (dry run)' : '' ) );
	}

	/**
	 * Clean all ELCPB child assessment data.
	 */
	private function clean( $cycle_id ) {
		global $wpdb;

		// Delete childrow records via instances.
		$wpdb->query( $wpdb->prepare(
			"DELETE cr FROM {$wpdb->prefix}hl_child_assessment_childrow cr
			 JOIN {$wpdb->prefix}hl_child_assessment_instance ci ON cr.instance_id = ci.instance_id
			 WHERE ci.cycle_id = %d",
			$cycle_id
		) );

		// Delete instances.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}hl_child_assessment_instance WHERE cycle_id = %d",
			$cycle_id
		) );

		// Delete children assigned to ELCPB classrooms.
		$wpdb->query( $wpdb->prepare(
			"DELETE cc FROM {$wpdb->prefix}hl_child_classroom_current cc
			 WHERE cc.classroom_id IN (SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE school_id IN (SELECT school_id FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d))",
			$cycle_id
		) );

		// Delete children with ELCPB fingerprints.
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}hl_child WHERE child_fingerprint LIKE '%elcpb%'"
		);

		// Delete ELCPB instruments.
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}hl_instrument WHERE name LIKE 'ELCPB %'"
		);

		// Delete teaching assignments for ELCPB enrollments.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}hl_teaching_assignment
			 WHERE enrollment_id IN (SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d)",
			$cycle_id
		) );

		WP_CLI::log( 'Cleaned: childrows, instances, children, instruments, teaching assignments' );
	}
}
