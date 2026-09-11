<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders the Film School top-level admin page: an "About" card, a
 * Settings card, and Enrollment/Overachievers stat cards, inside the
 * shared fs- design system shell (see Film_School_Admin_UI).
 */
class Film_School_Dashboard {

    public static function handle_load(): void {
        self::maybe_save_settings();
    }

    private static function maybe_save_settings(): void {
        if ( ! isset( $_POST['film_school_settings_nonce'] )
            || ! wp_verify_nonce( $_POST['film_school_settings_nonce'], 'film_school_save_settings' )
            || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        update_option( 'film_school_login_required_page_id', absint( $_POST['film_school_login_required_page_id'] ?? 0 ) );

        // A transient (rather than a `?film_school_saved=1` query arg) so the
        // "Settings saved." notice shows exactly once — a plain refresh, a
        // revisited history entry, or a reshared link to the plain admin URL
        // won't re-trigger it, since render_flash_notices() deletes it on read.
        set_transient( 'film_school_saved_' . get_current_user_id(), 1, 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=film-school' ) );
        exit;
    }

    public static function render(): void {
        Film_School_Admin_UI::page_start( 'Film School', 'dashboard' );

        Film_School_Admin_UI::card( 'About Film School', '', [ __CLASS__, 'render_about' ] );

        echo '<div class="fs-card-row">';
        Film_School_Admin_UI::card( 'Enrollment', '', [ __CLASS__, 'render_enrollment' ] );
        Film_School_Admin_UI::card( 'Overachievers', 'Top 5 students by total lessons completed.', [ __CLASS__, 'render_overachievers' ] );
        echo '</div>';

        Film_School_Admin_UI::card( 'Settings', '', [ __CLASS__, 'render_settings' ] );

        Film_School_Admin_UI::page_end();
    }

    public static function render_about(): void {
        ?>
        <div class="fs-prose">
            <p>Manage <strong>Courses</strong>, <strong>Units</strong>, and <strong>Lessons</strong> from the sidebar on the left.</p>
            <ul>
                <li>A <strong>Course</strong> is the top-level offering (e.g. "How to Make a Film").</li>
                <li>A <strong>Unit</strong> is an optional grouping of lessons within a course. Skip it entirely for a short, flat course.</li>
                <li>A <strong>Lesson</strong> always belongs to a course, and optionally to a unit within that course.</li>
            </ul>
            <p>Quizzes are built and managed under <strong>Forms</strong> (Gravity Forms) — link a quiz to a lesson from the lesson's edit screen.</p>
            <p><strong>Groups</strong> restrict a course to specific students. Leave a course's Restricted To Groups field empty to keep it open to everyone.</p>
        </div>
        <?php
    }

    public static function render_settings(): void {
        $page_id  = (int) get_option( 'film_school_login_required_page_id' );
        $dropdown = wp_dropdown_pages( [
            'name'              => 'film_school_login_required_page_id',
            'id'                => 'film_school_login_required_page_id',
            'selected'          => $page_id,
            'show_option_none'  => '— Select a page —',
            'option_none_value' => '0',
            'class'             => 'fs-select',
            'echo'              => 0,
        ] );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'film_school_save_settings', 'film_school_settings_nonce' ); ?>
            <?php
            Film_School_Admin_UI::row(
                'Login Required Page',
                'Anonymous visitors to the student profile page are sent here instead of a login prompt — build it with whatever messaging and a link to the course archive you want.',
                $dropdown
            );
            ?>
            <div class="fs-card-body-pad" style="border-top:1px solid var(--fs-border);">
                <button type="submit" name="film_school_save_settings" class="fs-abtn fs-abtn-primary">Save Settings</button>
            </div>
        </form>
        <?php
    }

    public static function render_enrollment(): void {
        $count = count( get_users( [ 'role' => 'student', 'fields' => 'ID' ] ) );
        ?>
        <div class="fs-stat">
            <div class="fs-stat-num"><?php echo esc_html( $count ); ?></div>
            <div class="fs-stat-label"><?php echo esc_html( 1 === $count ? 'student enrolled' : 'students enrolled' ); ?></div>
            <a class="fs-stat-link" href="<?php echo esc_url( admin_url( 'users.php?role=student' ) ); ?>">View all students &rarr;</a>
        </div>
        <?php
    }

    /**
     * Top 5 students by total lessons completed, across all courses.
     */
    public static function render_overachievers(): void {
        $students = get_users( [ 'role' => 'student' ] );

        $ranked = [];
        foreach ( $students as $student ) {
            $count = count( Film_School_Progress::get_completed_lessons( $student->ID ) );
            if ( $count > 0 ) {
                $ranked[] = [ 'name' => $student->display_name, 'count' => $count ];
            }
        }

        usort( $ranked, fn( $a, $b ) => $b['count'] <=> $a['count'] );
        $top = array_slice( $ranked, 0, 5 );

        if ( ! $top ) {
            Film_School_Admin_UI::empty_state( 'No completed lessons yet.' );
            return;
        }
        ?>
        <div class="fs-rank-list">
            <?php foreach ( $top as $i => $entry ) : ?>
                <div class="fs-rank-row">
                    <span class="fs-rank-pos">#<?php echo esc_html( $i + 1 ); ?></span>
                    <span class="fs-rank-name"><?php echo esc_html( $entry['name'] ); ?></span>
                    <span class="fs-rank-count"><?php echo esc_html( $entry['count'] ); ?> lesson<?php echo 1 === $entry['count'] ? '' : 's'; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
