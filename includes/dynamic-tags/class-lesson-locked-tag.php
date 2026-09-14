<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs "1" when the current Lesson is locked for the viewer, and an
 * empty string when it isn't — for use inside a Loop Item on the lesson
 * archive.
 *
 * Elementor's own display conditions are evaluated per *template*, not
 * per loop item, and can't run a per-post, per-user check anyway. Pairing
 * this tag with a conditional-display add-on (e.g. Dynamic Conditions)
 * lets a single loop item swap a "Locked" badge for a "Start lesson"
 * button. For plain styling, the fs-lesson-locked / fs-lesson-unlocked
 * post classes (see class-progress.php) need no add-on at all.
 */
class Film_School_Lesson_Locked_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-lesson-locked';
    }

    public function get_title(): string {
        return 'Lesson Locked';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    public function render(): void {
        $lesson_id = get_the_ID();

        if ( 'lesson' !== get_post_type( $lesson_id ) ) {
            return;
        }

        echo Film_School_Progress::is_locked_for_current_user( $lesson_id ) ? '1' : '';
    }
}
