<?php
/**
 * Runs when Film School is deleted from the Plugins screen — not on
 * deactivation, which intentionally leaves everything in place (see
 * Film_School_Activation::deactivate()).
 *
 * Courses, Units, Lessons, and Groups are deliberately NOT deleted.
 * They're the client's content, authored by hand over months, and a
 * plugin delete is far too easy to trigger by accident for it to take
 * the curriculum with it. Re-activating the plugin brings all of it
 * back intact. What goes here is only what the plugin itself created
 * and what would otherwise be orphaned: the quiz attempts table, the
 * Student role, per-user progress meta, and the plugin's options.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Quiz attempts — this table exists only for this plugin, and its rows
// reference lesson IDs that mean nothing without it.
$table = $wpdb->prefix . 'film_school_quiz_attempts';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

// Per-user lesson progress. Matches Film_School_Progress::META_KEY —
// the constant isn't loaded here, since uninstall.php runs standalone
// without the plugin's classes.
$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => '_completed_lessons' ] );

// The capability granted on activation, and the Student role itself.
// remove_role() leaves any user still holding it with no role, so those
// users are moved to 'subscriber' first — a student account is a real
// person's login, and a roleless user can't log in usefully.
$admin = get_role( 'administrator' );
if ( $admin ) {
    $admin->remove_cap( 'unlock_all_lessons' );
}

if ( get_role( 'student' ) ) {
    foreach ( get_users( [ 'role' => 'student', 'fields' => 'ID' ] ) as $user_id ) {
        $user = new WP_User( $user_id );
        $user->set_role( 'subscriber' );
    }

    remove_role( 'student' );
}

// The only other options are the per-user "Settings saved." transients
// set by the dashboard — they expire after 30 seconds on their own, so
// a LIKE sweep of the options table to catch them isn't worth it.
delete_option( 'film_school_login_required_page_id' );

// Set by Film_School_Post_Types::maybe_flush_rewrites() to track which
// plugin version last flushed rewrite rules.
delete_option( 'film_school_rewrite_version' );

// One-shot guard for the Course Order backfill.
delete_option( 'film_school_course_order_backfilled' );

// Rewrite rules referencing the now-unregistered CPTs.
flush_rewrite_rules();
