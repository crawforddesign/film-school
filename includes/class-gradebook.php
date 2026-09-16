<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-side aggregate progress view. One roster per course, with a
 * lesson-by-lesson drill-down per student.
 *
 * Note on scale: quiz results live in a real table and are queried
 * directly, but lesson completion lives in user meta, so the roster
 * loops over students in PHP rather than one SQL query. Fine at the
 * size this platform runs at (dozens–low hundreds of students); worth
 * revisiting if that grows by an order of magnitude.
 */
class Film_School_Gradebook {

    const CAPABILITY   = 'edit_posts';
    const NONCE_TOGGLE = 'film_school_gradebook_toggle';
    const NONCE_EXPORT = 'film_school_gradebook_export';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );

        // Both of these run before any output: the toggle redirects,
        // the export sends CSV headers. Neither can happen from inside
        // render(), which is already mid-page.
        add_action( 'admin_init', [ __CLASS__, 'handle_toggle' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_export' ] );
    }

    /**
     * Admin override for lesson completion. Every automatic path to
     * completion can fail a student in a way they can't fix themselves
     * — a quiz submitted while logged out, a Gravity Forms hiccup, a
     * lesson finished offline, a student who completed the work under
     * a different account. Without this the only remedy is editing
     * user meta by hand.
     */
    public static function handle_toggle(): void {
        if ( empty( $_POST[ self::NONCE_TOGGLE ] ) ) {
            return;
        }

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $lesson_id  = absint( $_POST['lesson_id'] ?? 0 );
        $nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! current_user_can( self::CAPABILITY ) || ! $student_id || ! $lesson_id ) {
            return;
        }

        if ( ! wp_verify_nonce( $nonce, self::NONCE_TOGGLE . '_' . $student_id . '_' . $lesson_id ) ) {
            return;
        }

        if ( Film_School_Progress::is_lesson_complete( $student_id, $lesson_id ) ) {
            Film_School_Progress::mark_lesson_incomplete( $student_id, $lesson_id );
        } else {
            Film_School_Progress::mark_lesson_complete( $student_id, $lesson_id );
        }

        $redirect = wp_get_referer() ?: admin_url( 'admin.php?page=film-school-gradebook' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * CSV of the roster exactly as filtered on screen — same course,
     * same group, same numbers. Streamed straight to the browser
     * rather than written anywhere on disk.
     */
    public static function handle_export(): void {
        if ( empty( $_GET['fs_gradebook_export'] ) || ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::NONCE_EXPORT ) ) {
            return;
        }

        $course_id = absint( $_GET['course_id'] ?? 0 );
        $group_id  = absint( $_GET['group_id'] ?? 0 );

        if ( ! $course_id ) {
            return;
        }

        $rows     = self::roster_rows( $course_id, $group_id );
        $slug     = sanitize_title( get_the_title( $course_id ) ?: 'course' );
        $filename = "gradebook-{$slug}-" . gmdate( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );

        // BOM so Excel reads UTF-8 names correctly instead of mangling
        // any non-ASCII character in a student's name.
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, [ 'Student', 'Email', 'Lessons Completed', 'Lessons Total', 'Percent', 'Quizzes Passed', 'Quizzes Failed', 'Last Activity' ] );

        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row['name'],
                $row['email'],
                $row['done'],
                $row['total'],
                $row['pct'],
                $row['passed'],
                $row['failed'],
                $row['last'] ?: '',
            ] );
        }

        fclose( $out );
        exit;
    }

    /**
     * One row per student for a course/group — the shared source for
     * both the on-screen roster and the CSV, so the two can't drift.
     */
    private static function roster_rows( int $course_id, int $group_id = 0 ): array {
        $lesson_ids = wp_list_pluck( self::get_course_lessons( $course_id ), 'ID' );
        $total      = count( $lesson_ids );
        $id_list    = implode( ',', array_map( 'absint', $lesson_ids ) ?: [ 0 ] );
        $students   = get_users( [ 'role' => 'student' ] );

        if ( $group_id ) {
            $member_ids = array_map( 'absint', (array) get_field( 'members', $group_id ) );
            $students   = array_filter( $students, fn( $student ) => in_array( $student->ID, $member_ids, true ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'film_school_quiz_attempts';
        $rows  = [];

        foreach ( $students as $student ) {
            $completed = Film_School_Progress::get_completed_lessons( $student->ID );
            $done      = count( array_intersect( $lesson_ids, $completed ) );

            $rows[] = [
                'id'     => (int) $student->ID,
                'name'   => $student->display_name,
                'email'  => $student->user_email,
                'done'   => $done,
                'total'  => $total,
                'pct'    => $total ? (int) round( $done / $total * 100 ) : 0,
                'passed' => (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT lesson_id) FROM {$table} WHERE user_id = %d AND passed = 1 AND lesson_id IN ({$id_list})",
                    $student->ID
                ) ),
                'failed' => (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND passed = 0 AND lesson_id IN ({$id_list})",
                    $student->ID
                ) ),
                'last'   => $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(created_at) FROM {$table} WHERE user_id = %d", $student->ID
                ) ),
            ];
        }

        return $rows;
    }

    public static function register_menu(): void {
        add_submenu_page(
            'film-school',
            'Grade Book',
            'Grade Book',
            'edit_posts',
            'film-school-gradebook',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        $courses = get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] );

        Film_School_Admin_UI::page_start( 'Grade Book', 'gradebook' );

        if ( ! $courses ) {
            Film_School_Admin_UI::card( '', '', function () {
                Film_School_Admin_UI::empty_state( 'No courses yet.' );
            } );
            Film_School_Admin_UI::page_end();
            return;
        }

        $course_id  = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : $courses[0]->ID;
        $group_id   = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
        $student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
        $groups     = get_posts( [ 'post_type' => 'group', 'numberposts' => -1 ] );

        Film_School_Admin_UI::card( '', '', function () use ( $courses, $course_id, $groups, $group_id, $student_id ) {
            self::render_course_selector( $courses, $course_id, $groups, $group_id );

            if ( $student_id ) {
                self::render_student_detail( $course_id, $student_id );
            } else {
                self::render_roster( $course_id, $group_id );
            }
        } );

        Film_School_Admin_UI::page_end();
    }

    private static function render_course_selector( array $courses, int $selected_course, array $groups, int $selected_group ): void {
        ?>
        <form method="get" class="fs-table-toolbar">
            <input type="hidden" name="page" value="film-school-gradebook">
            <select name="course_id" class="fs-select" onchange="this.form.submit()">
                <?php foreach ( $courses as $course ) : ?>
                    <option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $selected_course, $course->ID ); ?>>
                        <?php echo esc_html( $course->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="group_id" class="fs-select" onchange="this.form.submit()">
                <option value="0">All Students</option>
                <?php foreach ( $groups as $group ) : ?>
                    <option value="<?php echo esc_attr( $group->ID ); ?>" <?php selected( $selected_group, $group->ID ); ?>>
                        <?php echo esc_html( $group->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a class="fs-abtn fs-abtn-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [
                'page'                => 'film-school-gradebook',
                'course_id'           => $selected_course,
                'group_id'            => $selected_group,
                'fs_gradebook_export' => 1,
            ], admin_url( 'admin.php' ) ), self::NONCE_EXPORT ) ); ?>">Export CSV</a>
        </form>
        <?php
    }

    private static function get_course_lessons( int $course_id ): array {
        return get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => -1,
            'meta_key'    => 'lesson_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [ [ 'key' => 'parent_course', 'value' => $course_id ] ],
        ] );
    }

    private static function render_roster( int $course_id, int $group_id = 0 ): void {
        $rows = self::roster_rows( $course_id, $group_id );
        ?>
        <div class="fs-table-wrap">
            <table class="fs-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Lessons Completed</th>
                        <th>%</th>
                        <th>Quizzes Passed</th>
                        <th>Quizzes Failed</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="6" class="fs-empty"><?php echo $group_id ? 'No students in this group.' : 'No students yet.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( [ 'student_id' => $row['id'] ] ) ); ?>">
                                    <?php echo esc_html( $row['name'] ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( "{$row['done']} of {$row['total']}" ); ?></td>
                            <td><?php echo esc_html( $row['pct'] ); ?>%</td>
                            <td><?php echo esc_html( $row['passed'] ); ?></td>
                            <td><?php echo esc_html( $row['failed'] ); ?></td>
                            <td><?php echo $row['last'] ? esc_html( human_time_diff( strtotime( $row['last'] ) ) . ' ago' ) : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_student_detail( int $course_id, int $student_id ): void {
        $student = get_userdata( $student_id );
        if ( ! $student ) {
            Film_School_Admin_UI::empty_state( 'Student not found.' );
            return;
        }

        $lessons   = self::get_course_lessons( $course_id );
        $completed = Film_School_Progress::get_completed_lessons( $student_id );

        global $wpdb;
        $table = $wpdb->prefix . 'film_school_quiz_attempts';
        ?>
        <div class="fs-table-toolbar" style="justify-content:space-between;">
            <strong><?php echo esc_html( $student->display_name ); ?></strong>
            <a class="fs-abtn fs-abtn-secondary" href="<?php echo esc_url( remove_query_arg( 'student_id' ) ); ?>">&larr; Back to roster</a>
        </div>
        <div class="fs-table-wrap">
            <table class="fs-table">
                <thead><tr><th>Lesson</th><th>Status</th><th>Quiz</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ( $lessons as $lesson ) :
                    $done      = in_array( $lesson->ID, $completed, true );
                    $quiz_form = get_field( 'quiz_form', $lesson->ID );
                    $attempt   = $quiz_form ? $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY created_at DESC LIMIT 1",
                        $student_id, $lesson->ID
                    ) ) : null;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $lesson->post_title ); ?></td>
                        <td>
                            <?php if ( $done ) : ?>
                                <span class="fs-status-done">&#10003; Complete</span>
                            <?php else : ?>
                                <span class="fs-status-pending">&mdash; Incomplete</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $attempt ) : ?>
                                <?php echo esc_html( round( $attempt->percent ) ); ?>% — <?php echo $attempt->passed ? 'Pass' : 'Fail'; ?>
                            <?php elseif ( $quiz_form ) : ?>
                                Not attempted
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="fs-gradebook-toggle">
                                <?php wp_nonce_field( self::NONCE_TOGGLE . '_' . $student_id . '_' . $lesson->ID ); ?>
                                <input type="hidden" name="student_id" value="<?php echo esc_attr( $student_id ); ?>">
                                <input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson->ID ); ?>">
                                <button type="submit" class="fs-abtn fs-abtn-secondary" name="<?php echo esc_attr( self::NONCE_TOGGLE ); ?>" value="1">
                                    <?php echo $done ? 'Mark Incomplete' : 'Mark Complete'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
