<?php
defined( 'ABSPATH' ) || exit;

class Film_School_Activation {

    public static function activate(): void {
        self::create_tables();
        self::add_capabilities();
        self::register_student_role();

        // CPTs must be registered before rewrite rules are flushed.
        Film_School_Post_Types::register();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
        // Data (courses, lessons, quiz attempts) is intentionally left
        // in place on deactivation — only removed via uninstall.php.
    }

    /**
     * Numbers existing courses for the Course Order field.
     *
     * The field ships with a default of 1, but ACF only writes a default
     * when a post is saved — every course that existed beforehand would
     * have no value at all and would sort to the end of the library in
     * an arbitrary clump. This seeds them from the arrangement they
     * already had (menu_order, then title, which is how the library
     * navigator used to sort), so nothing visibly moves until someone
     * actually edits a number.
     *
     * Runs once, guarded by an option, on admin_init — plugin updates
     * don't re-fire the activation hook.
     */
    public static function backfill_course_order(): void {
        if ( get_option( 'film_school_course_order_backfilled' ) ) {
            return;
        }

        $courses = get_posts( [
            'post_type'   => 'course',
            'numberposts' => -1,
            'orderby'     => 'menu_order title',
            'order'       => 'ASC',
            'fields'      => 'ids',
        ] );

        $position = 0;

        foreach ( $courses as $course_id ) {
            $position++;

            // Never overwrite a value someone has already set.
            if ( '' === (string) get_post_meta( $course_id, 'course_order', true ) ) {
                update_post_meta( $course_id, 'course_order', $position );
            }
        }

        update_option( 'film_school_course_order_backfilled', 1 );
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table            = $wpdb->prefix . 'film_school_quiz_attempts';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            lesson_id BIGINT UNSIGNED NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL,
            score INT NOT NULL DEFAULT 0,
            percent FLOAT NOT NULL DEFAULT 0,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY lesson_id (lesson_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Administrators can view every lesson regardless of prerequisites.
     * Add this same capability to whatever instructor/teacher role is
     * introduced once the portal SSO piece is settled.
     */
    private static function add_capabilities(): void {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'unlock_all_lessons' );
        }
    }

    /**
     * Public and idempotent (not just called on activation) — a role
     * added only in register_activation_hook() never gets created on
     * a site where the plugin was already active when this code shipped,
     * since that hook only fires on the deactivated→activated transition,
     * not on a plain file update. Also hooked to admin_init in
     * film-school.php so it self-heals without a manual deactivate/
     * reactivate. get_role() is a cheap, cached lookup and add_role()
     * is a no-op once the role exists, so this is safe to run on every
     * admin page load.
     */
    public static function register_student_role(): void {
        if ( ! get_role( 'student' ) ) {
            add_role( 'student', 'Student', [ 'read' => true ] );
        }
    }
}
