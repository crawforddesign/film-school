<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tracks per-user lesson completion and enforces prerequisite gating.
 * Completion is stored as user meta — a single array of lesson IDs is
 * enough for "has this student finished lesson X", which is all the
 * gate check needs.
 */
class Film_School_Progress {

    const META_KEY     = '_completed_lessons';
    const NONCE_ACTION = 'film_school_complete_lesson';

    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'handle_completion_post' ], 5 );
        add_action( 'template_redirect', [ __CLASS__, 'enforce_lesson_gate' ] );
        add_action( 'template_redirect', [ __CLASS__, 'guard_student_profile_page' ] );
        add_filter( 'post_class', [ __CLASS__, 'add_lock_post_class' ], 10, 3 );
    }

    /**
     * Resolves lock state for whoever is viewing right now, including
     * logged-out visitors — is_lesson_locked() needs a real user ID to
     * check group access and prerequisites against, so an anonymous
     * visitor is locked out of everything except public-course lessons.
     * This is the check to use anywhere a lesson is being *listed*
     * (archives, loop grids, sidebars) rather than gated.
     */
    public static function is_locked_for_current_user( int $lesson_id ): bool {
        $course_id = (int) get_field( 'parent_course', $lesson_id );

        if ( $course_id && Film_School_Groups::is_public_course( $course_id ) ) {
            return false;
        }

        if ( ! is_user_logged_in() ) {
            return true;
        }

        return self::is_lesson_locked( $lesson_id, get_current_user_id() );
    }

    /**
     * Stamps every lesson in a loop with fs-lesson-locked or
     * fs-lesson-unlocked. Elementor Loop Grid items call post_class(),
     * which gives archive templates a per-item hook for styling locked
     * lessons — Elementor's own display conditions can't evaluate a
     * per-post, per-user check like this one.
     */
    public static function add_lock_post_class( array $classes, array $css_class, int $post_id ): array {
        if ( 'lesson' !== get_post_type( $post_id ) ) {
            return $classes;
        }

        $classes[] = self::is_locked_for_current_user( $post_id )
            ? 'fs-lesson-locked'
            : 'fs-lesson-unlocked';

        return $classes;
    }

    /**
     * Any page containing [student_progress_summary] is treated as the
     * student profile page — anonymous visitors are sent to a custom
     * page instead (set under Film School → the Settings box on the
     * dashboard), so you control the messaging entirely rather than
     * relying on a 404 template. Detected via the shortcode rather
     * than a hardcoded slug, so it works regardless of what the page
     * is actually named/slugged.
     */
    public static function guard_student_profile_page(): void {
        if ( is_user_logged_in() || ! is_page() ) {
            return;
        }

        $post = get_post();
        if ( ! $post || ! self::page_has_progress_summary( $post->ID ) ) {
            return;
        }

        $redirect_page_id = (int) get_option( 'film_school_login_required_page_id' );
        $redirect_url      = $redirect_page_id ? get_permalink( $redirect_page_id ) : home_url( '/' );

        wp_safe_redirect( $redirect_url ?: home_url( '/' ) );
        exit;
    }

    /**
     * The page containing [student_progress_summary], if one exists —
     * wherever a student would go to see their own progress. Detected
     * via the shortcode rather than a hardcoded slug/setting, so it
     * works regardless of what the page is actually named/slugged.
     */
    public static function get_student_profile_page_id(): ?int {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached ?: null;
        }

        $pages = get_posts( [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ] );

        foreach ( $pages as $page_id ) {
            if ( self::page_has_progress_summary( $page_id ) ) {
                $cached = $page_id;
                return $page_id;
            }
        }

        $cached = 0;
        return null;
    }

    // Check both post_content and Elementor's own stored data — a
    // shortcode placed via an Elementor Shortcode widget lives in
    // _elementor_data, not necessarily verbatim in post_content.
    private static function page_has_progress_summary( int $page_id ): bool {
        $elementor_data = get_post_meta( $page_id, '_elementor_data', true );

        return has_shortcode( get_post_field( 'post_content', $page_id ), 'student_progress_summary' )
            || ( $elementor_data && false !== strpos( $elementor_data, 'student_progress_summary' ) );
    }

    public static function get_completed_lessons( int $user_id ): array {
        return array_map( 'absint', (array) get_user_meta( $user_id, self::META_KEY, true ) );
    }

    public static function mark_lesson_complete( int $user_id, int $lesson_id ): void {
        $completed   = self::get_completed_lessons( $user_id );
        $completed[] = $lesson_id;
        update_user_meta( $user_id, self::META_KEY, array_values( array_unique( $completed ) ) );
    }

    public static function mark_lesson_incomplete( int $user_id, int $lesson_id ): void {
        $completed = array_diff( self::get_completed_lessons( $user_id ), [ $lesson_id ] );
        update_user_meta( $user_id, self::META_KEY, array_values( $completed ) );
    }

    public static function is_lesson_complete( int $user_id, int $lesson_id ): bool {
        return in_array( $lesson_id, self::get_completed_lessons( $user_id ), true );
    }

    /**
     * Whether a lesson can be completed by hand at all. A quiz-linked
     * lesson can't — passing the quiz is its completion event, and a
     * button beside it would let a student skip the assessment.
     *
     * Public-course lessons used to be excluded too, on the grounds
     * that a public course has no login and so no one to record
     * against. That is true of anonymous visitors, but it was being
     * applied to signed-in students as well: a logged-in student could
     * work through a whole public course and the button never appeared,
     * leaving the progress bar frozen at 0% forever. Whether there is
     * someone to record against is a question about the visitor, not
     * the course, and both callers already require a logged-in user.
     */
    public static function lesson_is_manually_completable( int $lesson_id ): bool {
        if ( 'lesson' !== get_post_type( $lesson_id ) ) {
            return false;
        }

        return ! get_field( 'quiz_form', $lesson_id );
    }

    /**
     * Handles the [lesson_complete] button's POST. Runs at priority 5
     * so it lands before enforce_lesson_gate() — the gate would only
     * ever bounce a request the checks here reject anyway, and doing
     * the write first keeps the redirect target honest.
     *
     * Post/Redirect/Get: the redirect back to the lesson means a
     * browser refresh doesn't re-submit, and the button re-renders
     * from real state rather than from a flag in the request.
     */
    public static function handle_completion_post(): void {
        if ( empty( $_POST[ self::NONCE_ACTION ] ) || ! is_user_logged_in() ) {
            return;
        }

        $lesson_id = absint( $_POST['lesson_id'] ?? 0 );

        // wp_verify_nonce(), not check_admin_referer() — this is a
        // front-end form, and check_admin_referer() dies with wp-admin's
        // "link you followed has expired" screen on a stale nonce. A
        // student who left a lesson open overnight should get the page
        // back with the button still on it, not an error page.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! $lesson_id || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $lesson_id ) ) {
            return;
        }

        $user_id = get_current_user_id();

        // Re-check everything the button's rendering checked. The
        // rendered button is a hint about what's allowed, never the
        // authority on it — this POST can arrive without it.
        if ( ! self::lesson_is_manually_completable( $lesson_id ) || self::is_lesson_locked( $lesson_id, $user_id ) ) {
            return;
        }

        $action = isset( $_POST['fs_action'] ) ? sanitize_key( wp_unslash( $_POST['fs_action'] ) ) : '';

        if ( 'undo' === $action ) {
            self::mark_lesson_incomplete( $user_id, $lesson_id );
        } else {
            self::mark_lesson_complete( $user_id, $lesson_id );
        }

        wp_safe_redirect( get_permalink( $lesson_id ) ?: home_url( '/' ) );
        exit;
    }

    public static function is_lesson_locked( int $lesson_id, int $user_id ): bool {
        // Instructors/admins bypass gating entirely.
        if ( user_can( $user_id, 'unlock_all_lessons' ) ) {
            return false;
        }

        $course_id = (int) get_field( 'parent_course', $lesson_id );

        // Public courses have no login requirement, and therefore no
        // per-user state to check prerequisites or group access against.
        if ( $course_id && Film_School_Groups::is_public_course( $course_id ) ) {
            return false;
        }

        if ( $course_id && ! Film_School_Groups::user_can_access_course( $user_id, $course_id ) ) {
            return true;
        }

        $requires = (int) get_field( 'requires_lesson', $lesson_id );

        if ( ! $requires ) {
            return false;
        }

        return ! in_array( $requires, self::get_completed_lessons( $user_id ), true );
    }

    /**
     * Runs on every single-lesson request. Public-course lessons skip
     * every check below — no login requirement, no group restriction,
     * no prerequisite chain. That holds for signed-in students too,
     * deliberately: a public course is one anyone can take, so gating
     * it on prerequisites would leave a logged-in student with less
     * access than a stranger. Their progress is still recorded; it just
     * doesn't gate anything. Everything else requires login, group
     * access if restricted, and prerequisite completion.
     */
    public static function enforce_lesson_gate(): void {
        if ( ! is_singular( 'lesson' ) ) {
            return;
        }

        $lesson_id = get_queried_object_id();
        $course_id = (int) get_field( 'parent_course', $lesson_id );

        if ( $course_id && Film_School_Groups::is_public_course( $course_id ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            auth_redirect();
            return;
        }

        $user_id = get_current_user_id();

        if ( ! self::is_lesson_locked( $lesson_id, $user_id ) ) {
            return;
        }

        // Locked because the course itself is restricted — send them
        // to the course page rather than a prerequisite lesson that
        // doesn't apply here.
        if ( $course_id && ! Film_School_Groups::user_can_access_course( $user_id, $course_id ) ) {
            wp_safe_redirect( get_permalink( $course_id ) ?: home_url( '/' ) );
            exit;
        }

        $requires = (int) get_field( 'requires_lesson', $lesson_id );
        wp_safe_redirect( $requires ? get_permalink( $requires ) : home_url( '/' ) );
        exit;
    }
}
