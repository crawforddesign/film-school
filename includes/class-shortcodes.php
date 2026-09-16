<?php
defined( 'ABSPATH' ) || exit;

class Film_School_Shortcodes {

    public static function init(): void {
        add_shortcode( 'lesson_quiz', [ __CLASS__, 'lesson_quiz' ] );
        add_shortcode( 'lesson_complete', [ __CLASS__, 'lesson_complete' ] );
        add_shortcode( 'course_sidebar', [ __CLASS__, 'course_sidebar' ] );
        add_shortcode( 'course_page_sidebar', [ __CLASS__, 'course_page_sidebar' ] );
        add_shortcode( 'film_school_sidebar', [ __CLASS__, 'film_school_sidebar' ] );
        add_shortcode( 'next_lesson', [ __CLASS__, 'next_lesson' ] );
        add_shortcode( 'student_progress_summary', [ __CLASS__, 'progress_summary' ] );
        add_shortcode( 'student_quiz_history', [ __CLASS__, 'quiz_history' ] );
        add_shortcode( 'student_assignments', [ __CLASS__, 'assignments' ] );
    }

    /**
     * Drop into the lesson template (e.g. an Elementor Shortcode widget).
     * Resolves the current lesson's linked quiz automatically.
     */
    public static function lesson_quiz(): string {
        if ( ! is_singular( 'lesson' ) ) {
            return '';
        }

        $form_id = get_field( 'quiz_form' );

        return $form_id ? do_shortcode( '[gravityform id="' . absint( $form_id ) . '" ajax="true"]' ) : '';
    }

