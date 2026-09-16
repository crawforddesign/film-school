/**
 * Film School — front-end behavior.
 *
 * One delegated listener for every collapsible in the three sidebars.
 * Delegation rather than per-element binding because a sidebar can be
 * rendered inside an Elementor widget that loads after DOMContentLoaded.
 */
( function () {
    'use strict';

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.fs-unit-toggle, .fs-course-toggle' );

        if ( ! btn ) {
            return;
        }

        var isUnit = btn.classList.contains( 'fs-unit-toggle' );
        var wrap   = btn.closest( isUnit ? '.fs-unit' : '.fs-course' );

        if ( ! wrap ) {
            return;
        }

        var body = wrap.querySelector( isUnit ? '.fs-lesson-list' : '.fs-course-body' );
        var open = wrap.classList.toggle( isUnit ? 'fs-unit--open' : 'fs-course--open' );

        if ( body ) {
            body.style.display = open ? '' : 'none';
        }

        btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
    } );
}() );
