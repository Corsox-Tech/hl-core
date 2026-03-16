<?php
if (!defined('ABSPATH')) exit;

class HL_REST_API {

    private static $instance = null;
    private $namespace = 'hl-core/v1';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        // Partnerships (run-level entities, formerly "cohorts")
        register_rest_route($this->namespace, '/partnerships', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_partnerships'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route($this->namespace, '/partnerships/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_partnership'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // Enrollments
        register_rest_route($this->namespace, '/enrollments', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_enrollments'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // OrgUnits
        register_rest_route($this->namespace, '/orgunits', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_orgunits'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // Pathways
        register_rest_route($this->namespace, '/pathways', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_pathways'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // Teams
        register_rest_route($this->namespace, '/teams', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_teams'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
    }

    public function check_admin_permission() {
        return current_user_can('manage_hl_core');
    }

    public function get_partnerships($request) {
        $repo = new HL_Partnership_Repository();
        $partnerships = $repo->get_all();
        $data = array_map(function($p) { return $p->to_array(); }, $partnerships);
        return new WP_REST_Response($data, 200);
    }

    public function get_partnership($request) {
        $repo = new HL_Partnership_Repository();
        $partnership = $repo->get_by_id($request['id']);
        if (!$partnership) {
            return new WP_Error('not_found', 'Partnership not found', array('status' => 404));
        }
        return new WP_REST_Response($partnership->to_array(), 200);
    }

    public function get_enrollments($request) {
        $repo = new HL_Enrollment_Repository();
        $filters = array();
        if ($request->get_param('partnership_id')) {
            $filters['partnership_id'] = intval($request->get_param('partnership_id'));
        }
        $enrollments = $repo->get_all($filters);
        $data = array_map(function($e) { return $e->to_array(); }, $enrollments);
        return new WP_REST_Response($data, 200);
    }

    public function get_orgunits($request) {
        $repo = new HL_OrgUnit_Repository();
        $type = $request->get_param('type');
        $orgunits = $repo->get_all($type);
        $data = array_map(function($o) { return $o->to_array(); }, $orgunits);
        return new WP_REST_Response($data, 200);
    }

    public function get_pathways($request) {
        $repo = new HL_Pathway_Repository();
        $partnership_id = $request->get_param('partnership_id') ? intval($request->get_param('partnership_id')) : null;
        $pathways = $repo->get_all($partnership_id);
        $data = array_map(function($p) { return $p->to_array(); }, $pathways);
        return new WP_REST_Response($data, 200);
    }

    public function get_teams($request) {
        $repo = new HL_Team_Repository();
        $filters = array();
        if ($request->get_param('partnership_id')) {
            $filters['partnership_id'] = intval($request->get_param('partnership_id'));
        }
        $teams = $repo->get_all($filters);
        $data = array_map(function($t) { return $t->to_array(); }, $teams);
        return new WP_REST_Response($data, 200);
    }
}
