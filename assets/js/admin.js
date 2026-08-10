/**
 * Nota Invoice Sync — admin UI behaviour.
 * Loaded only on the plugin's own settings page — see class-admin-assets.php.
 */
document.addEventListener( 'DOMContentLoaded', function () {
	var button = document.getElementById( 'nota-inv-copy-diagnostics' );
	var textarea = document.getElementById( 'nota-inv-diagnostics-text' );
	var status = document.getElementById( 'nota-inv-copy-diagnostics-status' );

	if ( ! button || ! textarea ) {
		return;
	}

	button.addEventListener( 'click', function () {
		textarea.focus();
		textarea.select();
		textarea.setSelectionRange( 0, textarea.value.length );

		var copied = false;
		try {
			copied = document.execCommand( 'copy' );
		} catch ( err ) {
			copied = false;
		}

		if ( status ) {
			status.textContent = copied ? status.getAttribute( 'data-copied' ) : status.getAttribute( 'data-failed' );
		}
	} );
} );
