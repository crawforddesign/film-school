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
    }

    /**
     * Narrows the course archive's main query down to courses the
     * current visitor can access — public courses for anyone, plus
     * (for logged-in students) unrestricted or group-assigned ones.
     * Filtering here rather than in a shortcode means the archive
     * template can still use Elementor's native Loop Grid widget on
     * "Current Query" instead of a fixed custom layout.
     */
    public static function filter_course_archive( WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'course' ) ) {
            return;
        }

        $user_id = get_current_user_id(); // 0 for anonymous visitors.
        $all_ids = get_posts( [ 'post_type' => 'course', 'numberposts' => -1, 'fields' => 'ids' ] );
        $visible = array_values( array_filter(
            $all_ids,
            fn( $id ) => self::user_can_access_course( $user_id, $id )
        ) );

        // post__in with an empty array is ignored by WP_Query (it falls
        // back to "no restriction"), so force a non-matching ID instead
        // to correctly show zero results when nothing's accessible.
        $query->set( 'post__in', $visible ?: [ 0 ] );
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
                    'instructions' => "No login required. Overrides Restricted To Groups below — a public course is open to everyone, so group restriction doesn't apply. Note: without a logged-in user there's no one to track progress for, so lesson completion, prerequisites, and the lesson sidebar's checkmarks don't apply to anonymous visitors on a public course.",
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
