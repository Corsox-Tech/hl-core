<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Smoke-test CLI command: render every HL shortcode page as different test users
 * and report issues.
 *
 * Usage:
 *   wp hl-core smoke-test
 *   wp hl-core smoke-test --role=admin
 *   wp hl-core smoke-test --page=hl_dashboard
 *   wp hl-core smoke-test --verbose
 */
class HL_CLI_Smoke_Test {

	/**
	 * Role → page visibility matrix.
	 * Pages listed here are expected to be accessible for this role.
	 * Pages NOT listed should produce "access denied" — that is expected, not a failure.
	 */
	private static $visibility_matrix = array(
		'admin'           => '__ALL__', // Admin can access every page.
		'coach'           => array(
			'hl_coach_dashboard',
			'hl_coach_mentors',
			'hl_coach_availability',
			'hl_coach_reports',
			'hl_user_profile',
		),
		'school_leader'   => array(
			'hl_dashboard',
			'hl_my_programs',
			'hl_my_cycle',
			'hl_classrooms_listing',
			'hl_user_profile',
			'hl_my_team',
			'hl_program_page',
			'hl_team_page',
		),
		'mentor'          => array(
			'hl_dashboard',
			'hl_my_programs',
			'hl_my_coaching',
			'hl_my_team',
			'hl_classrooms_listing',
			'hl_user_profile',
			'hl_program_page',
			'hl_component_page',
		),
		'teacher'         => array(
			'hl_dashboard',
			'hl_my_programs',
			'hl_my_team',
			'hl_classrooms_listing',
			'hl_user_profile',
			'hl_program_page',
			'hl_component_page',
		),
		'district_leader' => array(
			'hl_dashboard',
			'hl_my_cycle',
			'hl_classrooms_listing',
			'hl_user_profile',
			'hl_district_page',
		),
	);

	/**
	 * Shortcodes that need $_GET params to render, mapped to how to resolve them.
	 *
	 * Keys: shortcode name.
	 * Values: array of $_GET key => resolution strategy.
	 */
	private static $param_requirements = array(
		'hl_cycle_workspace'    => array( 'id'                   => 'active_cycle' ),
		'hl_program_page'       => array( 'enrollment_id'        => 'user_enrollment' ),
		'hl_component_page'     => array( 'id'                   => 'user_component' ),
		'hl_team_page'          => array( 'team_id'              => 'user_team' ),
		'hl_classroom_page'     => array( 'id'                   => 'user_classroom' ),
		'hl_school_page'        => array( 'id'                   => 'user_school' ),
		'hl_district_page'      => array( 'id'                   => 'user_district' ),
		'hl_coach_mentor_detail' => array( 'mentor_enrollment_id' => 'first_mentor' ),
	);

	/**
	 * Patterns that indicate a hard failure in rendered output.
	 */
	private static $fail_patterns = array(
		'/Fatal error/i',
		'/PHP Warning/i',
		'/PHP Notice.*Undefined/i',
		'/Uncaught Error/i',
		'/Uncaught Exception/i',
		'/Call to undefined/i',
		'/Cannot redeclare/i',
	);

	/**
	 * Patterns that indicate an access-denied response (expected for roles without visibility).
	 */
	private static $access_denied_patterns = array(
		'/You do not have permission/i',
		'/You do not have access/i',
		'/Access denied/i',
		'/not authorized/i',
	);

	/**
	 * Patterns that indicate a possible empty-state warning.
	 */
	private static $warn_patterns = array(
		'/No learners found/i',
		'/No data/i',
		'/not enrolled/i',
		'/No reports available/i',
		'/No teams found/i',
		'/No classrooms found/i',
		'/No components/i',
		'/No pathways/i',
		'/No sessions/i',
		'/No mentors/i',
		'/Nothing to display/i',
	);

