<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers a "Lesson Count" Elementor Dynamic Tag so course cards
 * (e.g. a Loop Item on the course archive) can show "X Lessons" via
 * native Dynamic Content. Elementor has no built-in way to count
 * posts related through an ACF field rather than a taxonomy or
 * post_parent, so this fills that specific gap.
 */
class Film_School_Dynamic_Tags {

    public static function init(): void {
        add_action( 'elementor/dynamic_tags/register', [ __CLASS__, 'register' ] );
    }

    public static function register( $dynamic_tags_manager ): void {
        // This hook only fires when Elementor Pro is active, but the
        // class check keeps this file safe to load unconditionally
        // either way — extending a class that doesn't exist yet would
        // fatal at require time, not just at render time.
        if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
            return;
        }

        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-lesson-count-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-first-lesson-url-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-logout-url-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-lesson-locked-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-short-excerpt-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-parent-course-tag.php';
        require_once FILM_SCHOOL_PATH . 'includes/dynamic-tags/class-parent-course-url-tag.php';

        $dynamic_tags_manager->register_group( 'film-school', [ 'title' => 'Film School' ] );
        $dynamic_tags_manager->register( new Film_School_Lesson_Count_Tag() );
        $dynamic_tags_manager->register( new Film_School_First_Lesson_Url_Tag() );
        $dynamic_tags_manager->register( new Film_School_Logout_Url_Tag() );
        $dynamic_tags_manager->register( new Film_School_Lesson_Locked_Tag() );
        $dynamic_tags_manager->register( new Film_School_Short_Excerpt_Tag() );
        $dynamic_tags_manager->register( new Film_School_Parent_Course_Tag() );
        $dynamic_tags_manager->register( new Film_School_Parent_Course_Url_Tag() );
    }
}
