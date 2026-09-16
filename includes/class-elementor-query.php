<?php
defined( 'ABSPATH' ) || exit;

/**
 * Puts "Lesson Order" and "Unit Order" in Elementor's Order By dropdown.
 *
 * Both are ACF number fields, and Elementor's Order By control only
 * offers post columns (date, title, menu order, rand) — sorting by meta
 * needs a meta_key and orderby => meta_value_num, which that dropdown
 * has no way to express.
 *
 * Two halves, deliberately split:
 *
 * 1. The option is added to the control, which means reaching into
 *    Elementor Pro's query controls. Those control names are not a
 *    public API, so the match is by shape (a select whose name ends in
 *    "orderby" and which already offers date + title) rather than by a
 *    hardcoded name that a future Elementor release could rename.
 *
 * 2. The sorting itself happens in pre_get_posts, which is core and
 *    stable. Elementor passes the chosen value straight through to
 *    WP_Query, so whatever Elementor changes upstream, a query asking
 *    for orderby => lesson_order still sorts correctly. If the dropdown
 *    injection ever breaks, the sort can still be reached by setting a
 *    Query ID and calling $query->set( 'orderby', 'lesson_order' ).
 */
class Film_School_Elementor_Query {

    /** Order By value (and ACF meta key) => label shown in the dropdown. */
    private const ORDER_FIELDS = [
        'course_order' => 'Course Order',
        'lesson_order' => 'Lesson Order',
        'unit_order'   => 'Unit Order',
    ];

    public static function init(): void {
        add_action( 'elementor/element/before_section_end', [ __CLASS__, 'add_orderby_options' ], 10, 2 );
        add_action( 'pre_get_posts', [ __CLASS__, 'apply_meta_orderby' ] );
    }

    /**
     * Adds the options to any post-query Order By select on the widgets
     * that have one. The widget list is filterable because Elementor
     * keeps adding query widgets, and a missing name here shows up as
     * the options simply not appearing.
     */
    public static function add_orderby_options( $element, $section_id ): void {
        $widgets = apply_filters(
            'film_school_orderby_widgets',
            [ 'loop-grid', 'loop-carousel', 'posts', 'archive-posts', 'portfolio' ]
        );

        if ( ! method_exists( $element, 'get_name' ) || ! in_array( $element->get_name(), (array) $widgets, true ) ) {
            return;
        }

        foreach ( (array) $element->get_controls() as $name => $control ) {
            if ( ! self::is_post_orderby_control( (string) $name, (array) $control ) ) {
                continue;
            }

            $options = (array) $control['options'];

            // Union, so Elementor's own options always win on key
            // collision and re-running this is a no-op.
            $element->update_control( $name, [ 'options' => $options + self::ORDER_FIELDS ] );
        }
    }

    /**
     * Identifies Elementor's post Order By control without depending on
     * its name. Requiring date + title in the options keeps this off the
     * unrelated order controls Elementor uses elsewhere (galleries and
     * such), which offer a different set.
     */
    private static function is_post_orderby_control( string $name, array $control ): bool {
        if ( 'select' !== ( $control['type'] ?? '' ) || ! preg_match( '/(^|_)orderby$/', $name ) ) {
            return false;
        }

        $options = $control['options'] ?? null;

        return is_array( $options ) && isset( $options['date'], $options['title'] );
    }

    /**
     * Translates the chosen value into a numeric meta sort. Expressed as
     * a named meta_query clause rather than meta_key, so it composes
     * with a meta_query the query already carries — the lesson archive's
     * parent_course filter, or the admin's filter-by-course — instead of
     * overwriting its meta_key.
     */
    public static function apply_meta_orderby( $query ): void {
        if ( ! $query instanceof WP_Query ) {
            return;
        }

        $orderby = $query->get( 'orderby' );

        if ( ! is_string( $orderby ) || ! isset( self::ORDER_FIELDS[ $orderby ] ) ) {
            return;
        }

        $order = strtoupper( (string) $query->get( 'order' ) );
        $order = 'DESC' === $order ? 'DESC' : 'ASC';

        $meta_query = (array) ( $query->get( 'meta_query' ) ?: [] );

        $meta_query['film_school_order'] = [
            'key'     => $orderby,
            'compare' => 'EXISTS',
            'type'    => 'NUMERIC',
        ];

        $query->set( 'meta_query', $meta_query );
        $query->set( 'orderby', [ 'film_school_order' => $order ] );
    }
}
