<?php
defined( 'ABSPATH' ) || exit;

/**
 * Groups let staff restrict a course to a subset of students. A course
 * with no groups assigned is open to every student — Groups are an
 * opt-in restriction, not a default requirement.
 */
class Film_School_Groups {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_course_archive' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_lesson_archive' ] );
    }

    /**
     * Narrows the course archive's main query down to courses the
     * current visitor can access — public courses for anyone, plus
     * (for logged-in students) unrestricted or group-assigned ones.
     * Filtering here rather than in a shortcode means the archive
     * template can still use Elementor's native Loop Grid widget on
     * "Current Query" instead of a fixed custom layout.
     *
     * Also supplies the default order, so /courses/ reads the same as
     * the library navigator. Ordering here rather than through a
     * widget's Order By matters twice over: a Loop Grid on "Current
     * Query" has no Order By of its own, and switching it to a custom
     * query to get one would skip this method entirely — it is gated on
     * is_main_query() — taking the access filter with it.
     */
    public static function filter_course_archive( WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'course' ) ) {
            return;
        }

        $user_id = get_current_user_id(); // 0 for anonymous visitors.
        $visible = [];

        // Already in Course Order, so post__in below carries that order
        // through to the archive.
        foreach ( Film_School_Post_Types::ordered_courses() as $course ) {
            if ( self::user_can_access_course( $user_id, $course->ID ) ) {
                $visible[] = $course->ID;
            }
        }

        // post__in with an empty array is ignored by WP_Query (it falls
        // back to "no restriction"), so force a non-matching ID instead
        // to correctly show zero results when nothing's accessible.
        $query->set( 'post__in', $visible ?: [ 0 ] );

        // Default the archive to the same order as the library
        // navigator. Only when nothing has asked for an order of its
        // own: an explicit choice — a template's, or Order By on a
        // widget — still wins, and reaches apply_meta_orderby intact.
        if ( '' === (string) $query->get( 'orderby' ) ) {
            $query->set( 'orderby', 'post__in' );
        }
    }

    /**
     * The lesson archive inherits its course's access rules: a lesson is
     * listed only when the visitor could open the course it belongs to.
     * Anonymous visitors therefore see public courses' lessons only.
     *
     * Access is resolved per course and applied as a meta_query rather
     * than walking every lesson, so the work scales with the number of
     * courses (a handful, each needing a group lookup) instead of the
     * number of lessons.
     *
     * Prerequisite-locked lessons are deliberately still listed — they
     * belong to a course the student is in, the sidebars show them the
     * same way, and enforce_lesson_gate() still redirects on click. Use
     * the Lesson Locked dynamic tag to badge them in the Loop Item.
     */
    public static function filter_lesson_archive( WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'lesson' ) ) {
            return;
        }

        $user_id = get_current_user_id(); // 0 for anonymous visitors.
        $all_ids = get_posts( [ 'post_type' => 'course', 'numberposts' => -1, 'fields' => 'ids' ] );
        $visible = array_values( array_filter(
            $all_ids,
            fn( $id ) => self::user_can_access_course( $user_id, $id )
        ) );

        // Nothing accessible: force zero results. post__in with an empty
        // array is ignored by WP_Query, so use a non-matching ID.
        if ( ! $visible ) {
            $query->set( 'post__in', [ 0 ] );
            return;
        }

        $meta_query   = (array) ( $query->get( 'meta_query' ) ?: [] );
        $meta_query[] = [
            'key'     => 'parent_course',
            'value'   => $visible,
            'compare' => 'IN',
        ];

        $query->set( 'meta_query', $meta_query );
    }

    public static function register_post_type(): void {
        register_post_type( 'group', [
            'labels' => [
                'name'          => 'Groups',
                'singular_name' => 'Group',
                'add_new_item'  => 'Add New Group',
                'edit_item'     => 'Edit Group',
                'all_items'     => 'Groups',
                'menu_name'     => 'Groups',
                'not_found'     => 'No groups found',
            ],
            // No public single/archive page — Groups are an internal
            // organizational tool, not front-end content.
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'film-school',
            'supports'     => [ 'title' ],
        ] );
    }

    public static function register_fields(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        // --- Group: who's in it -------------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_group',
            'title'  => 'Group Members',
            'fields' => [
                [
                    'key'           => 'field_group_members',
                    'label'         => 'Members',
                    'name'          => 'members',
                    'type'          => 'user',
                    'role'          => [ 'student' ],
                    'multiple'      => 1,
                    'return_format' => 'id',
                    'instructions'  => 'Students in this group get access to any course restricted to it. A student can belong to more than one group.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'group' ] ],
            ],
        ] );

        // --- Course: which groups can access it, and whether it's public
        acf_add_local_field_group( [
            'key'    => 'group_film_school_course_access',
            'title'  => 'Access',
            'fields' => [
                [
                    'key'          => 'field_course_is_public',
                    'label'        => 'Public Course',
                    'name'         => 'is_public',
                    'type'         => 'true_false',
                    'ui'           => 1,
                    'instructions' => "No login required. Overrides Restricted To Groups below — a public course is open to everyone, so group restriction doesn't apply. Signed-in students still get their progress tracked here: completion, checkmarks and the progress bar all work as they do on any other course. Anonymous visitors get the lessons, quizzes and assignments, but there's no account to record anything against, so they see plain links and no progress. Prerequisites never gate a public course, for anyone — otherwise a signed-in student would have less access than a stranger.",
                ],
                [
                    'key'           => 'field_course_restricted_groups',
                    'label'         => 'Restricted To Groups',
                    'name'          => 'restricted_groups',
                    'type'          => 'relationship',
                    'post_type'     => [ 'group' ],
                    'return_format' => 'id',
                    'instructions'  => 'Leave empty for a course open to every logged-in student. Add one or more groups to restrict it to just their members. Ignored if Public Course is on.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'course' ] ],
            ],
        ] );
    }

    /**
     * True if this course requires no login at all. Overrides group
     * restriction entirely.
     */
    public static function is_public_course( int $course_id ): bool {
        return (bool) get_field( 'is_public', $course_id );
    }

    /**
     * A user (0 for anonymous) can access a course if it's public, or
     * — for logged-in students — it has no groups assigned (open by
     * default) or they belong to at least one assigned group.
     */
    public static function user_can_access_course( int $user_id, int $course_id ): bool {
        if ( self::is_public_course( $course_id ) ) {
            return true;
        }

        // Everything past this point is a student affordance. "No groups
        // assigned" means open to every *student*, not to the whole
        // internet: without this check an anonymous visitor (user 0)
        // fell through to the empty-groups branch below and could see
        // every course that simply had no group on it.
        if ( ! $user_id ) {
            return false;
        }

        $groups = get_field( 'restricted_groups', $course_id );

        if ( empty( $groups ) ) {
            return true;
        }

        foreach ( (array) $groups as $group_id ) {
            $members = array_map( 'absint', (array) get_field( 'members', $group_id ) );
            if ( in_array( $user_id, $members, true ) ) {
                return true;
            }
        }

        return false;
    }
}
