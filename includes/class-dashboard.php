<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders the Film School top-level admin page as a native WP
 * meta-box dashboard — the same draggable/collapsible system as the
 * post-edit screens — rather than a static block of text.
 */
class Film_School_Dashboard {

    public static function register_meta_boxes(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        self::maybe_save_settings();

        add_meta_box( 'film_school_about', 'About Film School', [ __CLASS__, 'render_about' ], $screen->id, 'normal', 'high' );
        add_meta_box( 'film_school_settings', 'Settings', [ __CLASS__, 'render_settings' ], $screen->id, 'normal', 'default' );
        add_meta_box( 'film_school_enrollment', 'Current Student Enrollment', [ __CLASS__, 'render_enrollment' ], $screen->id, 'side', 'default' );
        add_meta_box( 'film_school_overachievers', 'Overachievers', [ __CLASS__, 'render_overachievers' ], $screen->id, 'side', 'default' );

        wp_enqueue_script( 'postbox' );
    }

    private static function maybe_save_settings(): void {
        if ( ! isset( $_POST['film_school_settings_nonce'] )
            || ! wp_verify_nonce( $_POST['film_school_settings_nonce'], 'film_school_save_settings' )
            || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        update_option( 'film_school_login_required_page_id', absint( $_POST['film_school_login_required_page_id'] ?? 0 ) );

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Film School settings saved.</p></div>';
        } );
    }

    public static function render(): void {
        $screen = get_current_screen();
        ?>
        <div class="wrap">
            <h1>Film School</h1>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="postbox-container-1" class="postbox-container">
                        <?php do_meta_boxes( $screen->id, 'side', null ); ?>
                    </div>
                    <div id="postbox-container-2" class="postbox-container">
                        <?php do_meta_boxes( $screen->id, 'normal', null ); ?>
                    </div>
                </div>
            </div>
        </div>
        <script>
            jQuery( function ( $ ) {
                postboxes.add_postbox_toggles( '<?php echo esc_js( $screen->id ); ?>' );
            } );
        </script>
        <?php
    }

    public static function render_about(): void {
        ?>
        <p>Manage <strong>Courses</strong>, <strong>Units</strong>, and <strong>Lessons</strong> from the menu on the left.</p>
        <ul style="list-style:disc; margin-left:20px;">
            <li>A <strong>Course</strong> is the top-level offering (e.g. "How to Make a Film").</li>
            <li>A <strong>Unit</strong> is an optional grouping of lessons within a course. Skip it entirely for a short, flat course.</li>
            <li>A <strong>Lesson</strong> always belongs to a course, and optionally to a unit within that course.</li>
        </ul>
        <p>Quizzes are built and managed under <strong>Forms</strong> (Gravity Forms) — link a quiz to a lesson from the lesson's edit screen.</p>
        <p><strong>Groups</strong> restrict a course to specific students. Leave a course's Restricted To Groups field empty to keep it open to everyone.</p>
        <?php
    }

    public static function render_settings(): void {
        $page_id = (int) get_option( 'film_school_login_required_page_id' );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'film_school_save_settings', 'film_school_settings_nonce' ); ?>
            <p>
                <label for="film_school_login_required_page_id"><strong>Login Required Page</strong></label><br>
                <?php
                wp_dropdown_pages( [
                    'name'              => 'film_school_login_required_page_id',
                    'id'                => 'film_school_login_required_page_id',
                    'selected'          => $page_id,
                    'show_option_none'  => '— Select a page —',
                    'option_none_value' => '0',
                ] );
                ?>
            </p>
            <p class="description" style="margin:0 0 10px;">Anonymous visitors to the student profile page are sent here instead of a login prompt — build it with whatever messaging and a link to the course archive you want.</p>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <?php
    }

    public static function render_enrollment(): void {
        $count = count( get_users( [ 'role' => 'student', 'fields' => 'ID' ] ) );
        ?>
        <p style="font-size:32px; font-weight:700; margin:0 0 4px;"><?php echo esc_html( $count ); ?></p>
        <p style="margin:0 0 10px; color:#646970;"><?php echo esc_html( 1 === $count ? 'student enrolled' : 'students enrolled' ); ?></p>
        <p style="margin:0;"><a href="<?php echo esc_url( admin_url( 'users.php?role=student' ) ); ?>">View all students &rarr;</a></p>
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
            echo '<p>No completed lessons yet.</p>';
            return;
        }
        ?>
        <ol style="margin:0; padding-left:20px;">
            <?php foreach ( $top as $entry ) : ?>
                <li style="padding:4px 0;">
                    <?php echo esc_html( $entry['name'] ); ?> —
                    <strong><?php echo esc_html( $entry['count'] ); ?></strong>
                    lesson<?php echo 1 === $entry['count'] ? '' : 's'; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }
}
