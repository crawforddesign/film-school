<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bridges Gravity Forms Quiz Add-On submissions into Film School's
 * progress system. The quiz itself is authored entirely in Forms —
 * this class only listens for a graded submission and records the
 * result against the lesson it's linked to.
 */
class Film_School_Quiz {

    public static function init(): void {
        add_action( 'gform_after_submission', [ __CLASS__, 'record_attempt' ], 10, 2 );
    }

    public static function record_attempt( array $entry, array $form ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $lessons = get_posts( [
            'post_type'   => 'lesson',
            'meta_key'    => 'quiz_form',
            'meta_value'  => $form['id'],
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );

        // This form isn't linked to any lesson — nothing to do.
        if ( ! $lessons ) {
            return;
        }

        $lesson_id = (int) $lessons[0];
        $user_id   = get_current_user_id();
        $passed    = (bool) rgar( $entry, 'gquiz_is_pass' );

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'film_school_quiz_attempts',
            [
                'user_id'    => $user_id,
                'lesson_id'  => $lesson_id,
                'form_id'    => (int) $form['id'],
                'score'      => (int) rgar( $entry, 'gquiz_score' ),
                'percent'    => (float) rgar( $entry, 'gquiz_percent' ),
                'passed'     => $passed ? 1 : 0,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%d', '%f', '%d', '%s' ]
        );

        if ( $passed ) {
            Film_School_Progress::mark_lesson_complete( $user_id, $lesson_id );
        }
    }
}
