<?php
/**
 * Plugin Name:       Film School
 * Plugin URI:        https://crawforddesigngroup.com/
 * Update URI:        https://github.com/crawforddesign/film-school
 * Description:       Lightweight course platform for Prize Foundation — courses, units, lessons, Gravity Forms quiz integration, and student progress tracking.
 * Version:           1.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Crawford Design Group
 * License:           GPL v2 or later
 * Text Domain:       film-school
 */

defined( 'ABSPATH' ) || exit;

// Update checker library (see "Automatic Updates" in README.md). The `use`
// import has to live at the top level of the file — PHP doesn't allow it
// inside an `if` block — but require_once is keyed on the file's absolute
// path, so it's already safe to run on every load.
require_once plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

define( 'FILM_SCHOOL_VERSION', '1.4.0' );
define( 'FILM_SCHOOL_PATH', plugin_dir_path( __FILE__ ) );
define( 'FILM_SCHOOL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Automatic Updates (GitHub Releases)
 *
 * Film School isn't listed on wordpress.org, so WordPress has no way to know
 * a new version exists unless something tells it. This points the update
 * checker at the crawforddesign/film-school GitHub repo's Releases — tag a
 * release and bump the "Version" header above to match. WordPress will then
 * show a normal "Update available" notice + "Update Now" button on the
 * Plugins page. Auto-updates are intentionally left off here; this only
 * makes the checker and button appear — nothing installs without a manual
 * click (or a site admin opting in via "Enable auto-updates").
 *
 * See README.md for the full release steps.
 */
$film_school_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/crawforddesign/film-school/',
    __FILE__,
    'film-school'
);
$film_school_update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );

require_once FILM_SCHOOL_PATH . 'includes/class-admin-ui.php';
require_once FILM_SCHOOL_PATH . 'includes/class-post-types.php';
require_once FILM_SCHOOL_PATH . 'includes/class-dashboard.php';
require_once FILM_SCHOOL_PATH . 'includes/class-acf-fields.php';
require_once FILM_SCHOOL_PATH . 'includes/class-groups.php';
require_once FILM_SCHOOL_PATH . 'includes/class-progress.php';
require_once FILM_SCHOOL_PATH . 'includes/class-quiz.php';
require_once FILM_SCHOOL_PATH . 'includes/class-shortcodes.php';
require_once FILM_SCHOOL_PATH . 'includes/class-gradebook.php';
require_once FILM_SCHOOL_PATH . 'includes/class-dynamic-tags.php';
require_once FILM_SCHOOL_PATH . 'includes/class-admin-columns.php';
require_once FILM_SCHOOL_PATH . 'includes/class-activation.php';

add_action( 'plugins_loaded', function () {
    Film_School_Admin_UI::init();
    Film_School_Post_Types::init();
    Film_School_ACF_Fields::init();
    Film_School_Groups::init();
    Film_School_Progress::init();
    Film_School_Quiz::init();
    Film_School_Shortcodes::init();
    Film_School_Gradebook::init();
    Film_School_Dynamic_Tags::init();
    Film_School_Admin_Columns::init();
} );

// The role is created on activation, but that hook doesn't re-fire on
// a plain file update — this keeps it self-healing either way.
add_action( 'admin_init', [ 'Film_School_Activation', 'register_student_role' ] );

// Students use the front end only — keep them out of wp-admin entirely.
add_action( 'admin_init', function () {
    if ( wp_doing_ajax() || ! is_user_logged_in() ) {
        return;
    }
    if ( in_array( 'student', (array) wp_get_current_user()->roles, true ) ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }
} );

add_filter( 'show_admin_bar', function ( $show ) {
    if ( is_user_logged_in() && in_array( 'student', (array) wp_get_current_user()->roles, true ) ) {
        return false;
    }
    return $show;
} );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'film-school', FILM_SCHOOL_URL . 'assets/css/film-school.css', [], FILM_SCHOOL_VERSION );

    // Sidebar collapse/expand. One delegated listener, no dependencies.
    // Loaded site-wide rather than per-shortcode: the shortcodes can be
    // rendered late (inside an Elementor widget, a loop item, a popup),
    // by which point conditionally enqueuing is no longer possible.
    wp_enqueue_script( 'film-school', FILM_SCHOOL_URL . 'assets/js/film-school.js', [], FILM_SCHOOL_VERSION, true );
} );

// Flag missing dependencies rather than fatal-erroring.
add_action( 'admin_notices', function () {
    $missing = [];

    if ( ! class_exists( 'ACF' ) ) {
        $missing[] = 'Advanced Custom Fields Pro';
    }
    if ( ! class_exists( 'GFForms' ) ) {
        $missing[] = 'Gravity Forms (with the Quiz Add-On)';
    }

    if ( $missing ) {
        printf(
            '<div class="notice notice-error"><p><strong>Film School</strong> requires: %s.</p></div>',
            esc_html( implode( ', ', $missing ) )
        );
    }
} );

register_activation_hook( __FILE__, [ 'Film_School_Activation', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Film_School_Activation', 'deactivate' ] );
