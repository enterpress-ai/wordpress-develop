/**
 * EnterPress Realtime API
 *
 * Replaces the WordPress Heartbeat polling API with a WebSocket-based
 * realtime system powered by Supabase Realtime. Provides backward-compatible
 * wp.heartbeat shim so existing plugins continue to work.
 *
 * Custom jQuery events (backward-compatible):
 * - heartbeat-send
 * - heartbeat-tick
 * - heartbeat-error
 * - heartbeat-connection-lost
 * - heartbeat-connection-restored
 * - heartbeat-nonces-expired
 *
 * @since EnterPress 1.0.0
 * @output wp-includes/js/realtime.js
 */

( function( $, window, undefined ) {

	var Realtime = function() {
		var $document = $(document),
			settings = {
				url: typeof wpRealtimeSettings !== 'undefined' ? wpRealtimeSettings.supabaseUrl : '',
				anonKey: typeof wpRealtimeSettings !== 'undefined' ? wpRealtimeSettings.supabaseAnonKey : '',
				tablePrefix: typeof wpRealtimeSettings !== 'undefined' ? wpRealtimeSettings.tablePrefix : 'wp_',
				ajaxUrl: typeof wpRealtimeSettings !== 'undefined' ? wpRealtimeSettings.ajaxUrl : '',
				nonce: typeof wpRealtimeSettings !== 'undefined' ? wpRealtimeSettings.nonce : '',
				screenId: typeof pagenow !== 'undefined' ? pagenow : 'front',
				hasFocus: true,
				connected: false,
				connectionError: false,
				autosaveInterval: 60
			},
			queue = {},
			channel = null,
			client = null,
			autosaveTimer = null;

		/**
		 * Initialize Supabase Realtime client and subscribe to channels.
		 */
		function initialize() {
			if ( ! settings.url || ! settings.anonKey ) {
				return;
			}

			if ( typeof window.supabase === 'undefined' || typeof window.supabase.createClient === 'undefined' ) {
				return;
			}

			client = window.supabase.createClient( settings.url, settings.anonKey, {
				realtime: {
					params: {
						eventsPerSecond: 2
					}
				}
			});

			var postmetaTable = settings.tablePrefix + 'postmeta';
			var postsTable = settings.tablePrefix + 'posts';

			channel = client.channel( 'enterpress-realtime' )
				.on(
					'postgres_changes',
					{ event: '*', schema: 'public', table: postmetaTable, filter: 'meta_key=eq._edit_lock' },
					function( payload ) {
						handlePostmetaChange( payload );
					}
				)
				.on(
					'postgres_changes',
					{ event: '*', schema: 'public', table: postsTable, filter: 'post_status=eq.auto-draft' },
					function( payload ) {
						handlePostChange( payload );
					}
				)
				.subscribe( function( status ) {
					if ( status === 'SUBSCRIBED' ) {
						settings.connected = true;
						if ( settings.connectionError ) {
							settings.connectionError = false;
							$document.trigger( 'heartbeat-connection-restored' );
						}
					} else if ( status === 'CLOSED' || status === 'CHANNEL_ERROR' ) {
						if ( settings.connected ) {
							settings.connected = false;
							settings.connectionError = true;
							$document.trigger( 'heartbeat-connection-lost' );
						}
					}
				});

			// Start autosave interval.
			startAutosave();

			// Track focus.
			$( window ).on( 'focus', function() {
				settings.hasFocus = true;
			}).on( 'blur', function() {
				settings.hasFocus = false;
			});
		}

		/**
		 * Handle postmeta changes (edit locks, etc.).
		 *
		 * Translates Supabase Realtime payloads into the keyed-object format
		 * expected by WordPress Heartbeat listeners.
		 */
		function handlePostmetaChange( payload ) {
			var data = {};

			if ( payload.new && payload.new.meta_key === '_edit_lock' ) {
				data['wp-edit-lock'] = {
					post_id: payload.new.post_id,
					lock: payload.new.meta_value
				};
			}

			$document.trigger( 'heartbeat-tick', [ data, 'realtime' ] );
		}

		/**
		 * Handle post changes (autosave notifications, etc.).
		 *
		 * Translates Supabase Realtime payloads into the keyed-object format
		 * expected by WordPress Heartbeat listeners.
		 */
		function handlePostChange( payload ) {
			var data = {};

			if ( payload.new ) {
				data['wp-autosave'] = {
					post_id: payload.new.ID || payload.new.id,
					post_status: payload.new.post_status,
					post_modified: payload.new.post_modified
				};
			}

			$document.trigger( 'heartbeat-tick', [ data, 'realtime' ] );
		}

		/**
		 * Start periodic autosave trigger.
		 */
		function startAutosave() {
			if ( autosaveTimer ) {
				return;
			}

			autosaveTimer = setInterval( function() {
				if ( ! settings.hasFocus ) {
					return;
				}

				var data = {};
				$document.trigger( 'heartbeat-send', [ data ] );

				// If autosave data was enqueued, post it to admin-ajax.php.
				if ( data.wp_autosave || ! $.isEmptyObject( queue ) ) {
					var sendData = $.extend( {}, data, queue );
					sendData.action = 'heartbeat';
					sendData._nonce = settings.nonce;
					sendData.screen_id = settings.screenId;
					sendData.has_focus = settings.hasFocus;

					$.ajax({
						url: settings.ajaxUrl,
						type: 'POST',
						data: sendData,
						dataType: 'json'
					}).done( function( response ) {
						if ( response ) {
							$document.trigger( 'heartbeat-tick', [ response, 'realtime' ] );
						}
					}).fail( function() {
						$document.trigger( 'heartbeat-error', [ null, 'realtime', '' ] );
					});
				}
			}, settings.autosaveInterval * 1000 );
		}

		// Run initialization immediately.
		initialize();

		/**
		 * Backward-compatible API matching wp.heartbeat.
		 */
		return {
			enqueue: function( handle, data, override ) {
				if ( handle ) {
					if ( override ) {
						queue[ handle ] = data;
					} else if ( ! queue.hasOwnProperty( handle ) ) {
						queue[ handle ] = data;
					}
				}
				return this;
			},

			dequeue: function( handle ) {
				delete queue[ handle ];
				return this;
			},

			isQueued: function( handle ) {
				return queue.hasOwnProperty( handle );
			},

			getQueuedItem: function( handle ) {
				return queue.hasOwnProperty( handle ) ? queue[ handle ] : undefined;
			},

			interval: function( speed ) {
				// In realtime mode, interval is not meaningful but we accept the call.
				if ( speed ) {
					settings.autosaveInterval = Math.max( 15, parseInt( speed, 10 ) );
				}
				return settings.autosaveInterval;
			},

			connectNow: function() {
				if ( ! settings.connected && client ) {
					channel && channel.subscribe();
				}
				return this;
			},

			disableSuspend: function() {
				// No-op in realtime mode. WebSocket stays connected.
				return this;
			},

			hasFocus: function() {
				return settings.hasFocus;
			},

			hasConnectionError: function() {
				return settings.connectionError;
			}
		};
	};

	// Initialize and expose as both wp.heartbeat (backward compat) and wp.realtime.
	$( function() {
		var instance = new Realtime();

		if ( typeof window.wp === 'undefined' ) {
			window.wp = {};
		}

		window.wp.heartbeat = instance;
		window.wp.realtime = instance;
	});

}( jQuery, window ));
