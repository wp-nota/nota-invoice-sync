/**
 * Remembers the dismissal of the Nota Invoice Sync Pro offer notice.
 * WordPress's own "×" button only hides a notice until the next page load;
 * this sends one request so the notice stays hidden for this user.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest || ! window.notaInvPromo ) {
			return;
		}

		var button = target.closest( '.nota-inv-promo .notice-dismiss' );
		if ( ! button ) {
			return;
		}

		var notice = button.closest( '.nota-inv-promo' );
		var data   = new FormData();
		data.append( 'action', window.notaInvPromo.action );
		data.append( 'nonce', window.notaInvPromo.nonce );
		data.append( 'phase', notice ? notice.getAttribute( 'data-phase' ) || '' : '' );

		window.fetch( window.notaInvPromo.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} );
	} );
}() );
