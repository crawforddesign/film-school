<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs the title of the Course the current Lesson (or Unit) belongs
 * to — for a Loop Item on the /lessons/ archive that needs to say which
 * course each lesson came from.
 *
 * ACF's own dynamic tag can't do this: `parent_course` is a post_object
 * field declared with 'return_format' => 'id', so ACF hands Elementor
 * the raw post ID and the card renders "42". Changing the field to
 * return an object is not an option — ten call sites across this plugin
 * do `(int) get_field( 'parent_course', ... )`, and casting a WP_Post to
 * int yields 1, which would silently repoint all of them at whatever
 * post has ID 1. So the lookup happens here instead.
 */
class Film_School_Parent_Course_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-parent-course';
    }

    public function get_title(): string {
        return 'Parent Course';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    public function render(): void {
        $course_id = self::parent_course_id();

        if ( ! $course_id ) {
            return;
        }

        echo esc_html( get_the_title( $course_id ) );
    }

    /**
     * Units carry the same field as lessons, so a Unit loop item gets
     * the same tag for free. Returns 0 for anything else, or when the
     * course has since been deleted.
     */
    public static function parent_course_id(): int {
        $post_id = get_the_ID();

        if ( ! $post_id || ! in_array( get_post_type( $post_id ), [ 'lesson', 'unit' ], true ) ) {
            return 0;
        }

        $course_id = (int) get_field( 'parent_course', $post_id );

        return $course_id && 'course' === get_post_type( $course_id ) ? $course_id : 0;
    }
}
