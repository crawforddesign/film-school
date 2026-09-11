<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin list-table customizations for course/unit/lesson — removes the
 * Date column entirely on Courses, hides it by default on Lessons/Units
 * (still available via Screen Options), and adds relational columns
 * (Course/Unit/Public/Lesson Order) so staff don't have to open each
 * post to see how it fits into the hierarchy. Also swaps the Lessons
 * screen's built-in month/year filter for a more useful Course filter.
 */
class Film_School_Admin_Columns {

    public static function init(): void {
        add_filter( 'default_hidden_columns', [ __CLASS__, 'hide_date_by_default' ], 10, 2 );

        // Lessons: Course + Unit columns, Quick Edit + Bulk Edit for both, sortable by Course.
        add_filter( 'manage_lesson_posts_columns', [ __CLASS__, 'lesson_columns' ] );
        add_action( 'manage_lesson_posts_custom_column', [ __CLASS__, 'render_lesson_column' ], 10, 2 );
        add_action( 'quick_edit_custom_box', [ __CLASS__, 'render_lesson_quick_edit' ], 10, 2 );
        add_action( 'bulk_edit_custom_box', [ __CLASS__, 'render_lesson_bulk_edit' ], 10, 2 );
        add_action( 'admin_footer-edit.php', [ __CLASS__, 'print_lesson_quick_edit_js' ] );
        add_action( 'save_post_lesson', [ __CLASS__, 'save_lesson_quick_edit' ] );
        add_action( 'wp_ajax_film_school_bulk_edit_lessons', [ __CLASS__, 'ajax_bulk_edit_lessons' ] );
        add_filter( 'manage_edit-lesson_sortable_columns', [ __CLASS__, 'lesson_sortable_columns' ] );
        add_filter( 'posts_join', [ __CLASS__, 'join_course_title_for_sorting' ], 10, 2 );
        add_filter( 'posts_orderby', [ __CLASS__, 'orderby_course_title' ], 10, 2 );
        add_action( 'restrict_manage_posts', [ __CLASS__, 'render_lesson_course_filter' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_lessons_by_course' ] );
        add_filter( 'disable_months_dropdown', [ __CLASS__, 'disable_lesson_months_dropdown' ], 10, 2 );

        // Courses: Public Course column.
        add_filter( 'manage_course_posts_columns', [ __CLASS__, 'course_columns' ] );
        add_action( 'manage_course_posts_custom_column', [ __CLASS__, 'render_course_column' ], 10, 2 );

        // Units: Parent Course + Unit Order columns, and Quick Edit for Order.
        add_filter( 'manage_unit_posts_columns', [ __CLASS__, 'unit_columns' ] );
        add_action( 'manage_unit_posts_custom_column', [ __CLASS__, 'render_unit_column' ], 10, 2 );
        add_action( 'quick_edit_custom_box', [ __CLASS__, 'render_unit_order_quick_edit' ], 10, 2 );
        add_action( 'admin_footer-edit.php', [ __CLASS__, 'print_unit_order_quick_edit_js' ] );
        add_action( 'save_post_unit', [ __CLASS__, 'save_unit_order_quick_edit' ] );
    }

    public static function hide_date_by_default( array $hidden, $screen ): array {
        if ( in_array( $screen->id, [ 'edit-lesson', 'edit-unit' ], true ) ) {
            $hidden[] = 'date';
        }
        return $hidden;
    }

    /**
     * Inserts new columns right after Title — same insertion point
     * used for all three post types below, for consistency.
     */
    private static function insert_after_title( array $columns, array $new_columns ): array {
        $result = [];
        foreach ( $columns as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'title' === $key ) {
                $result += $new_columns;
            }
        }
        return $result;
    }

    private static function linked_title( int $post_id ): string {
        if ( ! $post_id ) {
            return '—';
        }
        $edit_link = get_edit_post_link( $post_id );
        return $edit_link
            ? sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( get_the_title( $post_id ) ) )
            : esc_html( get_the_title( $post_id ) );
    }

    // --- Lessons -----------------------------------------------------

    public static function lesson_columns( array $columns ): array {
        return self::insert_after_title( $columns, [
            'film_school_course'       => 'Course',
            'film_school_unit'         => 'Unit',
            'film_school_lesson_order' => 'Lesson Order',
        ] );
    }

