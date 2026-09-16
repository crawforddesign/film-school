<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the course, unit, and lesson post types.
 *
 * All three are non-hierarchical — the course/unit/lesson relationship
 * lives in ACF Post Object fields (see class-acf-fields.php), not in
 * native post_parent. This is what lets a "flat" course (lessons attached
 * directly to a course, no units) and a "structured" course (lessons
 * grouped into units) share the same post types.
 */
class Film_School_Post_Types {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'init', [ __CLASS__, 'maybe_flush_rewrites' ], 20 );
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 5 );
        add_action( 'edit_form_after_title', [ __CLASS__, 'render_hierarchy_hint' ] );
        add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'disable_block_editor' ], 10, 2 );
    }

    /**
     * Archive slugs (and the rewrite rules behind them) only exist once
     * rewrite rules have been regenerated. register_activation_hook()
     * doesn't fire on a plain plugin update, so key a one-time flush on
     * the plugin version — bumping FILM_SCHOOL_VERSION re-flushes once,
     * on the first load after the update, instead of needing a manual
     * deactivate/reactivate or a Settings > Permalinks save.
     */
    public static function maybe_flush_rewrites(): void {
        if ( get_option( 'film_school_rewrite_version' ) === FILM_SCHOOL_VERSION ) {
            return;
        }
        flush_rewrite_rules();
        update_option( 'film_school_rewrite_version', FILM_SCHOOL_VERSION );
    }

    /**
     * Plain-language reminder of where this post sits in the
     * course > unit > lesson hierarchy, shown above the ACF fields
     * that actually set it. Self-contained (inline styles) so it
     * doesn't need its own stylesheet.
     */
    public static function render_hierarchy_hint(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, [ 'unit', 'lesson' ], true ) ) {
            return;
        }

        $text = 'unit' === $screen->post_type
            ? 'A <strong>Unit</strong> groups lessons within a <strong>Course</strong>. Set its parent course below.'
            : 'A <strong>Lesson</strong> belongs to a <strong>Course</strong>, and optionally a <strong>Unit</strong> within it. Set both below.';

        printf(
            '<div style="background:#f0f6fc;border:1px solid #c3ddf2;border-radius:4px;padding:10px 14px;margin:12px 0;font-size:13px;color:#1d2327;">%s</div>',
            wp_kses_post( $text )
        );
    }

    /**
     * Course/unit/lesson content is just title + banner + body — the
     * Classic Editor is simpler for the client to work in than Gutenberg,
     * and keeps content entry consistent with the rest of CDG's builds.
     * Works regardless of whether the Classic Editor plugin is active.
     */
    public static function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
        if ( in_array( $post_type, [ 'course', 'unit', 'lesson' ], true ) ) {
            return false;
        }
        return $use_block_editor;
    }

    public static function register_menu(): void {
        $hook = add_menu_page(
            'Film School',
            'Film School',
            'edit_posts',
            'film-school',
            [ 'Film_School_Dashboard', 'render' ],
            'dashicons-video-alt3',
            25
        );

        add_action( "load-{$hook}", [ 'Film_School_Dashboard', 'handle_load' ] );

        // Reuses the native Users screen rather than building a
        // parallel student list — filtered to the student role.
        add_submenu_page(
            'film-school',
            'Students',
            'Students',
            'list_users',
            'users.php?role=student'
        );
    }

    public static function register(): void {

        register_post_type( 'course', [
            'labels'       => self::labels( 'Course', 'Courses' ),
            'public'       => true,
            'has_archive'  => true,
            'show_in_rest' => true,
            'show_in_menu' => 'film-school',
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'      => [ 'slug' => 'courses', 'with_front' => false ],
        ] );

        // Units are structure, not a destination. They organize lessons
        // inside a course and show up in the sidebars, but they have no
        // page of their own: nothing in the plugin renders a unit's
        // contents on a unit URL, and because a unit isn't a lesson it
        // never passes through enforce_lesson_gate() either — so a
        // public /unit/... URL was an ungated route that displayed
        // nothing useful. show_ui keeps the full admin editing
        // experience; only the front-end surface is withdrawn.
        register_post_type( 'unit', [
            'labels'              => self::labels( 'Unit', 'Units' ),
            'public'              => false,
            'publicly_queryable'  => false,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_rest'        => true,
            'show_in_menu'        => 'film-school',
            'supports'            => [ 'title', 'editor' ],
            'rewrite'             => false,
        ] );

        register_post_type( 'lesson', [
            'labels'       => self::labels( 'Lesson', 'Lessons' ),
            'public'       => true,
            'has_archive'  => 'lessons',
            'show_in_rest' => true,
            'show_in_menu' => 'film-school',
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'      => [ 'slug' => 'lesson', 'with_front' => false ],
        ] );
    }

    /**
     * Every course, ordered by the Course Order field — the canonical
     * course order, shared by the library navigator, the progress
     * summary and the course archive so all three agree.
     *
     * Sorted in PHP rather than with meta_key + meta_value_num, because
     * a meta sort INNER JOINs and silently drops any course with no
     * course_order row — one imported, or created in code, or never
     * re-saved. Vanishing from the library is a far worse failure than
     * sorting last, so a missing value goes to the end and ties fall
     * back to title.
     */
    public static function ordered_courses(): array {
        $courses = get_posts( [ 'post_type' => 'course', 'numberposts' => -1 ] );

        usort( $courses, static function ( $a, $b ) {
            $a_raw = get_post_meta( $a->ID, 'course_order', true );
            $b_raw = get_post_meta( $b->ID, 'course_order', true );

            // No value sorts last, rather than sorting as 0 and jumping
            // to the front of the list.
            $a_key = '' === (string) $a_raw ? PHP_INT_MAX : (int) $a_raw;
            $b_key = '' === (string) $b_raw ? PHP_INT_MAX : (int) $b_raw;

            return ( $a_key <=> $b_key ) ?: strcasecmp( $a->post_title, $b->post_title );
        } );

        return $courses;
    }

    private static function labels( string $singular, string $plural ): array {
        return [
            'name'          => $plural,
            'singular_name' => $singular,
            'add_new_item'  => "Add New {$singular}",
            'edit_item'     => "Edit {$singular}",
            'new_item'      => "New {$singular}",
            'all_items'     => $plural,
            'search_items'  => "Search {$plural}",
            'menu_name'     => $plural,
            'not_found'     => "No {$plural} found",
        ];
    }
}
