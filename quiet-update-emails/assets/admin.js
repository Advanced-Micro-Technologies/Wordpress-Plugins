/**
 * Quiet Update Emails: asks for confirmation before the update log is cleared.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.classList || ! form.classList.contains( 'proadvanced-update-emails-clear-log' ) ) {
			return;
		}

		var message = form.getAttribute( 'data-confirm' );

		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
