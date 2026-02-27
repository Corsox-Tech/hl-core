<?php
if (!defined('ABSPATH')) exit;

/**
 * Documentation System — CPT, Taxonomy, and Shortcode Rendering
 *
 * Registers the `hl_doc` custom post type and `hl_doc_category` taxonomy,
 * and provides two shortcodes:
 *   - [hl_docs]                — Full documentation browser (landing / article / glossary)
 *   - [hl_doc_link slug="..."] — Inline cross-reference link for use inside article content
 *
 * @package HL_Core
 */
class HL_Frontend_Docs {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
        add_shortcode('hl_doc_link', array($this, 'render_doc_link'));
    }

    // =========================================================================
    // CPT & Taxonomy Registration
    // =========================================================================

    public function register_post_type() {
        register_post_type('hl_doc', array(
            'labels' => array(
                'name'               => __('Documentation', 'hl-core'),
                'singular_name'      => __('Doc Article', 'hl-core'),
                'add_new'            => __('Add Article', 'hl-core'),
                'add_new_item'       => __('Add New Article', 'hl-core'),
                'edit_item'          => __('Edit Article', 'hl-core'),
                'new_item'           => __('New Article', 'hl-core'),
                'view_item'          => __('View Article', 'hl-core'),
                'search_items'       => __('Search Articles', 'hl-core'),
                'not_found'          => __('No articles found.', 'hl-core'),
                'not_found_in_trash' => __('No articles found in Trash.', 'hl-core'),
                'all_items'          => __('Doc Articles', 'hl-core'),
                'menu_name'          => __('Doc Articles', 'hl-core'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'hl-tracks',
            'supports'     => array('title', 'editor', 'revisions', 'custom-fields'),
            'menu_icon'    => 'dashicons-media-document',
            'hierarchical' => false,
            'has_archive'  => false,
            'capability_type' => 'post',
            'capabilities' => array(
                'edit_post'          => 'manage_hl_core',
                'read_post'          => 'read',
                'delete_post'        => 'manage_hl_core',
                'edit_posts'         => 'manage_hl_core',
                'edit_others_posts'  => 'manage_hl_core',
                'publish_posts'      => 'manage_hl_core',
                'read_private_posts' => 'manage_hl_core',
            ),
        ));
    }

    public function register_taxonomy() {
        register_taxonomy('hl_doc_category', 'hl_doc', array(
            'labels' => array(
                'name'          => __('Doc Categories', 'hl-core'),
                'singular_name' => __('Doc Category', 'hl-core'),
                'add_new_item'  => __('Add New Category', 'hl-core'),
                'edit_item'     => __('Edit Category', 'hl-core'),
                'search_items'  => __('Search Categories', 'hl-core'),
                'all_items'     => __('All Categories', 'hl-core'),
            ),
            'hierarchical' => true,
            'show_ui'      => true,
            'show_in_menu' => true,
            'meta_box_cb'  => 'post_categories_meta_box',
        ));
    }

    // =========================================================================
    // [hl_docs] Shortcode — Main documentation browser
    // =========================================================================

    /**
     * Render the documentation browser.
     *
     * URL routing via ?doc= query parameter:
     *   - No ?doc=        → Landing page (category grid + search)
     *   - ?doc=glossary   → Glossary page
     *   - ?doc=<slug>     → Article detail view
     *   - ?cat=<slug>     → Category article list
     *
     * @return string HTML output.
     */
    public function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view documentation.', 'hl-core') . '</div>';
        }

        $doc_slug = isset($_GET['doc']) ? sanitize_text_field($_GET['doc']) : '';
        $cat_slug = isset($_GET['cat']) ? sanitize_text_field($_GET['cat']) : '';

        // Enqueue docs-specific assets
        $this->enqueue_docs_assets();

        if ($doc_slug === 'glossary') {
            return $this->render_glossary_page();
        }

        if ($doc_slug) {
            return $this->render_article_page($doc_slug);
        }

        if ($cat_slug) {
            return $this->render_category_page($cat_slug);
        }

        return $this->render_landing_page();
    }

    /**
     * Enqueue documentation-specific CSS and JS.
     */
    private function enqueue_docs_assets() {
        wp_enqueue_style('hl-frontend-docs', HL_CORE_ASSETS_URL . 'css/frontend-docs.css', array(), HL_CORE_VERSION);
        wp_enqueue_script('hl-frontend-docs', HL_CORE_ASSETS_URL . 'js/frontend-docs.js', array(), HL_CORE_VERSION, true);

        $docs_page_url = $this->get_docs_page_url();
        wp_localize_script('hl-frontend-docs', 'hlDocsConfig', array(
            'baseUrl' => $docs_page_url,
        ));
    }

    // =========================================================================
    // Landing Page
    // =========================================================================

    private function render_landing_page() {
        $categories = get_terms(array(
            'taxonomy'   => 'hl_doc_category',
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ));

        if (is_wp_error($categories)) {
            $categories = array();
        }

        $base_url = $this->get_docs_page_url();

        // Get all articles for search data
        $all_articles = $this->get_all_articles();

        ob_start();
        ?>
        <div class="hl-docs-wrapper">
            <div class="hl-docs-landing">
                <div class="hl-docs-landing-header">
                    <h1><?php esc_html_e('Documentation', 'hl-core'); ?></h1>
                    <p class="hl-docs-subtitle"><?php esc_html_e('Learn how to use HL Core — search for a topic or browse by category.', 'hl-core'); ?></p>
                    <div class="hl-docs-search-bar">
                        <span class="dashicons dashicons-search hl-docs-search-icon"></span>
                        <input type="text" id="hl-docs-search" class="hl-docs-search-input" placeholder="<?php esc_attr_e('Search documentation...', 'hl-core'); ?>" autocomplete="off" />
                    </div>
                </div>

                <!-- Search results (hidden by default) -->
                <div id="hl-docs-search-results" class="hl-docs-search-results" style="display:none;">
                    <h2 class="hl-docs-search-results-title"><?php esc_html_e('Search Results', 'hl-core'); ?></h2>
                    <div id="hl-docs-search-results-list"></div>
                    <div id="hl-docs-search-no-results" style="display:none;">
                        <p class="hl-docs-empty"><?php esc_html_e('No articles found matching your search.', 'hl-core'); ?></p>
                    </div>
                </div>

                <!-- Category grid (visible by default) -->
                <div id="hl-docs-category-grid" class="hl-docs-category-grid">
                    <?php
                    // Separate glossary category from others
                    $regular_categories = array();
                    $glossary_category = null;

                    foreach ($categories as $cat) {
                        if ($cat->slug === 'glossary') {
                            $glossary_category = $cat;
                        } else {
                            $regular_categories[] = $cat;
                        }
                    }

                    // Category icons map
                    $cat_icons = array(
                        'getting-started'          => 'dashicons-flag',
                        'core-concepts'            => 'dashicons-admin-generic',
                        'assessments'              => 'dashicons-forms',
                        'coaching-observations'    => 'dashicons-format-chat',
                        'import-data-management'   => 'dashicons-upload',
                        'pathways-activities'      => 'dashicons-randomize',
                        'reports-exports'          => 'dashicons-chart-bar',
                    );

                    foreach ($regular_categories as $cat) :
                        $article_count = $this->count_articles_in_category($cat->term_id);
                        $icon = isset($cat_icons[$cat->slug]) ? $cat_icons[$cat->slug] : 'dashicons-media-document';
                    ?>
                        <a href="<?php echo esc_url(add_query_arg('cat', $cat->slug, $base_url)); ?>" class="hl-docs-category-card">
                            <span class="dashicons <?php echo esc_attr($icon); ?> hl-docs-category-icon"></span>
                            <h3><?php echo esc_html($cat->name); ?></h3>
                            <p class="hl-docs-article-count">
                                <?php echo esc_html(sprintf(_n('%d article', '%d articles', $article_count, 'hl-core'), $article_count)); ?>
                            </p>
                            <?php if ($cat->description) : ?>
                                <p class="hl-docs-category-desc"><?php echo esc_html($cat->description); ?></p>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Glossary link card -->
                <?php if ($glossary_category) : ?>
                    <a href="<?php echo esc_url(add_query_arg('doc', 'glossary', $base_url)); ?>" class="hl-docs-glossary-card">
                        <span class="dashicons dashicons-book-alt hl-docs-glossary-icon"></span>
                        <div>
                            <h3><?php esc_html_e('Glossary', 'hl-core'); ?></h3>
                            <p><?php esc_html_e('Key terms and definitions used throughout HL Core.', 'hl-core'); ?></p>
                        </div>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Hidden article data for JS search -->
            <script type="application/json" id="hl-docs-articles-data">
                <?php echo wp_json_encode($all_articles); ?>
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Category Page
    // =========================================================================

    private function render_category_page($cat_slug) {
        $term = get_term_by('slug', $cat_slug, 'hl_doc_category');
        if (!$term) {
            return $this->render_landing_page();
        }

        $articles = get_posts(array(
            'post_type'      => 'hl_doc',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'hl_doc_category',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ),
            ),
            'meta_key'       => 'hl_doc_sort_order',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        ));

        $base_url = $this->get_docs_page_url();

        ob_start();
        ?>
        <div class="hl-docs-wrapper">
            <div class="hl-docs-breadcrumb">
                <a href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Documentation', 'hl-core'); ?></a>
                <span class="hl-docs-breadcrumb-sep">&rsaquo;</span>
                <span><?php echo esc_html($term->name); ?></span>
            </div>

            <div class="hl-docs-category-header">
                <h1><?php echo esc_html($term->name); ?></h1>
                <?php if ($term->description) : ?>
                    <p class="hl-docs-subtitle"><?php echo esc_html($term->description); ?></p>
                <?php endif; ?>
            </div>

            <?php if (empty($articles)) : ?>
                <p class="hl-docs-empty"><?php esc_html_e('No articles in this category yet.', 'hl-core'); ?></p>
            <?php else : ?>
                <div class="hl-docs-article-list">
                    <?php foreach ($articles as $index => $article) : ?>
                        <a href="<?php echo esc_url(add_query_arg('doc', $article->post_name, $base_url)); ?>" class="hl-docs-article-list-item">
                            <span class="hl-docs-article-number"><?php echo esc_html($index + 1); ?></span>
                            <div>
                                <h3><?php echo esc_html($article->post_title); ?></h3>
                                <?php
                                $excerpt = wp_trim_words(wp_strip_all_tags($article->post_content), 25, '...');
                                if ($excerpt) :
                                ?>
                                    <p class="hl-docs-article-excerpt"><?php echo esc_html($excerpt); ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Article Page
    // =========================================================================

    private function render_article_page($slug) {
        $article = get_page_by_path($slug, OBJECT, 'hl_doc');
        if (!$article || $article->post_status !== 'publish') {
            return $this->render_not_found();
        }

        $categories = get_the_terms($article->ID, 'hl_doc_category');
        $current_cat = $categories && !is_wp_error($categories) ? $categories[0] : null;
        $base_url = $this->get_docs_page_url();

        // Get sidebar data
        $sidebar_data = $this->get_sidebar_data();

        // Get prev/next articles in same category
        $prev_next = $current_cat ? $this->get_prev_next_articles($article->ID, $current_cat->term_id) : array('prev' => null, 'next' => null);

        // Process article content — run shortcodes and apply wpautop
        $content = do_shortcode($article->post_content);
        $content = wpautop($content);

        ob_start();
        ?>
        <div class="hl-docs-wrapper">
            <div class="hl-docs-breadcrumb">
                <a href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Documentation', 'hl-core'); ?></a>
                <?php if ($current_cat) : ?>
                    <span class="hl-docs-breadcrumb-sep">&rsaquo;</span>
                    <a href="<?php echo esc_url(add_query_arg('cat', $current_cat->slug, $base_url)); ?>"><?php echo esc_html($current_cat->name); ?></a>
                <?php endif; ?>
                <span class="hl-docs-breadcrumb-sep">&rsaquo;</span>
                <span><?php echo esc_html($article->post_title); ?></span>
            </div>

            <div class="hl-docs-layout">
                <!-- Sidebar -->
                <aside class="hl-docs-sidebar" id="hl-docs-sidebar">
                    <button class="hl-docs-sidebar-close" id="hl-docs-sidebar-close" aria-label="<?php esc_attr_e('Close sidebar', 'hl-core'); ?>">&times;</button>
                    <div class="hl-docs-sidebar-search">
                        <span class="dashicons dashicons-search hl-docs-search-icon-sm"></span>
                        <input type="text" id="hl-docs-sidebar-search" class="hl-docs-sidebar-search-input" placeholder="<?php esc_attr_e('Search...', 'hl-core'); ?>" autocomplete="off" />
                    </div>

                    <nav class="hl-docs-sidebar-nav">
                        <?php foreach ($sidebar_data as $cat_data) : ?>
                            <div class="hl-docs-sidebar-category <?php echo ($current_cat && $current_cat->term_id === $cat_data['term_id']) ? 'hl-docs-sidebar-category--open' : ''; ?>">
                                <button class="hl-docs-sidebar-category-toggle" data-category="<?php echo esc_attr($cat_data['slug']); ?>">
                                    <span class="hl-docs-sidebar-arrow"></span>
                                    <?php echo esc_html($cat_data['name']); ?>
                                </button>
                                <ul class="hl-docs-sidebar-articles">
                                    <?php foreach ($cat_data['articles'] as $art) : ?>
                                        <li class="<?php echo ($art['slug'] === $slug) ? 'hl-docs-sidebar-active' : ''; ?>">
                                            <a href="<?php echo esc_url(add_query_arg('doc', $art['slug'], $base_url)); ?>"><?php echo esc_html($art['title']); ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>

                        <a href="<?php echo esc_url(add_query_arg('doc', 'glossary', $base_url)); ?>" class="hl-docs-sidebar-glossary-link">
                            <span class="dashicons dashicons-book-alt"></span> <?php esc_html_e('Glossary', 'hl-core'); ?>
                        </a>
                    </nav>
                </aside>

                <!-- Mobile sidebar toggle -->
                <button class="hl-docs-sidebar-toggle" id="hl-docs-sidebar-toggle" aria-label="<?php esc_attr_e('Toggle sidebar', 'hl-core'); ?>">
                    <span class="dashicons dashicons-menu"></span>
                </button>

                <!-- Main content -->
                <main class="hl-docs-main">
                    <article class="hl-docs-article">
                        <h1 class="hl-docs-article-title"><?php echo esc_html($article->post_title); ?></h1>

                        <!-- Auto-generated TOC placeholder -->
                        <div class="hl-docs-toc" id="hl-docs-toc"></div>

                        <div class="hl-docs-article-body" id="hl-docs-article-body">
                            <?php echo $content; ?>
                        </div>
                    </article>

                    <!-- Prev / Next navigation -->
                    <div class="hl-docs-nav">
                        <?php if ($prev_next['prev']) : ?>
                            <a href="<?php echo esc_url(add_query_arg('doc', $prev_next['prev']['slug'], $base_url)); ?>" class="hl-docs-nav-prev">
                                <span class="hl-docs-nav-label">&larr; <?php esc_html_e('Previous', 'hl-core'); ?></span>
                                <span class="hl-docs-nav-title"><?php echo esc_html($prev_next['prev']['title']); ?></span>
                            </a>
                        <?php else : ?>
                            <span></span>
                        <?php endif; ?>

                        <?php if ($prev_next['next']) : ?>
                            <a href="<?php echo esc_url(add_query_arg('doc', $prev_next['next']['slug'], $base_url)); ?>" class="hl-docs-nav-next">
                                <span class="hl-docs-nav-label"><?php esc_html_e('Next', 'hl-core'); ?> &rarr;</span>
                                <span class="hl-docs-nav-title"><?php echo esc_html($prev_next['next']['title']); ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </main>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Glossary Page
    // =========================================================================

    private function render_glossary_page() {
        $glossary_term = get_term_by('slug', 'glossary', 'hl_doc_category');
        $articles = array();

        if ($glossary_term && !is_wp_error($glossary_term)) {
            $articles = get_posts(array(
                'post_type'      => 'hl_doc',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'hl_doc_category',
                        'field'    => 'term_id',
                        'terms'    => $glossary_term->term_id,
                    ),
                ),
                'orderby' => 'title',
                'order'   => 'ASC',
            ));
        }

        // Group by first letter
        $grouped = array();
        foreach ($articles as $art) {
            $letter = strtoupper(mb_substr($art->post_title, 0, 1));
            if (!isset($grouped[$letter])) {
                $grouped[$letter] = array();
            }
            $grouped[$letter][] = $art;
        }
        ksort($grouped);

        $base_url = $this->get_docs_page_url();
        $sidebar_data = $this->get_sidebar_data();

        ob_start();
        ?>
        <div class="hl-docs-wrapper">
            <div class="hl-docs-breadcrumb">
                <a href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Documentation', 'hl-core'); ?></a>
                <span class="hl-docs-breadcrumb-sep">&rsaquo;</span>
                <span><?php esc_html_e('Glossary', 'hl-core'); ?></span>
            </div>

            <div class="hl-docs-layout">
                <!-- Sidebar -->
                <aside class="hl-docs-sidebar" id="hl-docs-sidebar">
                    <button class="hl-docs-sidebar-close" id="hl-docs-sidebar-close" aria-label="<?php esc_attr_e('Close sidebar', 'hl-core'); ?>">&times;</button>
                    <div class="hl-docs-sidebar-search">
                        <span class="dashicons dashicons-search hl-docs-search-icon-sm"></span>
                        <input type="text" id="hl-docs-sidebar-search" class="hl-docs-sidebar-search-input" placeholder="<?php esc_attr_e('Search...', 'hl-core'); ?>" autocomplete="off" />
                    </div>

                    <nav class="hl-docs-sidebar-nav">
                        <?php foreach ($sidebar_data as $cat_data) : ?>
                            <div class="hl-docs-sidebar-category">
                                <button class="hl-docs-sidebar-category-toggle" data-category="<?php echo esc_attr($cat_data['slug']); ?>">
                                    <span class="hl-docs-sidebar-arrow"></span>
                                    <?php echo esc_html($cat_data['name']); ?>
                                </button>
                                <ul class="hl-docs-sidebar-articles">
                                    <?php foreach ($cat_data['articles'] as $art) : ?>
                                        <li>
                                            <a href="<?php echo esc_url(add_query_arg('doc', $art['slug'], $base_url)); ?>"><?php echo esc_html($art['title']); ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>

                        <a href="<?php echo esc_url(add_query_arg('doc', 'glossary', $base_url)); ?>" class="hl-docs-sidebar-glossary-link hl-docs-sidebar-active">
                            <span class="dashicons dashicons-book-alt"></span> <?php esc_html_e('Glossary', 'hl-core'); ?>
                        </a>
                    </nav>
                </aside>

                <!-- Mobile sidebar toggle -->
                <button class="hl-docs-sidebar-toggle" id="hl-docs-sidebar-toggle" aria-label="<?php esc_attr_e('Toggle sidebar', 'hl-core'); ?>">
                    <span class="dashicons dashicons-menu"></span>
                </button>

                <!-- Main content -->
                <main class="hl-docs-main">
                    <div class="hl-docs-glossary">
                        <h1><?php esc_html_e('Glossary', 'hl-core'); ?></h1>

                        <?php if (empty($grouped)) : ?>
                            <p class="hl-docs-empty"><?php esc_html_e('No glossary terms have been added yet.', 'hl-core'); ?></p>
                        <?php else : ?>
                            <!-- Letter navigation -->
                            <div class="hl-docs-glossary-letters">
                                <?php foreach (range('A', 'Z') as $l) : ?>
                                    <?php if (isset($grouped[$l])) : ?>
                                        <a href="#glossary-<?php echo esc_attr($l); ?>" class="hl-docs-glossary-letter-active"><?php echo esc_html($l); ?></a>
                                    <?php else : ?>
                                        <span class="hl-docs-glossary-letter-inactive"><?php echo esc_html($l); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Definitions -->
                            <?php foreach ($grouped as $letter => $terms) : ?>
                                <div class="hl-docs-glossary-section" id="glossary-<?php echo esc_attr($letter); ?>">
                                    <h2 class="hl-docs-glossary-letter-heading"><?php echo esc_html($letter); ?></h2>
                                    <dl class="hl-docs-glossary-dl">
                                        <?php foreach ($terms as $term_post) : ?>
                                            <dt id="term-<?php echo esc_attr($term_post->post_name); ?>"><?php echo esc_html($term_post->post_title); ?></dt>
                                            <dd><?php echo wpautop(do_shortcode($term_post->post_content)); ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </main>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Not Found
    // =========================================================================

    private function render_not_found() {
        $base_url = $this->get_docs_page_url();
        ob_start();
        ?>
        <div class="hl-docs-wrapper">
            <div class="hl-docs-not-found">
                <span class="dashicons dashicons-warning" style="font-size:48px;width:48px;height:48px;color:var(--hl-warning);"></span>
                <h2><?php esc_html_e('Article Not Found', 'hl-core'); ?></h2>
                <p><?php esc_html_e('The documentation article you\'re looking for doesn\'t exist or has been removed.', 'hl-core'); ?></p>
                <a href="<?php echo esc_url($base_url); ?>" class="hl-docs-back-link">&larr; <?php esc_html_e('Back to Documentation', 'hl-core'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // [hl_doc_link] Shortcode — Cross-reference link
    // =========================================================================

    /**
     * Render an inline cross-reference link to another doc article.
     *
     * Usage:
     *   [hl_doc_link slug="cohorts-vs-tracks"]
     *   [hl_doc_link slug="cohorts-vs-tracks" text="Learn about Cohorts"]
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML anchor tag or plain text if slug not found.
     */
    public function render_doc_link($atts) {
        $atts = shortcode_atts(array(
            'slug' => '',
            'text' => '',
        ), $atts, 'hl_doc_link');

        $slug = sanitize_title($atts['slug']);
        if (empty($slug)) {
            return '';
        }

        $article = get_page_by_path($slug, OBJECT, 'hl_doc');
        $link_text = !empty($atts['text']) ? $atts['text'] : ($article ? $article->post_title : $slug);

        if (!$article || $article->post_status !== 'publish') {
            return esc_html($link_text);
        }

        $base_url = $this->get_docs_page_url();
        $url = add_query_arg('doc', $slug, $base_url);

        return '<a href="' . esc_url($url) . '" class="hl-doc-xref">' . esc_html($link_text) . '</a>';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get the URL of the page containing [hl_docs].
     *
     * @return string
     */
    private function get_docs_page_url() {
        static $url = null;
        if ($url !== null) {
            return $url;
        }

        global $wpdb;
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
             AND post_status = 'publish'
             AND post_content LIKE '%[hl_docs%'
             LIMIT 1"
        );

        $url = $page_id ? get_permalink($page_id) : home_url('/documentation/');
        return $url;
    }

    /**
     * Get sidebar data (all categories with their articles, excluding Glossary).
     *
     * @return array
     */
    private function get_sidebar_data() {
        $categories = get_terms(array(
            'taxonomy'   => 'hl_doc_category',
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ));

        if (is_wp_error($categories)) {
            return array();
        }

        $data = array();
        foreach ($categories as $cat) {
            if ($cat->slug === 'glossary') {
                continue;
            }

            $articles = get_posts(array(
                'post_type'      => 'hl_doc',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'hl_doc_category',
                        'field'    => 'term_id',
                        'terms'    => $cat->term_id,
                    ),
                ),
                'meta_key'       => 'hl_doc_sort_order',
                'orderby'        => 'meta_value_num',
                'order'          => 'ASC',
            ));

            $art_data = array();
            foreach ($articles as $art) {
                $art_data[] = array(
                    'slug'  => $art->post_name,
                    'title' => $art->post_title,
                );
            }

            $data[] = array(
                'term_id'  => $cat->term_id,
                'slug'     => $cat->slug,
                'name'     => $cat->name,
                'articles' => $art_data,
            );
        }

        return $data;
    }

    /**
     * Get all published articles for search (landing page).
     *
     * @return array
     */
    private function get_all_articles() {
        $articles = get_posts(array(
            'post_type'      => 'hl_doc',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        $result = array();
        foreach ($articles as $art) {
            $cats = get_the_terms($art->ID, 'hl_doc_category');
            $cat_name = ($cats && !is_wp_error($cats)) ? $cats[0]->name : '';
            $cat_slug = ($cats && !is_wp_error($cats)) ? $cats[0]->slug : '';

            $result[] = array(
                'slug'    => $art->post_name,
                'title'   => $art->post_title,
                'excerpt' => wp_trim_words(wp_strip_all_tags($art->post_content), 30, '...'),
                'cat'     => $cat_name,
                'catSlug' => $cat_slug,
            );
        }

        return $result;
    }

    /**
     * Count published articles in a category.
     *
     * @param int $term_id
     * @return int
     */
    private function count_articles_in_category($term_id) {
        $query = new WP_Query(array(
            'post_type'      => 'hl_doc',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'hl_doc_category',
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ),
            ),
        ));
        return $query->found_posts;
    }

    /**
     * Get previous and next articles within the same category.
     *
     * @param int $article_id
     * @param int $cat_term_id
     * @return array{prev: ?array, next: ?array}
     */
    private function get_prev_next_articles($article_id, $cat_term_id) {
        $articles = get_posts(array(
            'post_type'      => 'hl_doc',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'hl_doc_category',
                    'field'    => 'term_id',
                    'terms'    => $cat_term_id,
                ),
            ),
            'meta_key'       => 'hl_doc_sort_order',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        ));

        $prev = null;
        $next = null;
        $found = false;

        foreach ($articles as $art) {
            if ($found) {
                $next = array('slug' => $art->post_name, 'title' => $art->post_title);
                break;
            }
            if ($art->ID === $article_id) {
                $found = true;
                continue;
            }
            $prev = array('slug' => $art->post_name, 'title' => $art->post_title);
        }

        return array('prev' => $prev, 'next' => $next);
    }
}