	/**
	 * Register the CLI command.
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
		WP_CLI::add_command( 'hl-core smoke-test', array( new self(), 'run' ) );
	}

	/**
	 * Run the smoke test.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Test only a specific role. One of: admin, coach, school_leader, mentor, teacher, district_leader.
	 *
	 * [--page=<shortcode>]
	 * : Test only a specific shortcode page (e.g. hl_dashboard).
	 *
	 * [--verbose]
	 * : Show full rendered output for failed tests.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core smoke-test
	 *     wp hl-core smoke-test --role=admin
	 *     wp hl-core smoke-test --page=hl_dashboard --verbose
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function run( $args, $assoc_args ) {
		global $wpdb;

		$filter_role    = isset( $assoc_args['role'] ) ? $assoc_args['role'] : null;
		$filter_page    = isset( $assoc_args['page'] ) ? $assoc_args['page'] : null;
		$verbose        = isset( $assoc_args['verbose'] );

		// Validate --role flag.
		$valid_roles = array_keys( self::$visibility_matrix );
		if ( $filter_role && ! in_array( $filter_role, $valid_roles, true ) ) {
			WP_CLI::error( "Invalid role '{$filter_role}'. Valid roles: " . implode( ', ', $valid_roles ) );
		}

		// ── Step 1: Discover all HL shortcode pages ──
		$shortcodes = $this->discover_shortcodes( $filter_page );
		if ( empty( $shortcodes ) ) {
			WP_CLI::error( 'No HL shortcode pages found.' );
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core Smoke Test ===' );
		WP_CLI::line( sprintf( 'Found %d shortcode(s) to test.', count( $shortcodes ) ) );
		WP_CLI::line( '' );

		// ── Step 2: Resolve test users ──
		$test_users = $this->resolve_test_users( $filter_role );
		if ( empty( $test_users ) ) {
			WP_CLI::error( 'Could not resolve any test users. Check that appropriate users/enrollments exist.' );
		}

		// Save original user so we can restore later.
		$original_user_id = get_current_user_id();
		$saved_get        = $_GET;

		// Summary accumulators.
		$summary  = array();
		$totals   = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'tests' => 0 );

		// ── Step 3: Test each role ──
		foreach ( $test_users as $role_key => $user_info ) {
			$user_id    = $user_info['user_id'];
			$user_email = $user_info['email'];
			$user_label = $user_info['label'];

			WP_CLI::line( sprintf( 'Testing as: %s (%s, ID %d)', $user_label, $user_email, $user_id ) );

			// Switch user context.
			wp_set_current_user( $user_id );

			$role_summary = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 );

			foreach ( $shortcodes as $shortcode ) {
				$totals['tests']++;

				// Determine if this role should have access to this page.
				$visibility   = self::$visibility_matrix[ $role_key ];
				$should_access = ( $visibility === '__ALL__' ) || in_array( $shortcode, $visibility, true );

				// Set up $_GET params if needed.
				$_GET = array();
				$param_ok = $this->setup_get_params( $shortcode, $user_id, $role_key );

				if ( ! $param_ok && $should_access ) {
					// Could not resolve required params — skip with note.
					$msg = sprintf(
						'  [SKIP] %s — Could not resolve required URL params for this user',
						$shortcode
					);
					WP_CLI::line( $msg );
					$role_summary['skip']++;
					$totals['skip']++;
					$_GET = array();
					continue;
				}

				// Clear wpdb error state.
				$wpdb->last_error  = '';
				$wpdb->last_query  = '';

				// Render the shortcode.
				ob_start();
				$output = do_shortcode( '[' . $shortcode . ']' );
				$buffered = ob_get_clean();

				// Combine direct return and any echoed output.
				$full_output = $output . $buffered;

				// Check $wpdb->last_error.
				$sql_error = $wpdb->last_error;

				// Reset $_GET.
				$_GET = array();

				// ── Evaluate result ──
				$result = $this->evaluate_output(
					$full_output,
					$sql_error,
					$shortcode,
					$role_key,
					$should_access
				);

				$status  = $result['status'];
				$detail  = $result['detail'];

				// Format output line.
				switch ( $status ) {
					case 'PASS':
						$role_summary['pass']++;
						$totals['pass']++;
						WP_CLI::line( sprintf( '  [PASS] %s — %s', $shortcode, $detail ) );
						break;

					case 'WARN':
						$role_summary['warn']++;
						$totals['warn']++;
						WP_CLI::line(
							WP_CLI::colorize( sprintf( '  %%y[WARN]%%n %s — %s', $shortcode, $detail ) )
						);
						break;

					case 'FAIL':
						$role_summary['fail']++;
						$totals['fail']++;
						WP_CLI::line(
							WP_CLI::colorize( sprintf( '  %%r[FAIL]%%n %s — %s', $shortcode, $detail ) )
						);
						if ( $verbose && ! empty( $full_output ) ) {
							WP_CLI::line( '  --- Begin output ---' );
							// Truncate to 2000 chars for readability.
							$display = strlen( $full_output ) > 2000
								? substr( $full_output, 0, 2000 ) . "\n  ... (truncated)"
								: $full_output;
							WP_CLI::line( $display );
							WP_CLI::line( '  --- End output ---' );
						}
						break;

					case 'SKIP':
						$role_summary['skip']++;
						$totals['skip']++;
						WP_CLI::line( sprintf( '  [SKIP] %s — %s', $shortcode, $detail ) );
						break;
				}
			}

			$summary[ $role_key ] = array(
				'label'   => $user_label,
				'results' => $role_summary,
			);

			WP_CLI::line( '' );
		}

		// ── Restore original state ──
		wp_set_current_user( $original_user_id );
		$_GET = $saved_get;

		// ── Print summary ──
		WP_CLI::line( '=== Summary ===' );
		foreach ( $summary as $role_key => $info ) {
			$r = $info['results'];
			WP_CLI::line( sprintf(
				'%-17s %d PASS, %d WARN, %d FAIL, %d SKIP',
				$info['label'] . ':',
				$r['pass'],
				$r['warn'],
				$r['fail'],
				$r['skip']
			) );
		}
		WP_CLI::line( sprintf(
			'Total: %d tests, %d passed, %d warnings, %d failures, %d skipped',
			$totals['tests'],
			$totals['pass'],
			$totals['warn'],
			$totals['fail'],
			$totals['skip']
		) );

		if ( $totals['fail'] > 0 ) {
			WP_CLI::warning( sprintf( '%d test(s) failed. Run with --verbose for details.', $totals['fail'] ) );
		} else {
			WP_CLI::success( 'All tests passed (or expected skips/warnings).' );
		}
	}

	/**
	 * Discover all registered HL shortcodes.
	 *
	 * If a filter is provided, returns only that shortcode (if it exists).
	 * Otherwise scans the global $shortcode_tags for 'hl_' prefixed shortcodes,
	 * excluding legacy aliases (hl_my_track, hl_track_workspace, etc.).
	 *
	 * @param string|null $filter_page Specific shortcode to test.
	 * @return array List of shortcode tag names.
	 */
	private function discover_shortcodes( $filter_page = null ) {
		global $shortcode_tags;

		// Legacy aliases — skip these to avoid duplicate testing.
		$legacy_aliases = array(
			'hl_my_track',
			'hl_track_workspace',
			'hl_tracks_listing',
			'hl_track_dashboard',
		);

		// Utility shortcodes that don't render full pages.
		$skip_shortcodes = array(
			'hl_doc_link',
		);

		$all_skip = array_merge( $legacy_aliases, $skip_shortcodes );

		if ( $filter_page ) {
			if ( isset( $shortcode_tags[ $filter_page ] ) ) {
				return array( $filter_page );
			}
			WP_CLI::warning( "Shortcode '{$filter_page}' not registered. Available HL shortcodes:" );
			foreach ( array_keys( $shortcode_tags ) as $tag ) {
				if ( strpos( $tag, 'hl_' ) === 0 && ! in_array( $tag, $all_skip, true ) ) {
					WP_CLI::line( "  {$tag}" );
				}
			}
			return array();
		}

		$shortcodes = array();
		foreach ( array_keys( $shortcode_tags ) as $tag ) {
			if ( strpos( $tag, 'hl_' ) === 0 && ! in_array( $tag, $all_skip, true ) ) {
				$shortcodes[] = $tag;
			}
		}

		sort( $shortcodes );
		return $shortcodes;
	}

