<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared chrome for Film School's own admin screens (Dashboard, Grade
 * Book) — page header, left sidebar nav, and card/row helpers. Mirrors
 * the Shadcn-inspired design system CDG Core uses for its own settings
 * page, scoped under its own .fs-v2/fs- prefix so the two never collide
 * on a site running both plugins. Native WP screens (the Courses/Units/
 * Lessons/Groups list tables, Users) are left alone — only the pages we
 * fully render ourselves get this treatment.
 */
class Film_School_Admin_UI {

    private const STYLED_HOOKS = [
        'toplevel_page_film-school',
        'film-school_page_film-school-gradebook',
    ];

    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, self::STYLED_HOOKS, true ) ) {
            return;
        }

        $css_path = FILM_SCHOOL_PATH . 'assets/css/admin.css';
        if ( ! file_exists( $css_path ) ) {
            return;
        }

        // Register with src=false so WP outputs only an inline <style>, no <link>.
        wp_register_style( 'film-school-admin', false );
        wp_enqueue_style( 'film-school-admin' );
        wp_add_inline_style( 'film-school-admin', file_get_contents( $css_path ) );
    }

    /**
     * @param string $active One of: dashboard, gradebook, courses, units, lessons, groups, students.
     */
    public static function page_start( string $title, string $active ): void {
        ?>
        <div class="wrap fs-v2">
            <div class="fs-page-header">
                <div class="fs-page-title">
                    <h1><?php echo esc_html( $title ); ?></h1>
                    <span class="fs-chip">v<?php echo esc_html( FILM_SCHOOL_VERSION ); ?></span>
                </div>
                <?php self::render_flash_notices(); ?>
            </div>
            <div class="fs-body-layout">
                <?php self::render_sidebar( $active ); ?>
                <main class="fs-content">
        <?php
    }

    public static function page_end(): void {
        ?>
                </main>
            </div>
        </div>
        <?php
    }

    private static function render_flash_notices(): void {
        $key = 'film_school_saved_' . get_current_user_id();
        if ( ! get_transient( $key ) ) {
            return;
        }
        delete_transient( $key );
        ?>
        <div class="fs-success-notice">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <?php esc_html_e( 'Settings saved.', 'film-school' ); ?>
        </div>
        <?php
    }

    private static function render_sidebar( string $active ): void {
        // Each entry's 4th element is the capability required to see it —
        // 'edit_posts' matches what the Film School menu itself already
        // requires (add_menu_page() in class-post-types.php), so those
        // items are always visible here; 'list_users' is stricter (only
        // Administrators by default) and matches the capability the native
        // Students submenu itself is registered with, so a lower-privilege
        // editor doesn't get a link to a page WordPress will then deny them.
        $overview = [
            'dashboard' => [ 'Dashboard', admin_url( 'admin.php?page=film-school' ), 'grid', 'edit_posts' ],
            'gradebook' => [ 'Grade Book', admin_url( 'admin.php?page=film-school-gradebook' ), 'clipboard', 'edit_posts' ],
        ];
        $content = [
            'courses'  => [ 'Courses', admin_url( 'edit.php?post_type=course' ), 'book', 'edit_posts' ],
            'units'    => [ 'Units', admin_url( 'edit.php?post_type=unit' ), 'layers', 'edit_posts' ],
            'lessons'  => [ 'Lessons', admin_url( 'edit.php?post_type=lesson' ), 'file', 'edit_posts' ],
            'groups'   => [ 'Groups', admin_url( 'edit.php?post_type=group' ), 'users', 'edit_posts' ],
            'students' => [ 'Students', admin_url( 'users.php?role=student' ), 'user', 'list_users' ],
        ];
        ?>
        <nav class="fs-sidebar">
            <div class="fs-sidebar-label"><?php esc_html_e( 'Overview', 'film-school' ); ?></div>
            <?php foreach ( $overview as $key => $item ) : self::nav_item( $key, $item, $active ); endforeach; ?>
            <div class="fs-sidebar-divider"></div>
            <div class="fs-sidebar-label"><?php esc_html_e( 'Content', 'film-school' ); ?></div>
            <?php foreach ( $content as $key => $item ) : self::nav_item( $key, $item, $active ); endforeach; ?>
        </nav>
        <?php
    }

    private static function nav_item( string $key, array $item, string $active ): void {
        [ $label, $url, $icon, $cap ] = $item;
        if ( ! current_user_can( $cap ) ) {
            return;
        }
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="fs-nav-item<?php echo $active === $key ? ' fs-active' : ''; ?>">
            <?php echo self::nav_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted SVG ?>
            <?php echo esc_html( $label ); ?>
        </a>
        <?php
    }

    private static function nav_icon( string $icon ): string {
        $icons = [
            'grid'      => '<path d="M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z"/>',
            'clipboard' => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6M9 16h6"/>',
            'book'      => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 006.5 22H20V2H6.5A2.5 2.5 0 004 4.5v15z"/>',
            'layers'    => '<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
            'file'      => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
            'users'     => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
            'user'      => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        ];
        $path = $icons[ $icon ] ?? '';
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }

    /**
     * @param callable $body       Echoes the card's inner content.
     * @param string   $extra_class Additional classes on the card wrapper.
     */
    public static function card( string $title, string $desc, callable $body, string $extra_class = '' ): void {
        $classes = 'fs-card' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
        echo '<div class="' . esc_attr( $classes ) . '">';
        if ( '' !== $title ) {
            echo '<div class="fs-card-header">';
            echo '<div class="fs-card-title">' . esc_html( $title ) . '</div>';
            if ( '' !== $desc ) {
                echo '<p class="fs-card-desc">' . wp_kses_post( $desc ) . '</p>';
            }
            echo '</div>';
        }
        echo '<div class="fs-card-body">';
        $body();
        echo '</div>';
        echo '</div>';
    }

    public static function empty_state( string $message ): void {
        echo '<div class="fs-empty">' . esc_html( $message ) . '</div>';
    }

    /**
     * A labeled row inside a card's body — label/hint on the left, an
     * arbitrary control (already-rendered HTML) on the right.
     */
    public static function row( string $label, string $hint, string $control ): void {
        echo '<div class="fs-setting-row">';
        echo '<div class="fs-setting-info">';
        echo '<div class="fs-setting-label">' . esc_html( $label ) . '</div>';
        if ( '' !== $hint ) {
            echo '<div class="fs-setting-hint">' . wp_kses_post( $hint ) . '</div>';
        }
        echo '</div>';
        echo '<div class="fs-setting-control">' . $control . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- caller-built control markup
        echo '</div>';
    }
}