    public static function render_lesson_column( string $column, int $post_id ): void {
        if ( 'film_school_course' === $column ) {
            $course_id = (int) get_field( 'parent_course', $post_id );
            echo self::linked_title( $course_id );
            printf( '<div class="hidden" id="film_school_lesson_course_inline_%d">%s</div>', esc_attr( $post_id ), esc_html( $course_id ) );
        }
        if ( 'film_school_unit' === $column ) {
            $unit_id = (int) get_field( 'parent_unit', $post_id );
            echo self::linked_title( $unit_id );
            printf( '<div class="hidden" id="film_school_lesson_unit_inline_%d">%s</div>', esc_attr( $post_id ), esc_html( $unit_id ) );
        }
        if ( 'film_school_lesson_order' === $column ) {
            echo esc_html( get_field( 'lesson_order', $post_id ) ?: '—' );
        }
    }

    /**
     * Replaces the built-in month/year filter (hidden on this screen —
     * see disable_lesson_months_dropdown) with a Course filter, since
     * staff manage lessons by course far more often than by date.
     */
    public static function render_lesson_course_filter(): void {
        global $typenow;
        if ( 'lesson' !== $typenow ) {
            return;
        }
        $selected = isset( $_GET['film_school_filter_course'] ) ? absint( $_GET['film_school_filter_course'] ) : 0;
        ?>
        <select name="film_school_filter_course">
            <option value="">All Courses</option>
            <?php foreach ( get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] ) as $course ) : ?>
                <option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $selected, (int) $course->ID ); ?>><?php echo esc_html( $course->post_title ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function filter_lessons_by_course( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() || 'lesson' !== $query->get( 'post_type' ) ) {
            return;
        }
        if ( empty( $_GET['film_school_filter_course'] ) ) {
            return;
        }
        $query->set( 'meta_key', 'parent_course' );
        $query->set( 'meta_value', absint( $_GET['film_school_filter_course'] ) );
    }

    public static function disable_lesson_months_dropdown( bool $disable, string $post_type ): bool {
        return 'lesson' === $post_type ? true : $disable;
    }

    public static function render_lesson_quick_edit( string $column_name, string $post_type ): void {
        if ( 'lesson' !== $post_type ) {
            return;
        }

        if ( 'film_school_course' === $column_name ) {
            ?>
            <fieldset class="inline-edit-col-right">
                <div class="inline-edit-col">
                    <label>
                        <span class="title">Course</span>
                        <span class="input-text-wrap">
                            <select name="film_school_lesson_course" class="film_school_lesson_course">
                                <?php foreach ( get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] ) as $course ) : ?>
                                    <option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </label>
                </div>
            </fieldset>
            <?php
        }

        if ( 'film_school_unit' === $column_name ) {
            ?>
            <fieldset class="inline-edit-col-right">
                <div class="inline-edit-col">
                    <label>
                        <span class="title">Unit</span>
                        <span class="input-text-wrap">
                            <select name="film_school_lesson_unit" class="film_school_lesson_unit">
                                <option value="">— None (flat course) —</option>
                                <?php foreach ( get_posts( [ 'post_type' => 'unit', 'numberposts' => -1 ] ) as $unit ) : ?>
                                    <option value="<?php echo esc_attr( $unit->ID ); ?>"><?php echo esc_html( $unit->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </label>
                </div>
            </fieldset>
            <?php
        }
    }

    /**
     * Bulk Edit's own fieldset — separate field names from Quick Edit's
     * so the two never collide. A "No Change" default means applying
     * Bulk Edit across a batch of lessons doesn't overwrite a field
     * nobody touched. Saved via a dedicated AJAX call (see
     * print_lesson_quick_edit_js and ajax_bulk_edit_lessons) rather
     * than save_post — see the note on ajax_bulk_edit_lessons for why.
     */
    public static function render_lesson_bulk_edit( string $column_name, string $post_type ): void {
        if ( 'lesson' !== $post_type ) {
            return;
        }

        self::maybe_print_bulk_edit_nonce();

        if ( 'film_school_course' === $column_name ) {
            ?>
            <fieldset class="inline-edit-col-right">
                <div class="inline-edit-col">
                    <label>
                        <span class="title">Course</span>
                        <span class="input-text-wrap">
                            <select name="film_school_bulk_course">
                                <option value="-1">— No Change —</option>
                                <?php foreach ( get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] ) as $course ) : ?>
                                    <option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </label>
                </div>
            </fieldset>
            <?php
        }

        if ( 'film_school_unit' === $column_name ) {
            ?>
            <fieldset class="inline-edit-col-right">
                <div class="inline-edit-col">
                    <label>
                        <span class="title">Unit</span>
                        <span class="input-text-wrap">
                            <select name="film_school_bulk_unit">
                                <option value="-1">— No Change —</option>
                                <option value="0">— None (flat course) —</option>
                                <?php foreach ( get_posts( [ 'post_type' => 'unit', 'numberposts' => -1 ] ) as $unit ) : ?>
                                    <option value="<?php echo esc_attr( $unit->ID ); ?>"><?php echo esc_html( $unit->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </label>
                </div>
            </fieldset>
            <?php
        }
    }

    private static function maybe_print_bulk_edit_nonce(): void {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        wp_nonce_field( 'film-school-bulk-edit', 'film_school_bulk_edit_nonce' );
    }

    /**
     * Two unrelated jobs share this one script tag (both only needed
     * on the lesson list screen, so one enqueue covers both):
     *
     * 1. Quick Edit: prefills the Course/Unit dropdowns from the
     *    hidden per-row values rendered above. Course and Unit aren't
     *    cross-filtered (picking a Course doesn't narrow the Unit
     *    list), matching the main lesson edit screen's own fields,
     *    which don't filter either.
     *
     * 2. Bulk Edit: WordPress's native bulk-edit save only fires
     *    save_post for a post if one of WordPress's OWN recognized
     *    fields (status, author, category, etc.) also changed — a
     *    bulk edit that touches only our custom Course/Unit fields
     *    can be silently skipped entirely, with save_post never
     *    firing. So this sends those two fields via a dedicated AJAX
     *    call instead, fired on the same "Update" click, independent
     *    of whatever WordPress's own bulk-edit save decides to do.
     */
    public static function print_lesson_quick_edit_js(): void {
        global $post_type;
        if ( 'lesson' !== $post_type ) {
            return;
        }
        ?>
        <script>
        jQuery( function( $ ) {
            var wpInlineEdit = inlineEditPost.edit;
            inlineEditPost.edit = function( postId ) {
                wpInlineEdit.apply( this, arguments );
                var id = ( typeof postId === 'object' ) ? parseInt( this.getId( postId ), 10 ) : 0;
                if ( id > 0 ) {
                    var courseId = $( '#film_school_lesson_course_inline_' + id ).text();
                    var unitId   = $( '#film_school_lesson_unit_inline_' + id ).text();
                    $( 'select[name="film_school_lesson_course"]', '.inline-edit-row' ).val( courseId );
                    $( 'select[name="film_school_lesson_unit"]', '.inline-edit-row' ).val( unitId );
                }
            };

            $( document ).on( 'click', '#bulk_edit', function() {
                var postIds = $( 'tbody .check-column input[type="checkbox"]:checked' )
                    .map( function() { return $( this ).val(); } ).get();

                if ( ! postIds.length ) {
                    return;
                }

                $.post( ajaxurl, {
                    action: 'film_school_bulk_edit_lessons',
                    nonce: $( '#film_school_bulk_edit_nonce' ).val(),
                    post_ids: postIds,
                    course: $( 'select[name="film_school_bulk_course"]' ).val(),
                    unit: $( 'select[name="film_school_bulk_unit"]' ).val()
                } );
            } );
        } );
        </script>
        <?php
    }

    public static function save_lesson_quick_edit( int $post_id ): void {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Quick Edit (single row) only — Bulk Edit is handled entirely
        // by ajax_bulk_edit_lessons() instead, see the note above.
        if ( isset( $_POST['film_school_lesson_course'] ) ) {
            update_field( 'parent_course', absint( $_POST['film_school_lesson_course'] ), $post_id );
        }
        if ( isset( $_POST['film_school_lesson_unit'] ) ) {
            $unit_id = absint( $_POST['film_school_lesson_unit'] );
            update_field( 'parent_unit', $unit_id ?: '', $post_id );
        }
    }

    /**
     * Handles the Bulk Edit save for Course/Unit directly, bypassing
     * WordPress's native bulk-edit → save_post path entirely (see the
     * note on print_lesson_quick_edit_js for why that path can't be
     * relied on here).
     */
    public static function ajax_bulk_edit_lessons(): void {
        check_ajax_referer( 'film-school-bulk-edit', 'nonce' );

        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : [];
        $course   = isset( $_POST['course'] ) ? sanitize_text_field( wp_unslash( $_POST['course'] ) ) : '-1';
        $unit     = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '-1';

        foreach ( $post_ids as $post_id ) {
            if ( 'lesson' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
                continue;
            }
            if ( '-1' !== $course ) {
                update_field( 'parent_course', absint( $course ), $post_id );
            }
            if ( '-1' !== $unit ) {
                update_field( 'parent_unit', absint( $unit ) ?: '', $post_id );
            }
        }

        wp_send_json_success();
    }

    public static function lesson_sortable_columns( array $columns ): array {
        $columns['film_school_course'] = 'film_school_course';
        return $columns;
    }

    private static function is_sorting_lessons_by_course( $query ): bool {
        return is_admin()
            && $query instanceof WP_Query
            && 'lesson' === $query->get( 'post_type' )
            && 'film_school_course' === $query->get( 'orderby' );
    }

    /**
     * Sorting "by Course" should mean alphabetically by the course's
     * title, not by the raw course ID stored in the lesson's meta —
     * that needs an actual JOIN to the courses themselves, tightly
     * scoped so it never touches any other query on the site.
     */
    public static function join_course_title_for_sorting( string $join, $query ): string {
        if ( ! self::is_sorting_lessons_by_course( $query ) ) {
            return $join;
        }

        global $wpdb;
        $join .= " LEFT JOIN {$wpdb->postmeta} fs_course_meta ON ( {$wpdb->posts}.ID = fs_course_meta.post_id AND fs_course_meta.meta_key = 'parent_course' )";
        $join .= " LEFT JOIN {$wpdb->posts} fs_course_post ON ( fs_course_post.ID = fs_course_meta.meta_value )";

        return $join;
    }

    public static function orderby_course_title( string $orderby, $query ): string {
        if ( ! self::is_sorting_lessons_by_course( $query ) ) {
            return $orderby;
        }

        $direction = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

        return "fs_course_post.post_title {$direction}";
    }

    // --- Courses -------------------------------------------------------

    public static function course_columns( array $columns ): array {
        $columns = self::insert_after_title( $columns, [ 'film_school_public' => 'Public Course' ] );
        unset( $columns['date'] );
        return $columns;
    }

    public static function render_course_column( string $column, int $post_id ): void {
        if ( 'film_school_public' === $column ) {
            echo Film_School_Groups::is_public_course( $post_id ) ? 'Yes' : 'No';
        }
    }

    // --- Units -----------------------------------------------------------

    public static function unit_columns( array $columns ): array {
        return self::insert_after_title( $columns, [
            'film_school_course' => 'Parent Course',
            'film_school_order'  => 'Unit Order',
        ] );
    }

    public static function render_unit_column( string $column, int $post_id ): void {
        if ( 'film_school_course' === $column ) {
            echo self::linked_title( (int) get_field( 'parent_course', $post_id ) );
        }
        if ( 'film_school_order' === $column ) {
            $order = get_field( 'unit_order', $post_id );
            echo esc_html( $order ?: '—' );
            // Hidden per-row value Quick Edit's JS reads to prefill the field.
            printf(
                '<div class="hidden" id="film_school_order_inline_%d">%s</div>',
                esc_attr( $post_id ),
                esc_html( $order )
            );
        }
    }

    public static function render_unit_order_quick_edit( string $column_name, string $post_type ): void {
        if ( 'film_school_order' !== $column_name || 'unit' !== $post_type ) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title">Unit Order</span>
                    <span class="input-text-wrap">
                        <input type="number" name="film_school_unit_order" class="film_school_unit_order" value="">
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * WP core's Quick Edit JS has no idea this custom field exists, so
     * this overrides inlineEditPost.edit to pull the row's current
     * value (from the hidden div rendered in render_unit_column) into
     * the field when Quick Edit opens.
     */
    public static function print_unit_order_quick_edit_js(): void {
        global $post_type;
        if ( 'unit' !== $post_type ) {
            return;
        }
        ?>
        <script>
        jQuery( function( $ ) {
            var wpInlineEdit = inlineEditPost.edit;
            inlineEditPost.edit = function( postId ) {
                wpInlineEdit.apply( this, arguments );
                var id = ( typeof postId === 'object' ) ? parseInt( this.getId( postId ), 10 ) : 0;
                if ( id > 0 ) {
                    var value = $( '#film_school_order_inline_' + id ).text();
                    $( ':input[name="film_school_unit_order"]', '.inline-edit-row' ).val( value );
                }
            };
        } );
        </script>
        <?php
    }

    public static function save_unit_order_quick_edit( int $post_id ): void {
        // Presence of this field is how we know it's a Quick Edit save
        // for this column, not a regular save or another screen entirely.
        if ( ! isset( $_POST['film_school_unit_order'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        update_field( 'unit_order', absint( $_POST['film_school_unit_order'] ), $post_id );
    }
}