	/**
	 * Resolve test users for each role.
	 *
	 * @param string|null $filter_role Only resolve this role.
	 * @return array Keyed by role slug, values have user_id, email, label.
	 */
	private function resolve_test_users( $filter_role = null ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$users = array();
		$roles_to_resolve = $filter_role
			? array( $filter_role )
			: array_keys( self::$visibility_matrix );

		foreach ( $roles_to_resolve as $role ) {
			$resolved = null;

			switch ( $role ) {
				case 'admin':
					$resolved = $this->find_admin_user();
					break;

				case 'coach':
					$resolved = $this->find_user_by_wp_role( 'coach' );
					break;

				case 'school_leader':
					$resolved = $this->find_user_by_enrollment_role( 'school_leader', 5 );
					break;

				case 'mentor':
					$resolved = $this->find_user_by_enrollment_role( 'mentor', 5 );
					break;

				case 'teacher':
					$resolved = $this->find_user_by_enrollment_role( 'teacher', 5 );
					break;

				case 'district_leader':
					$resolved = $this->find_user_by_enrollment_role( 'district_leader', 5 );
					break;
			}

			if ( $resolved ) {
				$users[ $role ] = $resolved;
			} else {
				WP_CLI::warning( "Could not find a test user for role: {$role}" );
			}
		}

		return $users;
	}

