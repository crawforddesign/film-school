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

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
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

        echo '<div class="wrap"><h1>Grade Book</h1>';

        if ( ! $courses ) {
            echo '<p>No courses yet.</p></div>';
            return;
        }

        $course_id  = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : $courses[0]->ID;
        $group_id   = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
        $student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
        $groups     = get_posts( [ 'post_type' => 'group', 'numberposts' => -1 ] );

        self::render_course_selector( $courses, $course_id, $groups, $group_id );

        if ( $student_id ) {
            self::render_student_detail( $course_id, $student_id );
        } else {
            self::render_roster( $course_id, $group_id );
        }

        echo '</div>';
    }

    private static function render_course_selector( array $courses, int $selected_course, array $groups, int $selected_group ): void {
        ?>
        <form method="get" style="margin:16px 0; display:flex; gap:12px; align-items:center;">
            <input type="hidden" name="page" value="film-school-gradebook">
            <select name="course_id" onchange="this.form.submit()">
                <?php foreach ( $courses as $course ) : ?>
                    <option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $selected_course, $course->ID ); ?>>
                        <?php echo esc_html( $course->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="group_id" onchange="this.form.submit()">
                <option value="0">All Students</option>
                <?php foreach ( $groups as $group ) : ?>
                    <option value="<?php echo esc_attr( $group->ID ); ?>" <?php selected( $selected_group, $group->ID ); ?>>
                        <?php echo esc_html( $group->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        $lessons    = self::get_course_lessons( $course_id );
        $lesson_ids = wp_list_pluck( $lessons, 'ID' );
        $total      = count( $lesson_ids );
        $id_list    = implode( ',', array_map( 'absint', $lesson_ids ) ?: [ 0 ] );

        global $wpdb;
        $table    = $wpdb->prefix . 'film_school_quiz_attempts';
        $students = get_users( [ 'role' => 'student' ] );

        if ( $group_id ) {
            $member_ids = array_map( 'absint', (array) get_field( 'members', $group_id ) );
            $students   = array_filter( $students, fn( $student ) => in_array( $student->ID, $member_ids, true ) );
        }
        ?>
        <table class="widefat striped">
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
                <?php if ( ! $students ) : ?>
                    <tr><td colspan="6"><?php echo $group_id ? 'No students in this group.' : 'No students yet.'; ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $students as $student ) :
                    $completed = Film_School_Progress::get_completed_lessons( $student->ID );
                    $done      = count( array_intersect( $lesson_ids, $completed ) );
                    $pct       = $total ? (int) round( $done / $total * 100 ) : 0;

                    $passed = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT lesson_id) FROM {$table} WHERE user_id = %d AND passed = 1 AND lesson_id IN ({$id_list})",
                        $student->ID
                    ) );
                    $failed = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND passed = 0 AND lesson_id IN ({$id_list})",
                        $student->ID
                    ) );
                    $last = $wpdb->get_var( $wpdb->prepare(
                        "SELECT MAX(created_at) FROM {$table} WHERE user_id = %d", $student->ID
                    ) );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( [ 'student_id' => $student->ID ] ) ); ?>">
                                <?php echo esc_html( $student->display_name ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( "{$done} of {$total}" ); ?></td>
                        <td><?php echo esc_html( $pct ); ?>%</td>
                        <td><?php echo esc_html( $passed ); ?></td>
                        <td><?php echo esc_html( $failed ); ?></td>
                        <td><?php echo $last ? esc_html( human_time_diff( strtotime( $last ) ) . ' ago' ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_student_detail( int $course_id, int $student_id ): void {
        $student = get_userdata( $student_id );
        if ( ! $student ) {
            echo '<p>Student not found.</p>';
            return;
        }

        $lessons   = self::get_course_lessons( $course_id );
        $completed = Film_School_Progress::get_completed_lessons( $student_id );

        global $wpdb;
        $table = $wpdb->prefix . 'film_school_quiz_attempts';

        echo '<h2>' . esc_html( $student->display_name ) . '</h2>';
        echo '<p><a href="' . esc_url( remove_query_arg( 'student_id' ) ) . '">&larr; Back to roster</a></p>';
        ?>
        <table class="widefat striped">
            <thead><tr><th>Lesson</th><th>Status</th><th>Quiz</th></tr></thead>
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
                    <td><?php echo $done ? '&#10003; Complete' : '&mdash; Incomplete'; ?></td>
                    <td>
                        <?php if ( $attempt ) : ?>
                            <?php echo esc_html( round( $attempt->percent ) ); ?>% — <?php echo $attempt->passed ? 'Pass' : 'Fail'; ?>
                        <?php elseif ( $quiz_form ) : ?>
                            Not attempted
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
