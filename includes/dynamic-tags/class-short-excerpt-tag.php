<?php
defined( 'ABSPATH' ) || exit;

/**
 * A character-limited excerpt for Loop Items.
 *
 * WordPress only ever counts excerpt length in words — `excerpt_length`
 * and Elementor's own "Excerpt Length" field are both word counts, so
 * neither can express "60 characters". Card layouts care about the
 * character count, because that is what decides whether the text wraps
 * to a third line, so this tag trims on characters instead.
 */
class Film_School_Short_Excerpt_Tag extends \Elementor\Core\DynamicTags\Tag {

    private const DEFAULT_LENGTH = 60;

    public function get_name(): string {
        return 'film-school-short-excerpt';
    }

    public function get_title(): string {
        return 'Short Excerpt (characters)';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    protected function register_controls(): void {
        $this->add_control( 'length', [
            'label'   => 'Max characters',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => self::DEFAULT_LENGTH,
            'min'     => 10,
            'max'     => 500,
        ] );

        $this->add_control( 'ellipsis', [
            'label'     => 'Ellipsis when trimmed',
            'type'      => \Elementor\Controls_Manager::SWITCHER,
            'default'   => 'yes',
        ] );
    }

    public function render(): void {
        $post = get_post( get_the_ID() );

        if ( ! $post ) {
            return;
        }

        $length = (int) $this->get_settings( 'length' );
        if ( $length < 1 ) {
            $length = self::DEFAULT_LENGTH;
        }

        // A hand-written excerpt is an editorial decision, so it wins —
        // it just gets held to the same character budget as the rest.
        $text = '' !== trim( (string) $post->post_excerpt )
            ? $post->post_excerpt
            : $post->post_content;

        $text = self::to_plain_text( $text );

        if ( '' === $text ) {
            return;
        }

        echo esc_html( self::trim_chars( $text, $length, 'yes' === $this->get_settings( 'ellipsis' ) ) );
    }

    /**
     * Lesson and course content is full of this plugin's own shortcodes
     * ([course_sidebar], [next_lesson], [lesson_quiz]) and of block
     * comments. Left in, they would be printed verbatim into the card,
     * so they come out before anything is measured — the same order
     * core's own wp_trim_excerpt() uses.
     */
    private static function to_plain_text( string $text ): string {
        $text = strip_shortcodes( $text );

        if ( function_exists( 'excerpt_remove_blocks' ) ) {
            $text = excerpt_remove_blocks( $text );
        }

        $text = str_replace( ']]>', ']]&gt;', $text );
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

        // Collapse the newlines block markup leaves behind, so the
        // character budget is spent on words rather than whitespace.
        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    /**
     * Trims to a character budget without splitting a word. The result
     * is at most $length characters before the ellipsis is added.
     */
    private static function trim_chars( string $text, int $length, bool $ellipsis ): string {
        if ( mb_strlen( $text ) <= $length ) {
            return $text;
        }

        $cut   = mb_substr( $text, 0, $length );
        $space = mb_strrpos( $cut, ' ' );

        // Fall back to the hard cut for a single word longer than the
        // whole budget, which would otherwise trim away to nothing.
        if ( false !== $space && $space > 0 ) {
            $cut = mb_substr( $cut, 0, $space );
        }

        $cut = rtrim( $cut, " \t\n\r\0\x0B.,;:!?-–—" );

        return $ellipsis ? $cut . '…' : $cut;
    }
}