	/**
	 * Find an admin user (with manage_options capability).
	 *
	 * @return array|null User info or null.
	 */
	private function find_admin_user() {
		$admins = get_users( array(
			'role'   => 'administrator',
			'number' => 1,
		) );

		if ( ! empty( $admins ) ) {
			$user = $admins[0];
			return array(
				'user_id' => $user->ID,
				'email'   => $user->user_email,
				'label'   => 'Admin',
			);
		}

		return null;
	}

	/**
	 * Find a user by WordPress role.
	 *
	 * @param string $role WP role slug.
	 * @return array|null User info or null.
	 */
	private function find_user_by_wp_role( $role ) {
		$users = get_users( array(
			'role'   => $role,
			'number' => 1,
		) );

		if ( ! empty( $users ) ) {
			$user = $users[0];
			$label = ucfirst( str_replace( '_', ' ', $role ) );
			return array(
				'user_id' => $user->ID,
				'email'   => $user->user_email,
				'label'   => $label,
			);
		}

		return null;
	}

	/**
	 * Find a user by enrollment role in a specific cycle.
	 *
	 * @param string $enrollment_role Role string to search for in JSON roles column.
	 * @param int    $cycle_id        Cycle ID to search within.
	 * @return array|null User info or null.
	 */
	private function find_user_by_enrollment_role( $enrollment_role, $cycle_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Search for enrollment with this role in the JSON roles column.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT e.user_id, u.user_email, u.display_name
			 FROM {$prefix}hl_enrollment e
			 JOIN {$wpdb->users} u ON e.user_id = u.ID
			 WHERE e.cycle_id = %d
			   AND e.status = 'active'
			   AND e.roles LIKE %s
			 LIMIT 1",
			$cycle_id,
			'%"' . $enrollment_role . '"%'
		) );

		if ( $row ) {
			$label = ucwords( str_replace( '_', ' ', $enrollment_role ) );
			return array(
				'user_id' => (int) $row->user_id,
				'email'   => $row->user_email,
				'label'   => $label,
			);
		}

		return null;
	}

	/**
	 * Set up $_GET params required by a shortcode before rendering.
	 *
	 * @param string $shortcode The shortcode tag.
	 * @param int    $user_id   Current test user ID.
	 * @param string $role_key  Current role being tested.
	 * @return bool True if params were resolved (or none needed), false if required params could not be resolved.
	 */
	private function setup_get_params( $shortcode, $user_id, $role_key ) {
		if ( ! isset( self::$param_requirements[ $shortcode ] ) ) {
			return true; // No params needed.
		}

		$requirements = self::$param_requirements[ $shortcode ];
		global $wpdb;
		$prefix = $wpdb->prefix;

		foreach ( $requirements as $get_key => $strategy ) {
			$value = null;

			switch ( $strategy ) {
				case 'active_cycle':
					$value = $wpdb->get_var(
						"SELECT cycle_id FROM {$prefix}hl_cycle WHERE status = 'active' ORDER BY cycle_id LIMIT 1"
					);
					break;

				case 'user_enrollment':
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT enrollment_id FROM {$prefix}hl_enrollment
						 WHERE user_id = %d AND status = 'active'
						 ORDER BY enrollment_id LIMIT 1",
						$user_id
					) );
					break;

				case 'user_component':
					// Find first component from the user's assigned pathway.
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT c.component_id
						 FROM {$prefix}hl_component c
						 JOIN {$prefix}hl_pathway_assignment pa ON c.pathway_id = pa.pathway_id
						 JOIN {$prefix}hl_enrollment e ON pa.enrollment_id = e.enrollment_id
						 WHERE e.user_id = %d AND e.status = 'active'
						 ORDER BY c.sort_order, c.component_id
						 LIMIT 1",
						$user_id
					) );
					break;

				case 'user_team':
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT tm.team_id
						 FROM {$prefix}hl_team_membership tm
						 JOIN {$prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
						 WHERE e.user_id = %d AND e.status = 'active'
						 ORDER BY tm.team_id LIMIT 1",
						$user_id
					) );
					break;

				case 'user_classroom':
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT cl.classroom_id
						 FROM {$prefix}hl_classroom cl
						 JOIN {$prefix}hl_enrollment e ON cl.enrollment_id = e.enrollment_id
						 WHERE e.user_id = %d AND e.status = 'active'
						 ORDER BY cl.classroom_id LIMIT 1",
						$user_id
					) );
					break;

				case 'user_school':
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT e.school_id
						 FROM {$prefix}hl_enrollment e
						 WHERE e.user_id = %d AND e.status = 'active' AND e.school_id IS NOT NULL
						 ORDER BY e.enrollment_id LIMIT 1",
						$user_id
					) );
					break;

				case 'user_district':
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT e.district_id
						 FROM {$prefix}hl_enrollment e
						 WHERE e.user_id = %d AND e.status = 'active' AND e.district_id IS NOT NULL
						 ORDER BY e.enrollment_id LIMIT 1",
						$user_id
					) );
					break;

				case 'first_mentor':
					// Find the first mentor enrollment in any active cycle (for coach testing).
					$value = $wpdb->get_var(
						"SELECT e.enrollment_id
						 FROM {$prefix}hl_enrollment e
						 JOIN {$prefix}hl_cycle cy ON e.cycle_id = cy.cycle_id
						 WHERE cy.status = 'active'
						   AND e.status = 'active'
						   AND e.roles LIKE '%\"mentor\"%'
						 ORDER BY e.enrollment_id LIMIT 1"
					);
					break;
			}

			if ( $value === null || $value === false ) {
				return false; // Could not resolve this param.
			}

			$_GET[ $get_key ] = $value;
		}

		return true;
	}

	/**
	 * Evaluate the rendered output of a shortcode and determine status.
	 *
	 * @param string $output        The rendered output.
	 * @param string $sql_error     Any SQL error from $wpdb->last_error.
	 * @param string $shortcode     The shortcode tag.
	 * @param string $role_key      The role being tested.
	 * @param bool   $should_access Whether this role should have access per the visibility matrix.
	 * @return array With 'status' (PASS|WARN|FAIL|SKIP) and 'detail' (description).
	 */
	private function evaluate_output( $output, $sql_error, $shortcode, $role_key, $should_access ) {
		$output_len = strlen( $output );
		$trimmed    = trim( $output );

		// ── Check for SQL errors ──
		if ( ! empty( $sql_error ) ) {
			return array(
				'status' => 'FAIL',
				'detail' => 'SQL Error: ' . $sql_error,
			);
		}

		// ── Check for PHP error patterns in output ──
		foreach ( self::$fail_patterns as $pattern ) {
			if ( preg_match( $pattern, $output, $matches ) ) {
				return array(
					'status' => 'FAIL',
					'detail' => 'PHP Error detected: ' . $matches[0],
				);
			}
		}

		// ── Check for access-denied patterns ──
		$is_access_denied = false;
		foreach ( self::$access_denied_patterns as $pattern ) {
			if ( preg_match( $pattern, $output ) ) {
				$is_access_denied = true;
				break;
			}
		}

		if ( $is_access_denied ) {
			if ( $should_access ) {
				// This role SHOULD have access but got denied — that's a failure.
				return array(
					'status' => 'FAIL',
					'detail' => 'Access denied (but role should have access per visibility matrix)',
				);
			} else {
				// Access denied is expected — skip.
				return array(
					'status' => 'SKIP',
					'detail' => 'Not in ' . $role_key . ' visibility (access denied expected)',
				);
			}
		}

		// ── If role should NOT have access but didn't get denied ──
		if ( ! $should_access ) {
			if ( empty( $trimmed ) ) {
				// Empty output for a page the role shouldn't see — treat as skip.
				return array(
					'status' => 'SKIP',
					'detail' => 'Not in ' . $role_key . ' visibility (empty output)',
				);
			}
			// Role shouldn't have access but got content — that might be a misconfiguration.
			return array(
				'status' => 'WARN',
				'detail' => sprintf(
					'Not in %s visibility but rendered %d chars (possible missing access check)',
					$role_key,
					$output_len
				),
			);
		}

		// ── From here: role SHOULD have access ──

		// Empty output is a failure.
		if ( empty( $trimmed ) ) {
			return array(
				'status' => 'FAIL',
				'detail' => 'Output is empty (0 chars)',
			);
		}

		// ── Check for warning patterns (empty-state messages) ──
		foreach ( self::$warn_patterns as $pattern ) {
			if ( preg_match( $pattern, $output, $matches ) ) {
				return array(
					'status' => 'WARN',
					'detail' => sprintf( 'Empty state: "%s" (%d chars)', $matches[0], $output_len ),
				);
			}
		}

		// ── If we got here, output looks good ──
		// Count table rows if present for extra detail.
		$row_count = substr_count( $output, '<tr' );
		$detail    = sprintf( '%d chars', $output_len );
		if ( $row_count > 0 ) {
			$detail .= sprintf( ', %d rows', $row_count );
		}

		return array(
			'status' => 'PASS',
			'detail' => $detail,
		);
	}
}

HL_CLI_Smoke_Test::register();
