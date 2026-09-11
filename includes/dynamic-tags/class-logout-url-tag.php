<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs a WordPress logout URL (with the required nonce) — for a
 * "Log Out" button's Link field anywhere on the site, most usefully
 * the student profile page.
 */
class Film_School_Logout_Url_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name(): string {
        return 'film-school-logout-url';
    }

    public function get_title(): string {
        return 'Logout URL';
    }

    public function get_group(): array {
        return [ 'film-school' ];
    }

    public function get_categories(): array {
        return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
    }

    public function render(): void {
        echo esc_url( wp_logout_url( home_url() ) );
    }
}
