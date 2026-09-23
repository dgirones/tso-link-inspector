/**
 * Keep background scan/check moving on any wp-admin screen.
 */
/* global tsoliinBgWorker */
( function ( $ ) {
	'use strict';

	if ( ! window.tsoliinBgWorker || ! tsoliinBgWorker.ajaxUrl ) {
		return;
	}

	var busy       = false;
	var timer      = null;
	var failMs     = 15000;
	var hasUiTicks = parseInt( tsoliinBgWorker.hasUiTicks, 10 ) === 1;
	var busyMs     = hasUiTicks ? 1500 : 5000;
	var active     = parseInt( tsoliinBgWorker.active, 10 ) === 1;

	function schedule( ms ) {
		if ( timer ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( tick, Math.max( 0, ms ) );
	}

	function tick() {
		if ( busy ) {
			return;
		}
		busy = true;
		$.ajax( {
			url    : tsoliinBgWorker.ajaxUrl,
			method : 'POST',
			data   : {
				action : 'tsoliin_bg_keep_alive',
				nonce  : tsoliinBgWorker.nonce
			}
		} ).done( function ( r ) {
			busy = false;
			var d = ( r && r.success && r.data ) ? r.data : {};
			active = !!( d.scan_running || d.check_running );
			if ( ! active ) {
				// Idle: stop polling; Heartbeat reports when a job starts.
				return;
			}
			schedule( d.busy ? busyMs : 0 );
		} ).fail( function ( xhr ) {
			busy = false;
			if ( xhr && ( xhr.status === 401 || xhr.status === 403 ) ) {
				return;
			}
			// Back off after a failed/timed-out worker instead of retrying at once.
			if ( active ) {
				schedule( failMs );
			}
		} );
	}

	$( function () {
		if ( active ) {
			schedule( 0 );
		}
	} );

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		if ( data ) {
			data.tsoliin_bg = 1;
		}
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data || ! data.tsoliin_bg ) {
			return;
		}
		if ( data.tsoliin_bg.scan_running || data.tsoliin_bg.check_running ) {
			active = true;
			if ( ! busy ) {
				schedule( 0 );
			}
		}
	} );
}( jQuery ) );
