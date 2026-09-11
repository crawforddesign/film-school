<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs the URL of the current Course's first lesson — for a "Start
 * Course" button on a Loop Item, or anywhere else a Course is the
 * current post. Unit-aware: uses the first unit's first lesson if the
 * course has units, otherwise the first lesson directly under the
 * course.
 */
class Film_School_First_Lesson_Url_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-first-lesson-url';
    }

    public function get_title(): string {
        return 'First Lesson URL';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
    }

    public function render(): void {
        $course_id = get_the_ID();

        if ( 'course' !== get_post_type( $course_id ) ) {
            return;
        }

        $lesson_id = self::find_first_lesson( $course_id );

        if ( $lesson_id ) {
            echo esc_url( get_permalink( $lesson_id ) );
        }
    }

    private static function find_first_lesson( int $course_id ): ?int {
        $units = get_posts( [
            'post_type'   => 'unit',
            'numberposts' => 1,
            'meta_key'    => 'unit_order',
            'orderby'     => 'meta_value_num',
            'order'       => 'ASC',
            'meta_query'  => [ [ 'key' => 'parent_course', 'value' => $course_id ] ],
        ] );

        if ( $units ) {
            $lessons = get_posts( [
                'post_type'   => 'lesson',
                'numberposts' => 1,
                'meta_key'    => 'lesson_order',
                'orderby'     => 'meta_value_num',
                'order'       => 'ASC',
                'meta_query'  => [ [ 'key' => 'parent_unit', 'value' => $units[0]->ID ] ],
            ] );

            return $lessons ? (int) $lessons[0]->ID : null;
        }

        // Flat course — no units, first lesson directly under the course.
        $lessons = get_posts( [
            'post_type'   => 'lesson',
            'numberposts' => 1,
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

        return $lessons ? (int) $lessons[0]->ID : null;
    }
}