    /**
     * "Mark Complete" button for the lesson template — the completion
     * event for a lesson with no quiz.
     *
     * Before this existed, Film_School_Progress::mark_lesson_complete()
     * had exactly one caller: a passing quiz submission. That made
     * quizzes mandatory in practice even though the content model
     * treats them as optional — a quiz-less lesson could never be
     * completed, so anything gated behind it stayed locked forever and
     * no course containing one could reach 100%.
     *
     * Renders nothing on a lesson that has a quiz (passing it is the
     * completion event there, and a button beside it would let a
     * student skip the assessment) or on a public course (no login, so
     * no one to record it against). Once complete it becomes a
     * "Completed" badge with an Undo — a student who clicks by mistake
     * shouldn't need an admin to fix it.
     *
     * Attributes: label, done_label, undo (yes/no) — e.g.
     * [lesson_complete label="I've watched this" undo="no"]
     */
    public static function lesson_complete( $atts = [] ): string {
        if ( ! is_singular( 'lesson' ) || ! is_user_logged_in() ) {
            return '';
        }

        $atts = shortcode_atts( [
            'label'      => 'Mark Complete',
            'done_label' => 'Completed',
            'undo'       => 'yes',
        ], $atts, 'lesson_complete' );

        $lesson_id = get_queried_object_id();
        $user_id   = get_current_user_id();

        if ( ! Film_School_Progress::lesson_is_manually_completable( $lesson_id ) ) {
            return '';
        }

        // A locked lesson is unreachable anyway (the gate redirects
        // before this renders), but an instructor with
        // unlock_all_lessons can be standing on one — no button there.
        if ( Film_School_Progress::is_lesson_locked( $lesson_id, $user_id ) ) {
            return '';
        }

        $is_done = Film_School_Progress::is_lesson_complete( $user_id, $lesson_id );
        $nonce   = Film_School_Progress::NONCE_ACTION;

        ob_start();
        ?>
        <div class="fs-complete <?php echo $is_done ? 'fs-complete--done' : ''; ?>">
            <?php if ( $is_done ) : ?>
                <span class="fs-complete-badge">
                    <span class="fs-complete-check" aria-hidden="true">&#10003;</span>
                    <?php echo esc_html( $atts['done_label'] ); ?>
                </span>
                <?php if ( 'yes' === $atts['undo'] ) : ?>
                    <form class="fs-complete-form" method="post" action="<?php echo esc_url( get_permalink( $lesson_id ) ); ?>">
                        <?php wp_nonce_field( $nonce . '_' . $lesson_id ); ?>
                        <input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson_id ); ?>">
                        <input type="hidden" name="fs_action" value="undo">
                        <button type="submit" class="fs-complete-undo" name="<?php echo esc_attr( $nonce ); ?>" value="1">Undo</button>
                    </form>
                <?php endif; ?>
            <?php else : ?>
                <form class="fs-complete-form" method="post" action="<?php echo esc_url( get_permalink( $lesson_id ) ); ?>">
                    <?php wp_nonce_field( $nonce . '_' . $lesson_id ); ?>
                    <input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson_id ); ?>">
                    <button type="submit" class="fs-complete-btn" name="<?php echo esc_attr( $nonce ); ?>" value="1">
                        <?php echo esc_html( $atts['label'] ); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * "Next Up" card linking to the next lesson in sequence — within
     * the current lesson's unit if it has one, rolling over to the
     * next unit's first lesson if it's the last one in its unit, or
     * walking lesson_order directly for a flat course. Shows an "End
     * of Course" card instead if this is the last lesson in the
     * course. Doesn't check lock status — it's a "what comes next"
     * pointer, not a gate; clicking through to a locked lesson still
     * redirects per the usual gate.
     */
    public static function next_lesson(): string {
        if ( ! is_singular( 'lesson' ) ) {
            return '';
        }

        $current_id = get_queried_object_id();
        $next_id    = self::find_next_lesson( $current_id );

        ob_start();
        self::print_next_lesson_assets();

        if ( $next_id ) {
            ?>
            <div class="fs-next-up">
                <a class="fs-next-up-link" href="<?php echo esc_url( get_permalink( $next_id ) ); ?>">
                    <span class="fs-next-up-text">
                        <span class="fs-next-up-label">Next Up</span>
                        <span class="fs-next-up-title"><?php echo esc_html( get_the_title( $next_id ) ); ?></span>
                    </span>
                    <span class="fs-next-up-arrow" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M5 12H19M19 12L13 6M19 12L13 18" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </a>
            </div>
            <?php
        } else {
            self::print_end_of_course_card();
        }

        return ob_get_clean();
    }

    /**
     * Shown in place of the "Next Up" card on a course's final lesson,
     * pointing the student at the course archive and their own profile
     * page instead of leaving the card blank.
     */
    private static function print_end_of_course_card(): void {
        $links = [];

        $courses_url = get_post_type_archive_link( 'course' );
        if ( $courses_url ) {
            $links[] = '<a class="fs-next-up-inline-link" href="' . esc_url( $courses_url ) . '">View more courses</a>';
        }

        $profile_page_id = Film_School_Progress::get_student_profile_page_id();
        $profile_url      = $profile_page_id ? get_permalink( $profile_page_id ) : '';
        if ( $profile_url ) {
            $links[] = '<a class="fs-next-up-inline-link" href="' . esc_url( $profile_url ) . '">check out your student profile</a>';
        }
        ?>
        <div class="fs-next-up fs-next-up-end">
            <span class="fs-next-up-text">
                <span class="fs-next-up-label">End of Course</span>
                <span class="fs-next-up-title">
                    <?php echo $links ? implode( ' or ', $links ) : "You've completed every lesson."; ?>
                </span>
            </span>
        </div>
        <?php
    }

    private static function find_next_lesson( int $current_id ): ?int {
        $course_id = (int) get_field( 'parent_course', $current_id );
        if ( ! $course_id ) {
            return null;
        }

        $unit_id = (int) get_field( 'parent_unit', $current_id );
        $order   = (float) get_field( 'lesson_order', $current_id );

        if ( ! $unit_id ) {
            // Flat course — walk lesson_order directly, excluding
            // anything that belongs to a unit.
            return self::get_next_lesson_by_order(
                [ 'key' => 'parent_course', 'value' => $course_id ],
                $order,
                true
            );
        }

        $next = self::get_next_lesson_by_order( [ 'key' => 'parent_unit', 'value' => $unit_id ], $order );
        if ( $next ) {
            return $next;
        }

        // Last lesson in its unit — roll over to the next unit's first lesson.
        $unit_order = (float) get_field( 'unit_order', $unit_id );
        $next_units = get_posts( [
            'post_type'   => 'unit',
            'numberposts' => 1,
            'meta_key'    => 'unit_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [
                'relation' => 'AND',
                [ 'key' => 'parent_course', 'value' => $course_id ],
                [ 'key' => 'unit_order', 'value' => $unit_order, 'compare' => '>', 'type' => 'NUMERIC' ],
            ],
        ] );

        if ( ! $next_units ) {
            return null;
        }

        $first = get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => 1,
            'meta_key'    => 'lesson_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [ [ 'key' => 'parent_unit', 'value' => $next_units[0]->ID ] ],
        ] );

        return $first ? (int) $first[0]->ID : null;
    }

    private static function get_next_lesson_by_order( array $scope, float $order, bool $flat_only = false ): ?int {
        $meta_query = [
            'relation' => 'AND',
            $scope,
            [ 'key' => 'lesson_order', 'value' => $order, 'compare' => '>', 'type' => 'NUMERIC' ],
        ];

        if ( $flat_only ) {
            $meta_query[] = [
                'relation' => 'OR',
                [ 'key' => 'parent_unit', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'parent_unit', 'value' => '', 'compare' => '=' ],
            ];
        }

        $next = get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => 1,
            'meta_key'    => 'lesson_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => $meta_query,
        ] );

        return $next ? (int) $next[0]->ID : null;
    }

    private static function print_next_lesson_assets(): void {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <style>
            .fs-next-up {
                margin: 24px 0;
            }
            .fs-next-up-link,
            .fs-next-up-end {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                padding: 16px 20px;
                border: 1px solid var(--e-global-color-68cab22);
                border-radius: 8px;
                text-decoration: none;
                color: inherit;
            }
            .fs-next-up-inline-link {
                color: #ffae00;
                text-decoration: underline;
            }
            .fs-next-up-inline-link:hover {
                text-decoration: none;
            }
            .fs-next-up-text {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .fs-next-up-label {
                text-transform: uppercase;
                letter-spacing: .05em;
                color: #ffae00;
                font-family: var(--e-global-typography-0e39a90-font-family);
                font-size: var(--e-global-typography-5d6e7b2-font-size);
            }
            .fs-next-up-title {
                font-weight: 700;
                font-size: var(--e-global-typography-f51f501-font-size);
                color: #f5f5f5;
                font-family: 'gotham', sans-serif;
            }
            .fs-next-up-arrow {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: #ffae00;
                color: #f5f5f5;
                flex-shrink: 0;
            }
        </style>
        <?php
    }

    /**
     * Collapsible unit/lesson navigator for the current lesson's course.
     * On a private course: checkmark on completed lessons, chevron on
     * reachable-but-incomplete ones, lock icon on anything gated —
     * requires login. On a public course: plain links for every lesson,
     * no login required, since there's no per-user state to reflect.
     * Only the unit containing the current lesson opens by default.
     * Works for both unit-based and flat courses. Self-contained
     * (prints its own scoped CSS/JS once) so it costs nothing on pages
     * where it isn't used.
     */
    public static function course_sidebar(): string {
        if ( ! is_singular( 'lesson' ) ) {
            return '';
        }

        $current_id = get_queried_object_id();
        $course_id  = (int) get_field( 'parent_course', $current_id );

        if ( ! $course_id ) {
            return '';
        }

        $is_public = Film_School_Groups::is_public_course( $course_id );

        if ( ! $is_public && ! is_user_logged_in() ) {
            return '';
        }

        $user_id   = $is_public ? 0 : get_current_user_id();
        $completed = $is_public ? [] : Film_School_Progress::get_completed_lessons( $user_id );

        ob_start();
        self::print_sidebar_assets();
        ?>
        <div class="fs-sidebar">
            <?php self::render_course_body( $course_id, $current_id, $completed, $user_id, $is_public ); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Course-template counterpart to [course_sidebar]: the course itself
     * as the parent row, with its units/lessons nested underneath. Same
     * per-lesson state (done / locked / plain links on a public course)
     * as the lesson sidebar, minus a "current lesson" — nothing is a
     * lesson here — so every unit opens by default rather than just the
     * one you're in.
     */
    public static function course_page_sidebar(): string {
        if ( ! is_singular( 'course' ) ) {
            return '';
        }

        $course_id = get_queried_object_id();
        $is_public = Film_School_Groups::is_public_course( $course_id );

        if ( ! $is_public && ! is_user_logged_in() ) {
            return '';
        }

        $user_id = $is_public ? 0 : get_current_user_id();

        // A course the student isn't in shouldn't advertise its contents.
        if ( ! Film_School_Groups::user_can_access_course( $user_id, $course_id ) ) {
            return '';
        }

        $completed = $is_public ? [] : Film_School_Progress::get_completed_lessons( $user_id );

        ob_start();
        self::print_sidebar_assets();
        ?>
        <div class="fs-sidebar fs-sidebar--course">
            <div class="fs-course fs-course--open fs-course--current">
                <div class="fs-course-head">
                    <span class="fs-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></span>
                </div>
                <div class="fs-course-body">
                    <?php self::render_course_body( $course_id, 0, $completed, $user_id, $is_public, true ); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Whole-library navigator for a "Film School" landing page: every
     * course the visitor can reach, each collapsible, with its units
     * and lessons nested inside. Unlike the other two sidebars this
     * isn't tied to a post type — it renders anywhere — and it works
     * logged out, showing only public courses. The course you're
     * currently inside (viewing the course, one of its units, or one
     * of its lessons) is the one that starts open; if you're nowhere
     * in particular, they all start closed.
     */
    public static function film_school_sidebar(): string {
        $user_id  = get_current_user_id();
        $courses  = array_filter(
            get_posts( [ 'post_type' => 'course', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC' ] ),
            fn( $course ) => Film_School_Groups::user_can_access_course( $user_id, $course->ID )
        );

        if ( ! $courses ) {
            return '';
        }

        $current_id     = self::current_lesson_id();
        $current_course = self::current_course_id();
        $completed      = $user_id ? Film_School_Progress::get_completed_lessons( $user_id ) : [];

        ob_start();
        self::print_sidebar_assets();
        ?>
        <div class="fs-sidebar fs-sidebar--library">
            <?php foreach ( $courses as $course ) :
                // Per-course, not per-sidebar: a public course drops to
                // plain links even for a logged-in student, matching
                // what [course_sidebar] does on that course's lessons.
                $is_public   = Film_School_Groups::is_public_course( $course->ID );
                $course_user = $is_public ? 0 : $user_id;
                $is_open     = $course->ID === $current_course;
                ?>
                <div class="fs-course <?php echo $is_open ? 'fs-course--open' : ''; ?>">
                    <div class="fs-course-head">
                        <a class="fs-course-title" href="<?php echo esc_url( get_permalink( $course ) ); ?>"><?php echo esc_html( $course->post_title ); ?></a>
                        <button type="button" class="fs-course-toggle" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
                            <span class="screen-reader-text"><?php echo esc_html( sprintf( 'Toggle %s', $course->post_title ) ); ?></span>
                            <span class="fs-chevron" aria-hidden="true">&#9662;</span>
                        </button>
                    </div>
                    <div class="fs-course-body" <?php echo $is_open ? '' : 'style="display:none;"'; ?>>
                        <?php self::render_course_body( $course->ID, $current_id, $is_public ? [] : $completed, $course_user, $is_public, true ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The units-and-lessons body shared by all three sidebars. Falls
     * back to a flat lesson list for a course with no units. $open_all
     * forces every unit open, for the contexts where there's no current
     * lesson to single one out.
     */
    private static function render_course_body( int $course_id, int $current_id, array $completed, int $user_id, bool $is_public, bool $open_all = false ): void {
        $units = get_posts( [
            'post_type'   => 'unit',
            'numberposts' => -1,
            'meta_key'    => 'unit_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [ [ 'key' => 'parent_course', 'value' => $course_id ] ],
        ] );

        if ( $units ) {
            foreach ( $units as $index => $unit ) {
                $lessons = get_posts( [
                    'post_type'   => 'lesson',
                    'numberposts' => -1,
                    'meta_key'    => 'lesson_order',
                    'orderby'     => 'meta_value_num',
                    'order'       => 'ASC',
                    'meta_query'  => [ [ 'key' => 'parent_unit', 'value' => $unit->ID ] ],
                ] );
                $is_open = $open_all || in_array( $current_id, wp_list_pluck( $lessons, 'ID' ), true );
                ?>
                <div class="fs-unit <?php echo $is_open ? 'fs-unit--open' : ''; ?>">
                    <button type="button" class="fs-unit-toggle" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
                        <span class="fs-unit-num"><?php echo esc_html( $index + 1 ); ?></span>
                        <span class="fs-unit-title"><?php echo esc_html( $unit->post_title ); ?></span>
                        <span class="fs-chevron" aria-hidden="true">&#9662;</span>
                    </button>
                    <ul class="fs-lesson-list" <?php echo $is_open ? '' : 'style="display:none;"'; ?>>
                        <?php foreach ( $lessons as $lesson ) : self::render_sidebar_row( $lesson, $current_id, $completed, $user_id, $is_public ); endforeach; ?>
                    </ul>
                </div>
                <?php
            }
            return;
        }

        $lessons = get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => -1,
            'meta_key'    => 'lesson_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [
                'relation' => 'AND',
                [ 'key' => 'parent_course', 'value' => $course_id ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'parent_unit', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => 'parent_unit', 'value' => '', 'compare' => '=' ],
                ],
            ],
        ] );
        ?>
        <ul class="fs-lesson-list fs-lesson-list--flat">
            <?php foreach ( $lessons as $lesson ) : self::render_sidebar_row( $lesson, $current_id, $completed, $user_id, $is_public ); endforeach; ?>
        </ul>
        <?php
    }

    /** The lesson being viewed, or 0 if this isn't a lesson page. */
    private static function current_lesson_id(): int {
        return is_singular( 'lesson' ) ? get_queried_object_id() : 0;
    }

    /**
     * The course the current page sits in — whether that's the course
     * itself, one of its units, or one of its lessons. 0 anywhere else.
     */
    private static function current_course_id(): int {
        if ( is_singular( 'course' ) ) {
            return get_queried_object_id();
        }

        if ( is_singular( [ 'unit', 'lesson' ] ) ) {
            return (int) get_field( 'parent_course', get_queried_object_id() );
        }

        return 0;
    }

    private static function render_sidebar_row( WP_Post $lesson, int $current_id, array $completed, int $user_id, bool $is_public = false ): void {
        $is_current = $lesson->ID === $current_id;

        // Public course: no login, no tracking — every lesson is just
        // a plain link with no done/locked state to reflect.
        if ( $is_public ) {
            $classes = [ 'fs-lesson' ];
            if ( $is_current ) {
                $classes[] = 'fs-lesson--current';
            }
            ?>
            <li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
                <span class="fs-icon">&#8250;</span>
                <a href="<?php echo esc_url( get_permalink( $lesson ) ); ?>"><?php echo esc_html( $lesson->post_title ); ?></a>
            </li>
            <?php
            return;
        }

        $is_done   = in_array( $lesson->ID, $completed, true );
        $is_locked = Film_School_Progress::is_lesson_locked( $lesson->ID, $user_id );

        $classes = [ 'fs-lesson' ];
        if ( $is_done ) {
            $classes[] = 'fs-lesson--done';
        }
        if ( $is_current ) {
            $classes[] = 'fs-lesson--current';
        }
        if ( $is_locked ) {
            $classes[] = 'fs-lesson--locked';
        }
        ?>
        <li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
            <?php if ( $is_locked ) : ?>
                <span class="fs-icon fs-icon--lock">&#128274;</span>
                <span><?php echo esc_html( $lesson->post_title ); ?></span>
            <?php else : ?>
                <span class="fs-icon"><?php echo $is_done ? '&#10003;' : '&#8250;'; ?></span>
                <a href="<?php echo esc_url( get_permalink( $lesson ) ); ?>"><?php echo esc_html( $lesson->post_title ); ?></a>
            <?php endif; ?>
        </li>
        <?php
    }

    private static function print_sidebar_assets(): void {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <style>
            .fs-sidebar { font-size: 14px; }
            .fs-course { margin-bottom: 8px; }
            .fs-course-head {
                display: flex; align-items: center; gap: 8px;
                padding: 10px 0; border-bottom: 1px solid #e2e2e2;
            }
            .fs-course-title {
                flex: 1; font-weight: 700; font-size: 16px;
                color: #222; text-decoration: none;
            }
            a.fs-course-title:hover { color: #12b76a; }
            .fs-course--current > .fs-course-head .fs-course-title { color: #12b76a; }
            .fs-course-toggle {
                background: none; border: none; padding: 4px; cursor: pointer; line-height: 1;
            }
            .fs-course-body { padding-top: 6px; }
            .fs-course--open > .fs-course-head .fs-chevron { transform: rotate(180deg); }
            .fs-unit { margin-bottom: 4px; }
            .fs-unit-toggle {
                display: flex; align-items: center; gap: 10px; width: 100%;
                background: none; border: none; padding: 8px 0; cursor: pointer;
                font-weight: 700; font-size: 15px; text-align: left;
            }
            .fs-unit-num {
                display: flex; align-items: center; justify-content: center;
                width: 24px; height: 24px; border-radius: 50%;
                background: #e2e2e2; color: #666; font-size: 12px; font-weight: 700;
                flex-shrink: 0;
            }
            .fs-unit--open > .fs-unit-toggle .fs-unit-num { background: #12b76a; color: #fff; }
            .fs-unit-title { flex: 1; }
            .fs-chevron { transition: transform .15s ease; color: #888; }
            .fs-unit--open > .fs-unit-toggle .fs-chevron { transform: rotate(180deg); }
            .fs-lesson-list {
                list-style: none; margin: 0 0 8px 12px; padding: 0 0 0 20px;
                border-left: 2px solid #e2e2e2;
            }
            .fs-lesson-list--flat { border-left: none; margin-left: 0; padding-left: 0; }
            .fs-lesson { display: flex; align-items: center; gap: 8px; padding: 8px 0; }
            .fs-lesson a { color: #333; text-decoration: none; }
            .fs-lesson--current a { font-weight: 700; color: #12b76a; }
            .fs-lesson--done .fs-icon { color: #12b76a; }
            .fs-lesson--locked { color: #aaa; }
            .fs-icon { width: 16px; text-align: center; flex-shrink: 0; color: #999; font-size: 13px; }
        </style>
        <script>
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.fs-unit-toggle, .fs-course-toggle');
            if (!btn) return;
            var isUnit = btn.classList.contains('fs-unit-toggle');
            var wrap = btn.closest(isUnit ? '.fs-unit' : '.fs-course');
            var body = wrap.querySelector(isUnit ? '.fs-lesson-list' : '.fs-course-body');
            var open = wrap.classList.toggle(isUnit ? 'fs-unit--open' : 'fs-course--open');
            if (body) body.style.display = open ? '' : 'none';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        </script>
        <?php
    }

    /**
     * Per-course completion bars + "Continue" link. Works the same for
     * flat courses and unit-based courses since it queries by
     * parent_course, which every lesson has regardless of structure.
     */
    public static function progress_summary(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id   = get_current_user_id();
        $completed = Film_School_Progress::get_completed_lessons( $user_id );
        $courses   = array_filter(
            get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] ),
            fn( $course ) => Film_School_Groups::user_can_access_course( $user_id, $course->ID )
        );

        ob_start();
        foreach ( $courses as $course ) {
            $lesson_ids = get_posts( [
                'post_type'   => 'lesson',
                'meta_key'    => 'parent_course',
                'meta_value'  => $course->ID,
                'numberposts' => -1,
                'fields'      => 'ids',
            ] );

            $total = count( $lesson_ids );
            $done  = count( array_intersect( $lesson_ids, $completed ) );
            $pct   = $total ? (int) round( $done / $total * 100 ) : 0;
            $next  = array_values( array_diff( $lesson_ids, $completed ) )[0] ?? null;
            ?>
            <div class="fs-course-progress">
                <h3><?php echo esc_html( $course->post_title ); ?></h3>
                <div class="fs-progress-bar"><div style="width:<?php echo esc_attr( $pct ); ?>%"></div></div>
                <p><?php echo esc_html( "{$done} of {$total} lessons ({$pct}%)" ); ?></p>
                <?php if ( $next ) : ?>
                    <a class="fs-btn" href="<?php echo esc_url( get_permalink( $next ) ); ?>">Continue</a>
                <?php else : ?>
                    <span class="fs-badge">Complete</span>
                <?php endif; ?>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Table of the current user's quiz attempts, with a retake link
     * on anything failed.
     */
    public static function quiz_history(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table   = $wpdb->prefix . 'film_school_quiz_attempts';

        $attempts = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id )
        );

        if ( ! $attempts ) {
            return '<p>No quiz attempts yet.</p>';
        }

        ob_start();
        ?>
        <table class="fs-quiz-history">
            <thead>
                <tr><th>Lesson</th><th>Score</th><th>Result</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ( $attempts as $attempt ) : ?>
                <tr>
                    <td><?php echo esc_html( get_the_title( $attempt->lesson_id ) ); ?></td>
                    <td><?php echo esc_html( round( $attempt->percent ) ); ?>%</td>
                    <td>
                        <?php if ( $attempt->passed ) : ?>
                            <span class="fs-badge fs-pass">Pass</span>
                        <?php else : ?>
                            <span class="fs-badge fs-fail">Fail</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $attempt->created_at ) ) ); ?></td>
                    <td>
                        <?php if ( ! $attempt->passed ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $attempt->lesson_id ) ); ?>">Retake</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * "To-do" list of quizzes the student hasn't passed yet. Only
     * surfaces quizzes on lessons that are actually reachable —
     * locked/future lessons don't show up as pending work.
     */
    public static function assignments(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = get_current_user_id();

        global $wpdb;
        $table  = $wpdb->prefix . 'film_school_quiz_attempts';
        $passed = array_map( 'absint', $wpdb->get_col(
            $wpdb->prepare( "SELECT DISTINCT lesson_id FROM {$table} WHERE user_id = %d AND passed = 1", $user_id )
        ) );

        $all_lessons = get_posts( [ 'post_type' => 'lesson', 'numberposts' => -1 ] );

        $todo = [];
        foreach ( $all_lessons as $lesson ) {
            $quiz_form = get_field( 'quiz_form', $lesson->ID );
            if ( ! $quiz_form || in_array( $lesson->ID, $passed, true ) ) {
                continue;
            }
            if ( Film_School_Progress::is_lesson_locked( $lesson->ID, $user_id ) ) {
                continue;
            }
            $todo[] = $lesson;
        }

        if ( ! $todo ) {
            return '<p class="fs-todo-empty">No pending quizzes — you\'re all caught up.</p>';
        }

        ob_start();
        ?>
        <ul class="fs-todo-list">
            <?php foreach ( $todo as $lesson ) :
                $course_id = (int) get_field( 'parent_course', $lesson->ID );
                ?>
                <li class="fs-todo-item">
                    <span class="fs-todo-label">Quiz</span>
                    <a href="<?php echo esc_url( get_permalink( $lesson ) ); ?>"><?php echo esc_html( $lesson->post_title ); ?></a>
                    <?php if ( $course_id ) : ?>
                        <span class="fs-todo-course"><?php echo esc_html( get_the_title( $course_id ) ); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }
}
