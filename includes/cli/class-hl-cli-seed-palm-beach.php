<?php
/**
 * WP-CLI command: wp hl-core seed-palm-beach
 *
 * Seeds realistic demo data from the ELC Palm Beach County program.
 * Use --clean to remove all Palm Beach data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Seed_Palm_Beach {

	/** Track code used to identify Palm Beach seeded data. */
	const TRACK_CODE = 'ELC-PB-2026';

	/** User meta key to tag Palm Beach demo users. */
	const DEMO_META_KEY = '_hl_palm_beach_seed';

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core seed-palm-beach', array( new self(), 'run' ) );
	}

	/**
	 * Seed ELC Palm Beach demo data for HL Core.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove all Palm Beach demo data before seeding.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core seed-palm-beach
	 *     wp hl-core seed-palm-beach --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'Palm Beach demo data cleaned.' );
			return;
		}

		if ( $this->demo_exists() ) {
			WP_CLI::warning( 'Palm Beach demo data already exists. Run with --clean first to reseed.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core Palm Beach Seeder ===' );
		WP_CLI::line( '' );

		// Step 1: Org Structure.
		$orgunits = $this->seed_orgunits();

		// Step 2: Track.
		$track_id = $this->seed_track( $orgunits );

		// Step 3: Classrooms.
		$classrooms = $this->seed_classrooms( $orgunits );

		// Step 4: Instruments.
		$instruments = $this->seed_instruments();

		// Step 5: WP Users.
		$users = $this->seed_users();

		// Step 6: Enrollments.
		$enrollments = $this->seed_enrollments( $users, $track_id, $orgunits );

		// Step 7: Teams.
		$teams = $this->seed_teams( $track_id, $orgunits, $enrollments );

		// Step 8: Teaching Assignments.
		$this->seed_teaching_assignments( $enrollments, $classrooms );

		// Step 9: Children.
		$this->seed_children( $classrooms, $orgunits );

		// Step 9b: Freeze child age groups for this track.
		$frozen = HL_Child_Snapshot_Service::freeze_age_groups( $track_id );
		WP_CLI::log( "  [9b] Frozen age group snapshots: {$frozen}" );

		// Step 10: Pathways & Activities.
		$pathways = $this->seed_pathways( $track_id, $instruments );

		// Step 11: Assign Pathways.
		$this->assign_pathways( $enrollments, $pathways );

		// Step 12: Prereq Rules.
		$this->seed_prereq_rules( $pathways );

		// Step 13: Drip Rules.
		$this->seed_drip_rules( $pathways );

		// Step 14: Activity States.
		$this->seed_activity_states( $enrollments, $pathways );

		// Step 15: Completion Rollups.
		$this->seed_rollups( $enrollments );

		// Step 16: Coach Assignments.
		$this->seed_coach_assignments( $track_id, $orgunits, $teams, $users );

		// Step 17: Coaching Sessions.
		$this->seed_coaching_sessions( $track_id, $enrollments, $users );

		// Step 18-20: Lutheran Control Group.
		$control_data = $this->seed_control_group( $track_id, $instruments );

		WP_CLI::line( '' );
		WP_CLI::success( 'Palm Beach demo data seeded successfully!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Track:        {$track_id} (code: " . self::TRACK_CODE . ')' );
		WP_CLI::line( '  Schools:      ' . count( $orgunits['schools'] ) );
		WP_CLI::line( '  Classrooms:   ' . count( $classrooms ) );
		WP_CLI::line( '  Instruments:  ' . count( $instruments ) );
		WP_CLI::line( '  Users:        ' . count( $users['all_ids'] ) );
		WP_CLI::line( '  Enrollments:  ' . count( $enrollments['all'] ) );
		if ( $control_data ) {
			WP_CLI::line( "  Control track: {$control_data['track_id']} (code: LSF-CTRL-2026)" );
			WP_CLI::line( "  Control participants: {$control_data['participant_count']}" );
			WP_CLI::line( "  Cohort (container): {$control_data['cohort_id']} (B2E Program Evaluation)" );
		}
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Data definitions
	// ------------------------------------------------------------------

	/**
	 * Get school definitions.
	 *
	 * @return array Keyed by school slug.
	 */
	private function get_school_defs() {
		return array(
			'south_bay' => 'South Bay Head Start/Early Head Start',
			'wpb'       => 'West Palm Beach Head Start/Early Head Start',
			'jupiter'   => 'Jupiter Head Start',
			'sunflower' => 'Sunflower Learning Center',
			'turner'    => 'Monica Turner Family Child Care Home',
			'oliver'    => 'Patricia Oliver Family Child Care Home',
			'lisas'     => "Lisa's Lil Wonders Family Child Care Home",
			'nichola'   => 'Nichola Griffiths-Butts Family Child Care Home',
			'smartkidz' => 'Smart Kidz College Family Child Care Home',
			'bear'      => 'Bear Necessities FCCH',
			'thurmond'  => 'Jessica Thurmond FCCH',
			'lillies'   => 'My Precious Lillies Family Child Care Home',
		);
	}

	/**
	 * Get classroom definitions.
	 * Each: [name, school_key, age_band].
	 *
	 * @return array
	 */
	private function get_classroom_defs() {
		return array(
			// WPB (14).
			array( 'EHS A', 'wpb', 'infant' ),
			array( 'EHS B', 'wpb', 'toddler' ),
			array( 'EHS C', 'wpb', 'toddler' ),
			array( 'EHS D', 'wpb', 'toddler' ),
			array( 'EHS E', 'wpb', 'toddler' ),
			array( 'EHS F', 'wpb', 'toddler' ),
			array( 'EHS G', 'wpb', 'toddler' ),
			array( 'HS A', 'wpb', 'preschool' ),
			array( 'HS B', 'wpb', 'preschool' ),
			array( 'HS C', 'wpb', 'preschool' ),
			array( 'HS D', 'wpb', 'preschool' ),
			array( 'HS E', 'wpb', 'preschool' ),
			array( 'HS F', 'wpb', 'preschool' ),
			array( 'HS G', 'wpb', 'preschool' ),
			// Jupiter (4).
			array( 'HS-A', 'jupiter', 'preschool' ),
			array( 'HS-B', 'jupiter', 'preschool' ),
			array( 'HS-C', 'jupiter', 'preschool' ),
			array( 'HS-D', 'jupiter', 'preschool' ),
			// South Bay (3).
			array( 'EHS-A', 'south_bay', 'infant' ),
			array( 'EHS-B', 'south_bay', 'toddler' ),
			array( 'EHS-C', 'south_bay', 'toddler' ),
			// FCCH (8).
			array( 'Turner', 'turner', 'mixed' ),
			array( 'Dr. Seuss', 'oliver', 'toddler' ),
			array( "Lisa's Classroom", 'lisas', 'mixed' ),
			array( "Nichola's Classroom", 'nichola', 'mixed' ),
			array( 'BNFDC28', 'bear', 'toddler' ),
			array( 'Jessica Thurmond FCCH', 'thurmond', 'mixed' ),
			array( "Lillie's", 'lillies', 'mixed' ),
			array( 'Big Bears', 'smartkidz', 'toddler' ),
		);
	}

	/**
	 * Get teacher definitions.
	 * Each: [first_name, last_name, email, school_key, classroom_name, is_lead, is_also_school_leader].
	 *
	 * @return array
	 */
	private function get_teacher_defs() {
		return array(
			// WPB teachers (25).
			array( 'Yanet', 'Bello Santos', 'yanet.bellosantos@lsfnet.org', 'wpb', 'EHS A', true, false ),
			array( 'Marta', 'Soto', 'marta.soto@lsfnet.org', 'wpb', 'EHS A', false, false ),
			array( 'Isbel', 'Roa', 'isbel.roa@lsfnet.org', 'wpb', 'EHS B', true, false ),
			array( 'Ashley', 'Bedward', 'ashley.bedward@lsfnet.org', 'wpb', 'EHS C', true, false ),
			array( 'Connie', 'Epps', 'connie.epps@lsfnet.org', 'wpb', 'EHS D', true, false ),
			array( 'Mileidys', 'Carvajal', 'mileidys.carvajal@lsfnet.org', 'wpb', 'EHS D', false, false ),
			array( 'Indra', 'Ramsaroop', 'indra.ramsaroop@lsfnet.org', 'wpb', 'EHS E', true, false ),
			array( 'Daimarelis', 'Junco Cordero', 'daimarelis.juncocordero@lsfnet.org', 'wpb', 'EHS E', false, false ),
			array( 'Serena', 'White', 'setena.white@lsfnet.org', 'wpb', 'EHS F', true, false ),
			array( 'Elianys', 'Pedraza', 'elianys.pedraza@lsfnet.org', 'wpb', 'EHS F', false, false ),
			array( 'Latonya', 'Perez', 'latonya.perez@lsfnet.org', 'wpb', 'EHS G', true, false ),
			array( 'Carissa', 'Nadeau', 'carissa.nadeau@lsfnet.org', 'wpb', 'EHS G', false, false ),
			array( 'Kenia', 'Mas', 'kenia.mas@lsfnet.org', 'wpb', 'HS A', true, false ),
			array( 'Sharon', 'Leighton', 'sharon.leighton@lsfnet.org', 'wpb', 'HS A', false, false ),
			array( 'Alina', 'Bach Porro', 'alina.bachporro@lsfnet.org', 'wpb', 'HS B', true, false ),
			array( 'Jeandeline', 'Delusme', 'jeandeline.delusme@lsfnet.org', 'wpb', 'HS B', false, false ),
			array( 'Cynthia', 'Desty', 'cynthia.desty@lsfnet.org', 'wpb', 'HS C', true, false ),
			array( 'Nakia', 'Harp', 'nakia.harp@lsfnet.org', 'wpb', 'HS C', false, false ),
			array( 'Shameka', 'Byrd', 'shameka.byrd@lsfnet.org', 'wpb', 'HS D', true, false ),
			array( 'Nilay', 'Veliz Padron', 'nilay.veliz@lsfnet.org', 'wpb', 'HS D', false, false ),
			array( 'Sangita', 'Patel', 'sangita.patel@lsfnet.org', 'wpb', 'HS E', true, false ),
			array( 'Dunia', 'Barreto', 'dunia.barreto@lsfnet.org', 'wpb', 'HS E', false, false ),
			array( 'Gale', 'Bethel', 'gale.bethel@lsfnet.org', 'wpb', 'HS F', true, false ),
			array( 'Kyesha', 'Curtis', 'kyesha.curtis@lsfnet.org', 'wpb', 'HS F', false, false ),
			array( 'Kelly', 'Castro Vargas', 'kelly.castro@lsfnet.org', 'wpb', 'HS G', true, false ),
			// Jupiter teachers (7).
			array( 'Rachelle', 'Louis', 'rachelle.louis1@lsfnet.org', 'jupiter', 'HS-A', true, false ),
			array( 'Ana', 'Vilfort', 'ana.vilfort@lsfnet.org', 'jupiter', 'HS-A', false, false ),
			array( 'Guerline', 'Villiere', 'guerline.villiere@lsfnet.org', 'jupiter', 'HS-B', true, false ),
			array( 'Seritha', 'Dilworth', 'seritha.dilworth@lsfnet.org', 'jupiter', 'HS-B', false, false ),
			array( 'Elva', 'Engativa', 'elva.engativa@lsfnet.org', 'jupiter', 'HS-C', true, false ),
			array( 'Luz', 'Bello', 'luz.bellosilverio@lsfnet.org', 'jupiter', 'HS-C', false, false ),
			array( 'Cynthia', 'Graham', 'cynthia.graham@lsfnet.org', 'jupiter', 'HS-D', true, false ),
			// South Bay teachers (6).
			array( 'Fatima', 'Flores', 'fatima.flores@lsfnet.org', 'south_bay', 'EHS-A', true, false ),
			array( 'Makeria', 'Davis', 'makceria.davis@lsfnet.org', 'south_bay', 'EHS-A', false, false ),
			array( 'Samoy', 'Grizzle', 'samoy.grizzle@lsfnet.org', 'south_bay', 'EHS-B', true, false ),
			array( 'Johnnie', 'Guyton', 'johnnie.guyton@lsfnet.org', 'south_bay', 'EHS-B', false, false ),
			array( 'Carlene', 'Thornton', 'carlene.thornton@lsfnet.org', 'south_bay', 'EHS-C', true, false ),
			array( 'Alejandra', 'Hernandez', 'alejandra.hernandez@lsfnet.org', 'south_bay', 'EHS-C', false, false ),
			// FCCH teachers (9) - dual-role teachers marked with true for is_also_school_leader.
			array( 'Monica', 'Turner', 'monicat52@aol.com', 'turner', 'Turner', true, true ),
			array( 'Patricia', 'Oliver', 'oliverfcc@yahoo.com', 'oliver', 'Dr. Seuss', true, true ),
			array( 'Lisa', 'Dupree', 'ladyd0316@yahoo.com', 'lisas', "Lisa's Classroom", true, true ),
			array( 'Nichola', 'Griffiths-Butts', 'nicholagriffiths@yahoo.com', 'nichola', "Nichola's Classroom", true, true ),
			array( 'Laconia', 'Walker', 'bearncty@yahoo.com', 'bear', 'BNFDC28', true, true ),
			array( 'Jessica', 'Thurmond', 'jessicathurmond1@gmail.com', 'thurmond', 'Jessica Thurmond FCCH', true, true ),
			array( 'Destiny', 'Daconcaicao', 'dessjenkins@gmail.com', 'thurmond', 'Jessica Thurmond FCCH', false, false ),
			array( 'Ezzieola', 'Jones', 'mypreciouslillieshccf@gmail.com', 'lillies', "Lillie's", true, true ),
			array( 'Loretta', 'Ferguson', 'smartkidzcollege@gmail.com', 'smartkidz', 'Big Bears', true, true ),
		);
	}

	/**
	 * Get children definitions.
	 * Each: [first_name, last_initial, classroom_name, school_key, dob_excel_serial, gender, ethnicity].
	 *
	 * @return array
	 */
	private function get_children_defs() {
		return array(
			// WPB EHS A (8).
			array('Noah','C','EHS A','wpb',45748,'Male','Black'),
			array('Olivia','H','EHS A','wpb',45717,'Female','Black'),
			array('Kamryn','L','EHS A','wpb',45715,'Female','Black'),
			array('Jamar','M','EHS A','wpb',45620,'Male','Black'),
			array('Fredwoobin','S','EHS A','wpb',45593,'Male','Haitian'),
			array('Chidley','B','EHS A','wpb',45663,'Male','Haitian'),
			array('Ricayla','W','EHS A','wpb',45755,'Female','Black'),
			array('Ricaylin','W','EHS A','wpb',45755,'Female','Black'),
			// WPB EHS B (8).
			array('Isai','G','EHS B','wpb',45346,'Male','Hispanic'),
			array('Miyomi','M','EHS B','wpb',45517,'Female','Black'),
			array('Brycen','M','EHS B','wpb',45347,'Male','Black'),
			array('Liam','N','EHS B','wpb',45422,'Male','Hispanic'),
			array('Blaize','R','EHS B','wpb',45465,'Male','Black'),
			array('Azaiah','R','EHS B','wpb',45365,'Male','Black'),
			array('Zyraire','R','EHS B','wpb',45430,'Male','Black'),
			array('Ryandy','T','EHS B','wpb',45426,'Male','Haitian'),
			// WPB EHS C (7).
			array('Marzell','A','EHS C','wpb',45204,'Male','Black'),
			array('Gemma','D','EHS C','wpb',45229,'Female','Black'),
			array('Gain','N','EHS C','wpb',45275,'Male','Black'),
			array('Syndly','B','EHS C','wpb',45305,'Female','Black'),
			array('Lyric','M','EHS C','wpb',45223,'Female','Black'),
			array('Jaymiahs','O','EHS C','wpb',45245,'Male','Black'),
			array('Lola','S','EHS C','wpb',45262,'Female','Black'),
			// WPB EHS D (8).
			array('Marsiah','A','EHS D','wpb',45204,'Female','Black'),
			array("K'Myri",'B','EHS D','wpb',45128,'Female','Black'),
			array('Micah','G','EHS D','wpb',45174,'Male','Black'),
			array('Maya','H','EHS D','wpb',44950,'Female','Black'),
			array('Wayne','H','EHS D','wpb',44997,'Male','Black'),
			array('Elani','L','EHS D','wpb',45057,'Female','Black'),
			array('Dakai','M','EHS D','wpb',45106,'Male','Black'),
			array('Chrishante','R','EHS D','wpb',44917,'Female','Black'),
			// WPB EHS E (8).
			array('Afnan','B','EHS E','wpb',44916,'Female','Indian'),
			array('Dijonay','B','EHS E','wpb',44993,'Female','Black'),
			array("Jah'mir",'B','EHS E','wpb',45110,'Male','Black'),
			array('Brayden','D','EHS E','wpb',44932,'Male','Black'),
			array('Loyal','F','EHS E','wpb',45015,'Male','Black'),
			array('Selena','M','EHS E','wpb',45057,'Female','Hispanic'),
			array('Akyla','M','EHS E','wpb',44898,'Female','Black'),
			array('Jonah','T','EHS E','wpb',44890,'Male','Black'),
			// WPB EHS F (8).
			array('Semaj','B','EHS F','wpb',45161,'Male','Black'),
			array('Kenji','C','EHS F','wpb',44933,'Male','Black'),
			array('Wilskaicha','F','EHS F','wpb',44970,'Female','Black'),
			array('Kaiden','H','EHS F','wpb',45169,'Male','Black'),
			array('Zuery','J','EHS F','wpb',45066,'Female','Black'),
			array('Amari','M','EHS F','wpb',45152,'Male','Black'),
			array('Autumn','N','EHS F','wpb',45172,'Female','Black'),
			array('Reginald','T','EHS F','wpb',44903,'Male','Black'),
			// WPB EHS G (8).
			array('Isadora','C','EHS G','wpb',45201,'Female','Black'),
			array('Adassah','C','EHS G','wpb',44927,'Female','Black'),
			array('Phaith','E','EHS G','wpb',45159,'Female','Black'),
			array('Skylar','H','EHS G','wpb',45051,'Female','Black'),
			array('Leah','J','EHS G','wpb',44890,'Female','Black'),
			array('Aryya','L','EHS G','wpb',45129,'Female','Black'),
			array("Joe'l",'M','EHS G','wpb',44992,'Male','Black'),
			array('Malani','S','EHS G','wpb',45087,'Female','Black'),
			// WPB HS A (15).
			array('Messiah','A','HS A','wpb',44654,'Male','Black'),
			array('Aariyah','B','HS A','wpb',44616,'Female','Black'),
			array('Kaiser','C','HS A','wpb',44671,'Male','Black'),
			array('Mapou','C','HS A','wpb',44640,'Male','Haitian'),
			array("Ja'zya",'C','HS A','wpb',44672,'Female','Black'),
			array('Peter','E','HS A','wpb',44708,'Male','Haitian'),
			array('August','G','HS A','wpb',44790,'Male','Black'),
			array('Sarah','J','HS A','wpb',44691,'Female','Black'),
			array('Antonio','K','HS A','wpb',44557,'Male','Black'),
			array("A'zariana",'M','HS A','wpb',44727,'Female','Black'),
			array('Naiara','M','HS A','wpb',44476,'Female','Black'),
			array('Malik','O','HS A','wpb',44809,'Male','Black'),
			array('Justin','S','HS A','wpb',44783,'Male','Black'),
			array('Guivenchi','S','HS A','wpb',44540,'Male','Black'),
			array('Skylar','V','HS A','wpb',44573,'Female','Black'),
			// WPB HS B (14).
			array('Diesel','B','HS B','wpb',44734,'Male','White'),
			array('Elodie','M','HS B','wpb',44714,'Female','Haitian'),
			array('Malakhi','C','HS B','wpb',44783,'Male','Black'),
			array('Nathanael','D','HS B','wpb',44715,'Male','Black'),
			array('Jose','G','HS B','wpb',44606,'Male','Hispanic'),
			array('Alisen','G','HS B','wpb',44719,'Female','Haitian'),
			array('Daisy','G','HS B','wpb',44673,'Female','Black'),
			array('Zylonnie','H','HS B','wpb',44492,'Female','Black'),
			array('Aidan','J','HS B','wpb',44788,'Male','Haitian'),
			array('Matthew','L','HS B','wpb',44636,'Male','Black'),
			array('Yood','M','HS B','wpb',44791,'Female','Haitian'),
			array('Yara','M','HS B','wpb',44705,'Female','Haitian'),
			array('Cyren','M','HS B','wpb',44537,'Female','Black'),
			array('Alani','W','HS B','wpb',44627,'Female','Black'),
			// WPB HS C (14).
			array('Jaylain','B','HS C','wpb',44456,'Male','Haitian'),
			array('Cassidy','B','HS C','wpb',44496,'Female','Black'),
			array('Emelia','B','HS C','wpb',44522,'Female','Hispanic'),
			array('Jayden','B','HS C','wpb',44525,'Male','Black'),
			array('Ajay','C','HS C','wpb',44560,'Male','Black'),
			array('Elias','D','HS C','wpb',44474,'Male','Hispanic'),
			array('Gianna','D','HS C','wpb',44449,'Female','Haitian'),
			array('Elisha','D','HS C','wpb',44510,'Female','Haitian'),
			array('Richie','E','HS C','wpb',44467,'Male','Haitian'),
			array('Leila','F','HS C','wpb',44496,'Female','Haitian'),
			array('Widlens','J','HS C','wpb',44432,'Male','Haitian'),
			array('Legacy','M','HS C','wpb',44555,'Male','Black'),
			array('Julanie','M','HS C','wpb',44504,'Female','Haitian'),
			array('Heaven','V','HS C','wpb',44504,'Female','Black'),
			// WPB HS D (15).
			array('Delaina','H','HS D','wpb',44091,'Female','White'),
			array('Nathaniel','B','HS D','wpb',44110,'Male','Black'),
			array("Jah'nari",'B','HS D','wpb',44440,'Male','Black'),
			array('Isabella','C','HS D','wpb',44275,'Female','Haitian'),
			array('Salif','C','HS D','wpb',44177,'Male','Black'),
			array('Joao','D','HS D','wpb',44414,'Male','Haitian'),
			array('Esther','D','HS D','wpb',44443,'Female','Haitian'),
			array('Jesse','G','HS D','wpb',44211,'Male','Haitian'),
			array('Ahmari','G','HS D','wpb',44200,'Female','Black'),
			array('Celisa','J','HS D','wpb',44354,'Female','Black'),
			array('Amari','L','HS D','wpb',44279,'Male','Black'),
			array('Karissa','L','HS D','wpb',44430,'Female','Haitian'),
			array('Aleeyah','M','HS D','wpb',44119,'Female','Hispanic'),
			array('Jaynica','O','HS D','wpb',44390,'Female','Haitian'),
			array('Sophia','P','HS D','wpb',44323,'Female','Haitian'),
			// WPB HS E (17).
			array('Giovani','D','HS E','wpb',44119,'Male','Haitian'),
			array('Perlina','E','HS E','wpb',44141,'Female','Haitian'),
			array('Zyrrayah','H','HS E','wpb',44440,'Female','Black'),
			array('Tyrell','H','HS E','wpb',44302,'Male','Black'),
			array('Amelia','J','HS E','wpb',44131,'Female','Black'),
			array('Amari','M','HS E','wpb',44330,'Male','Haitian'),
			array('Juni','M','HS E','wpb',44076,'Female','Haitian'),
			array('Celeste','N','HS E','wpb',44095,'Female','Hispanic'),
			array('Saman','R','HS E','wpb',44341,'Male','Hispanic'),
			array('Rique','O','HS E','wpb',44176,'Male','Black'),
			array('Rebecca','P','HS E','wpb',44281,'Female','Haitian'),
			array('Johnny','R','HS E','wpb',44276,'Male','Black'),
			array('Lurnise','S','HS E','wpb',44431,'Female','Haitian'),
			array('Micheal','S','HS E','wpb',44168,'Male','Black'),
			array('Shunna','T','HS E','wpb',44325,'Female','Haitian'),
			array('Noah','W','HS E','wpb',44427,'Male','Black'),
			array('Ily','W','HS E','wpb',44252,'Female','Hispanic'),
			// WPB HS F (17).
			array('Rose Kelly','A','HS F','wpb',44394,'Female','Haitian'),
			array('Sireen','B','HS F','wpb',44316,'Female','Indian'),
			array('River','C','HS F','wpb',44091,'Female','White'),
			array('Brailyn','D','HS F','wpb',44170,'Female','Black'),
			array('Myline','E','HS F','wpb',44387,'Female','Haitian'),
			array('Kash','G','HS F','wpb',44166,'Male','Black'),
			array('Markein','H','HS F','wpb',44269,'Male','Black'),
			array('Jaylyn','H','HS F','wpb',44195,'Female','Black'),
			array('Hadassa','H','HS F','wpb',44203,'Female','Black'),
			array('Zaharra','M','HS F','wpb',44206,'Female','Black'),
			array('Nyla Natural','N','HS F','wpb',44345,'Female','Black'),
			array('Lutchano','P','HS F','wpb',44379,'Male','Haitian'),
			array('Jaylah','R','HS F','wpb',44253,'Female','Black'),
			array('Roodolph','R','HS F','wpb',44197,'Male','Haitian'),
			array('Fedjina','S','HS F','wpb',44165,'Female','Haitian'),
			array('Allison','V','HS F','wpb',44127,'Female','Hispanic'),
			array('Jayson','V','HS F','wpb',44113,'Male','Black'),
			// WPB HS G (15).
			array('Kai','B','HS G','wpb',44722,'Male','Black'),
			array('Dolph','C','HS G','wpb',44768,'Male','Haitian'),
			array('Kelly','D','HS G','wpb',44758,'Male','Haitian'),
			array('Jayden','E','HS G','wpb',44656,'Male','Black'),
			array('Harper','G','HS G','wpb',44826,'Female','Haitian'),
			array("A'layia",'H','HS G','wpb',44517,'Female','Black'),
			array('Jay Jay','J','HS G','wpb',44460,'Male','Haitian'),
			array('Micah','L','HS G','wpb',44636,'Male','Black'),
			array('Jeremiah','P','HS G','wpb',44707,'Male','Haitian'),
			array('Nalani','P','HS G','wpb',44566,'Female','Black'),
			array('Alan','R','HS G','wpb',44547,'Male','Hispanic'),
			array('Steve','S','HS G','wpb',44772,'Male','Haitian'),
			array('Michael','S','HS G','wpb',44614,'Male','Haitian'),
			array('Joy','V','HS G','wpb',44841,'Female','Haitian'),
			array('Londyn','W','HS G','wpb',44645,'Female','Black'),
			// Jupiter HS-A (17).
			array('Rulcy','A','HS-A','jupiter',44101,'Male','Hispanic'),
			array('Farid','B','HS-A','jupiter',44394,'Male','Hispanic'),
			array('Samantha','C','HS-A','jupiter',44202,'Female','Hispanic'),
			array('Anderson','J','HS-A','jupiter',44367,'Male','Hispanic'),
			array('Wilver','L','HS-A','jupiter',44305,'Male','Hispanic'),
			array('Camila','L','HS-A','jupiter',44351,'Female','Hispanic'),
			array('Keidy','L','HS-A','jupiter',44418,'Female','Hispanic'),
			array('Rodri','L','HS-A','jupiter',44193,'Male','Hispanic'),
			array('Kevin','L','HS-A','jupiter',44162,'Male','Hispanic'),
			array('James','M','HS-A','jupiter',44348,'Male','Hispanic'),
			array('Dania','M','HS-A','jupiter',44296,'Female','Hispanic'),
			array('Alizon','M','HS-A','jupiter',44161,'Female','Hispanic'),
			array('Roman','R','HS-A','jupiter',44284,'Male','Hispanic'),
			array('Valentina','S','HS-A','jupiter',44254,'Female','Hispanic'),
			array('Judah','T','HS-A','jupiter',44196,'Male','Black'),
			array('Dayra','V','HS-A','jupiter',44118,'Female','Hispanic'),
			array('Sofia','V','HS-A','jupiter',44119,'Female','Hispanic'),
			// Jupiter HS-B (17).
			array('Daniel','A','HS-B','jupiter',44340,'Female','Hispanic'),
			array('Alison','C','HS-B','jupiter',44117,'Female','Hispanic'),
			array('Oneida','C','HS-B','jupiter',44210,'Male','Hispanic'),
			array('Erdogan','D','HS-B','jupiter',44332,'Male','Hispanic'),
			array('Kevin','D','HS-B','jupiter',44066,'Female','Hispanic'),
			array('Isabella','L','HS-B','jupiter',44158,'Female','Hispanic'),
			array('Keily','L','HS-B','jupiter',44252,'Female','Hispanic'),
			array('Anderson','L','HS-B','jupiter',44141,'Male','Hispanic'),
			array('Keyla','L','HS-B','jupiter',44162,'Female','Hispanic'),
			array('Gaia','M','HS-B','jupiter',44375,'Female','Hispanic'),
			array('Rodolfo','M','HS-B','jupiter',44138,'Male','Hispanic'),
			array('Edson','R','HS-B','jupiter',44387,'Male','Hispanic'),
			array('Lia','R','HS-B','jupiter',44387,'Female','Hispanic'),
			array('Khiren','S','HS-B','jupiter',44222,'Male','Black'),
			array('Samara','T','HS-B','jupiter',44084,'Female','Hispanic'),
			array('Yeshua','T','HS-B','jupiter',44128,'Male','Hispanic'),
			array('Erick','Z','HS-B','jupiter',44155,'Male','Hispanic'),
			// Jupiter HS-C (14).
			array('Yaslin','C','HS-C','jupiter',44723,'Female','Hispanic'),
			array('Ema','C','HS-C','jupiter',44571,'Female','Hispanic'),
			array('Sophia','S','HS-C','jupiter',44443,'Female','Hispanic'),
			array('Vilma','G','HS-C','jupiter',44478,'Female','Hispanic'),
			array('Oscar','I','HS-C','jupiter',44450,'Male','Hispanic'),
			array('Kimberly','J','HS-C','jupiter',44566,'Female','Hispanic'),
			array('Leah','L','HS-C','jupiter',44678,'Female','Hispanic'),
			array('Romina','L','HS-C','jupiter',44491,'Female','Hispanic'),
			array('Amori','M','HS-C','jupiter',44561,'Female','Black'),
			array('Laura','M','HS-C','jupiter',44542,'Female','Hispanic'),
			array('Bartolo','M','HS-C','jupiter',44486,'Male','Hispanic'),
			array('Romeo','R','HS-C','jupiter',44739,'Male','Hispanic'),
			array('Alex','S','HS-C','jupiter',44481,'Male','Hispanic'),
			array('Emma','S','HS-C','jupiter',44565,'Female','Hispanic'),
			// Jupiter HS-D (14).
			array('Hawk','A','HS-D','jupiter',44579,'Female','Hispanic'),
			array('Amelia','C','HS-D','jupiter',44757,'Female','Hispanic'),
			array('Angeles','C','HS-D','jupiter',44815,'Female','Hispanic'),
			array('Katherine','C','HS-D','jupiter',44771,'Female','Hispanic'),
			array('Genesis','E','HS-D','jupiter',44720,'Female','Hispanic'),
			array('Matias','L','HS-D','jupiter',44695,'Male','Hispanic'),
			array('Yessi','L','HS-D','jupiter',44441,'Female','Hispanic'),
			array('Edison','L','HS-D','jupiter',44760,'Male','Hispanic'),
			array('Amoni','M','HS-D','jupiter',44561,'Female','Black'),
			array('Kayla','M','HS-D','jupiter',44705,'Female','Hispanic'),
			array('Luna','P','HS-D','jupiter',44799,'Female','Hispanic'),
			array('Kailani','R','HS-D','jupiter',44646,'Female','Hispanic'),
			array('Liam','R','HS-D','jupiter',44542,'Female','Hispanic'),
			array('Eimy','V','HS-D','jupiter',44464,'Female','Hispanic'),
			// Monica Turner FCCH (2).
			array('Kahlynn','J','Turner','turner',45057,'Female','Black'),
			array('Jenesis','S','Turner','turner',45454,'Female','Black'),
			// Patricia Oliver FCCH (2).
			array('K.J.','P','Dr. Seuss','oliver',45224,'Male','Black'),
			array('Charly','T','Dr. Seuss','oliver',45130,'Male','Black'),
			// Lisa's Lil Wonders (9).
			array('Naomi','P',"Lisa's Classroom",'lisas',44894,'Female','Black'),
			array('Sam','O',"Lisa's Classroom",'lisas',45003,'Male','Black'),
			array('Hadassa','J',"Lisa's Classroom",'lisas',44994,'Female','Black'),
			array('Andy','R',"Lisa's Classroom",'lisas',45045,'Male','Hispanic'),
			array('Jhudsan','F',"Lisa's Classroom",'lisas',45112,'Male','Black'),
			array('Levi','I',"Lisa's Classroom",'lisas',44971,'Male','Black'),
			array('Nephtalie','J',"Lisa's Classroom",'lisas',45732,'Female','Black'),
			array('Clarissa','T',"Lisa's Classroom",'lisas',45691,'Female','Black'),
			array('Guwensky','L',"Lisa's Classroom",'lisas',45455,'Male','Black'),
			// Nichola Griffiths-Butts FCCH (9).
			array('Jatasia','J',"Nichola's Classroom",'nichola',44844,'Female','Black'),
			array('Treveon','N',"Nichola's Classroom",'nichola',44853,'Male','Black'),
			array('Khai','B',"Nichola's Classroom",'nichola',44881,'Male','Black'),
			array('Solai','J',"Nichola's Classroom",'nichola',45135,'Female','Black'),
			array('Harmony','P',"Nichola's Classroom",'nichola',45126,'Female','Hispanic'),
			array('Madison','C',"Nichola's Classroom",'nichola',45279,'Female','Black'),
			array('Karsyn','M',"Nichola's Classroom",'nichola',45494,'Female','Black'),
			array('Giovanni','M',"Nichola's Classroom",'nichola',45588,'Male','Black'),
			array('Josiah','A',"Nichola's Classroom",'nichola',45758,'Male','Black'),
			// Bear Necessities FCCH (4).
			array('Sarai','B','BNFDC28','bear',45272,'Female','Black'),
			array('Isaiah','Y','BNFDC28','bear',44898,'Male','Black'),
			array('Zariah','H','BNFDC28','bear',45108,'Female','Black'),
			array('Noah','M','BNFDC28','bear',45555,'Male','Black'),
			// Jessica Thurmond FCCH (6).
			array('Youvensley','F','Jessica Thurmond FCCH','thurmond',44940,'Male','Non-Hispanic'),
			array('Alani','G','Jessica Thurmond FCCH','thurmond',44893,'Female','Non-Hispanic'),
			array('Robentz','T','Jessica Thurmond FCCH','thurmond',45334,'Male','Non-Hispanic'),
			array('Wislene','J','Jessica Thurmond FCCH','thurmond',45107,'Female','Non-Hispanic'),
			array('Abija','F','Jessica Thurmond FCCH','thurmond',45229,'Female','Non-Hispanic'),
			array('Leando','P','Jessica Thurmond FCCH','thurmond',45138,'Male','Non-Hispanic'),
			// My Precious Lillies (5).
			array('Jayden','C',"Lillie's",'lillies',44888,'Male','Haitian'),
			array('Liam','G',"Lillie's",'lillies',45157,'Male','Hispanic'),
			array('Rubi','M',"Lillie's",'lillies',45629,'Female','African American'),
			array('Rylee','M',"Lillie's",'lillies',45231,'Female','African American'),
			array('Zhamari','N',"Lillie's",'lillies',45244,'Male','African American'),
			// Smart Kidz College (4).
			array('Evon','B','Big Bears','smartkidz',45234,'Male','Black'),
			array('Jakobe','H','Big Bears','smartkidz',45087,'Male','Black'),
			array('Jakyn','H','Big Bears','smartkidz',45624,'Male','Black'),
			array('Yohan','S','Big Bears','smartkidz',45033,'Male','Black'),
			// South Bay EHS-A (6).
			array('Akila','B','EHS-A','south_bay',45671,'Female','Black'),
			array("Kai'Ari",'C','EHS-A','south_bay',45562,'Female','Black'),
			array('Khase','C','EHS-A','south_bay',45562,'Male','Black'),
			array('Donali','J','EHS-A','south_bay',45673,'Female','Black'),
			array('Denim','T','EHS-A','south_bay',45725,'Male','Black'),
			array("De'Aria",'W','EHS-A','south_bay',45668,'Female','Black'),
			// South Bay EHS-B (8).
			array('Ahkeem','A','EHS-B','south_bay',45214,'Male','Black'),
			array('Demetrius','C','EHS-B','south_bay',45142,'Male','Black'),
			array('Aiden','K','EHS-B','south_bay',45085,'Male','Black'),
			array('Aubrey','L','EHS-B','south_bay',44869,'Female','Black'),
			array('Carson','L','EHS-B','south_bay',44962,'Male','Black'),
			array("Ah'Mari",'M','EHS-B','south_bay',44923,'Female','Black'),
			array("Ke'Yani",'S','EHS-B','south_bay',45015,'Female','Black'),
			array('Diore','T','EHS-B','south_bay',45277,'Female','Black'),
			// South Bay EHS-C (7).
			array('Amirion','D','EHS-C','south_bay',45296,'Male','Black'),
			array('Skylar','D','EHS-C','south_bay',45315,'Female','Black'),
			array('Stormi','D','EHS-C','south_bay',45315,'Female','Black'),
			array('Daniel','L','EHS-C','south_bay',45293,'Male','Black'),
			array('Curtavious','M','EHS-C','south_bay',45416,'Female','Black'),
			array('Layla','S','EHS-C','south_bay',45381,'Female','Black'),
			array('Jahki','S','EHS-C','south_bay',45364,'Female','Black'),
		);
	}

	// ------------------------------------------------------------------
	// Idempotency helpers
	// ------------------------------------------------------------------

	/**
	 * Check if Palm Beach demo data already exists.
	 *
	 * @return bool
	 */
	private function demo_exists() {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
				self::TRACK_CODE
			)
		);
		return ! empty( $row );
	}

	/**
	 * Remove all Palm Beach demo data.
	 */
	private function clean() {
		global $wpdb;

		WP_CLI::line( 'Cleaning Palm Beach demo data...' );

		$track_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
				self::TRACK_CODE
			)
		);

		if ( $track_id ) {
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d",
					$track_id
				)
			);

			if ( ! empty( $enrollment_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_state WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_override WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id IN ({$in_ids})" );

				$ca_ids = $wpdb->get_col(
					"SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})"
				);
				if ( ! empty( $ca_ids ) ) {
					$in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
					$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
				}
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_observation WHERE track_id = {$track_id}" );
			}

			$activity_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT activity_id FROM {$wpdb->prefix}hl_activity WHERE track_id = %d",
					$track_id
				)
			);
			if ( ! empty( $activity_ids ) ) {
				$in_act = implode( ',', array_map( 'intval', $activity_ids ) );
				$group_ids = $wpdb->get_col(
					"SELECT group_id FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id IN ({$in_act})"
				);
				if ( ! empty( $group_ids ) ) {
					$in_grp = implode( ',', array_map( 'intval', $group_ids ) );
					$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_prereq_item WHERE group_id IN ({$in_grp})" );
				}
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id IN ({$in_act})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_drip_rule WHERE activity_id IN ({$in_act})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_activity WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE track_id = %d", $track_id ) );

			$team_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT team_id FROM {$wpdb->prefix}hl_team WHERE track_id = %d", $track_id )
			);
			if ( ! empty( $team_ids ) ) {
				$in_teams = implode( ',', array_map( 'intval', $team_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE team_id IN ({$in_teams})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_team WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_track_school WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coach_assignment WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coaching_session WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_child_track_snapshot WHERE track_id = %d", $track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_track WHERE track_id = %d", $track_id ) );

			WP_CLI::log( "  Deleted track {$track_id} and all related records." );
		}

		// Delete Lutheran control track.
		$control_track_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
				'LSF-CTRL-2026'
			)
		);
		if ( $control_track_id ) {
			$ctrl_enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d",
					$control_track_id
				)
			);
			if ( ! empty( $ctrl_enrollment_ids ) ) {
				$in_ctrl = implode( ',', array_map( 'intval', $ctrl_enrollment_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ctrl})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_state WHERE enrollment_id IN ({$in_ctrl})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ctrl})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_activity WHERE track_id = %d", $control_track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE track_id = %d", $control_track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d", $control_track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_child_track_snapshot WHERE track_id = %d", $control_track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_track WHERE track_id = %d", $control_track_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE track_id = %d", $control_track_id ) );
			WP_CLI::log( "  Deleted control track {$control_track_id} and related records." );
		}

		// Delete B2E Program Evaluation cohort (container).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}hl_cohort WHERE cohort_code = %s",
				'B2E-EVAL'
			)
		);
		WP_CLI::log( '  Deleted cohort (B2E-EVAL).' );

		// Delete demo users.
		$demo_user_ids = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . self::DEMO_META_KEY . "' AND meta_value = '1'"
		);
		if ( ! empty( $demo_user_ids ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $demo_user_ids as $uid ) {
				wp_delete_user( (int) $uid );
			}
			WP_CLI::log( '  Deleted ' . count( $demo_user_ids ) . ' Palm Beach demo users.' );
		}

		// Delete Palm Beach instruments.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_instrument WHERE name LIKE 'Palm Beach %'" );
		// Only delete B2E instrument if demo seed isn't active (shared instrument).
		$demo_track = $wpdb->get_var( "SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = 'DEMO-2026' LIMIT 1" );
		if ( ! $demo_track ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key IN ('b2e_self_assessment','b2e_self_assessment_pre','b2e_self_assessment_post')" );
		}
		WP_CLI::log( '  Deleted Palm Beach instruments.' );

		// Delete children for Palm Beach schools.
		$school_names = array_values( $this->get_school_defs() );
		$placeholders = implode( ',', array_fill( 0, count( $school_names ), '%s' ) );
		$pb_school_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE name IN ({$placeholders})",
				$school_names
			)
		);
		if ( ! empty( $pb_school_ids ) ) {
			$in_c = implode( ',', array_map( 'intval', $pb_school_ids ) );
			$cls_ids = $wpdb->get_col( "SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_c})" );
			if ( ! empty( $cls_ids ) ) {
				$in_cls = implode( ',', array_map( 'intval', $cls_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_current WHERE classroom_id IN ({$in_cls})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_history WHERE classroom_id IN ({$in_cls})" );
			}
			$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child WHERE school_id IN ({$in_c})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_c})" );
			WP_CLI::log( '  Deleted Palm Beach children and classrooms.' );
		}

		// Delete orgunits.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_orgunit WHERE name = 'ELC Palm Beach County'" );
		if ( ! empty( $pb_school_ids ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id IN ({$in_c})" );
		}
		WP_CLI::log( '  Deleted Palm Beach org units.' );

		if ( $track_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE track_id = %d", $track_id ) );
			WP_CLI::log( '  Deleted Palm Beach audit log entries.' );
		}
	}

	// ------------------------------------------------------------------
	// Helper: Convert Excel serial date to Y-m-d
	// ------------------------------------------------------------------

	private function excel_date_to_ymd( $serial ) {
		return gmdate( 'Y-m-d', ( intval( $serial ) - 25569 ) * 86400 );
	}

	// ------------------------------------------------------------------
	// Step 1: Org Structure
	// ------------------------------------------------------------------

	private function seed_orgunits() {
		$repo = new HL_OrgUnit_Repository();

		$district_id = $repo->create( array(
			'name'         => 'ELC Palm Beach County',
			'orgunit_type' => 'district',
		) );

		$schools = array();
		foreach ( $this->get_school_defs() as $key => $name ) {
			$schools[ $key ] = $repo->create( array(
				'name'              => $name,
				'orgunit_type'      => 'school',
				'parent_orgunit_id' => $district_id,
			) );
		}

		WP_CLI::log( "  [1/17] Org units created: district={$district_id}, schools=" . count( $schools ) );

		return array( 'district_id' => $district_id, 'schools' => $schools );
	}

	// ------------------------------------------------------------------
	// Step 2: Track
	// ------------------------------------------------------------------

	private function seed_track( $orgunits ) {
		global $wpdb;
		$repo = new HL_Track_Repository();

		$track_id = $repo->create( array(
			'track_name'  => 'ELC Palm Beach 2026',
			'track_code'  => self::TRACK_CODE,
			'district_id' => $orgunits['district_id'],
			'status'      => 'active',
			'start_date'  => '2026-01-01',
			'end_date'    => '2026-12-31',
		) );

		foreach ( $orgunits['schools'] as $school_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_track_school', array(
				'track_id' => $track_id,
				'school_id' => $school_id,
			) );
		}

		WP_CLI::log( "  [2/17] Track created: id={$track_id}, code=" . self::TRACK_CODE );
		return $track_id;
	}

	// ------------------------------------------------------------------
	// Step 3: Classrooms
	// ------------------------------------------------------------------

	private function seed_classrooms( $orgunits ) {
		$svc        = new HL_Classroom_Service();
		$classrooms = array();

		foreach ( $this->get_classroom_defs() as $def ) {
			$school_id = $orgunits['schools'][ $def[1] ];
			$id = $svc->create_classroom( array(
				'classroom_name' => $def[0],
				'school_id'      => $school_id,
				'age_band'       => $def[2],
			) );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( 'Classroom creation error: ' . $id->get_error_message() );
				continue;
			}
			$key = $def[1] . '::' . $def[0];
			$classrooms[ $key ] = array(
				'classroom_id' => $id,
				'school_id'    => $school_id,
				'school_key'   => $def[1],
				'name'         => $def[0],
				'age_band'     => $def[2],
			);
		}

		WP_CLI::log( '  [3/17] Classrooms created: ' . count( $classrooms ) );
		return $classrooms;
	}

	// ------------------------------------------------------------------
	// Step 4: Instruments
	// ------------------------------------------------------------------

	private function seed_instruments() {
		global $wpdb;

		$types = array(
			'infant'    => array( 'name' => 'Palm Beach Infant Assessment', 'type' => 'children_infant' ),
			'toddler'   => array( 'name' => 'Palm Beach Toddler Assessment', 'type' => 'children_toddler' ),
			'preschool' => array( 'name' => 'Palm Beach Preschool Assessment', 'type' => 'children_preschool' ),
		);

		$sample_questions = wp_json_encode( array(
			array( 'question_id' => 'q1', 'type' => 'likert', 'prompt_text' => 'Child demonstrates age-appropriate social skills', 'required' => true, 'allowed_values' => array( '1', '2', '3', '4', '5' ) ),
			array( 'question_id' => 'q2', 'type' => 'text', 'prompt_text' => 'Describe the child\'s language development', 'required' => true ),
			array( 'question_id' => 'q3', 'type' => 'number', 'prompt_text' => 'Number of peer interactions observed (15 min sample)', 'required' => false ),
			array( 'question_id' => 'q4', 'type' => 'single_select', 'prompt_text' => 'Primary learning style observed', 'required' => true, 'allowed_values' => array( 'Visual', 'Auditory', 'Kinesthetic', 'Mixed' ) ),
		) );

		$instruments = array();
		foreach ( $types as $band => $info ) {
			$wpdb->insert( $wpdb->prefix . 'hl_instrument', array(
				'instrument_uuid' => wp_generate_uuid4(),
				'name'            => $info['name'],
				'instrument_type' => $info['type'],
				'version'         => '1.0',
				'questions'       => $sample_questions,
				'behavior_key'    => wp_json_encode( HL_CLI_Seed_Demo::get_behavior_key_for_band( $band ) ),
				'instructions'    => HL_CLI_Seed_Demo::get_default_child_assessment_instructions(),
				'effective_from'  => '2026-01-01',
			) );
			$instruments[ $band ] = $wpdb->insert_id;
		}

		// B2E Teacher Self-Assessment instruments — separate PRE and POST.
		$b2e_scale_labels = wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_scale_labels() );

		// PRE instrument.
		$existing_pre = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT instrument_id FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
				'b2e_self_assessment_pre'
			)
		);
		if ( $existing_pre ) {
			$instruments['teacher_b2e_pre'] = (int) $existing_pre;
		} else {
			$wpdb->insert( $wpdb->prefix . 'hl_teacher_assessment_instrument', array(
				'instrument_name'    => 'Teacher Self-Assessment',
				'instrument_key'     => 'b2e_self_assessment_pre',
				'instrument_version' => '1.0',
				'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_pre() ),
				'scale_labels'       => $b2e_scale_labels,
				'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_pre(),
				'status'             => 'active',
				'created_at'         => current_time( 'mysql' ),
			) );
			$instruments['teacher_b2e_pre'] = $wpdb->insert_id;
		}

		// POST instrument.
		$existing_post = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT instrument_id FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
				'b2e_self_assessment_post'
			)
		);
		if ( $existing_post ) {
			$instruments['teacher_b2e_post'] = (int) $existing_post;
		} else {
			$wpdb->insert( $wpdb->prefix . 'hl_teacher_assessment_instrument', array(
				'instrument_name'    => 'Teacher Self-Assessment',
				'instrument_key'     => 'b2e_self_assessment_post',
				'instrument_version' => '1.0',
				'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_post() ),
				'scale_labels'       => $b2e_scale_labels,
				'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_post(),
				'status'             => 'active',
				'created_at'         => current_time( 'mysql' ),
			) );
			$instruments['teacher_b2e_post'] = $wpdb->insert_id;
		}

		WP_CLI::log( '  [4/17] Instruments created: ' . count( $instruments ) );
		return $instruments;
	}

	// ------------------------------------------------------------------
	// Step 5: WP Users
	// ------------------------------------------------------------------

	private function seed_users() {
		$users = array(
			'teachers'        => array(),
			'school_leaders'  => array(),
			'mentors'         => array(),
			'district_leader' => null,
			'coach'           => null,
			'all_ids'         => array(),
		);

		// 47 Teachers.
		foreach ( $this->get_teacher_defs() as $t ) {
			$uid = $this->create_demo_user( $t[2], $t[0], $t[1], 'subscriber' );
			$users['teachers'][] = array(
				'user_id'               => $uid,
				'school_key'            => $t[3],
				'classroom_name'        => $t[4],
				'is_lead'               => $t[5],
				'is_also_school_leader' => $t[6],
			);
			$users['all_ids'][] = $uid;
		}

		// 4 School leaders (non-teacher, for large schools).
		$cl_data = array(
			array( 'Francetine', 'Inman-Dennard', 'francetine.inman@lsfnet.org', 'south_bay' ),
			array( 'Gwendolyn', 'Kelly', 'gwendolyn.kelly@lsfnet.org', 'wpb' ),
			array( 'Josephine', 'Mitchell', 'josephine.mitchell@lsfnet.org', 'jupiter' ),
			array( 'Noemi', 'Torres', 'director@sunflowerlc.com', 'sunflower' ),
		);
		foreach ( $cl_data as $cl ) {
			$uid = $this->create_demo_user( $cl[2], $cl[0], $cl[1], 'subscriber' );
			$users['school_leaders'][] = array( 'user_id' => $uid, 'school_key' => $cl[3] );
			$users['all_ids'][] = $uid;
		}

		// 4 Mentors (synthetic).
		$mentor_data = array(
			array( 'PB', 'Mentor 1', 'pb-mentor-1@housmanlearning.com', 'wpb' ),
			array( 'PB', 'Mentor 2', 'pb-mentor-2@housmanlearning.com', 'wpb' ),
			array( 'PB', 'Mentor 3', 'pb-mentor-3@housmanlearning.com', 'jupiter' ),
			array( 'PB', 'Mentor 4', 'pb-mentor-4@housmanlearning.com', 'south_bay' ),
		);
		foreach ( $mentor_data as $m ) {
			$uid = $this->create_demo_user( $m[2], $m[0], $m[1], 'subscriber' );
			$users['mentors'][] = array( 'user_id' => $uid, 'school_key' => $m[3] );
			$users['all_ids'][] = $uid;
		}

		// 1 District leader (synthetic).
		$uid = $this->create_demo_user( 'pb-districtleader@housmanlearning.com', 'PB', 'District Leader', 'subscriber' );
		$users['district_leader'] = $uid;
		$users['all_ids'][] = $uid;

		// 1 Coach (synthetic).
		$uid = $this->create_demo_user( 'pb-coach@housmanlearning.com', 'PB', 'Coach', 'coach' );
		$users['coach'] = $uid;
		$users['all_ids'][] = $uid;

		WP_CLI::log( '  [5/17] WP users created: ' . count( $users['all_ids'] ) );
		return $users;
	}

	private function create_demo_user( $email, $first_name, $last_name, $role ) {
		$parts    = explode( '@', $email );
		$username = sanitize_user( $parts[0], true );

		// Handle duplicate usernames.
		$base_username = $username;
		$suffix        = 1;
		while ( username_exists( $username ) ) {
			$existing = get_user_by( 'login', $username );
			if ( $existing && $existing->user_email === $email ) {
				update_user_meta( $existing->ID, self::DEMO_META_KEY, '1' );
				return $existing->ID;
			}
			$username = $base_username . '-' . $suffix;
			$suffix++;
		}

		// Handle duplicate emails.
		$existing_by_email = get_user_by( 'email', $email );
		if ( $existing_by_email ) {
			update_user_meta( $existing_by_email->ID, self::DEMO_META_KEY, '1' );
			return $existing_by_email->ID;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => $first_name . ' ' . $last_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'role'         => $role,
		) );

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::warning( "Could not create user {$email}: " . $user_id->get_error_message() );
			return 0;
		}

		update_user_meta( $user_id, self::DEMO_META_KEY, '1' );
		return $user_id;
	}

	// ------------------------------------------------------------------
	// Step 6: Enrollments
	// ------------------------------------------------------------------

	private function seed_enrollments( $users, $track_id, $orgunits ) {
		$repo = new HL_Enrollment_Repository();
		$c    = $orgunits['schools'];
		$did  = $orgunits['district_id'];

		$enrollments = array(
			'teachers'  => array(), // Indexed array matching teacher order.
			'mentors'   => array(),
			'all'       => array(),
		);

		// Teachers.
		foreach ( $users['teachers'] as $idx => $t ) {
			$school_id = $c[ $t['school_key'] ];
			$roles     = array( 'teacher' );
			if ( $t['is_also_school_leader'] ) {
				$roles[] = 'school_leader';
			}
			$eid = $repo->create( array(
				'user_id'     => $t['user_id'],
				'track_id'   => $track_id,
				'roles'       => $roles,
				'status'      => 'active',
				'school_id'   => $school_id,
				'district_id' => $did,
			) );
			$enrollments['teachers'][ $idx ] = array(
				'enrollment_id'  => $eid,
				'user_id'        => $t['user_id'],
				'school_key'     => $t['school_key'],
				'classroom_name' => $t['classroom_name'],
				'is_lead'        => $t['is_lead'],
			);
			$enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $t['user_id'], 'role' => 'teacher' );
		}

		// School leaders (non-teacher).
		foreach ( $users['school_leaders'] as $cl ) {
			$school_id = $c[ $cl['school_key'] ];
			$eid = $repo->create( array(
				'user_id'     => $cl['user_id'],
				'track_id'   => $track_id,
				'roles'       => array( 'school_leader' ),
				'status'      => 'active',
				'school_id'   => $school_id,
				'district_id' => $did,
			) );
			$enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $cl['user_id'], 'role' => 'school_leader' );
		}

		// Mentors.
		foreach ( $users['mentors'] as $m ) {
			$school_id = $c[ $m['school_key'] ];
			$eid = $repo->create( array(
				'user_id'     => $m['user_id'],
				'track_id'   => $track_id,
				'roles'       => array( 'mentor' ),
				'status'      => 'active',
				'school_id'   => $school_id,
				'district_id' => $did,
			) );
			$enrollments['mentors'][] = array( 'enrollment_id' => $eid, 'user_id' => $m['user_id'], 'school_key' => $m['school_key'] );
			$enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $m['user_id'], 'role' => 'mentor' );
		}

		// District leader.
		$uid = $users['district_leader'];
		$eid = $repo->create( array(
			'user_id'     => $uid,
			'track_id'   => $track_id,
			'roles'       => array( 'district_leader' ),
			'status'      => 'active',
			'district_id' => $did,
		) );
		$enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader' );

		WP_CLI::log( '  [6/17] Enrollments created: ' . count( $enrollments['all'] ) );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 7: Teams
	// ------------------------------------------------------------------

	private function seed_teams( $track_id, $orgunits, $enrollments ) {
		$svc       = new HL_Team_Service();
		$c         = $orgunits['schools'];
		$team_ids  = array();
		$mem_count = 0;

		// WPB Team Alpha: mentor 1, first 13 WPB teachers (indices 0-12).
		$team_id = $svc->create_team( array( 'team_name' => 'WPB Team Alpha', 'track_id' => $track_id, 'school_id' => $c['wpb'] ) );
		if ( ! is_wp_error( $team_id ) ) {
			$team_ids['wpb_alpha'] = $team_id;
			$svc->add_member( $team_id, $enrollments['mentors'][0]['enrollment_id'], 'mentor' );
			$mem_count++;
			for ( $i = 0; $i < 13 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
				if ( $enrollments['teachers'][ $i ]['school_key'] === 'wpb' ) {
					$svc->add_member( $team_id, $enrollments['teachers'][ $i ]['enrollment_id'], 'member' );
					$mem_count++;
				}
			}
		}

		// WPB Team Beta: mentor 2, remaining WPB teachers (indices 13-24).
		$team_id = $svc->create_team( array( 'team_name' => 'WPB Team Beta', 'track_id' => $track_id, 'school_id' => $c['wpb'] ) );
		if ( ! is_wp_error( $team_id ) ) {
			$team_ids['wpb_beta'] = $team_id;
			$svc->add_member( $team_id, $enrollments['mentors'][1]['enrollment_id'], 'mentor' );
			$mem_count++;
			for ( $i = 13; $i < 25 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
				if ( $enrollments['teachers'][ $i ]['school_key'] === 'wpb' ) {
					$svc->add_member( $team_id, $enrollments['teachers'][ $i ]['enrollment_id'], 'member' );
					$mem_count++;
				}
			}
		}

		// Jupiter Team: mentor 3, all Jupiter teachers (indices 25-31).
		$team_id = $svc->create_team( array( 'team_name' => 'Jupiter Team', 'track_id' => $track_id, 'school_id' => $c['jupiter'] ) );
		if ( ! is_wp_error( $team_id ) ) {
			$team_ids['jupiter'] = $team_id;
			$svc->add_member( $team_id, $enrollments['mentors'][2]['enrollment_id'], 'mentor' );
			$mem_count++;
			for ( $i = 25; $i < 32 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
				if ( $enrollments['teachers'][ $i ]['school_key'] === 'jupiter' ) {
					$svc->add_member( $team_id, $enrollments['teachers'][ $i ]['enrollment_id'], 'member' );
					$mem_count++;
				}
			}
		}

		// South Bay Team: mentor 4, all South Bay teachers (indices 32-37).
		$team_id = $svc->create_team( array( 'team_name' => 'South Bay Team', 'track_id' => $track_id, 'school_id' => $c['south_bay'] ) );
		if ( ! is_wp_error( $team_id ) ) {
			$team_ids['south_bay'] = $team_id;
			$svc->add_member( $team_id, $enrollments['mentors'][3]['enrollment_id'], 'mentor' );
			$mem_count++;
			for ( $i = 32; $i < 38 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
				if ( $enrollments['teachers'][ $i ]['school_key'] === 'south_bay' ) {
					$svc->add_member( $team_id, $enrollments['teachers'][ $i ]['enrollment_id'], 'member' );
					$mem_count++;
				}
			}
		}

		WP_CLI::log( '  [7/17] Teams created: ' . count( $team_ids ) . " (with {$mem_count} memberships)" );
		return $team_ids;
	}

	// ------------------------------------------------------------------
	// Step 8: Teaching Assignments
	// ------------------------------------------------------------------

	private function seed_teaching_assignments( $enrollments, $classrooms ) {
		// Suppress auto-generation of child assessment instances during seeding.
		// The seeder creates instances explicitly with proper activity_id, phase,
		// and instrument_id values.
		remove_all_actions( 'hl_core_teaching_assignment_changed' );

		$svc   = new HL_Classroom_Service();
		$count = 0;

		foreach ( $enrollments['teachers'] as $t ) {
			$key = $t['school_key'] . '::' . $t['classroom_name'];
			if ( ! isset( $classrooms[ $key ] ) ) {
				WP_CLI::warning( "Classroom not found for key: {$key}" );
				continue;
			}
			$result = $svc->create_teaching_assignment( array(
				'enrollment_id'   => $t['enrollment_id'],
				'classroom_id'    => $classrooms[ $key ]['classroom_id'],
				'is_lead_teacher' => $t['is_lead'] ? 1 : 0,
			) );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		WP_CLI::log( "  [8/17] Teaching assignments created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 9: Children
	// ------------------------------------------------------------------

	private function seed_children( $classrooms, $orgunits ) {
		$repo  = new HL_Child_Repository();
		$svc   = new HL_Classroom_Service();
		$total = 0;

		foreach ( $this->get_children_defs() as $ch ) {
			// [first_name, last_initial, classroom_name, school_key, dob_serial, gender, ethnicity]
			$school_id = $orgunits['schools'][ $ch[3] ];
			$dob       = $this->excel_date_to_ymd( $ch[4] );
			$metadata  = wp_json_encode( array( 'gender' => $ch[5], 'ethnicity' => $ch[6] ) );

			$child_id = $repo->create( array(
				'first_name' => $ch[0],
				'last_name'  => $ch[1],
				'dob'        => $dob,
				'school_id'  => $school_id,
				'metadata'   => $metadata,
			) );

			if ( $child_id ) {
				$cr_key = $ch[3] . '::' . $ch[2];
				if ( isset( $classrooms[ $cr_key ] ) ) {
					$svc->assign_child_to_classroom( $child_id, $classrooms[ $cr_key ]['classroom_id'], 'Palm Beach seed initial assignment' );
				}
				$total++;
			}
		}

		WP_CLI::log( "  [9/17] Children created and assigned: {$total}" );
	}

	// ------------------------------------------------------------------
	// Step 10: Pathways & Activities
	// ------------------------------------------------------------------

	private function seed_pathways( $track_id, $instruments ) {
		$svc = new HL_Pathway_Service();

		// --- Teacher Pathway ---
		$tp_id = $svc->create_pathway( array(
			'pathway_name'  => 'Teacher Pathway',
			'track_id'     => $track_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$ta = array();
		$ta['ld_course'] = $svc->create_activity( array( 'title' => 'Foundations of Early Learning', 'pathway_id' => $tp_id, 'track_id' => $track_id, 'activity_type' => 'learndash_course', 'weight' => 2.0, 'ordering_hint' => 1, 'external_ref' => wp_json_encode( array( 'course_id' => 99901 ) ) ) );
		$ta['pre_self']  = $svc->create_activity( array( 'title' => 'Pre Self-Assessment', 'pathway_id' => $tp_id, 'track_id' => $track_id, 'activity_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 2, 'external_ref' => wp_json_encode( array( 'teacher_instrument_id' => $instruments['teacher_b2e_pre'], 'phase' => 'pre' ) ) ) );
		$ta['post_self'] = $svc->create_activity( array( 'title' => 'Post Self-Assessment', 'pathway_id' => $tp_id, 'track_id' => $track_id, 'activity_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 3, 'external_ref' => wp_json_encode( array( 'teacher_instrument_id' => $instruments['teacher_b2e_post'], 'phase' => 'post' ) ) ) );
		$ta['children']  = $svc->create_activity( array( 'title' => 'Child Assessment', 'pathway_id' => $tp_id, 'track_id' => $track_id, 'activity_type' => 'child_assessment', 'weight' => 2.0, 'ordering_hint' => 4, 'external_ref' => wp_json_encode( array( 'instrument_id' => $instruments['infant'] ) ) ) );
		$ta['coaching']  = $svc->create_activity( array( 'title' => 'Coaching Attendance', 'pathway_id' => $tp_id, 'track_id' => $track_id, 'activity_type' => 'coaching_session_attendance', 'weight' => 1.0, 'ordering_hint' => 5, 'external_ref' => wp_json_encode( (object) array() ) ) );

		// --- Mentor Pathway ---
		$mp_id = $svc->create_pathway( array(
			'pathway_name'  => 'Mentor Pathway',
			'track_id'     => $track_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$ma = array();
		$ma['ld_course']    = $svc->create_activity( array( 'title' => 'Mentor Training Course', 'pathway_id' => $mp_id, 'track_id' => $track_id, 'activity_type' => 'learndash_course', 'weight' => 2.0, 'ordering_hint' => 1, 'external_ref' => wp_json_encode( array( 'course_id' => 99902 ) ) ) );
		$ma['observation']  = $svc->create_activity( array( 'title' => 'Teacher Observations', 'pathway_id' => $mp_id, 'track_id' => $track_id, 'activity_type' => 'observation', 'weight' => 1.0, 'ordering_hint' => 2, 'external_ref' => wp_json_encode( array( 'form_plugin' => 'jetformbuilder', 'form_id' => 99903, 'required_count' => 2 ) ) ) );

		WP_CLI::log( '  [10/17] Pathways created: 2 (teacher=' . count( $ta ) . ' activities, mentor=' . count( $ma ) . ' activities)' );

		return array( 'teacher_pathway_id' => $tp_id, 'mentor_pathway_id' => $mp_id, 'teacher_activities' => $ta, 'mentor_activities' => $ma );
	}

	// ------------------------------------------------------------------
	// Step 11: Assign Pathways
	// ------------------------------------------------------------------

	private function assign_pathways( $enrollments, $pathways ) {
		$repo  = new HL_Enrollment_Repository();
		$count = 0;

		foreach ( $enrollments['all'] as $e ) {
			$pathway_id = null;
			if ( $e['role'] === 'teacher' ) {
				$pathway_id = $pathways['teacher_pathway_id'];
			} elseif ( $e['role'] === 'mentor' ) {
				$pathway_id = $pathways['mentor_pathway_id'];
			}
			if ( $pathway_id ) {
				$repo->update( $e['enrollment_id'], array( 'assigned_pathway_id' => $pathway_id ) );
				$count++;
			}
		}

		WP_CLI::log( "  [11/17] Pathway assignments updated: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 12: Prereq Rules
	// ------------------------------------------------------------------

	private function seed_prereq_rules( $pathways ) {
		global $wpdb;
		$ta = $pathways['teacher_activities'];

		// ALL_OF: Post Self requires Pre Self.
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array( 'activity_id' => $ta['post_self'], 'prereq_type' => 'all_of' ) );
		$gid = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['pre_self'] ) );

		// ANY_OF: Children requires LD course OR Pre Self.
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array( 'activity_id' => $ta['children'], 'prereq_type' => 'any_of' ) );
		$gid = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['ld_course'] ) );
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['pre_self'] ) );

		// N_OF_M: Coaching requires 2 of 3.
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array( 'activity_id' => $ta['coaching'], 'prereq_type' => 'n_of_m', 'n_required' => 2 ) );
		$gid = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['ld_course'] ) );
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['pre_self'] ) );
		$wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array( 'group_id' => $gid, 'prerequisite_activity_id' => $ta['children'] ) );

		WP_CLI::log( '  [12/17] Prereq rules created: ALL_OF, ANY_OF, N_OF_M' );
	}

	// ------------------------------------------------------------------
	// Step 13: Drip Rules
	// ------------------------------------------------------------------

	private function seed_drip_rules( $pathways ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'hl_activity_drip_rule', array(
			'activity_id'     => $pathways['teacher_activities']['post_self'],
			'drip_type'       => 'fixed_date',
			'release_at_date' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
		) );
		WP_CLI::log( '  [13/17] Drip rule created: Post Self-Assessment released 30 days ago' );
	}

	// ------------------------------------------------------------------
	// Step 14: Activity States
	// ------------------------------------------------------------------

	private function seed_activity_states( $enrollments, $pathways ) {
		global $wpdb;
		$ta    = $pathways['teacher_activities'];
		$ma    = $pathways['mentor_activities'];
		$now   = current_time( 'mysql', true );
		$count = 0;

		$insert_state = function( $enrollment_id, $activity_id, $percent, $status, $completed_at = null ) use ( $wpdb, $now, &$count ) {
			$wpdb->insert( $wpdb->prefix . 'hl_activity_state', array(
				'enrollment_id'      => $enrollment_id,
				'activity_id'        => $activity_id,
				'completion_percent' => $percent,
				'completion_status'  => $status,
				'completed_at'       => $completed_at,
				'last_computed_at'   => $now,
			) );
			$count++;
		};

		// First 6 WPB teachers (indices 0-5): LD course + Pre Self done.
		for ( $i = 0; $i < 6 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
			$eid = $enrollments['teachers'][ $i ]['enrollment_id'];
			$insert_state( $eid, $ta['ld_course'], 100, 'complete', $now );
			$insert_state( $eid, $ta['pre_self'], 100, 'complete', $now );
		}

		// Next 6 WPB teachers (indices 6-11): LD course done only.
		for ( $i = 6; $i < 12 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
			$eid = $enrollments['teachers'][ $i ]['enrollment_id'];
			$insert_state( $eid, $ta['ld_course'], 100, 'complete', $now );
		}

		// Jupiter teachers 1-3 (indices 25-27): LD course done.
		for ( $i = 25; $i < 28 && isset( $enrollments['teachers'][ $i ] ); $i++ ) {
			$eid = $enrollments['teachers'][ $i ]['enrollment_id'];
			$insert_state( $eid, $ta['ld_course'], 100, 'complete', $now );
		}

		// Mentor 1: LD course done.
		if ( ! empty( $enrollments['mentors'][0] ) ) {
			$insert_state( $enrollments['mentors'][0]['enrollment_id'], $ma['ld_course'], 100, 'complete', $now );
		}

		WP_CLI::log( "  [14/17] Activity states created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 15: Completion Rollups
	// ------------------------------------------------------------------

	private function seed_rollups( $enrollments ) {
		$reporting = HL_Reporting_Service::instance();
		$count     = 0;
		$errors    = 0;

		foreach ( $enrollments['all'] as $e ) {
			$result = $reporting->compute_rollups( $e['enrollment_id'] );
			if ( is_wp_error( $result ) ) {
				$errors++;
			} else {
				$count++;
			}
		}

		WP_CLI::log( "  [15/17] Completion rollups computed: {$count}" . ( $errors ? " ({$errors} errors)" : '' ) );
	}

	// ------------------------------------------------------------------
	// Step 16: Coach Assignments
	// ------------------------------------------------------------------

	private function seed_coach_assignments( $track_id, $orgunits, $teams, $users ) {
		$service       = new HL_Coach_Assignment_Service();
		$c             = $orgunits['schools'];
		$coach_user_id = $users['coach'];
		$count         = 0;

		if ( ! $coach_user_id ) {
			WP_CLI::warning( 'No coach user found, skipping coach assignments.' );
			return;
		}

		// School-level: WPB, Jupiter, South Bay.
		foreach ( array( 'wpb', 'jupiter', 'south_bay' ) as $ck ) {
			$result = $service->assign_coach( array(
				'coach_user_id'  => $coach_user_id,
				'scope_type'     => 'school',
				'scope_id'       => $c[ $ck ],
				'track_id'      => $track_id,
				'effective_from' => '2026-01-01',
			) );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		// Team-level override for WPB Team Alpha.
		if ( ! empty( $teams['wpb_alpha'] ) ) {
			$result = $service->assign_coach( array(
				'coach_user_id'  => $coach_user_id,
				'scope_type'     => 'team',
				'scope_id'       => $teams['wpb_alpha'],
				'track_id'      => $track_id,
				'effective_from' => '2026-01-15',
			) );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		WP_CLI::log( "  [16/17] Coach assignments created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 17: Coaching Sessions
	// ------------------------------------------------------------------

	private function seed_coaching_sessions( $track_id, $enrollments, $users ) {
		$service       = new HL_Coaching_Service();
		$coach_user_id = $users['coach'];
		$count         = 0;

		if ( ! $coach_user_id ) {
			WP_CLI::warning( 'No coach user found, skipping coaching sessions.' );
			return;
		}

		// WPB teacher 1 (index 0): attended session (past).
		if ( isset( $enrollments['teachers'][0] ) ) {
			$result = $service->create_session( array(
				'track_id'            => $track_id,
				'mentor_enrollment_id' => $enrollments['teachers'][0]['enrollment_id'],
				'coach_user_id'        => $coach_user_id,
				'session_title'        => 'Coaching Session 1',
				'meeting_url'          => 'https://zoom.us/j/pb-1',
				'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days 10:00' ) ),
			) );
			if ( ! is_wp_error( $result ) ) {
				$service->transition_status( $result, 'attended' );
				$count++;
			}
		}

		// WPB teacher 2 (index 1): scheduled session (upcoming).
		if ( isset( $enrollments['teachers'][1] ) ) {
			$result = $service->create_session( array(
				'track_id'            => $track_id,
				'mentor_enrollment_id' => $enrollments['teachers'][1]['enrollment_id'],
				'coach_user_id'        => $coach_user_id,
				'session_title'        => 'Coaching Session 1',
				'meeting_url'          => 'https://zoom.us/j/pb-2',
				'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 14:00' ) ),
			) );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		// WPB teacher 3 (index 2): missed session (past).
		if ( isset( $enrollments['teachers'][2] ) ) {
			$result = $service->create_session( array(
				'track_id'            => $track_id,
				'mentor_enrollment_id' => $enrollments['teachers'][2]['enrollment_id'],
				'coach_user_id'        => $coach_user_id,
				'session_title'        => 'Coaching Session 1',
				'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days 09:00' ) ),
			) );
			if ( ! is_wp_error( $result ) ) {
				$service->transition_status( $result, 'missed' );
				$count++;
			}
		}

		// Jupiter teacher 1 (index 25): scheduled session (upcoming).
		if ( isset( $enrollments['teachers'][25] ) ) {
			$result = $service->create_session( array(
				'track_id'            => $track_id,
				'mentor_enrollment_id' => $enrollments['teachers'][25]['enrollment_id'],
				'coach_user_id'        => $coach_user_id,
				'session_title'        => 'Coaching Session 1',
				'meeting_url'          => 'https://zoom.us/j/pb-jup-1',
				'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '+10 days 15:00' ) ),
			) );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		// South Bay teacher 1 (index 32): attended session (past).
		if ( isset( $enrollments['teachers'][32] ) ) {
			$result = $service->create_session( array(
				'track_id'            => $track_id,
				'mentor_enrollment_id' => $enrollments['teachers'][32]['enrollment_id'],
				'coach_user_id'        => $coach_user_id,
				'session_title'        => 'Coaching Session 1',
				'meeting_url'          => 'https://zoom.us/j/pb-sb-1',
				'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days 11:00' ) ),
			) );
			if ( ! is_wp_error( $result ) ) {
				$service->transition_status( $result, 'attended' );
				$count++;
			}
		}

		WP_CLI::log( "  [17/17] Coaching sessions created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Steps 18-20: Lutheran Control Group
	// ------------------------------------------------------------------

	/**
	 * Seed the Lutheran Services Florida control group.
	 *
	 * Creates a cohort (container), a control track, assessment-only pathway,
	 * control participants, and submitted PRE assessments.
	 *
	 * @param int   $program_track_id The Palm Beach program track ID.
	 * @param array $instruments       Instrument IDs keyed by type.
	 * @return array|null Control group data or null on failure.
	 */
	private function seed_control_group( $program_track_id, $instruments ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Ensure is_control_group column exists on hl_track.
		$col_check = $wpdb->get_var( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			$prefix . 'hl_track',
			'is_control_group'
		) );
		if ( ! $col_check ) {
			WP_CLI::log( '  Running schema migration for is_control_group column...' );
			HL_Installer::create_tables();
		}

		// Step 18: Create cohort (container).
		$cohort_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT cohort_id FROM {$prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
			'B2E-EVAL'
		) );

		if ( ! $cohort_id ) {
			$wpdb->insert( $prefix . 'hl_cohort', array(
				'cohort_uuid' => wp_generate_uuid4(),
				'cohort_name' => 'B2E Program Evaluation',
				'cohort_code' => 'B2E-EVAL',
				'status'      => 'active',
			) );
			$cohort_id = $wpdb->insert_id;
		}

		if ( ! $cohort_id ) {
			WP_CLI::log( '  [18/20] ERROR: Could not create cohort (container). DB error: ' . $wpdb->last_error );
			return null;
		}

		// Assign the ELC Palm Beach track to this cohort.
		$wpdb->update(
			$prefix . 'hl_track',
			array( 'cohort_id' => $cohort_id ),
			array( 'track_id' => $program_track_id )
		);

		// Step 19: Create the Lutheran control track.
		$control_track_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT track_id FROM {$prefix}hl_track WHERE track_code = %s LIMIT 1",
			'LSF-CTRL-2026'
		) );

		if ( ! $control_track_id ) {
			$repo = new HL_Track_Repository();
			$control_track_id = $repo->create( array(
				'track_name'       => 'Lutheran Services Florida — Control Group',
				'track_code'       => 'LSF-CTRL-2026',
				'is_control_group' => 1,
				'cohort_id'        => $cohort_id,
				'status'           => 'active',
				'start_date'       => '2026-01-01',
				'end_date'         => '2026-12-31',
			) );
		}

		if ( ! $control_track_id ) {
			WP_CLI::log( '  [19/20] ERROR: Could not create control track. DB error: ' . $wpdb->last_error );
			return null;
		}

		WP_CLI::log( "  [18/20] Cohort id={$cohort_id} (B2E-EVAL), control track id={$control_track_id} (LSF-CTRL-2026)" );

		// Create assessment-only pathway.
		$svc = new HL_Pathway_Service();

		$ctrl_pathway_id = $svc->create_pathway( array(
			'pathway_name'  => 'Control Assessment Pathway',
			'track_id'     => $control_track_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$teacher_pre_instrument_id  = isset( $instruments['teacher_b2e_pre'] ) ? $instruments['teacher_b2e_pre'] : 0;
		$teacher_post_instrument_id = isset( $instruments['teacher_b2e_post'] ) ? $instruments['teacher_b2e_post'] : 0;

		$pre_activity_id = $svc->create_activity( array(
			'title'         => 'Pre Self-Assessment',
			'pathway_id'    => $ctrl_pathway_id,
			'track_id'     => $control_track_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 1,
			'external_ref'  => wp_json_encode( array(
				'teacher_instrument_id' => $teacher_pre_instrument_id,
				'phase'                 => 'pre',
			) ),
		) );

		$svc->create_activity( array(
			'title'         => 'Post Self-Assessment',
			'pathway_id'    => $ctrl_pathway_id,
			'track_id'     => $control_track_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 2,
			'external_ref'  => wp_json_encode( array(
				'teacher_instrument_id' => $teacher_post_instrument_id,
				'phase'                 => 'post',
			) ),
		) );

		WP_CLI::log( "  [19/20] Control pathway id={$ctrl_pathway_id} (PRE + POST self-assessment)" );

		// Step 20: Seed control participants (Lutheran teachers).
		$enrollment_repo = new HL_Enrollment_Repository();
		$ctrl_enrollment_ids = array();

		// Real LSF school info from spreadsheet: 6 staff were to participate.
		$ctrl_teachers = array(
			array( 'name' => 'Maria Santos',    'email' => 'maria.santos@lsfnet.org' ),
			array( 'name' => 'Angela Roberts',  'email' => 'angela.roberts@lsfnet.org' ),
			array( 'name' => 'Denise Williams', 'email' => 'denise.williams@lsfnet.org' ),
			array( 'name' => 'Keisha Brown',    'email' => 'keisha.brown@lsfnet.org' ),
			array( 'name' => 'Sandra Lopez',    'email' => 'sandra.lopez@lsfnet.org' ),
			array( 'name' => 'Tamika Johnson',  'email' => 'tamika.johnson@lsfnet.org' ),
		);

		foreach ( $ctrl_teachers as $teacher ) {
			$parts = explode( ' ', $teacher['name'], 2 );
			$uid   = $this->create_demo_user( $teacher['email'], $parts[0], isset( $parts[1] ) ? $parts[1] : '', 'subscriber' );
			if ( ! $uid ) {
				continue;
			}

			$eid = $enrollment_repo->create( array(
				'user_id'             => $uid,
				'track_id'           => $control_track_id,
				'roles'               => array( 'teacher' ),
				'status'              => 'active',
				'assigned_pathway_id' => $ctrl_pathway_id,
			) );
			if ( $eid ) {
				$ctrl_enrollment_ids[] = $eid;
			}
		}

		// Get PRE instrument sections for generating plausible responses.
		$instrument_sections = array();
		if ( $teacher_pre_instrument_id ) {
			$sections_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT sections FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_id = %d",
				$teacher_pre_instrument_id
			) );
			if ( $sections_json ) {
				$instrument_sections = json_decode( $sections_json, true ) ?: array();
			}
		}

		// Submit PRE assessments for all control participants.
		$now = current_time( 'mysql' );
		$pre_submitted = 0;

		foreach ( $ctrl_enrollment_ids as $eid ) {
			$responses = $this->generate_control_responses( $instrument_sections );

			$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'      => wp_generate_uuid4(),
				'track_id'          => $control_track_id,
				'enrollment_id'      => $eid,
				'phase'              => 'pre',
				'instrument_id'      => $teacher_pre_instrument_id,
				'instrument_version' => '1.0',
				'status'             => 'submitted',
				'submitted_at'       => $now,
				'responses_json'     => wp_json_encode( $responses ),
			) );

			$wpdb->insert( $prefix . 'hl_activity_state', array(
				'enrollment_id'      => $eid,
				'activity_id'        => $pre_activity_id,
				'completion_percent' => 100,
				'completion_status'  => 'complete',
				'completed_at'       => $now,
				'last_computed_at'   => $now,
			) );

			$pre_submitted++;
		}

		// Also submit PRE assessments for the ELC program track teachers.
		$program_teacher_enrollments = $wpdb->get_results( $wpdb->prepare(
			"SELECT enrollment_id FROM {$prefix}hl_enrollment
			 WHERE track_id = %d AND status = 'active' AND roles LIKE %s",
			$program_track_id,
			'%"teacher"%'
		), ARRAY_A );

		$program_pre_count = 0;
		foreach ( $program_teacher_enrollments as $row ) {
			$eid = $row['enrollment_id'];

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT instance_id FROM {$prefix}hl_teacher_assessment_instance
				 WHERE enrollment_id = %d AND track_id = %d AND phase = 'pre'",
				$eid, $program_track_id
			) );

			if ( $existing ) {
				$status = $wpdb->get_var( $wpdb->prepare(
					"SELECT status FROM {$prefix}hl_teacher_assessment_instance WHERE instance_id = %d",
					$existing
				) );
				if ( $status !== 'submitted' ) {
					$responses = $this->generate_program_responses( $instrument_sections );
					$wpdb->update(
						$prefix . 'hl_teacher_assessment_instance',
						array(
							'status'         => 'submitted',
							'submitted_at'   => $now,
							'responses_json' => wp_json_encode( $responses ),
						),
						array( 'instance_id' => $existing )
					);
					$program_pre_count++;
				}
				continue;
			}

			$responses = $this->generate_program_responses( $instrument_sections );
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'      => wp_generate_uuid4(),
				'track_id'          => $program_track_id,
				'enrollment_id'      => $eid,
				'phase'              => 'pre',
				'instrument_id'      => $teacher_pre_instrument_id,
				'instrument_version' => '1.0',
				'status'             => 'submitted',
				'submitted_at'       => $now,
				'responses_json'     => wp_json_encode( $responses ),
			) );
			$program_pre_count++;
		}

		// Compute rollups for control enrollments.
		$reporting = HL_Reporting_Service::instance();
		foreach ( $ctrl_enrollment_ids as $eid ) {
			$reporting->compute_rollups( $eid );
		}

		WP_CLI::log( "  [20/20] Control participants: " . count( $ctrl_enrollment_ids )
			. ", control PRE submitted: {$pre_submitted}"
			. ", program PRE submitted: {$program_pre_count}" );

		return array(
			'cohort_id'         => $cohort_id,
			'track_id'          => $control_track_id,
			'participant_count' => count( $ctrl_enrollment_ids ),
		);
	}

	/**
	 * Generate plausible control group PRE assessment responses.
	 * Control group baselines tend to be slightly lower than program.
	 */
	private function generate_control_responses( $sections_def ) {
		$responses = array();
		foreach ( $sections_def as $section ) {
			$section_key = isset( $section['section_key'] ) ? $section['section_key'] : '';
			$items       = isset( $section['items'] ) ? $section['items'] : array();
			$scale_key   = isset( $section['scale_key'] ) ? $section['scale_key'] : 'likert_5';

			$range = $this->get_response_range( $scale_key, 'control' );
			foreach ( $items as $item ) {
				$item_key = isset( $item['key'] ) ? $item['key'] : '';
				if ( $item_key !== '' ) {
					$responses[ $section_key ][ $item_key ] = wp_rand( $range['min'], $range['max'] );
				}
			}
		}
		return $responses;
	}

	/**
	 * Generate plausible program group PRE assessment responses.
	 * Program group baselines tend to be slightly higher.
	 */
	private function generate_program_responses( $sections_def ) {
		$responses = array();
		foreach ( $sections_def as $section ) {
			$section_key = isset( $section['section_key'] ) ? $section['section_key'] : '';
			$items       = isset( $section['items'] ) ? $section['items'] : array();
			$scale_key   = isset( $section['scale_key'] ) ? $section['scale_key'] : 'likert_5';

			$range = $this->get_response_range( $scale_key, 'program' );
			foreach ( $items as $item ) {
				$item_key = isset( $item['key'] ) ? $item['key'] : '';
				if ( $item_key !== '' ) {
					$responses[ $section_key ][ $item_key ] = wp_rand( $range['min'], $range['max'] );
				}
			}
		}
		return $responses;
	}

	/**
	 * Get min/max response range for a scale + profile.
	 */
	private function get_response_range( $scale_key, $profile ) {
		$ranges = array(
			'likert_5' => array(
				'control' => array( 'min' => 2, 'max' => 4 ),
				'program' => array( 'min' => 2, 'max' => 5 ),
			),
			'likert_7' => array(
				'control' => array( 'min' => 3, 'max' => 5 ),
				'program' => array( 'min' => 3, 'max' => 6 ),
			),
			'scale_0_10' => array(
				'control' => array( 'min' => 4, 'max' => 7 ),
				'program' => array( 'min' => 4, 'max' => 8 ),
			),
		);
		if ( isset( $ranges[ $scale_key ][ $profile ] ) ) {
			return $ranges[ $scale_key ][ $profile ];
		}
		return array( 'min' => 2, 'max' => 4 );
	}

}
