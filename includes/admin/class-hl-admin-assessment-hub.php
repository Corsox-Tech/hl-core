<?php if (!defined('ABSPATH')) exit;

/**
 * Assessment Hub — Unified Admin Page
 *
 * Merges Assessments and Instruments into a single admin page
 * with vertical sidebar navigation.
 *
 * Sidebar sections:
 *   Assessment Data > Teacher Assessments, Child Assessments
 *   Instruments     > Child Instruments, Teacher Instruments
 *
 * @package HL_Core
 */
class HL_Admin_Assessment_Hub {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Dispatch early actions (POST/redirect) before HTML output.
     */
    public function handle_early_actions() {
        $section = $this->get_current_section();

        switch ($section) {
            case 'teacher-assessments':
            case 'child-assessments':
                HL_Admin_Assessments::instance()->handle_early_actions();
                break;

            case 'child-instruments':
            case 'teacher-instruments':
                HL_Admin_Instruments::instance()->handle_early_actions();
                break;

            case 'course-surveys':
                if ( class_exists( 'HL_Admin_Survey' ) ) {
                    HL_Admin_Survey::instance()->handle_early_actions();
                }
                break;
        }
    }

    /**
     * Main render entry point.
     */
    public function render_page() {
        $section = $this->get_current_section();
        $action  = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        echo '<div class="wrap hl-admin-wrap">';
        HL_Admin::render_page_header(__('Assessments', 'hl-core'));

        // Detail views render without the sidebar layout
        if ($this->is_detail_view($section, $action)) {
            $this->render_detail_view($section, $action);
            echo '</div>';
            return;
        }

        // Sidebar layout
        echo '<div class="hl-sidebar-layout">';
        $this->render_sidebar($section);
        echo '<div class="hl-sidebar-content">';
        $this->render_section_content($section);
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Get current section from URL.
     */
    private function get_current_section() {
        return isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'teacher-assessments';
    }

    /**
     * Check if current view is a detail page.
     */
    private function is_detail_view($section, $action) {
        if (in_array($action, array('view_teacher', 'view_children'), true)) {
            return true;
        }
        if (in_array($section, array('child-instruments', 'teacher-instruments', 'course-surveys'), true)) {
            if (in_array($action, array('new', 'edit', 'preview'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the vertical sidebar navigation.
     */
    private function render_sidebar($active_section) {
        $base_url = admin_url('admin.php?page=hl-assessment-hub');

        $sections = array(
            array(
                'group' => __('Assessment Data', 'hl-core'),
                'items' => array(
                    'teacher-assessments' => array(
                        'label' => __('Teacher Assessments', 'hl-core'),
                        'icon'  => 'dashicons-welcome-learn-more',
                    ),
                    'child-assessments'   => array(
                        'label' => __('Child Assessments', 'hl-core'),
                        'icon'  => 'dashicons-groups',
                    ),
                ),
            ),
            array(
                'group' => __('Instruments', 'hl-core'),
                'items' => array(
                    'child-instruments'   => array(
                        'label' => __('Child Instruments', 'hl-core'),
                        'icon'  => 'dashicons-clipboard',
                    ),
                    'teacher-instruments' => array(
                        'label' => __('Teacher Instruments', 'hl-core'),
                        'icon'  => 'dashicons-edit-large',
                    ),
                    'course-surveys'     => array(
                        'label' => __('Course Surveys', 'hl-core'),
                        'icon'  => 'dashicons-forms',
                    ),
                ),
            ),
        );

        echo '<nav class="hl-sidebar-nav">';
        foreach ($sections as $group) {
            echo '<div class="hl-sidebar-group">';
            echo '<div class="hl-sidebar-group-label">' . esc_html($group['group']) . '</div>';
            foreach ($group['items'] as $slug => $item) {
                $url = add_query_arg('section', $slug, $base_url);
                $class = 'hl-sidebar-item' . ($slug === $active_section ? ' active' : '');
                echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">';
                echo '<span class="dashicons ' . esc_attr($item['icon']) . '"></span>';
                echo esc_html($item['label']);
                echo '</a>';
            }
            echo '</div>';
        }
        echo '</nav>';
    }

    /**
     * Render the content for the active section.
     */
    private function render_section_content($section) {
        switch ($section) {
            case 'teacher-assessments':
                HL_Admin_Assessments::instance()->render_teacher_section();
                break;

            case 'child-assessments':
                HL_Admin_Assessments::instance()->render_child_section();
                break;

            case 'child-instruments':
                HL_Admin_Instruments::instance()->render_children_tab(
                    isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list'
                );
                break;

            case 'teacher-instruments':
                HL_Admin_Instruments::instance()->render_teacher_tab(
                    isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list'
                );
                break;

            case 'course-surveys':
                if ( class_exists( 'HL_Admin_Survey' ) ) {
                    HL_Admin_Survey::instance()->render_tab();
                }
                break;
        }
    }

    /**
     * Render a detail view (without sidebar layout).
     */
    private function render_detail_view($section, $action) {
        if (in_array($action, array('view_teacher', 'view_children'), true)) {
            $instance_id = isset($_GET['instance_id']) ? absint($_GET['instance_id']) : 0;
            if ($action === 'view_teacher') {
                HL_Admin_Assessments::instance()->render_teacher_detail_page($instance_id);
            } else {
                HL_Admin_Assessments::instance()->render_child_detail_page($instance_id);
            }
        } elseif (in_array($section, array('child-instruments', 'teacher-instruments'), true)) {
            if ($section === 'child-instruments') {
                HL_Admin_Instruments::instance()->render_children_tab($action);
            } else {
                HL_Admin_Instruments::instance()->render_teacher_tab($action);
            }
        } elseif ($section === 'course-surveys' && class_exists('HL_Admin_Survey')) {
            HL_Admin_Survey::instance()->render_tab();
        }
    }
}
