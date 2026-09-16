<?php
defined( 'ABSPATH' ) || exit;

/**
 * The permalink of the Course the current Lesson (or Unit) belongs to.
 * Pair it with the Parent Course tag to make the course name on a
 * lesson card link back to the course.
 */
class Film_School_Parent_Course_Url_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-parent-course-url';
    }

    public function get_title(): string {
        return 'Parent Course URL';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
    }

    public function render(): void {
        $course_id = Film_School_Parent_Course_Tag::parent_course_id();

        if ( ! $course_id ) {
            return;
        }

        echo esc_url( get_permalink( $course_id ) );
    }
}
