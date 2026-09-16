<?php
defined( 'ABSPATH' ) || exit;

/**
 * ACF field groups. Registered in code (not the DB) so the field
 * structure ships with the plugin and is version-controllable.
 */
class Film_School_ACF_Fields {

    public static function init(): void {
        add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
        add_filter( 'acf/load_field/name=quiz_form', [ __CLASS__, 'load_quiz_form_choices' ] );
    }

    public static function register_fields(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        // --- Course -------------------------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_course',
            'title'  => 'Course Settings',
            'fields' => [
                [
                    'key'           => 'field_course_order',
                    'label'         => 'Course Order',
                    'name'          => 'course_order',
                    'type'          => 'number',
                    'default_value' => 1,
                    'instructions'  => 'Position of this course in the library. Lower numbers come first; ties fall back to title. Courses that existed before this field was added were numbered from their previous order, so the library reads the same until you change something.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'course' ] ],
            ],
        ] );

        // --- Unit ---------------------------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_unit',
            'title'  => 'Unit Settings',
            'fields' => [
                [
                    'key'           => 'field_unit_parent_course',
                    'label'         => 'Parent Course',
                    'name'          => 'parent_course',
                    'type'          => 'post_object',
                    'post_type'     => [ 'course' ],
                    'return_format' => 'id',
                    'required'      => 1,
                ],
                [
                    'key'           => 'field_unit_order',
                    'label'         => 'Unit Order',
                    'name'          => 'unit_order',
                    'type'          => 'number',
                    'default_value' => 1,
                    'instructions'  => 'Controls the order units appear on the course landing page.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'unit' ] ],
            ],
        ] );

        // --- Lesson ---------------------------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_lesson',
            'title'  => 'Lesson Settings',
            'fields' => [
                [
                    'key'           => 'field_lesson_parent_course',
                    'label'         => 'Parent Course',
                    'name'          => 'parent_course',
                    'type'          => 'post_object',
                    'post_type'     => [ 'course' ],
                    'return_format' => 'id',
                    'required'      => 1,
                    'instructions'  => 'Always set, even when this lesson also belongs to a unit below.',
                ],
                [
                    'key'           => 'field_lesson_parent_unit',
                    'label'         => 'Parent Unit',
                    'name'          => 'parent_unit',
                    'type'          => 'post_object',
                    'post_type'     => [ 'unit' ],
                    'return_format' => 'id',
                    'allow_null'    => 1,
                    'instructions'  => "Leave blank for courses that don't use units.",
                ],
                [
                    'key'           => 'field_lesson_order',
                    'label'         => 'Lesson Order',
                    'name'          => 'lesson_order',
                    'type'          => 'number',
                    'default_value' => 1,
                    'instructions'  => 'Order within its unit, or within its course if there is no unit.',
                ],
                [
                    'key'           => 'field_lesson_requires',
                    'label'         => 'Requires Completion Of',
                    'name'          => 'requires_lesson',
                    'type'          => 'post_object',
                    'post_type'     => [ 'lesson' ],
                    'return_format' => 'id',
                    'allow_null'    => 1,
                    'instructions'  => 'Leave blank if this is the first lesson a student can access. Locks this lesson until the selected one is complete.',
                ],
                [
                    'key'          => 'field_lesson_quiz_form',
                    'label'        => 'Quiz',
                    'name'         => 'quiz_form',
                    'type'         => 'select',
                    'choices'      => [ '' => '— No quiz —' ],
                    'allow_null'   => 1,
                    'ui'           => 1,
                    'instructions' => 'Choose the Gravity Forms quiz to show at the end of this lesson. Build/edit the quiz itself under Forms.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'lesson' ] ],
            ],
        ] );

        // --- Lesson: optional assignment ------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_assignment',
            'title'  => 'Assignment',
            'fields' => [
                [
                    'key'          => 'field_lesson_assignment_instructions',
                    'label'        => 'Instructions',
                    'name'         => 'assignment_instructions',
                    'type'         => 'wysiwyg',
                    'tabs'         => 'visual',
                    'media_upload' => 0,
                    'instructions' => 'Leave blank if this lesson has no assignment.',
                ],
                [
                    'key'           => 'field_lesson_assignment_file',
                    'label'         => 'Attachment',
                    'name'          => 'assignment_file',
                    'type'          => 'file',
                    'return_format' => 'array',
                    'instructions'  => 'Optional downloadable worksheet/template for the assignment.',
                ],
                [
                    'key'          => 'field_lesson_assignment_link',
                    'label'        => 'Link',
                    'name'         => 'assignment_link',
                    'type'         => 'url',
                    'instructions' => 'Optional external link for the assignment (a form, an outside resource, etc.), separate from the file attachment above.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'lesson' ] ],
            ],
        ] );

        // --- Lesson: recap ------------------------------------------
        acf_add_local_field_group( [
            'key'    => 'group_film_school_recap',
            'title'  => 'Recap',
            'fields' => [
                [
                    'key'          => 'field_lesson_what_we_accomplished',
                    'label'        => 'What We Accomplished',
                    'name'         => 'what_we_accomplished',
                    'type'         => 'wysiwyg',
                    'tabs'         => 'visual',
                    'media_upload' => 0,
                    'instructions' => 'Optional recap of what this lesson covered.',
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'lesson' ] ],
            ],
        ] );
    }

    /**
     * Populates the Quiz dropdown with every Gravity Forms form, so
     * whoever's editing a lesson never has to know a numeric form ID.
     */
    public static function load_quiz_form_choices( array $field ): array {
        $field['choices'] = [ '' => '— No quiz —' ];

        if ( class_exists( 'GFAPI' ) ) {
            foreach ( GFAPI::get_forms() as $form ) {
                $field['choices'][ $form['id'] ] = $form['title'];
            }
        }

        return $field;
    }
}
