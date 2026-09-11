<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs the number of lessons belonging to the current Course post —
 * for use inside a Loop Item on the course archive, or anywhere else
 * a Course is the current post in the Loop.
 */
class Film_School_Lesson_Count_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-lesson-count';
    }

    public function get_title(): string {
        return 'Lesson Count';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    public function render(): void {
        $course_id = get_the_ID();

        if ( 'course' !== get_post_type( $course_id ) ) {
            return;
        }

        $count = count( get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [ [ 'key' => 'parent_course', 'value' => $course_id ] ],
        ] ) );

        echo esc_html( $count );
    }
}
