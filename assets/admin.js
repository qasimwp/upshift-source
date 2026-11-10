/* global jQuery, upshiftScan */
/**
 * Check tab: start / continue / stop, the batch loop (spec §7.6), card filter, copy and print (docs/UI-v1.md §3).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.upshiftScan;
	var scanId = 0;
	var failures = 0;
	var stopped = false;

	// Scanning pace (DECISIONS D54, D57): a 1 s pause after each batch. After the first resource error, and from the
	// start on LiteSpeed hosts (cfg.startGentle), the gentle pace applies: a pause as long as the batch took (3-60 s),
	// doubled, batches of 8 s, and longer waits after further resource errors.
	var REST_DEFAULT = 1000;
	var REST_MIN = 3000;
	var REST_MAX = 60000;
	var BUSY_WAIT = 3000;
	var POLL_WAIT = 10000;
	var RESOURCE_WAITS = [ 60, 120, 240, 300 ];
	var RESOURCE_TRIES = 8;
	var GENTLE_BUDGET = 8;
	var resourceFailures = 0;
	var gentle = !! ( cfg && cfg.startGentle ); // Also set after the first resource error, for the rest of this page run.
	var inFlight = false; // A batch request is on its way: never send a second one.
	var loop = 0; // Bumped by Resume and Cancel: replies and timers of an older loop are ignored.
	var timer = 0;
	var ticker = 0;
	var starting = false; // The start request is on its way, so the scan has no id yet.
	var stopAfterStart = false; // Stop was pressed during that request.

	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return text.replace( /%%|%(\d+)\$s|%s/g, function ( match, n ) {
			if ( '%%' === match ) {
				return '%';
			}
			return String( n ? args[ n - 1 ] : args[ 0 ] );
		} );
	}

	// wp.i18n can be missing if an optimization plugin removed it: English plurals still work then.
	function plural( single, many, number ) {
		if ( window.wp && window.wp.i18n ) {
			return window.wp.i18n._n( single, many, number, 'upshift' );
		}
		return 1 === number ? single : many;
	}

	function num( value ) {
		return Number( value ).toLocaleString();
	}

	// Same strings as CheckTab::progress_line().
	function progressLine( p ) {
		if ( ! p.components_total ) {
			return cfg.i18n.starting;
		}
		var checked = fmt( plural( '%1$s of %2$s plugin checked.', '%1$s of %2$s plugins checked.', p.components_total ), num( p.components_done ), num( p.components_total ) );
		if ( ! p.current_component_label || ! p.component_files_total ) {
			return checked;
		}
		return fmt( plural( '%1$s Now checking %2$s (%3$s of %4$s file).', '%1$s Now checking %2$s (%3$s of %4$s files).', p.component_files_total ), checked, p.current_component_label, num( p.component_files_done ), num( p.component_files_total ) );
	}

	// WordPress re-checks its pinned menu only on scroll and resize. Once our page gets shorter than the menu, a pinned
	// (position: fixed) menu adds no height, so the page can't scroll and the menu stays stuck (DECISIONS D60).
	function repinMenu() {
		$( document ).trigger( 'wp-pin-menu' );
	}

	function show( message, progress ) {
		var $box = $( '#upshift-scan-progress' ).prop( 'hidden', false );
		var $results = $( '#upshift-results' );
		var shrinks = ! $results.prop( 'hidden' );
		$( '#upshift-strip' ).prop( 'hidden', true );
		$results.prop( 'hidden', true );
		if ( shrinks ) {
			repinMenu();
		}
		$box.find( '.upshift-scan-message' ).text( message );
		if ( progress && progress.files_total > 0 ) {
			$box.find( 'progress' ).attr( { max: progress.files_total, value: progress.files_done } );
			$box.find( '.upshift-percent' ).text( fmt( cfg.i18n.percent, num( Math.floor( 100 * progress.files_done / progress.files_total ) ) ) );
		}
		if ( progress && progress.files_reused > 0 ) {
			$box.find( '.upshift-reused' ).prop( 'hidden', false ).text( fmt( plural( 'Reused %s unchanged file.', 'Reused %s unchanged files.', progress.files_reused ), num( progress.files_reused ) ) );
		}
	}

	function showBack() {
		$( '#upshift-back' ).prop( 'hidden', false );
	}

	// "Speed up" while the check runs in the background (with its one-line hint); "Continue" when it is paused.
	function setBackground( background ) {
		var $button = $( '#upshift-scan-resume' );
		$button.text( background ? $button.attr( 'data-speed' ) : $button.attr( 'data-continue' ) );
		$( '#upshift-speedup-note' ).prop( 'hidden', ! background );
	}

	function setRunning( running ) {
		$( '#upshift-scan-start' ).prop( 'disabled', running );
		$( '#upshift-scan-resume' ).toggle( ! running && scanId > 0 ).prop( 'disabled', running );
		$( '#upshift-scan-cancel' ).toggle( scanId > 0 || starting );
		setBackground( false );
	}

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: 'upshift_scan_' + action, nonce: cfg.nonce }, data ) );
	}

	// Short plain text of a non-JSON reply (an HTML error page, "-1", "0"); shown with .text(), never as HTML.
	function plainText( text ) {
		return String( text )
			.replace( /<(script|style)[^>]*>[\s\S]*?<\/\1>/gi, ' ' )
			.replace( /<[^>]*>/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim()
			.slice( 0, 150 );
	}

	// The JSON message if there is one, else the HTTP status and the start of the reply. statusText is empty on
	// HTTP/2, so it is not used (DECISIONS D53).
	function errorMessage( xhr, response ) {
		var json = response && 'object' === typeof response ? response : xhr && xhr.responseJSON;
		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}
		if ( ! xhr || ! xhr.status ) {
			return cfg.i18n.noResponse;
		}
		var text = 'string' === typeof xhr.responseText ? plainText( xhr.responseText ) : '';
		return 'HTTP ' + xhr.status + ( '' !== text ? ': "' + text + '"' : '' );
	}

	// Overload replies of shared hosts and CDNs (LiteSpeed 508, Cloudflare 520–524) and no reply at all.
	function isResourceError( xhr ) {
		var s = xhr ? xhr.status : 0;
		return ! s || 429 === s || 502 === s || 503 === s || 504 === s || 508 === s || ( s >= 520 && s <= 524 );
	}

	function clearPending() {
		clearTimeout( timer );
		clearInterval( ticker );
		timer = 0;
		ticker = 0;
	}

	// Runs next() after ms, showing text( seconds left ) once a second. Only one wait exists at a time.
	function wait( ms, text, next ) {
		var mine = loop;
		var end = Date.now() + ms;
		var tick = function () {
			show( text( Math.max( 0, Math.ceil( ( end - Date.now() ) / 1000 ) ) ) );
		};
		clearPending();
		tick();
		ticker = setInterval( tick, 1000 );
		timer = setTimeout( function () {
			clearPending();
			if ( mine === loop && ! stopped ) {
				next();
			}
		}, ms );
	}

	function fail( message ) {
		clearPending();
		show( fmt( cfg.i18n.failed, message ) );
		showBack();
		setRunning( false );
	}

	function retry( message ) {
		failures++;
		if ( failures > 3 ) {
			fail( message );
			return;
		}
		wait( 2000, function () {
			return fmt( cfg.i18n.retry, failures );
		}, batch );
	}

	// 60, 120, 240, then 300 s, up to 8 tries; then "paused" with Resume, not a scan error. Resume tries at once.
	function resourceRetry( xhr ) {
		var code = xhr && xhr.status ? 'HTTP ' + xhr.status : cfg.i18n.noResponse;
		gentle = true;
		resourceFailures++;
		if ( resourceFailures > RESOURCE_TRIES ) {
			clearPending();
			show( fmt( cfg.i18n.paused, code ) );
			showBack();
			setRunning( false );
			return;
		}
		var seconds = RESOURCE_WAITS[ Math.min( resourceFailures, RESOURCE_WAITS.length ) - 1 ];
		var attempt = resourceFailures;
		wait( seconds * 1000, function ( left ) {
			return fmt( cfg.i18n.resourceWait, code, left, attempt, RESOURCE_TRIES );
		}, batch );
		$( '#upshift-scan-resume' ).show().prop( 'disabled', false );
		setBackground( false );
	}

	function batch() {
		if ( stopped || inFlight ) {
			return;
		}
		var mine = loop;
		var sent = Date.now();
		var data = { scan_id: scanId };
		if ( gentle ) {
			data.budget = GENTLE_BUDGET;
			data.slow = 1; // The page waits minutes between batches: the background step keeps off for longer.
		}
		inFlight = true;
		setRunning( true );
		post( 'batch', data )
			.always( function () {
				inFlight = false;
			} )
			.done( function ( response, textStatus, xhr ) {
				if ( mine !== loop || stopped ) {
					return;
				}
				if ( ! response || ! response.success ) {
					if ( response && response.data && 'busy' === response.data.code ) {
						wait( BUSY_WAIT, function () {
							return cfg.i18n.busy;
						}, batch );
						return;
					}
					retry( errorMessage( xhr, response ) );
					return;
				}
				failures = 0;
				resourceFailures = 0;
				var p = response.data;
				var line = progressLine( p );
				show( line, p );
				if ( 'done' === p.status ) {
					show( cfg.i18n.done, p );
					window.location.reload();
				} else if ( 'running' === p.status ) {
					// Rest as long as the batch took (3–60 s), twice that after a resource error.
					var rest = gentle ? Math.min( REST_MAX, Math.max( REST_MIN, Date.now() - sent ) ) * 2 : REST_DEFAULT;
					wait( rest, function () {
						return line;
					}, batch );
				} else if ( 'cancelled' === p.status ) {
					window.location.reload();
				} else {
					fail( p.error || p.status );
				}
			} )
			.fail( function ( xhr ) {
				if ( mine !== loop || stopped ) {
					return;
				}
				if ( isResourceError( xhr ) ) {
					resourceRetry( xhr );
					return;
				}
				retry( errorMessage( xhr ) );
			} );
	}

	function run( id ) {
		if ( inFlight ) {
			return;
		}
		clearPending();
		loop++;
		scanId = id;
		failures = 0;
		resourceFailures = 0;
		stopped = false;
		setRunning( true );
		batch();
	}

	// Without upshiftScan (removed by an optimization plugin) the buttons can't work: say so next to them (DECISIONS D53).
	$( function () {
		if ( cfg ) {
			return;
		}
		$( '[data-upshift-no-config]' ).each( function () {
			$( '<span class="upshift-no-config" role="alert" style="color:#b32d2e;margin-left:0.5em"></span>' )
				.text( $( this ).attr( 'data-upshift-no-config' ) )
				.insertAfter( this );
		} );
	} );

	// "Update usage data now": one rollup step per request until the job is done (DECISIONS D48).
	$( function () {
		var $button = $( '#upshift-rollup-now' );
		if ( ! cfg || ! $button.length ) {
			return;
		}
		var $message = $( '#upshift-rollup-message' );
		var rollupFailures = 0;
		var rollupBusy = 0;

		function step( start ) {
			$.post( cfg.ajaxUrl, { action: 'upshift_rollup_step', nonce: cfg.rollupNonce, start: start ? 1 : 0 } )
				.done( function ( response, textStatus, xhr ) {
					if ( ! response || ! response.success ) {
						again( errorMessage( xhr, response ), start );
						return;
					}
					rollupFailures = 0;
					var p = response.data;
					if ( p.busy ) {
						// The lock TTL is 60 s, so 60 tries (2 min) means something else is wrong (DECISIONS D51).
						rollupBusy++;
						if ( rollupBusy > 60 ) {
							$message.text( cfg.i18n.rollupBusyLong );
							$button.prop( 'disabled', false );
							return;
						}
						$message.text( cfg.i18n.rollupBusy );
						setTimeout( function () {
							step( start );
						}, 2000 );
						return;
					}
					rollupBusy = 0;
					if ( p.running ) {
						$message.text( fmt( cfg.i18n.rollupProgress, p.percent, p.phase ) );
						step( false );
					} else {
						$message.text( cfg.i18n.rollupDone );
						window.location.reload();
					}
				} )
				.fail( function ( xhr ) {
					again( errorMessage( xhr ), start );
				} );
		}

		function again( message, start ) {
			rollupFailures++;
			if ( rollupFailures > 3 ) {
				$message.text( fmt( cfg.i18n.rollupFailed, message ) );
				$button.prop( 'disabled', false );
				return;
			}
			$message.text( fmt( cfg.i18n.rollupRetry, rollupFailures ) );
			setTimeout( function () {
				step( start );
			}, 2000 );
		}

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );
			rollupFailures = 0;
			rollupBusy = 0;
			step( true );
		} );
	} );

	// Spike metrics: copy the Markdown textarea.
	$( function () {
		$( '#upshift-copy-markdown' ).on( 'click', function () {
			var area = document.getElementById( 'upshift-markdown' );
			area.focus();
			area.select();
			var done = function () {
				$( '#upshift-copy-status' ).text( cfg.i18n.copied );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( area.value ).then( done );
			} else if ( document.execCommand( 'copy' ) ) {
				done();
			}
		} );
	} );

	$( function () {
		// The 8.5 note shows only while 8.5 is the selected target.
		$( '#upshift-target' ).on( 'change', function () {
			$( '#upshift-target-note' ).toggle( '8.5' === $( this ).val() );
		} );

		var $controls = $( '#upshift-scan-controls' );
		if ( ! cfg || ! $controls.length ) {
			return;
		}
		scanId = parseInt( $controls.data( 'running' ), 10 ) || 0;

		// A check left running (the page was closed), which WP-Cron may be carrying on (DECISIONS D57): follow it here.
		// Only asks for its status and starts nothing; Continue (which bumps loop) takes over from this page.
		function poll() {
			if ( 0 !== loop ) {
				return;
			}
			post( 'status', { scan_id: scanId } ).always( function ( response ) {
				if ( 0 !== loop ) {
					return;
				}
				var p = response && response.success ? response.data : null;
				if ( p && 'running' !== p.status ) {
					window.location.reload();
					return;
				}
				if ( p ) {
					show( progressLine( p ), p );
					setBackground( !! p.background );
				}
				setTimeout( poll, POLL_WAIT );
			} );
		}
		if ( scanId > 0 ) {
			poll();
		}

		$( '#upshift-scan-start' ).on( 'click', function () {
			starting = true;
			stopAfterStart = false;
			setRunning( true );
			$( '#upshift-run-from' ).text( $( '#upshift-from' ).val() );
			$( '#upshift-run-target' ).text( $( '#upshift-target' ).val() );
			show( cfg.i18n.starting );
			post( 'start', {
				from: $( '#upshift-from' ).val(),
				target: $( '#upshift-target' ).val(),
				// The "Everything installed" radio button.
				inactive: $( '#upshift-scan-inactive' ).is( ':checked' ) ? 1 : 0
			} )
				.done( function ( response, textStatus, xhr ) {
					starting = false;
					if ( response && response.success ) {
						if ( stopAfterStart ) {
							// Stop was pressed before the id was known: cancel this scan, never send a batch.
							scanId = response.data.scan_id;
							post( 'cancel', { scan_id: scanId } ).always( function () {
								window.location.reload();
							} );
							return;
						}
						run( response.data.scan_id );
						return;
					}
					if ( stopAfterStart ) {
						// Nothing of ours started. A check that was already running is left alone.
						window.location.reload();
						return;
					}
					if ( response && response.data && 'running' === response.data.code ) {
						scanId = response.data.scan_id;
					}
					fail( errorMessage( xhr, response ) );
				} )
				.fail( function ( xhr ) {
					starting = false;
					if ( stopAfterStart ) {
						window.location.reload();
						return;
					}
					fail( errorMessage( xhr ) );
				} );
		} );

		$( '#upshift-scan-resume' ).on( 'click', function () {
			run( scanId );
		} );

		$( '#upshift-scan-cancel' ).on( 'click', function () {
			if ( ! window.confirm( cfg.i18n.confirm ) ) {
				return;
			}
			if ( starting ) {
				stopAfterStart = true;
				$( this ).prop( 'disabled', true );
				show( cfg.i18n.stopping );
				return;
			}
			stopped = true;
			clearPending();
			loop++;
			post( 'cancel', { scan_id: scanId } ).always( function () {
				window.location.reload();
			} );
		} );
	} );
	// Count cards filter the list; pressing the same card (or "Show all plugins") shows everything again.
	$( function () {
		var $cards = $( '.upshift-count' );
		var $items = $( '#upshift-active-list .upshift-item' );
		var $group = $( '#upshift-ready-group' );
		var $status = $( '#upshift-filter-status' );
		var $all = $( '#upshift-show-all' );
		var active = '';
		var groupWasOpen = false;

		function filterLine( filter, count ) {
			switch ( filter ) {
				case 'break':
					return fmt( plural( 'Showing %s that will break.', 'Showing %s that will break.', count ), num( count ) );
				case 'host':
					return fmt( plural( 'Showing %s to ask your host about.', 'Showing %s to ask your host about.', count ), num( count ) );
				case 'later':
					return fmt( plural( 'Showing %s that works but needs a fix later.', 'Showing %s that work but need a fix later.', count ), num( count ) );
				case 'ready':
					return fmt( plural( 'Showing %s that is ready.', 'Showing %s that are ready.', count ), num( count ) );
			}
			return '';
		}

		function apply( filter ) {
			var shown = 0;

			$cards.each( function () {
				var $card = $( this );
				var current = filter === $card.attr( 'data-filter' );
				$card.attr( 'aria-pressed', current ? 'true' : 'false' );
				$card.find( '.upshift-count__show' ).text( $card.find( '.upshift-count__show' ).attr( current ? 'data-showall' : 'data-show' ) );
			} );
			$items.each( function () {
				var match = ! filter || $( this ).attr( 'data-status' ) === filter;
				shown += match ? 1 : 0;
				this.hidden = ! match;
			} );
			// The group of ready plugins opens for the Ready card and goes away for the others.
			if ( $group.length ) {
				$group.prop( 'hidden', !! filter && 'ready' !== filter );
				if ( 'ready' === filter ) {
					groupWasOpen = 'ready' === active ? groupWasOpen : $group.prop( 'open' );
					$group.prop( 'open', true );
				} else if ( 'ready' === active ) {
					$group.prop( 'open', groupWasOpen );
				}
			}
			active = filter;
			$all.prop( 'hidden', ! filter );
			$status.parent().toggleClass( 'is-active', !! filter );
			$status.text( filter ? filterLine( filter, shown ) : '' );
			repinMenu();
		}

		// A closed plugin row can make the page shorter too. "toggle" doesn't bubble, so it is caught on the way down.
		document.addEventListener( 'toggle', function ( event ) {
			if ( event.target.closest && event.target.closest( '.upshift-wrap' ) ) {
				repinMenu();
			}
		}, true );

		$cards.on( 'click', function () {
			var $card = $( this );
			apply( 'true' === $card.attr( 'aria-pressed' ) ? '' : $card.attr( 'data-filter' ) );
		} );
		$all.on( 'click', function () {
			apply( '' );
		} );
	} );

	// Settings: "Delete collected data" only submits after the owner confirms in plain words.
	$( function () {
		$( '#upshift-delete-data' ).on( 'click', function () {
			if ( window.confirm( $( this ).attr( 'data-confirm' ) ) ) {
				document.getElementById( 'upshift-delete-form' ).submit();
			}
		} );
	} );

	// Copy buttons: a message from data-copy, or the whole report from a textarea.
	$( function () {
		function fallbackCopy( text ) {
			var area = document.createElement( 'textarea' );
			var ok = false;
			area.value = text;
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			return ok;
		}

		$( '.upshift-copy' ).on( 'click', function () {
			var $button = $( this );
			var from = $button.attr( 'data-copy-from' );
			var text = from ? document.getElementById( from ).value : $button.attr( 'data-copy' );
			var label = $button.text();
			var done = function () {
				$( '#upshift-copy-status' ).text( cfg.i18n.copied );
				$button.text( cfg.i18n.copied );
				setTimeout( function () {
					$button.text( label );
				}, 2000 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done, function () {
					if ( fallbackCopy( text ) ) {
						done();
					}
				} );
			} else if ( fallbackCopy( text ) ) {
				done();
			}
		} );
	} );

	// Print: every plugin opens for paper and closes again afterwards. Technical details stay out (see admin.css).
	$( function () {
		var opened = [];

		$( '#upshift-print' ).on( 'click', function () {
			window.print();
		} );
		window.addEventListener( 'beforeprint', function () {
			opened = $( 'details.upshift-item:not([open])' ).prop( 'open', true ).get();
		} );
		window.addEventListener( 'afterprint', function () {
			$( opened ).prop( 'open', false );
			opened = [];
		} );
	} );
}( jQuery ) );
