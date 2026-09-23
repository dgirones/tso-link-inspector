/**
 * TSO Link Inspector – Admin JS v1.0.6
 *
 * Background scan/check: AJAX ticks on this screen (full time budget, no pause
 * between batches). Other wp-admin pages keep the job alive via bg-worker.js.
 */
/* global tsoliinData */
( function ( $ ) {
	'use strict';

	var LC = {

		// ---------------------------------------------------------------
		// State
		// ---------------------------------------------------------------
		scanning       : false,
		scanAborted    : false,
		scanCompleted  : false,
		scanStartPending : false,
		checkStartPending: false,
		polling        : false,
		pollTimer      : null,
		statsTimer     : null,
		completed      : false,   // Guard: prevents check reload loop
		checkSessionActive : false,
		scanSessionActive  : false,
		scanTickBusy       : false,
		checkTickBusy      : false,
		scanTickTimer      : null,
		checkTickTimer     : null,
		scanChainCheck     : false,
		editLinkId  : 0,
		editOldUrl  : '',
		editPostId  : 0,
		editLinkType: 'link',
		previewTimer: null,
		searchTimer   : null,
		searchXhr     : null,
		listNavXhr    : null,
		lastSearchVal : '',
		suggestXhr      : null,
		suggestTrigger  : null,
		suggestRequestId: 0,
		listReloadTimer : null,
		_pendingAjax    : 0,
		_bulkInProgress : false,

		/**
		 * Escape text for safe HTML insertion.
		 *
		 * @param {string} text Raw text.
		 * @return {string}
		 */
		escapeHtml: function ( text ) {
			return $( '<div/>' ).text( text == null ? '' : String( text ) ).html();
		},

		/**
		 * Remove all suggestion panels stacked after a list row.
		 *
		 * @param {jQuery} $row Data table row.
		 */
		removeSuggestPanelsForRow: function ( $row ) {
			if ( ! $row || ! $row.length ) {
				return;
			}
			var $next = $row.next();
			while ( $next.length && $next.hasClass( 'tsoliin-suggest-row' ) ) {
				var $remove = $next;
				$next = $next.next();
				$remove.remove();
			}
		},

		/**
		 * Find the main list row for a link ID.
		 *
		 * @param {number} linkId Link row ID.
		 * @return {jQuery}
		 */
		findLinkRow: function ( linkId ) {
			var id = String( linkId );
			var $byCb = $( 'input[name="link_ids[]"][value="' + id + '"]' ).closest( 'tr' );
			if ( $byCb.length ) {
				return $byCb.first();
			}
			return $( 'tr' ).filter( function () {
				return $( this ).find(
					'.tsoliin-edit-link[data-id="' + id + '"], .tsoliin-suggest[data-id="' + id + '"], .tsoliin-make-relative[data-id="' + id + '"], .tsoliin-upgrade-https[data-id="' + id + '"], .tsoliin-recheck[data-id="' + id + '"]'
				).length > 0;
			} ).first();
		},

		/**
		 * jQuery.ajax wrapper that counts in-flight plugin requests (so Check now can wait).
		 *
		 * @param {Object} opts $.ajax options.
		 * @return {jqXHR}
		 */
		trackedAjax: function ( opts ) {
			var self = this;
			self._pendingAjax = ( self._pendingAjax || 0 ) + 1;
			var origComplete = opts.complete;
			opts.complete = function () {
				self._pendingAjax = Math.max( 0, ( self._pendingAjax || 1 ) - 1 );
				if ( typeof origComplete === 'function' ) {
					origComplete.apply( this, arguments );
				}
			};
			return $.ajax( opts );
		},

		/**
		 * Whether a Check now reload would interrupt the user.
		 *
		 * @param {number} started   Date.now() when waiting began.
		 * @param {number} maxWaitMs Cap for short AJAX (not modal/scan/bulk).
		 * @return {boolean}
		 */
		shouldDeferCheckReload: function ( started, maxWaitMs ) {
			if ( this.isScanBlockingCheck() ) {
				return true;
			}
			if ( this.polling || parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
				return true;
			}
			if ( this._bulkInProgress ) {
				return true;
			}
			if ( this.$modal && this.$modal.is( ':visible' ) ) {
				return true;
			}
			if ( ( Date.now() - started ) >= maxWaitMs ) {
				return false;
			}
			if ( ( this._pendingAjax || 0 ) > 0 ) {
				return true;
			}
			if ( this.searchXhr && this.searchXhr.readyState !== 4 ) {
				return true;
			}
			if ( this.listNavXhr && this.listNavXhr.readyState !== 4 ) {
				return true;
			}
			if ( this.suggestXhr && this.suggestXhr.readyState !== 4 ) {
				return true;
			}
			return false;
		},

		/**
		 * Whether scan work is active or paused mid-run (HTTP check must wait).
		 *
		 * @return {boolean}
		 */
		isScanBlockingCheck: function () {
			return this.scanning
				|| parseInt( tsoliinData.scanRunning, 10 ) === 1
				|| parseInt( tsoliinData.scanResumable, 10 ) === 1;
		},

		// ---------------------------------------------------------------
		// Init
		// ---------------------------------------------------------------
		repositionScreenMeta: function () {
			var $wrap  = $( '.tsoliin-wrap' );
			var $slot  = $( '#tsoliin-screen-meta-slot' );
			var $links = $( '#screen-meta-links' );
			var $meta  = $( '#screen-meta' );
			var $head  = $wrap.find( '.tsoliin-page-head' ).first();
			if ( $slot.length && $links.length && ! $links.closest( '#tsoliin-screen-meta-slot' ).length ) {
				$links.appendTo( $slot );
			}
			// Keep the Screen Options panel with the plugin header (not orphaned at the page top).
			if ( $head.length && $meta.length && ! $meta.data( 'tsoliinRepositioned' ) ) {
				$head.after( $meta );
				$meta.data( 'tsoliinRepositioned', true );
			}
		},

		markScrollBeforePostNav: function () {
			try {
				window.sessionStorage.setItem( 'tsoliinScrollToList', '1' );
			} catch ( err ) {
				// Ignore — full navigation still works without scroll restore.
			}
		},

		maybeScrollToListOnLoad: function () {
			var should = false;
			try {
				if ( '1' === window.sessionStorage.getItem( 'tsoliinScrollToList' ) ) {
					should = true;
					window.sessionStorage.removeItem( 'tsoliinScrollToList' );
				}
			} catch ( err ) {
				// Ignore storage errors.
			}
			if ( ! should && /[?&]post_id=\d+/.test( window.location.search ) ) {
				should = true;
			}
			if ( ! should || ! document.getElementById( 'tsoliin-list-table-region' ) ) {
				return;
			}
			var self = this;
			self.restoreListScroll();
			window.requestAnimationFrame( function () {
				self.restoreListScroll();
			} );
		},

		init: function () {
			this.repositionScreenMeta();
			this.$form         = $( '#tsoliin-list-form' );
			this.$startBtn     = $( '#tsoliin-start-scan' );
			this.$stopScanBtn  = $( '#tsoliin-stop-scan' );
			this.$restartScanBtn = $( '#tsoliin-restart-scan' );
			this.$discardScanBtn = $( '#tsoliin-discard-scan' );
			this.$discardCheckBtn = $( '#tsoliin-discard-check' );
			this.$discardAllBtn = $( '#tsoliin-discard-all' );
			this.$checkBtn     = $( '#tsoliin-start-check' );
			this.$restartBtn   = $( '#tsoliin-restart-check' );
			this.$stopBtn      = $( '#tsoliin-stop-check' );
			this.$progress     = $( '#tsoliin-scan-progress' );
			this.$progressBar  = this.$progress.find( '.tsoliin-progress__bar' );
			this.$progressLbl  = this.$progress.find( '.tsoliin-progress__label' );
			this.$checkProg    = $( '#tsoliin-check-progress' );
			this.$checkBar     = this.$checkProg.find( '.tsoliin-progress__bar' );
			this.$checkLbl     = this.$checkProg.find( '.tsoliin-progress__label' );
			this.$modal        = $( '#tsoliin-modal' );
			this.$modalOldUrl  = $( '#tsoliin-modal-old-url' );
			this.$newUrlInput  = $( '#tsoliin-new-url' );
			this.$newAnchorInput = $( '#tsoliin-new-anchor' );
			this.$anchorRow      = $( '#tsoliin-modal-anchor-row' );
			this.$anchorLabel    = $( '#tsoliin-modal-anchor-label' );
			this.$anchorNote     = $( '#tsoliin-modal-anchor-note' );
			this.$modalSave    = $( '#tsoliin-modal-save' );
			this.$modalCancel  = $( '#tsoliin-modal-cancel' );
			this.$applyAnyway  = $( '#tsoliin-apply-anyway' );
			this.$ignoreDomain = $( '#tsoliin-ignore-domain' );
			this.$modalSpinner = $( '.tsoliin-modal__spinner' );
			this.$feedback     = $( '#tsoliin-modal-feedback' );
			this.$previewPanel = $( '#tsoliin-modal-preview' );
			this.$revisionNote = $( '#tsoliin-modal-revision-note' );
			this.$previewBefore = $( '#tsoliin-preview-before' );
			this.$previewAfter  = $( '#tsoliin-preview-after' );

			this.bindEvents();
			this.bindLiveSearch();
			this.maybeScrollToListOnLoad();
			this.initThemeSwitcher();
			$( document ).on( 'heartbeat-send', function ( event, data ) {
				if ( data ) {
					data.tsoliin_bg = 1;
				}
			} );

			var scanRunning = parseInt( tsoliinData.scanRunning, 10 ) === 1;
			var checkRunning = parseInt( tsoliinData.bgRunning, 10 ) === 1;

			if ( scanRunning ) {
				this.scanning = true;
				this.scanSessionActive = true;
				this.$progress.show();
				this.$startBtn.prop( 'disabled', false );
				this.$stopScanBtn.show();
				if ( this.$restartScanBtn && this.$restartScanBtn.length ) {
					this.$restartScanBtn.hide();
				}
				this.$checkBtn.prop(
					'disabled',
					! parseInt( tsoliinData.bgRunning, 10 ) || this.checkSessionActive
				);
				if ( tsoliinData.scanPct ) {
					this.updateProgress( tsoliinData.scanPct, tsoliinData.i18n.scanning );
				}
			} else if ( tsoliinData.scanError ) {
				this.$progress.show();
				this.$progressBar.css( { width: '100%', background: '#cc1818' } );
				this.$progressLbl.text( tsoliinData.scanError );
				this.resetScanButton();
				if ( parseInt( tsoliinData.scanResumable, 10 ) === 1 && this.$restartScanBtn && this.$restartScanBtn.length ) {
					this.$restartScanBtn.show();
				}
			}

			if ( checkRunning ) {
				this.checkSessionActive = true;
				this.$checkProg.show();
				this.$checkBtn.prop( 'disabled', false );
				this.$restartBtn.hide();
				this.$startBtn.prop( 'disabled', scanRunning );
				this.$stopBtn.show();
			} else {
				var pendingOnLoad = parseInt( tsoliinData.pendingCheck, 10 ) || 0;
				var pctOnLoad     = parseInt( tsoliinData.bgPct, 10 ) || 0;
				var scanActive    = this.isScanBlockingCheck();
				var checkPaused   = parseInt( tsoliinData.checkPaused, 10 ) === 1;
				if ( checkPaused && pctOnLoad > 0 && pctOnLoad < 100 ) {
					this.$checkProg.show();
					// Incomplete Check now / Scan→Check (not a manual Stop): finish automatically.
					if ( ! scanActive && parseInt( tsoliinData.checkAutoResume, 10 ) === 1 ) {
						this.updateCheckProgress( pctOnLoad, tsoliinData.i18n.checking );
						this.startBgCheck( true, true, parseInt( tsoliinData.bgPostId, 10 ) || 0 );
					} else {
						this.updateCheckProgress( pctOnLoad, tsoliinData.i18n.checkPaused || tsoliinData.i18n.stopped );
					}
				} else {
					this.$checkProg.hide();
				}
			}

			this.syncDiscardButtons();

			if ( scanRunning || checkRunning ) {
				this.startPolling();
			}
			if ( scanRunning ) {
				this.scanTick();
			}
			if ( checkRunning ) {
				this.checkTick();
			}

			// Live stat / filter tab counts while editing the list.
			if ( $( '.tsoliin-wrap .tsoliin-stats' ).length ) {
				this.refreshStats();
				var interval = parseInt( tsoliinData.refreshInterval, 10 ) || 8000;
				if ( interval > 0 ) {
					this.statsTimer = window.setInterval( function () {
						LC.refreshStats();
					}, interval );
				}
			}
		},

		/**
		 * Parse list filter/pagination link query args.
		 *
		 * @param {string} href Link href.
		 * @return {Object|null}
		 */
		parseListNavLink: function ( href ) {
			if ( ! href ) {
				return null;
			}
			try {
				var url = new URL( href, window.location.href );
				if ( url.searchParams.get( 'page' ) !== 'tso-link-inspector' ) {
					return null;
				}
				var postId = parseInt( url.searchParams.get( 'post_id' ), 10 ) || 0;
				var view   = url.searchParams.get( 'view' ) || 'links';
				if ( postId > 0 ) {
					view = 'links';
				}
				return {
					href             : url.toString(),
					filter           : url.searchParams.get( 'filter' ) || 'all',
					quality_filter   : url.searchParams.get( 'quality_filter' ) || '',
					link_type_filter : url.searchParams.get( 'link_type_filter' ) || '',
					scope            : url.searchParams.get( 'scope' ) || 'all',
					paged          : Math.max( 1, parseInt( url.searchParams.get( 'paged' ), 10 ) || 1 ),
					s              : url.searchParams.has( 's' ) ? ( url.searchParams.get( 's' ) || '' ) : '',
					post_id        : postId,
					view           : view,
					orderby        : url.searchParams.get( 'orderby' ) || '',
					order          : url.searchParams.get( 'order' ) || ''
				};
			} catch ( e ) {
				return null;
			}
		},

		/**
		 * Keep JS state and the address bar in sync after AJAX list navigation.
		 *
		 * @param {Object} params Parsed nav params.
		 * @param {string} href   Canonical URL.
		 */
		updateListNavState: function ( params, href ) {
			tsoliinData.listFilter = params.filter || 'all';
			tsoliinData.listQualityFilter = params.quality_filter || '';
			tsoliinData.listTypeFilter = params.link_type_filter || '';
			tsoliinData.listScope = params.scope || 'all';
			tsoliinData.viewPostId = parseInt( params.post_id, 10 ) || 0;
			tsoliinData.listView = params.view || 'links';
			if ( params.orderby ) {
				tsoliinData.listOrderby = params.orderby;
			}
			if ( params.order ) {
				tsoliinData.listOrder = params.order;
			}
			this.lastSearchVal = params.s || '';
			if ( window.history && window.history.replaceState && href ) {
				try {
					window.history.replaceState( null, '', href );
				} catch ( err ) {
					// Ignore URL API errors on very old browsers.
				}
			}
		},

		/**
		 * Re-bind list form refs after AJAX scope navigation replaces the region.
		 */
		refreshDomRefs: function () {
			this.$form = $( '#tsoliin-list-form' );
		},

		/**
		 * Keep export toolbar forms aligned with the active post scope.
		 *
		 * @param {number} postId Active post_id (0 = all links).
		 */
		syncExportScope: function ( postId ) {
			postId = parseInt( postId, 10 ) || 0;
			$( '.tsoliin-export-form' ).each( function () {
				var $form  = $( this );
				var $field = $form.find( 'input[name="post_id"]' );
				if ( postId > 0 ) {
					if ( $field.length ) {
						$field.val( postId );
					} else {
						$form.append( '<input type="hidden" name="post_id" value="' + postId + '" />' );
					}
				} else {
					$field.remove();
				}
			} );
		},

		/**
		 * Update the page heading after AJAX scope navigation.
		 *
		 * Server HTML is escaped markup (text + spans). Never pass it to $() —
		 * strings that do not start with "<" are treated as CSS selectors and
		 * wipe the title (and can throw in Sizzle).
		 *
		 * @param {string} html Title HTML returned by the server.
		 */
		updatePageTitle: function ( html ) {
			if ( ! html ) {
				return;
			}
			var $h1 = $( '.tsoliin-page-head h1.wp-heading-inline' );
			if ( ! $h1.length ) {
				return;
			}
			$h1.html( '<span class="dashicons dashicons-admin-links tsoliin-title-icon"></span> ' + html );
		},

		/**
		 * Clear AJAX list-loading chrome on live DOM nodes (not a detached $target).
		 */
		clearListNavLoading: function () {
			$( '#tsoliin-scope-region, #tsoliin-list-form, #tsoliin-list-table-region' )
				.removeClass( 'tsoliin-list-table-region--loading' )
				.attr( 'aria-busy', 'false' );
		},

		/**
		 * Apply AJAX scope metadata (title, check button, exports).
		 *
		 * @param {Object} data AJAX response data.
		 */
		applyScopeNavMeta: function ( data ) {
			if ( ! data ) {
				return;
			}
			if ( data.page_title_html ) {
				this.updatePageTitle( data.page_title_html );
			}
			if ( data.check_btn_label && this.$checkBtn && this.$checkBtn.length ) {
				var $icon = this.$checkBtn.find( '.dashicons' ).first();
				if ( $icon.length ) {
					this.$checkBtn.empty().append( $icon ).append( document.createTextNode( ' ' + data.check_btn_label ) );
				} else {
					this.$checkBtn.text( data.check_btn_label );
				}
			}
			if ( typeof data.view_post_id !== 'undefined' ) {
				tsoliinData.viewPostId = parseInt( data.view_post_id, 10 ) || 0;
				this.syncExportScope( data.view_post_id );
			}
			if ( data.list_view ) {
				tsoliinData.listView = data.list_view;
			}
			this.refreshStats();
		},

		/**
		 * Fetch list-table HTML without reloading the admin page.
		 *
		 * @param {Object} params Nav/search params.
		 * @param {Object} opts   Callback options.
		 */
		fetchListRegion: function ( params, opts ) {
			opts = opts || {};
			var self = this;
			var useScope = ( 'scope' === opts.region ) || ! $( '#tsoliin-list-form' ).length;
			var $scope = $( '#tsoliin-scope-region' );
			var $form  = $( '#tsoliin-list-form' );
			var $target = useScope ? $scope : $form;
			if ( ! $target.length ) {
				$target = $( '#tsoliin-list-table-region' );
			}

			if ( ! $target.length ) {
				if ( opts.fallbackNavigate && params.href ) {
					window.location.href = params.href;
				}
				return;
			}

			if ( self.listNavXhr && self.listNavXhr.readyState !== 4 ) {
				self.listNavXhr.abort();
			}
			if ( self.searchXhr && self.searchXhr.readyState !== 4 ) {
				self.searchXhr.abort();
			}

			self.listNavRequestId = ( self.listNavRequestId || 0 ) + 1;
			var requestId = self.listNavRequestId;

			$target.addClass( 'tsoliin-list-table-region--loading' );
			$target.attr( 'aria-busy', 'true' );

			var xhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 60000,
				data   : {
					action           : 'tsoliin_search_list',
					nonce            : tsoliinData.nonce,
					region           : useScope ? 'scope' : 'list',
					s                : params.s || '',
					filter           : params.filter || tsoliinData.listFilter || 'all',
					quality_filter   : params.quality_filter !== undefined ? params.quality_filter : ( tsoliinData.listQualityFilter || '' ),
					link_type_filter : params.link_type_filter !== undefined ? params.link_type_filter : ( tsoliinData.listTypeFilter || '' ),
					scope            : params.scope || tsoliinData.listScope || 'all',
					post_id        : params.post_id !== undefined ? params.post_id : ( parseInt( tsoliinData.viewPostId, 10 ) || 0 ),
					view           : params.view || tsoliinData.listView || 'links',
					paged          : params.paged || 1,
					orderby        : params.orderby || tsoliinData.listOrderby || 'date_found',
					order          : params.order || tsoliinData.listOrder || 'DESC'
				},
				success: function ( r ) {
					if ( r.success && r.data && r.data.html ) {
						try {
							var responseRegion = r.data.region || ( useScope ? 'scope' : 'list' );
							if ( 'scope' === responseRegion && $scope.length ) {
								$scope.html( r.data.html );
							} else if ( $form.length ) {
								$form.replaceWith( r.data.html );
							} else if ( $scope.length ) {
								$scope.html( r.data.html );
							} else {
								$target.html( r.data.html );
							}
							self.refreshDomRefs();
							if ( opts.updateUrl && params.href ) {
								self.updateListNavState( params, params.href );
							}
							if ( opts.updateScopeMeta ) {
								self.applyScopeNavMeta( r.data );
							}
							if ( typeof opts.onSuccess === 'function' ) {
								opts.onSuccess( r.data );
							}
						} catch ( err ) {
							if ( window.console && console.error ) {
								console.error( 'tsoliin list nav', err );
							}
							if ( opts.fallbackNavigate && params.href ) {
								window.location.href = params.href;
							}
						}
					} else if ( opts.fallbackNavigate && params.href ) {
						window.location.href = params.href;
					}
				},
				error: function ( xhr, status ) {
					if ( 'abort' === status ) {
						return;
					}
					if ( opts.fallbackNavigate && params.href ) {
						window.location.href = params.href;
					} else {
						alert( tsoliinData.i18n.error );
					}
				},
				complete: function () {
					if ( requestId !== self.listNavRequestId ) {
						return;
					}
					self.clearListNavLoading();
					if ( self.listNavXhr === xhr ) {
						self.listNavXhr = null;
					}
					if ( self.searchXhr === xhr ) {
						self.searchXhr = null;
					}
					if ( typeof opts.onComplete === 'function' ) {
						opts.onComplete();
					}
				}
			} );

			if ( opts.trackAsSearch ) {
				self.searchXhr = xhr;
			} else {
				self.listNavXhr = xhr;
			}
		},

		/**
		 * Align the link list with the viewport after AJAX navigation.
		 */
		restoreListScroll: function () {
			var el = document.getElementById( 'tsoliin-list-table-region' )
				|| document.getElementById( 'tsoliin-scope-region' );
			if ( el ) {
				el.scrollIntoView( { block: 'start', behavior: 'auto' } );
			}
		},

		/**
		 * AJAX navigation for filters, scope tabs, pagination, and stat cards.
		 *
		 * @param {string} href Target admin URL.
		 */
		loadListNav: function ( href, opts ) {
			opts = opts || {};
			var params = this.parseListNavLink( href );
			if ( ! params ) {
				window.location.href = href;
				return;
			}
			if ( opts.region ) {
				params.region = opts.region;
			}
			this.fetchListRegion( params, {
				region          : opts.region || 'scope',
				updateUrl       : true,
				updateScopeMeta : true,
				fallbackNavigate: true,
				onSuccess       : function () {
					LC.restoreListScroll();
				}
			} );
		},

		// ---------------------------------------------------------------
		// Event bindings
		// ---------------------------------------------------------------
		bindEvents: function () {
			var self = this;
			var listNavSelector = '.tsoliin-wrap .pagination-links a, .tsoliin-wrap .tablenav-pages a, .tsoliin-wrap .tsoliin-filter-tabs a, .tsoliin-wrap .tsoliin-quality-tabs a, .tsoliin-wrap .tsoliin-scope-tabs a, .tsoliin-wrap a.tsoliin-stat[href], .tsoliin-wrap .wp-list-table thead th a[href]';
			var scopeNavSelector = '.tsoliin-wrap .tsoliin-section-tabs a';

			$( document ).on( 'click', listNavSelector, function ( e ) {
				if ( e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || 2 === e.which ) {
					return;
				}
				var href = $( this ).attr( 'href' );
				if ( ! href || ! $( '#tsoliin-scope-region, #tsoliin-list-table-region' ).length ) {
					return;
				}
				var params = self.parseListNavLink( href );
				if ( ! params ) {
					return;
				}
				e.preventDefault();
				self.fetchListRegion( params, {
					updateUrl       : true,
					fallbackNavigate: true,
					onSuccess       : function () {
						self.restoreListScroll();
					}
				} );
			} );

			$( document ).on( 'click', scopeNavSelector, function ( e ) {
				if ( e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || 2 === e.which ) {
					return;
				}
				var href = $( this ).attr( 'href' );
				if ( ! href || ! $( '#tsoliin-scope-region' ).length ) {
					return;
				}
				if ( ! self.parseListNavLink( href ) ) {
					return;
				}
				e.preventDefault();
				self.loadListNav( href );
			} );

			// Type filter dropdown: same AJAX list-nav path as the scope tabs above,
			// just triggered by a <select> change instead of an <a> click.
			$( document ).on( 'change', '.tsoliin-type-filter__select', function () {
				var href = $( this ).val();
				if ( ! href || ! $( '#tsoliin-scope-region' ).length ) {
					return;
				}
				self.loadListNav( href );
			} );

			$( document ).on(
				'click',
				'.tsoliin-post-icon--list, .tsoliin-post-icon--back, .tsoliin-wrap a.tsoliin-post-scope-link',
				function ( e ) {
					if ( e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || 2 === e.which ) {
						return;
					}
					var href = $( this ).attr( 'href' );
					if ( ! href || ! $( '#tsoliin-scope-region' ).length ) {
						return;
					}
					if ( ! self.parseListNavLink( href ) ) {
						return;
					}
					e.preventDefault();
					self.loadListNav( href );
				}
			);

			this.$startBtn.on( 'click', function () {
				if ( self.scanning && parseInt( tsoliinData.scanRunning, 10 ) === 1 ) {
					self.scanAborted = false;
					self.scanSessionActive = true;
					self.$startBtn.prop( 'disabled', true );
					self.startPolling();
					self.scanTick();
					return;
				}
				if ( self.scanning ) { return; }
				if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 && ! window.confirm( tsoliinData.i18n.confirmScanWhileCheck ) ) {
					return;
				}
				self.startScan();
			} );

			this.$stopScanBtn.on( 'click', function () {
				self.stopScan();
			} );

			if ( this.$restartScanBtn && this.$restartScanBtn.length ) {
				this.$restartScanBtn.on( 'click', function () {
					if ( $( this ).is( ':hidden' ) ) { return; }
					if ( ! window.confirm( tsoliinData.i18n.confirmRestartScan ) ) {
						return;
					}
					self.startScan( true, false );
				} );
			}

			if ( this.$discardScanBtn && this.$discardScanBtn.length ) {
				this.$discardScanBtn.on( 'click', function () {
					if ( $( this ).is( ':hidden' ) ) { return; }
					self.discardBgJobs( 'scan' );
				} );
			}
			if ( this.$discardCheckBtn && this.$discardCheckBtn.length ) {
				this.$discardCheckBtn.on( 'click', function () {
					if ( $( this ).is( ':hidden' ) ) { return; }
					self.discardBgJobs( 'check' );
				} );
			}
			if ( this.$discardAllBtn && this.$discardAllBtn.length ) {
				this.$discardAllBtn.on( 'click', function () {
					if ( $( this ).is( ':hidden' ) ) { return; }
					self.discardBgJobs( 'all' );
				} );
			}

			this.$checkBtn.on( 'click', function () {
				if ( $( this ).prop( 'disabled' ) ) { return; }
				if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
					self.startBgCheck();
					return;
				}
				if ( self.scanning && ! window.confirm( tsoliinData.i18n.confirmCheckWhileScan ) ) {
					return;
				}
				self.startBgCheck();
			} );

			this.$restartBtn.on( 'click', function () {
				if ( $( this ).prop( 'disabled' ) || $( this ).is( ':hidden' ) ) { return; }
				if ( self.scanning && ! window.confirm( tsoliinData.i18n.confirmCheckWhileScan ) ) {
					return;
				}
				if ( ! window.confirm( tsoliinData.i18n.confirmRestartCheck ) ) {
					return;
				}
				self.startBgCheck( true, false );
			} );


			this.$stopBtn.on( 'click', function () {
				self.stopBgCheck();
			} );

			$( document ).on( 'click', '.tsoliin-url a[data-tsoliin-action-url="1"]', function ( e ) {
				var msg = tsoliinData.i18n.actionUrlWarn || 'This link ends your WordPress session. Open it anyway?';
				if ( ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );

			$( document ).on( 'submit', '.tsoliin-reset-form, .tsoliin-clear-history-form', function ( e ) {
				var msg = $( this ).attr( 'data-tsoliin-confirm' ) || $( this ).find( '[data-tsoliin-confirm]' ).first().attr( 'data-tsoliin-confirm' );
				if ( msg && ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );

			$( document ).on( 'click', '.tsoliin-toggle-chain', function ( e ) {
				e.preventDefault();
				var $btn  = $( this );
				var $list = $btn.siblings( '.tsoliin-redirect-chain' );
				var open  = 'true' === $btn.attr( 'aria-expanded' );
				$btn.attr( 'aria-expanded', open ? 'false' : 'true' );
				$list.prop( 'hidden', open );
			} );

			$( document ).on( 'click', '.tsoliin-edit-link', function ( e ) {
				e.preventDefault();
				var $a = $( this );
				self.openModal(
					parseInt( $a.data( 'id' ), 10 ),
					$a.attr( 'data-url' ) || $a.data( 'url' ),
					parseInt( $a.data( 'post' ), 10 ),
					$a.data( 'anchor' ) || '',
					$a.data( 'type' ) || 'link',
					'1' === String( $a.data( 'anchor-editable' ) || $a.attr( 'data-anchor-editable' ) || '1' )
				);
			} );

			$( document ).on( 'click', '.tsoliin-recheck', function ( e ) {
				e.preventDefault();
				self.recheckLink( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-unlink', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmUnlink ) ) { return; }
				self.unlinkItem( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-not-broken', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmNotBroken ) ) { return; }
				self.markNotBroken( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-add-ignore', function ( e ) {
				e.preventDefault();
				self.addToIgnoreList( $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-onboarding-dismiss', function ( e ) {
				e.preventDefault();
				self.dismissOnboarding( $( '#tsoliin-onboarding' ) );
			} );

			$( document ).on( 'click', '.tsoliin-make-relative', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmMakeRelative ) ) { return; }
				self.makeRelative( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-upgrade-https', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmUpgradeHttps ) ) { return; }
				self.upgradeHttps( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-delete', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( tsoliinData.i18n.confirmDelete ) ) { return; }
				self.deleteItem( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			// Smart suggest.
			$( document ).on( 'click', '.tsoliin-suggest', function ( e ) {
				e.preventDefault();
				self.smartSuggest( parseInt( $( this ).data( 'id' ), 10 ), $( this ) );
			} );

			$( document ).on( 'click', '.tsoliin-suggest-close', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).closest( '.tsoliin-suggest-row' ).remove();
			} );

			$( document ).on( 'click', '.tsoliin-apply-suggest', function ( e ) {
				e.preventDefault();
				var $btn   = $( this );
				var linkId = parseInt( $btn.data( 'id' ), 10 );
				var newUrl = $btn.attr( 'data-url' ) || $btn.data( 'url' );
				var $row   = self.findLinkRow( linkId );
				var opts   = {
					applyAnyway  : 1 === parseInt( $btn.data( 'applyAnyway' ), 10 ) || 1 === parseInt( $btn.attr( 'data-apply-anyway' ), 10 ),
					ignoreDomain : 1 === parseInt( $btn.data( 'ignoreDomain' ), 10 ) || 1 === parseInt( $btn.attr( 'data-ignore-domain' ), 10 )
				};
				if ( ! $row.length ) {
					$row = $btn.closest( '.tsoliin-suggest-row' ).prev( 'tr' );
					while ( $row.length && $row.hasClass( 'tsoliin-suggest-row' ) ) {
						$row = $row.prev( 'tr' );
					}
				}
				if ( opts.ignoreDomain ) {
					if ( ! window.confirm( tsoliinData.i18n.confirmApplyAnywayIgnore || tsoliinData.i18n.confirmAddIgnore ) ) {
						return;
					}
				} else if ( opts.applyAnyway ) {
					if ( ! window.confirm( tsoliinData.i18n.confirmApplyAnyway ) ) {
						return;
					}
				}
				self.applySmartUrl( linkId, newUrl, $btn, $row, opts );
			} );

			this.$modalSave.on( 'click', function () { self.saveLink(); } );
			this.$modalCancel.on( 'click', function () { self.closeModal(); } );
			this.$newUrlInput.on( 'input', function () {
				self.syncIgnoreDomainOption();
				self.scheduleLinkPreview();
			} );
			$( document ).on( 'click', '.tsoliin-modal__overlay', function () { self.closeModal(); } );
			$( document ).on( 'keydown', function ( e ) {
				if ( 27 === e.which && self.$modal.is( ':visible' ) ) { self.closeModal(); }
			} );

			$( '#tsoliin-diagnose' ).on( 'click', function () { self.runDiagnose(); } );

			$( document ).on( 'submit', '#tsoliin-list-form', function ( e ) {
				var $form = $( this );
				var action = $form.find( 'select[name="action"]' ).val();
				if ( ! action || '-1' === action ) {
					action = $form.find( 'select[name="action2"]' ).val();
				}
				var managed = [ 'recheck', 'delete', 'unlink', 'not_broken', 'upgrade_https' ];
				if ( parseInt( tsoliinData.relativeUrlTool, 10 ) === 1 ) {
					managed.push( 'make_relative' );
				}
				if ( -1 !== managed.indexOf( action ) ) {
					e.preventDefault();
					self.$form = $form;
					self.doBulkAction( action );
				}
			} );

			// Meta scan row toggle.
			var $cb  = $( '#tsoliin_scan_meta' );
			var $row = $( '#tsoliin-meta-exclude-row' );
			if ( $cb.length && $row.length ) {
				$row.toggle( $cb.is( ':checked' ) );
				$cb.on( 'change', function () { $row.toggle( $( this ).is( ':checked' ) ); } );
			}
		},

		// ---------------------------------------------------------------
		// Scan (server-side background, browser polls)
		// ---------------------------------------------------------------
		formatAjaxError: function ( xhr, fallback ) {
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				return xhr.responseJSON.data.message;
			}
			if ( xhr && xhr.status ) {
				return ( fallback || tsoliinData.i18n.error ) + ' (HTTP ' + xhr.status + ')';
			}
			return fallback || tsoliinData.i18n.error;
		},

		resetScanButton: function () {
			var label = parseInt( tsoliinData.scanResumable, 10 ) === 1
				? tsoliinData.i18n.continueScan
				: tsoliinData.i18n.scanNow;
			this.$startBtn.show().prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-search"></span> ' + label
			);
			this.syncDiscardButtons();
		},

		resetCheckButton: function () {
			var postId = parseInt( tsoliinData.viewPostId, 10 ) || 0;
			var pending = parseInt( tsoliinData.pendingCheck, 10 ) || 0;
			var checkPaused = parseInt( tsoliinData.checkPaused, 10 ) === 1;
			var label;
			if ( checkPaused && pending > 0 ) {
				label = postId > 0
					? ( tsoliinData.i18n.continueThisPost || tsoliinData.i18n.checkThisPost )
					: ( tsoliinData.i18n.continueCheck || tsoliinData.i18n.checkNow );
				if ( this.$restartBtn && this.$restartBtn.length ) {
					this.$restartBtn.show().prop( 'disabled', false );
				}
			} else {
				label = postId > 0
					? tsoliinData.i18n.checkThisPost
					: tsoliinData.i18n.checkNow;
				if ( this.$restartBtn && this.$restartBtn.length ) {
					this.$restartBtn.hide();
				}
			}
			this.$checkBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' + label
			);
			this.syncDiscardButtons();
		},

		syncDiscardButtons: function () {
			var scanPaused = ( parseInt( tsoliinData.scanResumable, 10 ) === 1
				|| !! tsoliinData.scanError )
				&& ! parseInt( tsoliinData.scanRunning, 10 );
			var checkPaused = parseInt( tsoliinData.checkPaused, 10 ) === 1
				&& ! parseInt( tsoliinData.bgRunning, 10 );
			if ( this.$discardScanBtn && this.$discardScanBtn.length ) {
				this.$discardScanBtn.toggle( scanPaused );
			}
			if ( this.$discardCheckBtn && this.$discardCheckBtn.length ) {
				this.$discardCheckBtn.toggle( checkPaused );
			}
			if ( this.$discardAllBtn && this.$discardAllBtn.length ) {
				this.$discardAllBtn.toggle( scanPaused && checkPaused );
			}
		},

		discardBgJobs: function ( scope ) {
			var self = this;
			var confirmMsg = tsoliinData.i18n.confirmDiscardAll;
			if ( 'scan' === scope ) {
				confirmMsg = tsoliinData.i18n.confirmDiscardScan;
			} else if ( 'check' === scope ) {
				confirmMsg = tsoliinData.i18n.confirmDiscardCheck;
			}
			if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
				return;
			}
			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : {
					action  : 'tsoliin_discard_bg_jobs',
					nonce   : tsoliinData.nonce,
					scope   : scope || 'all',
					post_id : parseInt( tsoliinData.viewPostId, 10 ) || 0
				},
				success: function ( r ) {
					if ( ! r || ! r.success || ! r.data ) {
						self.showNotice( tsoliinData.i18n.error, 'error' );
						return;
					}
					if ( 'scan' === scope || 'all' === scope ) {
						tsoliinData.scanResumable = 0;
						tsoliinData.scanRunning = 0;
						tsoliinData.scanError = '';
						self.scanning = false;
						self.scanSessionActive = false;
						self.$stopScanBtn.hide();
						self.$progress.fadeOut( 400 );
						if ( self.$restartScanBtn && self.$restartScanBtn.length ) {
							self.$restartScanBtn.hide();
						}
						self.resetScanButton();
					}
					if ( 'check' === scope || 'all' === scope ) {
						tsoliinData.checkPaused = 0;
						tsoliinData.bgRunning = 0;
						self.checkSessionActive = false;
						tsoliinData.bgPct = 0;
						self.$stopBtn.hide();
						self.$checkProg.fadeOut( 400 );
						if ( self.$restartBtn && self.$restartBtn.length ) {
							self.$restartBtn.hide();
						}
						if ( typeof r.data.pending !== 'undefined' ) {
							tsoliinData.pendingCheck = r.data.pending;
						}
						self.resetCheckButton();
					}
					if ( r.data.scan ) {
						self.applyScanProgress( r.data.scan );
					}
					if ( r.data.check_btn_label && self.$checkBtn && self.$checkBtn.length ) {
						var $icon = self.$checkBtn.find( '.dashicons' ).first();
						if ( $icon.length ) {
							self.$checkBtn.empty().append( $icon ).append( document.createTextNode( ' ' + r.data.check_btn_label ) );
						}
					}
					if ( ! parseInt( tsoliinData.scanRunning, 10 ) && ! parseInt( tsoliinData.bgRunning, 10 ) ) {
						self.stopPolling();
					}
					self.syncDiscardButtons();
					if ( ! self.isScanBlockingCheck() ) {
						self.$checkBtn.prop( 'disabled', false );
					}
				},
				error: function () {
					self.showNotice( tsoliinData.i18n.error, 'error' );
				}
			} );
		},

		startScan: function ( skipConfirm, resume, isRetry ) {
			var self = this;
			var forceRestart = ( false === resume );
			if ( self.scanning && ! forceRestart ) {
				self.scanAborted = false;
				self.scanSessionActive = true;
				self.startPolling();
				self.scanTick();
				return;
			}
			if ( self.scanning && forceRestart ) {
				self.scanAborted = true;
				self.scanSessionActive = false;
				self.scanTickBusy = false;
				self.clearTickTimer( 'scan' );
				self.scanning = false;
			}
			var willResume = ( typeof resume === 'undefined' )
				? ( parseInt( tsoliinData.scanResumable, 10 ) === 1 )
				: !! resume;

			self.scanning       = true;
			self.scanAborted    = false;
			self.scanCompleted  = false;
			self.scanStartPending = true;
			self.scanSessionActive = true;
			tsoliinData.scanError = '';
			self.$startBtn.hide().prop( 'disabled', true );
			self.$stopScanBtn.show();
			if ( self.$restartScanBtn && self.$restartScanBtn.length ) {
				self.$restartScanBtn.hide();
			}
			self.$checkBtn.prop( 'disabled', true );
			self.$progress.show().attr( 'aria-valuenow', willResume ? ( parseInt( tsoliinData.scanPct, 10 ) || 0 ) : 0 );
			self.$progressBar.css( 'background', '' );
			self.updateProgress(
				willResume ? ( parseInt( tsoliinData.scanPct, 10 ) || 0 ) : 0,
				tsoliinData.i18n.scanning
			);

			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : {
					action : 'tsoliin_start_bg_scan',
					nonce  : tsoliinData.nonce,
					resume : willResume ? '1' : '0'
				},
				success: function ( r ) {
					if ( ! r.success ) {
						if ( ! isRetry ) {
							self.scanning = false;
							self.scanStartPending = false;
							setTimeout( function () {
								self.startScan( skipConfirm, resume, true );
							}, 400 );
							return;
						}
						self.scanStartPending = false;
						self.scanError( r.data ? r.data.message : tsoliinData.i18n.error );
						return;
					}
					self.scanStartPending = false;
					tsoliinData.scanRunning = 1;
					tsoliinData.bgRunning = 0;
					self.checkSessionActive = false;
					self.$stopBtn.hide();
					tsoliinData.scanResumable = 0;
					tsoliinData.scanError = '';
					if ( typeof r.data.pct !== 'undefined' ) {
						tsoliinData.scanPct = r.data.pct;
						self.updateProgress( r.data.pct, r.data.message || tsoliinData.i18n.scanning );
					}
					if ( r.data.message ) {
						self.showNotice( r.data.message, 'info' );
					}
					self.syncDiscardButtons();
					self.startPolling();
					self.scanTick();
				},
				error: function ( xhr ) {
					// The server may have accepted the start before the response was lost.
					// Reconcile through the progress endpoint instead of showing a false stop.
					tsoliinData.scanRunning = 1;
					self.showNotice( self.formatAjaxError( xhr, tsoliinData.i18n.scanFailed ), 'error' );
					self.startPolling();
					self.scanTick();
				}
			} );
		},

		stopScan: function () {
			var self = this;
			self.scanAborted = true;
			self.scanSessionActive = false;
			self.$stopScanBtn.prop( 'disabled', true );
			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : { action: 'tsoliin_stop_bg_scan', nonce: tsoliinData.nonce },
				success: function ( r ) {
					self.scanning = false;
					tsoliinData.scanRunning = 0;
					self.$stopScanBtn.hide().prop( 'disabled', false );
					self.resetScanButton();
					if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
						self.$checkBtn.prop( 'disabled', self.isScanBlockingCheck() );
					}
					if ( r.success && r.data ) {
						tsoliinData.scanResumable = r.data.resumable ? 1 : 0;
						tsoliinData.scanPct = r.data.pct;
						self.updateProgress( r.data.pct, tsoliinData.i18n.scanStopped );
						if ( r.data.resumable && self.$restartScanBtn && self.$restartScanBtn.length ) {
							self.$restartScanBtn.show();
							self.resetScanButton();
						}
						self.syncDiscardButtons();
					} else {
						self.updateProgress( 0, tsoliinData.i18n.scanStopped );
					}
					if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
						self.stopPolling();
					}
				},
				error: function () {
					self.scanAborted = false;
					self.scanSessionActive = true;
					self.$stopScanBtn.prop( 'disabled', false );
					self.showNotice( tsoliinData.i18n.error, 'error' );
					if ( ! self.polling ) {
						self.startPolling();
					}
				}
			} );
		},

		/**
		 * Wait for the server-side pause between steps instead of polling it.
		 *
		 * @param {Object} d Tick response data.
		 * @return {number} Milliseconds.
		 */
		tickDelay: function ( d ) {
			var wait = d && d.retry_after ? parseInt( d.retry_after, 10 ) * 1000 : 0;
			if ( d && d.busy ) {
				wait = Math.max( wait, 3000 );
			}
			return Math.max( wait, 500 );
		},

		/**
		 * Schedule the next scan/check tick. Only one pending timer per loop, so
		 * progress polls and button handlers can never start parallel tick chains.
		 *
		 * @param {string} kind 'scan' or 'check'.
		 * @param {number} ms   Delay in milliseconds.
		 */
		scheduleTick: function ( kind, ms ) {
			var self = this;
			var key  = 'scan' === kind ? 'scanTickTimer' : 'checkTickTimer';
			self.clearTickTimer( kind );
			self[ key ] = setTimeout( function () {
				self[ key ] = null;
				if ( 'scan' === kind ) {
					self.scanTick();
				} else {
					self.checkTick();
				}
			}, Math.max( 0, ms ) );
		},

		clearTickTimer: function ( kind ) {
			var key = 'scan' === kind ? 'scanTickTimer' : 'checkTickTimer';
			if ( this[ key ] ) {
				clearTimeout( this[ key ] );
				this[ key ] = null;
			}
		},

		scanTick: function () {
			var self = this;
			if ( self.scanTickBusy || self.scanTickTimer || self.scanAborted || ! self.scanSessionActive ) {
				return;
			}
			self.scanTickBusy = true;
			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : {
					action : 'tsoliin_bg_scan_tick',
					nonce  : tsoliinData.nonce
				},
				success: function ( r ) {
					self.scanTickBusy = false;
					if ( ! r.success ) {
						self.scanError( r.data ? r.data.message : tsoliinData.i18n.error );
						return;
					}
					var scan = r.data || {};
					if ( scan.error ) {
						self.scanError( scan.error );
						return;
					}
					self.applyScanProgress( scan );
					if ( scan.done ) {
						self.scanFinished();
						return;
					}
					if ( scan.running && ! self.scanAborted && self.scanSessionActive ) {
						self.scheduleTick( 'scan', self.tickDelay( scan ) );
					}
				},
				error: function ( xhr ) {
					self.scanTickBusy = false;
					if ( xhr && ( xhr.status === 401 || xhr.status === 403 ) ) {
						self.scanError( tsoliinData.i18n.sessionExpired || tsoliinData.i18n.error );
						return;
					}
					if ( ! self.scanAborted && self.scanSessionActive ) {
						self.scheduleTick( 'scan', 15000 );
					}
				}
			} );
		},

		applyScanProgress: function ( scan ) {
			if ( ! scan ) {
				return;
			}
			tsoliinData.scanPct = scan.pct;
			tsoliinData.scanScanned = scan.scanned;
			tsoliinData.scanTotal = scan.total;
			tsoliinData.scanResumable = scan.resumable ? 1 : 0;
			tsoliinData.scanError = scan.error ? scan.error : '';
			if ( scan.error ) {
				this.$progressBar.css( { width: '100%', background: '#cc1818' } );
			} else {
				this.$progressBar.css( 'background', '' );
			}
			this.updateProgress( scan.pct, scan.message || tsoliinData.i18n.scanning );
			this.$progress.show();
			var $scannedCard = $( '.tsoliin-stat--posts .tsoliin-stat__number' );
			if ( $scannedCard.length && scan.total ) {
				$scannedCard.text( scan.scanned + ' / ' + scan.total );
			}
			this.syncDiscardButtons();
			if ( scan.running ) {
				this.scanning = true;
				this.scanSessionActive = true;
				this.$startBtn.hide().prop( 'disabled', true );
				this.$stopScanBtn.show();
				if ( this.$restartScanBtn && this.$restartScanBtn.length ) {
					this.$restartScanBtn.hide();
				}
				this.$checkBtn.prop(
					'disabled',
					! parseInt( tsoliinData.bgRunning, 10 ) || this.checkSessionActive
				);
			}
		},

		updateProgress: function ( pct, label ) {
			pct = Math.min( 100, Math.max( 0, pct ) );
			this.$progressBar.css( 'width', pct + '%' );
			this.$progressLbl.text( pct + '% – ' + label );
			this.$progress.attr( 'aria-valuenow', pct );
		},

		scanFinished: function () {
			if ( this.scanAborted ) {
				return;
			}
			this.scanning = false;
			this.scanSessionActive = false;
			tsoliinData.scanRunning = 0;
			tsoliinData.scanResumable = 0;
			tsoliinData.scanError = '';
			this.$stopScanBtn.hide();
			if ( this.$restartScanBtn && this.$restartScanBtn.length ) {
				this.$restartScanBtn.hide();
			}
			this.resetScanButton();
			this.$progressBar.css( 'background', '' );
			this.updateProgress( 100, tsoliinData.i18n.scanDone );
			var self = this;
			setTimeout( function () {
				if ( self.scanAborted ) {
					return;
				}
				self.showNotice( '✅ ' + tsoliinData.i18n.scanThenCheck, 'success' );
				self.$checkProg.hide();
				self.$checkBar.css( 'width', '0%' );
				self.$checkLbl.text( '' );
				self.scanChainCheck = true;
				self.startBgCheck( true, false, 0 );
			}, 800 );
		},

		scanError: function ( msg ) {
			this.scanning = false;
			this.scanSessionActive = false;
			tsoliinData.scanRunning = 0;
			this.$stopScanBtn.hide();
			this.resetScanButton();
			if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
				this.$checkBtn.prop( 'disabled', false );
				this.stopPolling();
			}
			if ( parseInt( tsoliinData.scanResumable, 10 ) === 1 && this.$restartScanBtn && this.$restartScanBtn.length ) {
				this.$restartScanBtn.show();
			}
			tsoliinData.scanError = msg;
			this.$progressLbl.text( msg );
			this.$progressBar.css( { width: '100%', background: '#cc1818' } );
			this.showNotice( msg, 'error' );
		},

		// ---------------------------------------------------------------
		// Background check – server does the work, browser just polls
		// ---------------------------------------------------------------
		startBgCheck: function ( skipConfirm, resume, postIdOverride ) {
			var self = this;
			var postId = ( typeof postIdOverride !== 'undefined' )
				? ( parseInt( postIdOverride, 10 ) || 0 )
				: ( parseInt( tsoliinData.viewPostId, 10 ) || 0 );
			var pending = parseInt( tsoliinData.pendingCheck, 10 ) || 0;
			var willResume = ( typeof resume === 'undefined' ) ? ( pending > 0 ) : !! resume;

			if ( parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
				this.checkSessionActive = true;
				this.$checkBtn.prop( 'disabled', true );
				this.startPolling();
				this.checkTick();
				return;
			}

			var scanActive = this.isScanBlockingCheck();
			if ( scanActive && ! this.scanChainCheck ) {
				if ( tsoliinData.i18n.confirmCheckWhileScan ) {
					this.showNotice( tsoliinData.i18n.confirmCheckWhileScan, 'warning' );
				}
				return;
			}

			// Resume ("Continue check") starts immediately — the button label already states intent.
			// Only ask before a full site-wide recheck from zero.
			if ( ! skipConfirm && ! willResume && postId <= 0 && tsoliinData.i18n.confirmFullCheck ) {
				if ( ! window.confirm( tsoliinData.i18n.confirmFullCheck ) ) {
					return;
				}
			}

			this.completed = false;
			this.checkSessionActive = true;
			this.checkStartPending = true;

			this.$checkBtn.prop( 'disabled', true );
			if ( this.$restartBtn && this.$restartBtn.length ) {
				this.$restartBtn.hide();
			}
			this.$startBtn.prop( 'disabled', parseInt( tsoliinData.scanRunning, 10 ) === 1 );
			this.$stopBtn.show();
			this.$checkProg.show();
			// Keep last known % while the server decides resume vs full reset.
			var startPct = willResume ? ( parseInt( tsoliinData.bgPct, 10 ) || 0 ) : 0;
			this.updateCheckProgress( startPct, tsoliinData.i18n.checking );

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action   : 'tsoliin_start_bg_check',
					nonce    : tsoliinData.nonce,
					// Resume partial progress (pending last_checked IS NULL). Only resets when the queue is empty.
					resume   : willResume ? '1' : '0',
					post_id  : postId
				},
				success: function ( r ) {
					self.scanChainCheck = false;
					if ( r.success ) {
						self.checkStartPending = false;
						tsoliinData.bgRunning = 1;
						tsoliinData.checkPaused = 0;
						tsoliinData.scanRunning = 0;
						self.scanning = false;
						self.scanSessionActive = false;
						self.$stopScanBtn.hide();
						tsoliinData.bgPostId = parseInt( r.data.post_id, 10 ) || 0;
						if ( typeof r.data.pct !== 'undefined' ) {
							tsoliinData.bgPct = r.data.pct;
							self.updateCheckProgress( r.data.pct, tsoliinData.i18n.checking );
						}
						if ( typeof r.data.pending !== 'undefined' ) {
							tsoliinData.pendingCheck = r.data.pending;
						}
						self.refreshStats();
						var noticeMsg = r.data.message || tsoliinData.i18n.checkStarted;
						if ( r.data.resumed && tsoliinData.i18n.checkResumed ) {
							noticeMsg = r.data.message || tsoliinData.i18n.checkResumed;
						}
						self.showNotice(
							'✅ ' + noticeMsg,
							'success'
						);
						self.startPolling();
						self.checkTick();
					} else {
						self.checkStartPending = false;
						self.resetCheckButton();
						self.$startBtn.prop( 'disabled', false );
						self.$stopBtn.hide();
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () {
					self.scanChainCheck = false;
					// The start request has an indeterminate result; polling is the source
					// of truth and will reveal whether the server created the job.
					tsoliinData.bgRunning = 1;
					self.showNotice( tsoliinData.i18n.error, 'error' );
					self.startPolling();
					self.checkTick();
				}
			} );
		},

		stopBgCheck: function () {
			var self = this;
			this.checkSessionActive = false;
			this.$stopBtn.prop( 'disabled', true );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action : 'tsoliin_stop_bg_check',
					nonce  : tsoliinData.nonce,
					post_id: parseInt( tsoliinData.viewPostId, 10 ) || 0
				},
				success: function ( r ) {
					if ( ! r || ! r.success ) {
						self.$stopBtn.prop( 'disabled', false );
						self.showNotice( tsoliinData.i18n.error, 'error' );
						return;
					}
					self.checkSessionActive = false;
					self.stopPolling();
					tsoliinData.bgRunning = 0;
					tsoliinData.checkPaused = 1;
					if ( self.$restartBtn && self.$restartBtn.length ) {
						self.$restartBtn.hide();
					}
					self.$startBtn.prop( 'disabled', false );
					self.$stopBtn.hide().prop( 'disabled', false );
					if ( r.data && typeof r.data.pending !== 'undefined' ) {
						tsoliinData.pendingCheck = r.data.pending;
					}
					if ( r.data && typeof r.data.pct !== 'undefined' ) {
						tsoliinData.bgPct = r.data.pct;
					}
					self.resetCheckButton();
					self.updateCheckProgress( parseInt( tsoliinData.bgPct, 10 ) || 0, tsoliinData.i18n.checkPaused || tsoliinData.i18n.stopped );
					self.syncDiscardButtons();
				},
				error: function () {
					self.$stopBtn.prop( 'disabled', false );
					self.showNotice( tsoliinData.i18n.error, 'error' );
					if ( ! self.polling ) {
						self.startPolling();
					}
				}
			} );
		},

		startPolling: function () {
			if ( this.polling ) { return; }
			this.polling = true;
			this.pollProgress();
		},

		stopPolling: function () {
			this.polling = false;
			if ( this.pollTimer ) {
				clearTimeout( this.pollTimer );
				this.pollTimer = null;
			}
		},

		pollProgress: function () {
			if ( ! this.polling ) { return; }
			var self = this;
			var needsNudge = this.checkSessionActive
				&& ! parseInt( tsoliinData.bgRunning, 10 )
				&& ( parseInt( tsoliinData.bgPending, 10 ) || 0 ) > 0
				&& ! this.completed;
			var sendNudge = needsNudge && ! this.nudgePending;
			if ( sendNudge ) {
				this.nudgePending = true;
			}

			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action        : 'tsoliin_check_progress',
					nonce         : tsoliinData.nonce,
					post_id       : parseInt( tsoliinData.viewPostId, 10 ) || 0,
					scope         : tsoliinData.listScope || 'all',
					nudge         : sendNudge ? '1' : '0',
					check_session_active: self.checkSessionActive ? '1' : '0',
					scan_session_active : self.scanSessionActive ? '1' : '0'
				},
				success: function ( r ) {
					if ( ! r.success ) {
						self.nudgePending = false;
						self.stopPolling();
						self.$stopBtn.prop( 'disabled', true );
						self.$stopScanBtn.prop( 'disabled', true );
						self.showNotice(
							( r.data && r.data.message ) || tsoliinData.i18n.sessionExpired || tsoliinData.i18n.error,
							'error'
						);
						return;
					}
					var d = r.data;
					tsoliinData.bgPostId = parseInt( d.post_id, 10 ) || 0;
					if ( typeof d.bg_pending !== 'undefined' ) {
						tsoliinData.bgPending = d.bg_pending;
					}
					if ( d.stats ) {
						self.applyStatsToUI( d.stats, d.display );
					}
					var runPending = ( typeof d.bg_pending !== 'undefined' )
						? ( parseInt( d.bg_pending, 10 ) || 0 )
						: ( parseInt( d.pending, 10 ) || 0 );
					var scanWasRunning = parseInt( tsoliinData.scanRunning, 10 ) === 1;
					var checkWasRunning = parseInt( tsoliinData.bgRunning, 10 ) === 1;
					var scanBlocking = self.isScanBlockingCheck();

					if ( d.scan ) {
						if ( d.scan.running ) {
							self.scanStartPending = false;
							tsoliinData.scanRunning = 1;
							self.scanning = true;
							self.scanAborted = false;
							self.scanSessionActive = true;
							self.applyScanProgress( d.scan );
							if ( ! self.scanTickBusy ) {
								self.scanTick();
							}
						} else if ( scanWasRunning ) {
							if ( self.scanStartPending ) {
								self.scanStartPending = false;
								tsoliinData.scanRunning = 0;
								self.scanning = false;
								self.scanSessionActive = false;
								self.$stopScanBtn.hide();
								self.resetScanButton();
							} else if ( d.scan.error ) {
								self.scanError( d.scan.error );
							} else if ( d.scan.done && ! self.scanCompleted ) {
								self.scanCompleted = true;
								self.applyScanProgress( d.scan );
								self.scanFinished();
							} else {
								// Stopped mid-run (Stop scan or stale flag cleared).
								tsoliinData.scanRunning = 0;
								self.scanning = false;
								self.scanSessionActive = false;
								tsoliinData.scanResumable = d.scan.resumable ? 1 : 0;
								self.$stopScanBtn.hide();
								self.resetScanButton();
								if ( d.scan.resumable && self.$restartScanBtn && self.$restartScanBtn.length ) {
									self.$restartScanBtn.show();
								}
								if ( ! parseInt( tsoliinData.bgRunning, 10 ) ) {
									self.$checkBtn.prop( 'disabled', self.isScanBlockingCheck() );
								}
								self.applyScanProgress( d.scan );
							}
						}
					}

					if ( d.running && ! scanBlocking ) {
						self.checkStartPending = false;
						tsoliinData.bgRunning = 1;
						tsoliinData.checkPaused = 0;
						self.checkSessionActive = true;
						self.completed = false;
						self.updateCheckProgress( d.pct, d.message );
						if ( typeof d.pending !== 'undefined' ) {
							tsoliinData.pendingCheck = d.pending;
						}
						if ( typeof d.pct !== 'undefined' ) {
							tsoliinData.bgPct = d.pct;
						}
						if ( d.queue ) {
							self.updateQueueChip( d.queue );
						}
						if ( ! self.checkTickBusy ) {
							self.checkTick();
						}
					} else if ( ! scanBlocking && ( checkWasRunning || self.checkSessionActive ) && ! self.completed ) {
						if ( typeof d.pending !== 'undefined' ) {
							tsoliinData.pendingCheck = d.pending;
						}
						if ( typeof d.pct !== 'undefined' ) {
							tsoliinData.bgPct = d.pct;
						}
						if ( d.queue ) {
							self.updateQueueChip( d.queue );
						}
						if ( self.checkStartPending ) {
							self.checkStartPending = false;
							tsoliinData.bgRunning = 0;
							self.checkSessionActive = false;
							self.$stopBtn.hide();
							self.resetCheckButton();
							self.$startBtn.prop( 'disabled', parseInt( tsoliinData.scanRunning, 10 ) === 1 );
						} else if ( d.done ) {
							self.completed = true;
							self.checkSessionActive = false;
							self.nudgePending = false;
							self.checkDone();
						} else if ( runPending > 0 ) {
							tsoliinData.bgRunning = d.running ? 1 : 0;
							if ( typeof d.check_paused !== 'undefined' ) {
								tsoliinData.checkPaused = d.check_paused ? 1 : 0;
							} else if ( ! d.running && d.pct > 0 && d.pct < 100 ) {
								tsoliinData.checkPaused = 1;
							}
							if ( d.running ) {
								self.nudgePending = false;
								self.$stopBtn.show();
								self.$checkBtn.prop( 'disabled', true );
							}
							self.resetCheckButton();
							self.updateCheckProgress(
								d.pct,
								d.running ? d.message : ( tsoliinData.i18n.checkPaused || tsoliinData.i18n.stopped )
							);
							if ( ! d.running && parseInt( tsoliinData.checkPaused, 10 ) === 1 ) {
								self.$checkProg.show();
							}
						} else {
							tsoliinData.bgRunning = 0;
							self.checkSessionActive = false;
							self.nudgePending = false;
							self.$stopBtn.hide();
							self.resetCheckButton();
							self.$startBtn.prop( 'disabled', parseInt( tsoliinData.scanRunning, 10 ) === 1 );
						}
					}

					var stillActive = d.running
						|| ( d.scan && d.scan.running )
						|| parseInt( tsoliinData.scanRunning, 10 ) === 1
						|| parseInt( tsoliinData.bgRunning, 10 ) === 1
						|| ( self.checkSessionActive && runPending > 0 && ! self.completed );
					if ( stillActive && ! self.completed ) {
						var pollMs = self.scanSessionActive ? 2000 : 5000;
						self.pollTimer = setTimeout( function () { self.pollProgress(); }, pollMs );
					} else {
						self.stopPolling();
					}
				},
				error: function ( xhr ) {
					self.nudgePending = false;
					if ( xhr && ( xhr.status === 401 || xhr.status === 403 ) ) {
						self.stopPolling();
						self.$stopBtn.prop( 'disabled', true );
						self.$stopScanBtn.prop( 'disabled', true );
						self.showNotice( tsoliinData.i18n.sessionExpired || tsoliinData.i18n.error, 'error' );
						return;
					}
					if ( self.polling ) {
						self.pollTimer = setTimeout( function () { self.pollProgress(); }, 10000 );
					}
				}
			} );
		},

		checkTick: function () {
			var self = this;
			if ( self.checkTickBusy || self.checkTickTimer || ! self.checkSessionActive || self.completed ) {
				return;
			}
			self.checkTickBusy = true;
			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				data   : {
					action : 'tsoliin_bg_check_tick',
					nonce  : tsoliinData.nonce
				},
				success: function ( r ) {
					self.checkTickBusy = false;
					if ( ! r.success ) {
						self.showNotice( r.data ? r.data.message : tsoliinData.i18n.error, 'error' );
						return;
					}
					var d = r.data || {};
					if ( typeof d.pending !== 'undefined' ) {
						tsoliinData.pendingCheck = d.pending;
					}
					if ( typeof d.pct !== 'undefined' ) {
						tsoliinData.bgPct = d.pct;
					}
					if ( d.running ) {
						tsoliinData.bgRunning = 1;
						self.updateCheckProgress( d.pct, d.message || tsoliinData.i18n.checking );
						if ( self.checkSessionActive && ! self.completed ) {
							self.scheduleTick( 'check', self.tickDelay( d ) );
						}
						return;
					}
					tsoliinData.bgRunning = 0;
					if ( d.done && ! self.completed ) {
						self.completed = true;
						self.checkSessionActive = false;
						self.checkDone();
						return;
					}
					self.updateCheckProgress(
						d.pct,
						d.message || tsoliinData.i18n.checkPaused || tsoliinData.i18n.stopped
					);
				},
				error: function ( xhr ) {
					self.checkTickBusy = false;
					if ( xhr && ( xhr.status === 401 || xhr.status === 403 ) ) {
						self.showNotice( tsoliinData.i18n.sessionExpired || tsoliinData.i18n.error, 'error' );
						return;
					}
					if ( self.checkSessionActive && ! self.completed ) {
						self.scheduleTick( 'check', 15000 );
					}
				}
			} );
		},

		updateCheckProgress: function ( pct, label ) {
			pct = Math.min( 100, Math.max( 0, pct ) );
			this.$checkBar.css( 'width', pct + '%' );
			this.$checkLbl.text( pct + '% – ' + label );
			this.$checkProg.attr( 'aria-valuenow', pct );
		},

		updateQueueChip: function ( chip ) {
			if ( ! chip || ! chip.label ) {
				return;
			}
			var $chip = $( '#tsoliin-queue-chip' );
			if ( ! $chip.length ) {
				return;
			}
			var warn = !! chip.warn;
			var icon = warn ? 'clock' : 'saved';
			$chip.attr( 'title', chip.title || '' );
			$chip.toggleClass( 'tsoliin-chip--warn', warn );
			$chip.toggleClass( 'tsoliin-chip--ok', ! warn );
			$chip.empty();
			$chip.append(
				$( '<span>', { class: 'dashicons dashicons-' + icon, 'aria-hidden': 'true' } ),
				document.createTextNode( ' ' + chip.label )
			);
		},

		checkDone: function () {
			// Guard: only execute once even if called multiple times.
			if ( ! this.completed ) { return; }
			this.checkSessionActive = false;
			tsoliinData.bgRunning = 0;
			this.$stopBtn.hide();
			this.$startBtn.prop( 'disabled', false );
			this.updateCheckProgress( 100, tsoliinData.i18n.checkDone );
			this.$checkBtn.prop( 'disabled', false ).html(
				'<span class="dashicons dashicons-yes-alt"></span> ' + tsoliinData.i18n.checkDone
			);
			// Reload to show final stats, but not while the user is editing or an AJAX action is running.
			var self = this;
			var delayMs = 2000;
			var maxWaitMs = 120000;
			var started = Date.now();
			var tryReload = function () {
				if ( self.shouldDeferCheckReload( started, maxWaitMs ) ) {
					self.updateCheckProgress( 100, tsoliinData.i18n.checkCompleteWaiting || tsoliinData.i18n.checkDone );
					setTimeout( tryReload, 500 );
					return;
				}
				window.location.reload();
			};
			setTimeout( tryReload, delayMs );
		},

		// ---------------------------------------------------------------
		// Live stat + filter tab counts
		// ---------------------------------------------------------------
		applyStatsToUI: function ( stats, display ) {
			if ( ! stats ) {
				return;
			}
			var tabMap = {
				all                : 'total',
				broken             : 'broken',
				redirect           : 'redirect',
				ok                 : 'ok',
				unchecked          : 'unchecked',
				http_insecure      : 'http_insecure',
				manual_locked      : 'manual_locked',
				empty_anchor       : 'empty_anchor',
				generic_anchor     : 'generic_anchor',
				unpublished_target : 'unpublished_target'
			};
			var qualityKeys = {
				empty_anchor       : true,
				generic_anchor     : true,
				unpublished_target : true
			};
			var getFilterKeyFromHref = function ( href ) {
				if ( ! href || href.indexOf( 'filter=' ) === -1 ) {
					return 'all';
				}
				var match = href.match( /[?&]filter=([^&]+)/ );
				return match ? match[1] : 'all';
			};
			var getQualityKeyFromHref = function ( href ) {
				if ( ! href || href.indexOf( 'quality_filter=' ) === -1 ) {
					return '';
				}
				var match = href.match( /[?&]quality_filter=([^&]+)/ );
				return match ? match[1] : '';
			};
			var updateTabLabels = function ( selector, templateMap, mode ) {
				$.each( tabMap, function ( filterKey, statKey ) {
					if ( undefined === stats[ statKey ] ) {
						return;
					}
					var count = ( display && display[ statKey ] ) ? display[ statKey ] : stats[ statKey ];
					$( selector + ' a' ).each( function () {
						var href = $( this ).attr( 'href' ) || '';
						if ( 'quality' === mode ) {
							if ( getQualityKeyFromHref( href ) !== filterKey ) {
								return;
							}
						} else if ( qualityKeys[ filterKey ] ) {
							return;
						} else if ( getFilterKeyFromHref( href ) !== filterKey ) {
							return;
						}
						var template = templateMap && templateMap[ filterKey ];
						if ( template ) {
							$( this ).text( template.replace( '%s', count ) );
						} else {
							var label = $( this ).text().replace( /\([^)]*\)/, '(' + count + ')' );
							$( this ).text( label );
						}
					} );
				} );
			};
			updateTabLabels( '.tsoliin-filter-tabs', tsoliinData.filterTabs, 'status' );
			updateTabLabels( '.tsoliin-quality-tabs', tsoliinData.qualityFilterTabs, 'quality' );

			var cardMap = {
				total          : '.tsoliin-stat--total .tsoliin-stat__number',
				broken         : '.tsoliin-stat--broken .tsoliin-stat__number',
				redirect       : '.tsoliin-stat--redirect .tsoliin-stat__number',
				ok             : '.tsoliin-stat--ok .tsoliin-stat__number',
				unchecked      : '.tsoliin-stat--unchecked .tsoliin-stat__number',
				http_insecure  : '.tsoliin-stat--http-insecure .tsoliin-stat__number'
			};
			$.each( cardMap, function ( statKey, selector ) {
				if ( undefined === stats[ statKey ] ) {
					return;
				}
				var $el = $( selector );
				if ( $el.length ) {
					$el.text( display && display[ statKey ] ? display[ statKey ] : stats[ statKey ] );
				}
			} );
		},

		refreshStats: function () {
			if ( ! $( '.tsoliin-wrap .tsoliin-stats' ).length ) {
				return;
			}
			var self = this;
			var checkRunning = parseInt( tsoliinData.bgRunning, 10 ) === 1 || this.checkSessionActive;
			var postId = checkRunning
				? ( parseInt( tsoliinData.bgPostId, 10 ) || 0 )
				: ( parseInt( tsoliinData.viewPostId, 10 ) || 0 );
			var scope = checkRunning ? 'all' : ( tsoliinData.listScope || 'all' );
			$.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action  : 'tsoliin_get_stats',
					nonce   : tsoliinData.nonce,
					post_id : postId,
					scope   : scope
				},
				success: function ( r ) {
					if ( ! r.success || ! r.data ) {
						return;
					}
					self.applyStatsToUI( r.data.stats, r.data.display );
				}
			} );
		},

		showNotice: function ( msg, type ) {
			$( '.tsoliin-notice' ).remove();
			var $n = $( '<div class="notice notice-' + ( type || 'info' ) + ' is-dismissible tsoliin-notice"><p>' + msg + '</p><button type="button" class="notice-dismiss"></button></div>' );
			var $anchor = $( '.tsoliin-hero' );
			if ( ! $anchor.length ) {
				$anchor = $( '.tsoliin-toolbar' );
			}
			$anchor.after( $n );
			$n.on( 'click', '.notice-dismiss', function ( e ) {
				e.preventDefault();
				$n.fadeOut( 200, function () { $n.remove(); } );
			} );
		},

		/**
		 * Reload the list table so pagination and row counts match the database.
		 * Stat cards alone cannot refresh WP_List_Table tablenav output.
		 *
		 * @param {number} delay Ms before reload (lets the user read a short message).
		 */
		scheduleListReload: function ( delay ) {
			var self = this;
			self.cancelListReload();
			self.listReloadTimer = window.setTimeout( function () {
				self.listReloadTimer = null;
				var wait = function () {
					if ( self.isScanBlockingCheck() || parseInt( tsoliinData.bgRunning, 10 ) === 1 ) {
						self.listReloadTimer = window.setTimeout( wait, 500 );
						return;
					}
					if ( self.$modal && self.$modal.is( ':visible' ) ) {
						self.listReloadTimer = window.setTimeout( wait, 500 );
						return;
					}
					window.location.reload();
				};
				wait();
			}, delay || 1200 );
		},

		/**
		 * Cancel a pending full-page list reload (e.g. user starts another action).
		 */
		cancelListReload: function () {
			if ( this.listReloadTimer ) {
				window.clearTimeout( this.listReloadTimer );
				this.listReloadTimer = null;
			}
		},

		countListRows: function () {
			return $( '#tsoliin-list-form' ).find( 'tbody tr' ).not( '.tsoliin-suggest-row' ).length;
		},

		maybeReloadEmptyList: function () {
			if ( 0 === this.countListRows() ) {
				this.scheduleListReload( 800 );
			}
		},

		listFilterParam: function () {
			var params = { list_filter: tsoliinData.listFilter || 'all' };
			if ( tsoliinData.listQualityFilter ) {
				params.list_quality_filter = tsoliinData.listQualityFilter;
			}
			if ( tsoliinData.listScope && 'all' !== tsoliinData.listScope ) {
				params.list_scope = tsoliinData.listScope;
			}
			return params;
		},

		isStalePayload: function ( data ) {
			return !!( data && ( data.gone || data.stale || ( data.removed && ! data.new_url ) ) );
		},

		removeStaleRow: function ( $row, data ) {
			var self = this;
			var msg  = ( data && data.message ) ? data.message : ( tsoliinData.i18n.staleRowGone || tsoliinData.i18n.urlSaved );
			if ( ! $row || ! $row.length ) {
				this.showNotice( msg, 'success' );
				this.refreshStats();
				return true;
			}
			this.removeSuggestPanelsForRow( $row );
			$row.fadeOut( 300, function () {
				$( this ).remove();
				self.maybeReloadEmptyList();
			} );
			this.showNotice( msg, 'success' );
			this.refreshStats();
			return true;
		},

		removeRowIfFilterMismatch: function ( $row, data, skipReload ) {
			if ( ! $row || ! $row.length || ! data || false !== data.matches_filter ) {
				return false;
			}
			var hasStatusFilter = 'all' !== ( tsoliinData.listFilter || 'all' );
			var hasQuality      = !! tsoliinData.listQualityFilter;
			var hasScope        = !!( tsoliinData.listScope && 'all' !== tsoliinData.listScope );
			if ( ! hasStatusFilter && ! hasQuality && ! hasScope ) {
				return false;
			}
			var self = this;
			self.removeSuggestPanelsForRow( $row );
			$row.fadeOut( 300, function () {
				$( this ).remove();
				if ( skipReload ) {
					return;
				}
				// Do not full-reload the page here: it aborts in-flight Suggest/Apply on the next row.
				// Stats tabs refresh via refreshStats(); reload only when the list is empty.
				self.maybeReloadEmptyList();
			} );
			return true;
		},

		// ---------------------------------------------------------------
		// Recheck single row
		// ---------------------------------------------------------------
		recheckLink: function ( linkId, $trigger ) {
			var self    = this;
			var $row    = $trigger.closest( 'tr' );
			var $status = $row.find( '.column-status_code' );
			var $chk    = $row.find( '.column-last_checked' );

			$trigger.text( tsoliinData.i18n.rechecking );
			$status.html( '<em>...</em>' );

			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( { action: 'tsoliin_recheck', nonce: tsoliinData.nonce, link_id: linkId }, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						$status.html( '<span class="tsoliin-status tsoliin-status--broken">' + tsoliinData.i18n.error + '</span>' );
						$trigger.text( tsoliinData.i18n.recheck );
						return;
					}
					var d = r.data;
					if ( self.isStalePayload( d ) ) {
						self.removeStaleRow( $row, d );
						return;
					}
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.refreshStats();
						$trigger.text( tsoliinData.i18n.recheck );
						return;
					}
					if ( d.new_url ) {
						self.applyLinkEditToRow( $row, d );
					} else if ( d.status_html ) {
						$status.html( d.status_html );
					} else {
						$status.html( '<span class="tsoliin-status ' + d.css_class + '">' + d.status_code + ' ' + d.label + '</span>' );
					}
					if ( d.link_id && parseInt( d.link_id, 10 ) !== linkId ) {
						$row.find( '[data-id="' + linkId + '"]' ).attr( 'data-id', d.link_id );
					}
					$chk.text( d.last_checked );
					$trigger.text( tsoliinData.i18n.recheck );
					$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
					self.refreshStats();
				},
				error: function () {
					$status.html( '<span class="tsoliin-status tsoliin-status--broken">' + tsoliinData.i18n.error + '</span>' );
					$trigger.text( tsoliinData.i18n.recheck );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Edit modal
		// ---------------------------------------------------------------
		openModal: function ( linkId, oldUrl, postId, anchorText, linkType, anchorEditable ) {
			this.editLinkId   = linkId;
			this.editOldUrl   = oldUrl;
			this.editPostId   = postId;
			this.editLinkType = linkType || 'link';
			this.editAnchorEditable = false !== anchorEditable;
			this.$modalOldUrl.text( oldUrl );
			this.$newUrlInput.val( oldUrl );
			if ( this.$anchorRow.length ) {
				if ( 'iframe' === this.editLinkType ) {
					this.$anchorRow.hide();
				} else {
					this.$anchorRow.show();
					if ( this.editAnchorEditable ) {
						this.$anchorLabel.text(
							'image' === this.editLinkType
								? ( tsoliinData.i18n.altText || 'Alt text:' )
								: ( tsoliinData.i18n.linkText || 'Link text:' )
						);
						this.$newAnchorInput.prop( 'readonly', false ).removeClass( 'tsoliin-input--readonly' );
						if ( this.$anchorNote.length ) {
							this.$anchorNote.text( '' ).hide();
						}
					} else {
						this.$anchorLabel.text( tsoliinData.i18n.commentLabel || 'Inspector label (read-only):' );
						this.$newAnchorInput.prop( 'readonly', true ).addClass( 'tsoliin-input--readonly' );
						if ( this.$anchorNote.length ) {
							this.$anchorNote.text( tsoliinData.i18n.commentLabelNote || '' ).show();
						}
					}
				}
			}
			if ( this.$newAnchorInput.length ) {
				this.$newAnchorInput.val( anchorText || '' );
			}
			this.$feedback.text( '' ).removeClass( 'is-error is-success is-warning' );
			if ( this.$applyAnyway.length ) {
				this.$applyAnyway.prop( 'checked', false );
			}
			if ( this.$ignoreDomain.length ) {
				this.$ignoreDomain.prop( 'checked', false );
			}
			this.syncIgnoreDomainOption();
			if ( this.canPreviewLinkEdit() ) {
				this.$previewPanel.show();
				this.scheduleLinkPreview();
			} else if ( this.$previewPanel.length ) {
				this.$previewPanel.hide();
			}
			if ( this.$revisionNote.length ) {
				if ( tsoliinData.createRevision && this.editPostId > 0 && -1 !== [ 'link', 'image', 'iframe' ].indexOf( this.editLinkType ) ) {
					this.$revisionNote.text( tsoliinData.i18n.revisionModalNote || '' ).show();
				} else {
					this.$revisionNote.text( '' ).hide();
				}
			}
			this.$modal.show();
			this.$newUrlInput.trigger( 'focus' );
		},

		closeModal: function () {
			if ( this.previewTimer ) {
				window.clearTimeout( this.previewTimer );
				this.previewTimer = null;
			}
			if ( this.previewXhr && this.previewXhr.abort ) {
				this.previewXhr.abort();
			}
			this.previewReqId = ( this.previewReqId || 0 ) + 1;
			this.$modal.hide();
			this.editLinkId = 0;
		},

		canPreviewLinkEdit: function () {
			return this.$previewPanel.length
				&& this.editPostId > 0
				&& -1 !== [ 'link', 'image', 'iframe' ].indexOf( this.editLinkType );
		},

		/**
		 * Relative /path URLs have no external host — disable ignore-domain.
		 */
		syncIgnoreDomainOption: function () {
			if ( ! this.$ignoreDomain.length ) {
				return;
			}
			var url = ( this.$newUrlInput.val() || '' ).trim();
			var hasHost = /^https?:\/\//i.test( url ) || /^\/\//.test( url );
			var $wrap = this.$ignoreDomain.closest( '.tsoliin-ignore-domain-wrap' );
			if ( hasHost ) {
				this.$ignoreDomain.prop( 'disabled', false );
				if ( $wrap.length ) {
					$wrap.show();
				}
			} else {
				this.$ignoreDomain.prop( { checked: false, disabled: true } );
				if ( $wrap.length ) {
					$wrap.show();
				}
			}
		},

		scheduleLinkPreview: function () {
			var self = this;
			if ( ! this.canPreviewLinkEdit() || ! this.editLinkId ) {
				return;
			}
			if ( this.previewTimer ) {
				window.clearTimeout( this.previewTimer );
			}
			this.$previewBefore.text( tsoliinData.i18n.previewLoading || 'Loading preview...' );
			this.$previewAfter.text( '' );
			this.previewTimer = window.setTimeout( function () {
				self.fetchLinkPreview();
			}, 350 );
		},

		fetchLinkPreview: function () {
			var self   = this;
			var newUrl = this.$newUrlInput.val().trim();
			var oldUrl = ( this.editOldUrl || '' ).toString();
			if ( ! newUrl ) {
				this.$previewBefore.text( '' );
				this.$previewAfter.text( '' );
				return;
			}
			this.previewReqId = ( this.previewReqId || 0 ) + 1;
			var reqId = this.previewReqId;
			if ( this.previewXhr && this.previewXhr.abort ) {
				this.previewXhr.abort();
			}
			this.previewXhr = $.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : {
					action  : 'tsoliin_link_preview',
					nonce   : tsoliinData.nonce,
					link_id : self.editLinkId,
					new_url : newUrl
				},
				success: function ( r ) {
					if ( reqId !== self.previewReqId ) {
						return;
					}
					if ( ! r.success || ! r.data ) {
						self.$previewBefore.text( r.data && r.data.message ? r.data.message : tsoliinData.i18n.error );
						self.$previewAfter.text( '' );
						return;
					}
					if ( ! r.data.found ) {
						self.$previewBefore.text( tsoliinData.i18n.previewNotFound || 'No matching HTML tag found in the post for this URL.' );
						self.$previewAfter.text( '' );
						return;
					}
					var before = r.data.before || '';
					var after  = r.data.after || '';
					self.$previewBefore.text( before );
					if ( after && after !== before ) {
						self.$previewAfter.text( after );
					} else if ( before && newUrl && newUrl !== oldUrl ) {
						// Server replace failed or returned a no-op — still show the intended href.
						var forced = before;
						if ( oldUrl && -1 !== before.indexOf( oldUrl ) ) {
							forced = before.split( oldUrl ).join( newUrl );
						}
						self.$previewAfter.text( forced !== before ? forced : newUrl );
					} else if ( after ) {
						self.$previewAfter.text( after );
					} else {
						self.$previewAfter.text( newUrl );
					}
				},
				error: function ( xhr, status ) {
					if ( reqId !== self.previewReqId || 'abort' === status ) {
						return;
					}
					self.$previewBefore.text( tsoliinData.i18n.error );
					self.$previewAfter.text( '' );
				}
			} );
		},

		applyLinkEditToRow: function ( $row, d ) {
			if ( ! $row.length || ! d ) {
				return;
			}
			if ( d.new_url ) {
				var display = d.new_url.length > 110 ? d.new_url.substring( 0, 107 ) + '...' : d.new_url;
				var $link   = $row.find( '.tsoliin-url a' );
				var $icon   = $link.find( '.dashicons' ).detach();
				$link.attr( 'href', d.new_url ).attr( 'title', d.new_url );
				$link.text( display + ' ' );
				$link.append( $icon );
				$row.find( '.tsoliin-edit-link' ).attr( 'data-url', d.new_url ).data( 'url', d.new_url );
				this.editOldUrl = d.new_url;
			}
			if ( undefined !== d.new_anchor && null !== d.new_anchor ) {
				$row.find( '.column-anchor_text' ).text( d.new_anchor );
				$row.find( '.tsoliin-edit-link' ).attr( 'data-anchor', d.new_anchor ).data( 'anchor', d.new_anchor );
			}
			if ( d.status_html ) {
				$row.find( '.column-status_code' ).html( d.status_html );
			} else if ( undefined !== d.status_code && undefined !== d.css_class && undefined !== d.label ) {
				$row.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + this.escapeHtml( d.label ) + '</span>' );
			}
			if ( undefined !== d.is_broken ) {
				$row.toggleClass( 'tsoliin-row--broken', 1 === d.is_broken );
			}
		},

		saveLink: function () {
			var self       = this;
			var newUrl     = this.$newUrlInput.val().trim();
			var newAnchor  = this.$newAnchorInput.length ? this.$newAnchorInput.val().trim() : '';
			var applyAnyway  = this.$applyAnyway.length && this.$applyAnyway.prop( 'checked' );
			var ignoreDomain = this.$ignoreDomain.length && this.$ignoreDomain.prop( 'checked' );
			if ( ! newUrl ) {
				this.$feedback.text( tsoliinData.i18n.urlRequired ).addClass( 'is-error' );
				return;
			}

			this.$modalSave.prop( 'disabled', true ).text( tsoliinData.i18n.saving );
			this.$modalSpinner.addClass( 'is-active' );
			this.$feedback.text( '' ).removeClass( 'is-error is-success' );

			this.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action        : 'tsoliin_update_link',
					nonce         : tsoliinData.nonce,
					link_id       : self.editLinkId,
					new_url       : newUrl,
					new_anchor    : newAnchor,
					apply_anyway  : applyAnyway ? 1 : 0,
					ignore_domain : ignoreDomain ? 1 : 0
				}, self.listFilterParam() ),
				success: function ( r ) {
					self.$modalSave.prop( 'disabled', false ).text( tsoliinData.i18n.save );
					self.$modalSpinner.removeClass( 'is-active' );
					if ( ! r.success ) {
						var err = r.data || {};
						self.$feedback.text( err.message || tsoliinData.i18n.error ).addClass( 'is-error' );
						if ( err.unverified && ! applyAnyway && window.confirm( tsoliinData.i18n.confirmApplyAnyway ) ) {
							if ( self.$applyAnyway.length ) {
								self.$applyAnyway.prop( 'checked', true );
							}
							self.saveLink();
						}
						return;
					}
					var d    = r.data || {};
					var $row = $( 'tr' ).filter( function () {
						return $( this ).find( '.tsoliin-edit-link[data-id="' + self.editLinkId + '"]' ).length > 0;
					} );
					if ( self.isStalePayload( d ) ) {
						self.closeModal();
						self.removeStaleRow( $row, d );
						return;
					}
					var msg  = d.filter_promotion_message || ( d.warning ? d.warning : ( '✓ ' + tsoliinData.i18n.urlSaved ) );
					if ( ! d.warning && d.revision_created ) {
						msg += ' ' + ( tsoliinData.i18n.revisionSaved || '' );
					}
					if ( $row.length ) {
						if ( self.removeRowIfFilterMismatch( $row, d ) ) {
							self.$feedback.text( msg ).addClass( d.warning ? 'is-warning' : 'is-success' );
							setTimeout( function () { self.closeModal(); }, 800 );
							self.refreshStats();
							return;
						}
						self.applyLinkEditToRow( $row, d );
					}
					self.$feedback.text( msg ).addClass( d.warning ? 'is-warning' : 'is-success' );
					setTimeout( function () { self.closeModal(); }, d.warning ? 1800 : 1200 );
					self.refreshStats();
				},
				error: function () {
					self.$modalSave.prop( 'disabled', false ).text( tsoliinData.i18n.save );
					self.$modalSpinner.removeClass( 'is-active' );
					self.$feedback.text( tsoliinData.i18n.error ).addClass( 'is-error' );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Unlink / Delete
		// ---------------------------------------------------------------
		unlinkItem: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_unlink', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( r.success ) {
						var d = r.data || {};
						if ( self.isStalePayload( d ) ) {
							self.removeStaleRow( $row, d );
							return;
						}
						self.removeSuggestPanelsForRow( $row );
						$row.fadeOut( 400, function () {
							$( this ).remove();
							self.refreshStats();
							self.maybeReloadEmptyList();
						} );
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.unlink );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); $trigger.text( tsoliinData.i18n.unlink ); }
			} );
		},

		// ---------------------------------------------------------------
		// Ignore list (row action)
		// ---------------------------------------------------------------
		addToIgnoreList: function ( $trigger ) {
			var self     = this;
			var linkId   = parseInt( $trigger.data( 'id' ), 10 );
			var pattern  = $trigger.data( 'pattern' ) || '';
			var $row     = $trigger.closest( 'tr' );
			var confirmT = ( tsoliinData.i18n.confirmAddIgnore || 'Add %s to the ignore list?' ).replace( '%s', pattern );

			if ( ! window.confirm( confirmT ) ) {
				return;
			}

			$trigger.text( tsoliinData.i18n.saving || 'Saving...' );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action  : 'tsoliin_add_ignore',
					nonce   : tsoliinData.nonce,
					link_id : linkId,
					pattern : pattern
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						self.showNotice( r.data ? r.data.message : tsoliinData.i18n.error, 'error' );
						$trigger.text( tsoliinData.i18n.addIgnore || 'Ignore domain' );
						return;
					}
					var d = r.data || {};
					if ( self.isStalePayload( d ) ) {
						self.removeStaleRow( $row, d );
						return;
					}
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.showNotice( d.message, 'success' );
						self.refreshStats();
						return;
					}
					if ( d.status_html ) {
						$row.find( '.column-status_code' ).html( d.status_html );
					}
					$row.removeClass( 'tsoliin-row--broken' );
					$trigger.closest( 'span' ).remove();
					self.showNotice( d.message, 'success' );
					self.refreshStats();
				},
				error: function () {
					self.showNotice( tsoliinData.i18n.error, 'error' );
					$trigger.text( tsoliinData.i18n.addIgnore || 'Ignore domain' );
				}
			} );
		},

		dismissOnboarding: function ( $banner ) {
			var self = this;
			if ( $banner && $banner.length ) {
				$banner.fadeOut( 200, function () { $banner.remove(); } );
			}
			$.post( tsoliinData.ajaxUrl, {
				action: 'tsoliin_dismiss_onboarding',
				nonce : tsoliinData.nonce
			} );
		},

		makeRelative: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action  : 'tsoliin_make_relative',
					nonce   : tsoliinData.nonce,
					link_id : linkId
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.makeRelative || 'Convert to /path' );
						return;
					}
					var d = r.data || {};
					if ( self.isStalePayload( d ) ) {
						self.removeStaleRow( $row, d );
						return;
					}
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.showNotice( d.message, 'success' );
						self.refreshStats();
						return;
					}
					self.applyLinkEditToRow( $row, d );
					$trigger.closest( 'span' ).remove();
					self.showNotice( d.message, 'success' );
					self.refreshStats();
				},
				error: function () {
					alert( tsoliinData.i18n.error );
					$trigger.text( tsoliinData.i18n.makeRelative || 'Use relative URL' );
				}
			} );
		},

		upgradeHttps: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.upgradingHttps || tsoliinData.i18n.saving );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action  : 'tsoliin_upgrade_https',
					nonce   : tsoliinData.nonce,
					link_id : linkId
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						alert( r.data && r.data.message ? r.data.message : ( tsoliinData.i18n.upgradeHttpsFailed || tsoliinData.i18n.error ) );
						$trigger.text( tsoliinData.i18n.upgradeHttps || 'Upgrade to HTTPS' );
						return;
					}
					var d = r.data || {};
					if ( self.isStalePayload( d ) ) {
						self.removeStaleRow( $row, d );
						return;
					}
					if ( self.removeRowIfFilterMismatch( $row, d ) ) {
						self.showNotice( d.message, 'success' );
						self.refreshStats();
						return;
					}
					self.applyLinkEditToRow( $row, d );
					$trigger.closest( 'span' ).remove();
					self.showNotice( d.message, 'success' );
					self.refreshStats();
				},
				error: function () {
					alert( tsoliinData.i18n.error );
					$trigger.text( tsoliinData.i18n.upgradeHttps || 'Upgrade to HTTPS' );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Mark as not broken
		// ---------------------------------------------------------------
		markNotBroken: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			$trigger.text( tsoliinData.i18n.saving );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( { action: 'tsoliin_not_broken', nonce: tsoliinData.nonce, link_id: linkId }, self.listFilterParam() ),
				success: function ( r ) {
					if ( r.success ) {
						if ( self.isStalePayload( r.data ) ) {
							self.removeStaleRow( $row, r.data );
							return;
						}
						if ( self.removeRowIfFilterMismatch( $row, r.data ) ) {
							$trigger.text( tsoliinData.i18n.notBroken );
							self.refreshStats();
							return;
						}
						if ( r.data.status_html ) {
							$row.find( '.column-status_code' ).html( r.data.status_html );
						} else {
							$row.find( '.column-status_code' ).html( '<span class="tsoliin-status tsoliin-status--ok">' + r.data.status_code + ' ' + r.data.label + '</span>' );
						}
						$row.removeClass( 'tsoliin-row--broken' );
						$row.find( '.tsoliin-not-broken' ).closest( 'span' ).remove();
						$row.find( '.tsoliin-suggest' ).closest( 'span' ).remove();
						if ( r.data && r.data.message ) {
							self.showNotice( r.data.message, 'success' );
						}
						self.refreshStats();
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						$trigger.text( tsoliinData.i18n.notBroken );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); $trigger.text( tsoliinData.i18n.notBroken ); }
			} );
		},

		deleteItem: function ( linkId, $trigger ) {
			var self = this;
			var $row = $trigger.closest( 'tr' );
			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_delete_link', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( r.success ) {
						var d = r.data || {};
						if ( self.isStalePayload( d ) ) {
							self.removeStaleRow( $row, d );
							return;
						}
						self.removeSuggestPanelsForRow( $row );
						$row.fadeOut( 400, function () {
							$( this ).remove();
							self.refreshStats();
							self.maybeReloadEmptyList();
						} );
					} else {
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
					}
				},
				error: function () { alert( tsoliinData.i18n.error ); }
			} );
		},

		// ---------------------------------------------------------------
		// Bulk
		// ---------------------------------------------------------------
		doBulkAction: function ( action ) {
			var self    = this;
			if ( self._bulkInProgress ) {
				self.showNotice( tsoliinData.i18n.bulkBusy || tsoliinData.i18n.error, 'warning' );
				return;
			}
			var linkIds = [];
			this.$form.find( 'input[name="link_ids[]"]:checked' ).each( function () {
				linkIds.push( parseInt( $( this ).val(), 10 ) );
			} );
			if ( ! linkIds.length ) {
				self.showNotice( tsoliinData.i18n.noItemsSelected || tsoliinData.i18n.error, 'warning' );
				return;
			}

			if ( 'unlink' === action ) {
				var unlinkMsg = tsoliinData.i18n.confirmUnlinkBulk || tsoliinData.i18n.confirmUnlink;
				if ( ! window.confirm( unlinkMsg ) ) {
					return;
				}
			}
			if ( 'delete' === action && ! window.confirm( tsoliinData.i18n.confirmDeleteBulk || tsoliinData.i18n.confirmDelete ) ) {
				return;
			}

			if ( 'recheck' === action || 'unlink' === action || 'make_relative' === action || 'upgrade_https' === action || 'not_broken' === action || 'delete' === action ) {
				if ( 'make_relative' === action && ! window.confirm( tsoliinData.i18n.confirmMakeRelativeBulk ) ) {
					return;
				}
				if ( 'upgrade_https' === action && ! window.confirm( tsoliinData.i18n.confirmUpgradeHttpsBulk ) ) {
					return;
				}
				if ( 'not_broken' === action && ! window.confirm( tsoliinData.i18n.confirmNotBrokenBulk ) ) {
					return;
				}
				self.bulkRecheckStep( linkIds, 0, action );
			} else {
				self.showNotice( tsoliinData.i18n.error, 'error' );
			}
		},

		/**
		 * Process bulk recheck one link at a time with live row updates.
		 */
		bulkRecheckStep: function ( linkIds, index, action ) {
			var self  = this;
			var total = linkIds.length;
			var act   = action || 'recheck';

			// Show progress notice on first call.
			if ( 0 === index ) {
				self._bulkInProgress = true;
				self._bulkStats = { unlinked: 0, skipped: 0, failed: 0, converted: 0, marked: 0, deleted: 0 };
				self._bulkFilterRemoved = 0;
				var initMsg = tsoliinData.i18n.checking;
				if ( 'unlink' === act ) {
					initMsg = tsoliinData.i18n.unlinking;
				} else if ( 'make_relative' === act ) {
					initMsg = tsoliinData.i18n.convertingRelative || 'Converting to /path…';
				} else if ( 'upgrade_https' === act ) {
					initMsg = tsoliinData.i18n.upgradingHttps || 'Upgrading to HTTPS…';
				} else if ( 'not_broken' === act ) {
					initMsg = tsoliinData.i18n.markingNotBroken || 'Marking as OK…';
				} else if ( 'delete' === act ) {
					initMsg = tsoliinData.i18n.deleting || 'Deleting…';
				}
				self.$bulkProgress = $( '<div class="notice notice-info tsoliin-notice"><p><strong id="tsoliin-bulk-msg">' + initMsg + '</strong> <progress id="tsoliin-bulk-bar" max="100" value="0" style="width:200px;vertical-align:middle;"></progress></p></div>' );
				$( '.tsoliin-toolbar' ).after( self.$bulkProgress );
			}

			if ( index >= total ) {
				self._bulkInProgress = false;
				var doneMsg;
				if ( 'unlink' === act ) {
					var parts = [];
					if ( self._bulkStats.unlinked > 0 ) {
						parts.push( '✅ ' + self._bulkStats.unlinked + ' ' + tsoliinData.i18n.itemsUnlinked );
					}
					if ( self._bulkStats.skipped > 0 ) {
						parts.push( '⚠ ' + self._bulkStats.skipped + ' ' + tsoliinData.i18n.itemsSkipped );
					}
					if ( self._bulkStats.failed > 0 ) {
						parts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = parts.length ? parts.join( ' ' ) : ( '✅ 0 ' + tsoliinData.i18n.itemsUnlinked );
				} else if ( 'make_relative' === act ) {
					var relParts = [];
					if ( self._bulkStats.converted > 0 ) {
						relParts.push( '✅ ' + self._bulkStats.converted + ' ' + ( tsoliinData.i18n.itemsConverted || 'links converted to /path.' ) );
					}
					if ( self._bulkStats.skipped > 0 ) {
						relParts.push( '⚠ ' + self._bulkStats.skipped + ' ' + tsoliinData.i18n.itemsSkipped );
					}
					if ( self._bulkStats.failed > 0 ) {
						relParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = relParts.length ? relParts.join( ' ' ) : ( '✅ 0 ' + ( tsoliinData.i18n.itemsConverted || 'links converted to /path.' ) );
				} else if ( 'upgrade_https' === act ) {
					var httpsParts = [];
					if ( self._bulkStats.converted > 0 ) {
						httpsParts.push( '✅ ' + self._bulkStats.converted + ' ' + ( tsoliinData.i18n.itemsUpgradedHttps || 'links upgraded to HTTPS.' ) );
					}
					if ( self._bulkStats.skipped > 0 ) {
						httpsParts.push( '⚠ ' + self._bulkStats.skipped + ' ' + ( tsoliinData.i18n.itemsSkippedHttps || tsoliinData.i18n.itemsSkipped ) );
					}
					if ( self._bulkStats.failed > 0 ) {
						httpsParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = httpsParts.length ? httpsParts.join( ' ' ) : ( '✅ 0 ' + ( tsoliinData.i18n.itemsUpgradedHttps || 'links upgraded to HTTPS.' ) );
				} else if ( 'not_broken' === act ) {
					var okParts = [];
					if ( self._bulkStats.marked > 0 ) {
						okParts.push( '✅ ' + self._bulkStats.marked + ' ' + ( tsoliinData.i18n.itemsMarkedOk || 'marked as OK.' ) );
					}
					if ( self._bulkStats.failed > 0 ) {
						okParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = okParts.length ? okParts.join( ' ' ) : ( '✅ 0 ' + ( tsoliinData.i18n.itemsMarkedOk || 'marked as OK.' ) );
				} else if ( 'delete' === act ) {
					var delParts = [];
					if ( self._bulkStats.deleted > 0 ) {
						delParts.push( '✅ ' + self._bulkStats.deleted + ' ' + ( tsoliinData.i18n.itemsDeleted || 'records deleted.' ) );
					}
					if ( self._bulkStats.failed > 0 ) {
						delParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = delParts.length ? delParts.join( ' ' ) : ( '✅ 0 ' + ( tsoliinData.i18n.itemsDeleted || 'records deleted.' ) );
				} else {
					var recheckParts = [];
					recheckParts.push( '✅ ' + total + ' ' + tsoliinData.i18n.itemsChecked );
					if ( self._bulkStats.failed > 0 ) {
						recheckParts.push( '❌ ' + self._bulkStats.failed + ' ' + tsoliinData.i18n.itemsFailed );
					}
					doneMsg = recheckParts.join( ' ' );
				}
				$( '#tsoliin-bulk-msg' ).text( doneMsg );
				$( '#tsoliin-bulk-bar' ).val( 100 );
				var needsReload = (
					'unlink' === act
					|| 'make_relative' === act
					|| 'upgrade_https' === act
					|| 'delete' === act
					|| self._bulkFilterRemoved > 0
				);
				if ( needsReload ) {
					self.scheduleListReload( 1500 );
				} else {
					self.refreshStats();
					self.maybeReloadEmptyList();
					setTimeout( function () {
						if ( self.$bulkProgress ) {
							self.$bulkProgress.fadeOut( 300, function () { $( this ).remove(); } );
							self.$bulkProgress = null;
						}
					}, 2500 );
				}
				return;
			}

			var progressLabel = tsoliinData.i18n.checking;
			if ( 'unlink' === act ) {
				progressLabel = tsoliinData.i18n.unlinking;
			} else if ( 'make_relative' === act ) {
				progressLabel = tsoliinData.i18n.convertingRelative || 'Converting to /path…';
			} else if ( 'upgrade_https' === act ) {
				progressLabel = tsoliinData.i18n.upgradingHttps || 'Upgrading to HTTPS…';
			} else if ( 'not_broken' === act ) {
				progressLabel = tsoliinData.i18n.markingNotBroken || 'Marking as OK…';
			} else if ( 'delete' === act ) {
				progressLabel = tsoliinData.i18n.deleting || 'Deleting…';
			}
			var pct = Math.round( ( ( index + 1 ) / total ) * 100 );
			$( '#tsoliin-bulk-msg' ).text( progressLabel + ' ' + ( index + 1 ) + '/' + total );
			$( '#tsoliin-bulk-bar' ).val( pct );

			$.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 30000,
				data   : $.extend( {
					action     : 'tsoliin_bulk_action',
					nonce      : tsoliinData.nonce,
					bulk_action: act,
					link_ids   : linkIds,
					index      : index
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( ! r.success ) {
						self._bulkStats.failed++;
						alert( r.data ? r.data.message : tsoliinData.i18n.error );
						self.bulkRecheckStep( linkIds, index + 1, act );
						return;
					}
					if ( 'recheck' === act && r.data.row ) {
							// Update row status live (use request ID — resync may assign a new DB row).
							var d         = r.data.row;
							var requestId = linkIds[ index ];
							var $tr       = $( 'tr' ).filter( function () {
								return $( this ).find( 'input[value="' + requestId + '"]' ).length > 0;
							} );
							if ( $tr.length ) {
								if ( d.removed ) {
									self.removeSuggestPanelsForRow( $tr );
									$tr.fadeOut( 200, function () { $( this ).remove(); } );
									self._bulkFilterRemoved++;
									self.refreshStats();
								} else if ( self.removeRowIfFilterMismatch( $tr, d, true ) ) {
									self._bulkFilterRemoved++;
									self.refreshStats();
								} else {
									if ( d.new_url ) {
										self.applyLinkEditToRow( $tr, d );
									} else if ( d.status_html ) {
										$tr.find( '.column-status_code' ).html( d.status_html );
									} else if ( undefined !== d.status_code && undefined !== d.css_class && undefined !== d.label ) {
										$tr.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d.css_class + '">' + parseInt( d.status_code, 10 ) + ' ' + self.escapeHtml( d.label ) + '</span>' );
									}
									$tr.find( '.column-last_checked' ).text( d.last_checked );
									$tr.toggleClass( 'tsoliin-row--broken', 1 === parseInt( d.is_broken, 10 ) );
									if ( d.link_id && parseInt( d.link_id, 10 ) !== parseInt( requestId, 10 ) ) {
										$tr.find( 'input[name="link_ids[]"]' ).val( d.link_id );
										$tr.find( '[data-id="' + requestId + '"]' ).attr( 'data-id', d.link_id );
									}
								}
							}
						} else if ( 'unlink' === act && r.data.link_id ) {
							if ( r.data.skipped ) {
								self._bulkStats.skipped++;
							} else if ( r.data.unlinked ) {
								self._bulkStats.unlinked++;
								var $tr2 = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $tr2.length ) {
									self.removeSuggestPanelsForRow( $tr2 );
									$tr2.fadeOut( 200, function () { $( this ).remove(); } );
								}
							} else {
								self._bulkStats.failed++;
							}
						} else if ( ( 'make_relative' === act || 'upgrade_https' === act ) && r.data.link_id ) {
							if ( r.data.skipped ) {
								self._bulkStats.skipped++;
							} else if ( r.data.converted ) {
								self._bulkStats.converted++;
								var $tr3 = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $tr3.length && r.data.row ) {
									if ( self.removeRowIfFilterMismatch( $tr3, r.data.row, true ) ) {
										self._bulkFilterRemoved++;
									} else {
										self.applyLinkEditToRow( $tr3, r.data.row );
									}
								}
							} else {
								self._bulkStats.failed++;
							}
						} else if ( 'not_broken' === act && r.data.link_id ) {
							if ( r.data.marked && r.data.row ) {
								self._bulkStats.marked++;
								var $tr4 = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $tr4.length ) {
									var d4 = r.data.row;
									if ( self.removeRowIfFilterMismatch( $tr4, d4, true ) ) {
										self._bulkFilterRemoved++;
									} else {
										if ( d4.status_html ) {
											$tr4.find( '.column-status_code' ).html( d4.status_html );
										} else if ( undefined !== d4.status_code && undefined !== d4.css_class && undefined !== d4.label ) {
											$tr4.find( '.column-status_code' ).html( '<span class="tsoliin-status ' + d4.css_class + '">' + parseInt( d4.status_code, 10 ) + ' ' + self.escapeHtml( d4.label ) + '</span>' );
										}
										$tr4.removeClass( 'tsoliin-row--broken' );
										$tr4.find( '.tsoliin-not-broken' ).closest( 'span' ).remove();
										$tr4.find( '.tsoliin-suggest' ).closest( 'span' ).remove();
									}
								}
							} else {
								self._bulkStats.failed++;
							}
						} else if ( 'delete' === act && r.data.link_id ) {
							if ( r.data.deleted ) {
								self._bulkStats.deleted++;
								var $trDel = $( 'tr' ).filter( function () {
									return $( this ).find( 'input[value="' + r.data.link_id + '"]' ).length > 0;
								} );
								if ( $trDel.length ) {
									self.removeSuggestPanelsForRow( $trDel );
									$trDel.fadeOut( 200, function () { $( this ).remove(); } );
								}
							} else {
								self._bulkStats.failed++;
							}
						}
					self.bulkRecheckStep( linkIds, index + 1, act );
				},
				error: function () {
					self._bulkStats.failed++;
					alert( tsoliinData.i18n.error );
					self.bulkRecheckStep( linkIds, index + 1, act );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Live search (filter list as you type)
		// ---------------------------------------------------------------
		bindLiveSearch: function () {
			var self = this;

			self.lastSearchVal = $( '.tsoliin-search-input' ).first().val() || '';

			$( document ).on( 'input', '.tsoliin-search-input', function () {
				var term = $.trim( $( this ).val() );
				window.clearTimeout( self.searchTimer );
				self.searchTimer = window.setTimeout( function () {
					if ( term === self.lastSearchVal ) {
						return;
					}
					self.lastSearchVal = term;
					self.runLiveSearch( term );
				}, 400 );
			} );

			$( document ).on( 'click', '.tsoliin-search-submit', function ( e ) {
				e.preventDefault();
				var term = $.trim( $( this ).closest( '.tsoliin-search-form' ).find( '.tsoliin-search-input' ).val() );
				window.clearTimeout( self.searchTimer );
				self.lastSearchVal = term;
				self.runLiveSearch( term );
			} );
		},

		runLiveSearch: function ( searchTerm ) {
			var self    = this;
			var $input  = $( '.tsoliin-search-input' ).first();
			var $btn    = $( '.tsoliin-search-submit' ).first();
			var focusPos = $input.length ? $input[0].selectionStart : null;

			if ( ! $( '#tsoliin-scope-region, #tsoliin-list-table-region' ).length ) {
				if ( $( '#tsoliin-list-form' ).length ) {
					$( '#tsoliin-list-form' )[0].submit();
				}
				return;
			}

			if ( $btn.length ) {
				$btn.prop( 'disabled', true ).text( tsoliinData.i18n.searching );
			}

			self.fetchListRegion(
				{
					s              : searchTerm,
					filter         : tsoliinData.listFilter || 'all',
					quality_filter : tsoliinData.listQualityFilter || '',
					scope          : tsoliinData.listScope || 'all',
					post_id        : parseInt( tsoliinData.viewPostId, 10 ) || 0,
					paged          : 1
				},
				{
					trackAsSearch: true,
					onSuccess: function () {
						self.updateSearchUrl( searchTerm );
						self.lastSearchVal = searchTerm;
						var $newInput = $( '.tsoliin-search-input' ).first();
						if ( $newInput.length ) {
							$newInput.val( searchTerm ).trigger( 'focus' );
							if ( null !== focusPos && $newInput[0].setSelectionRange ) {
								var pos = Math.min( focusPos, String( searchTerm ).length );
								$newInput[0].setSelectionRange( pos, pos );
							}
						}
					},
					onComplete: function () {
						var $doneBtn = $( '.tsoliin-search-submit' ).first();
						if ( $doneBtn.length ) {
							$doneBtn.prop( 'disabled', false ).text( tsoliinData.i18n.searchBtn || 'Search' );
						}
					}
				}
			);
		},

		updateSearchUrl: function ( searchTerm ) {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}
			try {
				var url    = new URL( window.location.href );
				if ( searchTerm ) {
					url.searchParams.set( 's', searchTerm );
				} else {
					url.searchParams.delete( 's' );
				}
				url.searchParams.delete( 'paged' );
				window.history.replaceState( null, '', url.toString() );
			} catch ( e ) {
				// Ignore URL API errors on very old browsers.
			}
		},

		// ---------------------------------------------------------------
		// Smart URL Suggestion
		// ---------------------------------------------------------------
		smartSuggest: function ( linkId, $trigger ) {
			var self    = this;
			var $row    = $trigger.closest( 'tr' );
			var linkType = String( $trigger.data( 'link-type' ) || '' );
			self.cancelListReload();
			self.removeSuggestPanelsForRow( $row );

			// Non-custom menu items: URL comes from the linked object — explain where to edit.
			if ( 'menu' === linkType ) {
				var colsMenu = $row.find( 'td' ).length;
				var noteMenu = ( tsoliinData.i18n.menuSuggestNote )
					? tsoliinData.i18n.menuSuggestNote
					: tsoliinData.i18n.noSuggestions;
				var htmlMenu = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + colsMenu + '" class="tsoliin-suggest-panel">';
				htmlMenu += '<div class="notice notice-info inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( noteMenu ) + '</p></div>';
				htmlMenu += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
				htmlMenu += '</td></tr>';
				$row.after( htmlMenu );
				return;
			}

			// WooCommerce product fields: same — edit in the product screen only.
			if ( 'woocommerce' === linkType ) {
				var colsWoo = $row.find( 'td' ).length;
				var noteWoo = ( tsoliinData.i18n.wooSuggestNote )
					? tsoliinData.i18n.wooSuggestNote
					: tsoliinData.i18n.noSuggestions;
				var htmlWoo = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + colsWoo + '" class="tsoliin-suggest-panel">';
				htmlWoo += '<div class="notice notice-info inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( noteWoo ) + '</p></div>';
				htmlWoo += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
				htmlWoo += '</td></tr>';
				$row.after( htmlWoo );
				return;
			}

			if ( self.suggestXhr && self.suggestXhr.readyState !== 4 ) {
				self.suggestXhr.abort();
				if ( self.suggestTrigger && self.suggestTrigger.length && self.suggestTrigger[0] !== $trigger[0] ) {
					self.suggestTrigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );
				}
			}

			self.suggestTrigger   = $trigger;
			self.suggestRequestId = linkId;
			$trigger.text( tsoliinData.i18n.smartChecking );

			self.suggestXhr = $.ajax( {
				url    : tsoliinData.ajaxUrl,
				method : 'POST',
				timeout: 60000,
				data   : { action: 'tsoliin_smart_suggest', nonce: tsoliinData.nonce, link_id: linkId },
				success: function ( r ) {
					if ( self.suggestRequestId !== linkId ) {
						return;
					}
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );

					var cols = $row.find( 'td' ).length;
					var html = '<tr class="tsoliin-suggest-row" data-link-id="' + linkId + '"><td colspan="' + cols + '" class="tsoliin-suggest-panel">';

					if ( r.success && self.isStalePayload( r.data ) ) {
						self.removeStaleRow( $row, r.data );
						return;
					}

					if ( ! r.success ) {
						html += '<span style="color:#b32d2e;">' + self.escapeHtml( ( r.data && r.data.message ) ? r.data.message : tsoliinData.i18n.error ) + '</span>';
					} else if ( ! r.data.suggestions || ! r.data.suggestions.length ) {
						if ( r.data.note ) {
							var noteCls = ( r.data.menu_only || r.data.woo_only ) ? 'notice-info' : 'notice-warning';
							html += '<div class="notice ' + noteCls + ' inline" style="margin:0;"><p style="margin:0.5em 0;">' + self.escapeHtml( r.data.note ) + '</p></div>';
						} else {
							html += '<span style="color:#646970;font-style:italic;">💡 ' + tsoliinData.i18n.noSuggestions + '</span>';
						}
					} else {
						html += '<strong>💡 ' + tsoliinData.i18n.smartSuggest + ':</strong><ul class="tsoliin-suggest-list">';
						$.each( r.data.suggestions, function ( i, s ) {
							var actionable = ( false !== s.actionable );
							var unverified = !! s.unverified;
							var conf = 'high' === s.confidence ? '🟢' : '🟡';
							var isHttps = /^https:\/\//i.test( s.url || '' );
							var code = parseInt( s.status_code, 10 );
							var isBotBlock = ( 401 === code || 403 === code || 429 === code );
							var isUnverifiedRemote = unverified || isBotBlock || ( 0 === code || -3 === code || -4 === code || -5 === code || -7 === code );
							if ( ! actionable || isUnverifiedRemote ) {
								conf = '⚠️';
							} else if ( ! isHttps ) {
								conf = '⚠️';
							}
							var urlAttr  = String( s.url ).replace( /"/g, '&quot;' );
							var urlLabel = self.escapeHtml( s.url );
							var statusCls = 'tsoliin-status--broken';
							if ( actionable && ! isUnverifiedRemote ) {
								statusCls = isHttps ? 'tsoliin-status--ok' : 'tsoliin-status--warning';
							} else if ( isUnverifiedRemote ) {
								statusCls = 'tsoliin-status--warning';
							}
							html += '<li>';
							html += conf + ' <a href="' + urlAttr + '" target="_blank" rel="noopener">' + urlLabel + '</a>';
							html += ' <span class="tsoliin-status ' + statusCls + '" style="font-size:11px;">' + parseInt( s.status_code, 10 ) + ' ' + self.escapeHtml( s.label ) + '</span>';
							html += ' <em style="color:#646970;font-size:12px;">— ' + self.escapeHtml( s.reason ) + '</em>';
							if ( actionable && ! unverified ) {
								html += ' <button type="button" class="button button-small tsoliin-apply-suggest" style="margin-left:8px;"'
									+ ' data-id="' + linkId + '" data-url="' + urlAttr + '">'
									+ self.escapeHtml( tsoliinData.i18n.applyUrl ) + '</button>';
							} else if ( unverified ) {
								html += ' <button type="button" class="button button-small tsoliin-apply-suggest" style="margin-left:8px;"'
									+ ' data-id="' + linkId + '" data-url="' + urlAttr + '" data-apply-anyway="1">'
									+ self.escapeHtml( tsoliinData.i18n.applyAnyway || tsoliinData.i18n.applyUrl ) + '</button>';
								html += ' <button type="button" class="button button-small tsoliin-apply-suggest" style="margin-left:4px;"'
									+ ' data-id="' + linkId + '" data-url="' + urlAttr + '" data-apply-anyway="1" data-ignore-domain="1">'
									+ self.escapeHtml( tsoliinData.i18n.applyAnywayIgnore || tsoliinData.i18n.addIgnore ) + '</button>';
							}
							html += '</li>';
						} );
						html += '</ul>';
						if ( r.data.note ) {
							html += '<div class="notice notice-warning inline" style="margin:0.75em 0 0;"><p style="margin:0.5em 0;">' + self.escapeHtml( r.data.note ) + '</p></div>';
						}
					}

					html += '<button type="button" class="tsoliin-suggest-close button-link" aria-label="' + self.escapeHtml( tsoliinData.i18n.closePanel ) + '">✕</button>';
					html += '</td></tr>';
					if ( $row.closest( 'body' ).length ) {
						$row.after( html );
					}
				},
				error: function ( xhr, status ) {
					if ( 'abort' === status ) {
						return;
					}
					if ( self.suggestRequestId !== linkId ) {
						return;
					}
					$trigger.html( '💡 ' + tsoliinData.i18n.smartSuggest );
					alert( tsoliinData.i18n.error );
				},
				complete: function () {
					if ( self.suggestRequestId === linkId ) {
						self.suggestXhr = null;
					}
				}
			} );
		},

		applySmartUrl: function ( linkId, newUrl, $btn, $row, opts ) {
			var self = this;
			opts = opts || {};
			self.cancelListReload();
			$btn.prop( 'disabled', true ).text( tsoliinData.i18n.saving );

			self.trackedAjax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : $.extend( {
					action        : 'tsoliin_update_link',
					nonce         : tsoliinData.nonce,
					link_id       : linkId,
					new_url       : newUrl,
					change_type   : 'suggest',
					apply_anyway  : opts.applyAnyway ? 1 : 0,
					ignore_domain : opts.ignoreDomain ? 1 : 0
				}, self.listFilterParam() ),
				success: function ( r ) {
					if ( r.success ) {
						var d = r.data || {};
						if ( self.isStalePayload( d ) ) {
							self.removeStaleRow( $row, d );
							return;
						}
						if ( self.removeRowIfFilterMismatch( $row, d ) ) {
							$btn.closest( '.tsoliin-suggest-row' ).fadeOut( 300, function () { $( this ).remove(); } );
							self.showNotice( d.filter_promotion_message || ( '✅ ' + tsoliinData.i18n.urlUpdated + ' ' + d.new_url ), 'success' );
							self.refreshStats();
							return;
						}
						self.applyLinkEditToRow( $row, d );
						$btn.closest( '.tsoliin-suggest-row' ).fadeOut( 300, function () { $( this ).remove(); } );
						self.showNotice( '✅ ' + tsoliinData.i18n.urlUpdated + ' ' + d.new_url, 'success' );
						self.refreshStats();
					} else {
						var err = r.data || {};
						$btn.prop( 'disabled', false ).text( opts.applyAnyway ? ( tsoliinData.i18n.applyAnyway || tsoliinData.i18n.applyUrl ) : tsoliinData.i18n.applyUrl );
						if ( err.unverified && ! opts.applyAnyway && window.confirm( tsoliinData.i18n.confirmApplyAnyway ) ) {
							self.applySmartUrl( linkId, newUrl, $btn, $row, { applyAnyway: true, ignoreDomain: !! opts.ignoreDomain } );
							return;
						}
						window.setTimeout( function () {
							alert( err.message || tsoliinData.i18n.error );
						}, 0 );
					}
				},
				error: function () {
					$btn.prop( 'disabled', false ).text( opts.applyAnyway ? ( tsoliinData.i18n.applyAnyway || tsoliinData.i18n.applyUrl ) : tsoliinData.i18n.applyUrl );
					alert( tsoliinData.i18n.error );
				}
			} );
		},

		// ---------------------------------------------------------------
		// Diagnose
		// ---------------------------------------------------------------
		runDiagnose: function () {
			var self   = this;
			var $panel = $( '#tsoliin-diagnose-panel' );
			var $btn   = $( '#tsoliin-diagnose' );
			var btnHtml = '<span class="dashicons dashicons-info"></span> ' + tsoliinData.i18n.diagnosi;

			if ( $panel.is( ':visible' ) ) {
				if ( self._diagXhr ) {
					self._diagXhr.abort();
					self._diagXhr = null;
				}
				$panel.hide().empty();
				$btn.prop( 'disabled', false ).attr( 'aria-expanded', 'false' ).html( btnHtml );
				return;
			}

			$btn.prop( 'disabled', true ).attr( 'aria-expanded', 'true' ).text( tsoliinData.i18n.diagChecking );
			$panel.html( '<p><em>' + tsoliinData.i18n.diagChecking + '</em></p>' ).show();

			self._diagXhr = $.ajax( {
				url   : tsoliinData.ajaxUrl,
				method: 'POST',
				data  : { action: 'tsoliin_diagnose', nonce: tsoliinData.nonce },
				success: function ( r ) {
					$btn.prop( 'disabled', false ).html( btnHtml );
					if ( ! $panel.is( ':visible' ) ) {
						return;
					}
					if ( r.success ) {
						$panel.html( '<strong>' + self.escapeHtml( tsoliinData.i18n.diagResult ) + '</strong><br><code style="display:block;white-space:pre-wrap;margin-top:8px;">' + self.escapeHtml( r.data.lines.join( '\n' ) ) + '</code>' );
					} else {
						$panel.html( '<p style="color:red;">' + ( r.data ? r.data.message : 'Error' ) + '</p>' );
					}
				},
				error: function ( _jqXHR, textStatus ) {
					$btn.prop( 'disabled', false ).html( btnHtml );
					if ( 'abort' === textStatus || ! $panel.is( ':visible' ) ) {
						return;
					}
					$panel.html( '<p style="color:red;">' + tsoliinData.i18n.error + '</p>' );
				},
				complete: function () {
					self._diagXhr = null;
				}
			} );
		},

		// ---------------------------------------------------------------
		// Theme switcher (auto by sunrise/sunset / day / night)
		// ---------------------------------------------------------------
		_themeAutoTimer: null,
		_themeDayMs: 86400000,
		_themeJ1970: 2440588,
		_themeJ2000: 2451545,
		_themeRad: Math.PI / 180,
		_themeE: ( Math.PI / 180 ) * 23.4397,

		_themeTzCoords: {
			'Europe/Madrid': [ 40.42, -3.70 ],
			'Europe/Andorra': [ 42.51, 1.52 ],
			'Atlantic/Canary': [ 28.29, -16.63 ],
			'Europe/London': [ 51.51, -0.13 ],
			'Europe/Paris': [ 48.86, 2.35 ],
			'Europe/Berlin': [ 52.52, 13.41 ],
			'Europe/Rome': [ 41.90, 12.50 ],
			'Europe/Lisbon': [ 38.72, -9.14 ],
			'Europe/Brussels': [ 50.85, 4.35 ],
			'Europe/Amsterdam': [ 52.37, 4.90 ],
			'America/Mexico_City': [ 19.43, -99.13 ],
			'America/New_York': [ 40.71, -74.01 ],
			'America/Chicago': [ 41.88, -87.63 ],
			'America/Denver': [ 39.74, -104.99 ],
			'America/Los_Angeles': [ 34.05, -118.24 ],
			'America/Argentina/Buenos_Aires': [ -34.60, -58.38 ],
			'America/Sao_Paulo': [ -23.55, -46.63 ],
			'America/Bogota': [ 4.71, -74.07 ],
			'America/Lima': [ -12.05, -77.04 ],
			'America/Santiago': [ -33.45, -70.67 ],
			'America/Caracas': [ 10.48, -66.90 ],
			'Asia/Tokyo': [ 35.68, 139.69 ],
			'Australia/Sydney': [ -33.87, 151.21 ],
			'UTC': [ 0, 0 ]
		},

		readThemePreference: function () {
			var theme = localStorage.getItem( 'tsoliin_ui_theme' );
			if ( theme === 'day' || theme === 'night' || theme === 'auto' ) {
				return theme;
			}
			return 'auto';
		},

		getThemeCoords: function () {
			if ( typeof tsoliinData !== 'undefined' ) {
				var wpLat = parseFloat( tsoliinData.lat );
				var wpLng = parseFloat( tsoliinData.lng );
				if ( ! isNaN( wpLat ) && ! isNaN( wpLng ) ) {
					return { lat: wpLat, lng: wpLng, source: 'wordpress' };
				}
			}

			try {
				var raw = localStorage.getItem( 'tsoliin_theme_coords' );
				if ( raw ) {
					var parsed = JSON.parse( raw );
					if ( parsed && typeof parsed.lat === 'number' && typeof parsed.lng === 'number' ) {
						return parsed;
					}
				}
			} catch ( e ) { /* ignore */ }

			var tz = '';
			if ( typeof tsoliinData !== 'undefined' && tsoliinData.timezone ) {
				tz = String( tsoliinData.timezone );
			}
			if ( ! tz && typeof Intl !== 'undefined' && Intl.DateTimeFormat ) {
				try {
					tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
				} catch ( e2 ) { /* ignore */ }
			}

			if ( tz && this._themeTzCoords[ tz ] ) {
				return { lat: this._themeTzCoords[ tz ][0], lng: this._themeTzCoords[ tz ][1], source: 'timezone' };
			}

			return { lat: 41.39, lng: 2.17, source: 'default' };
		},

		themeToJulian: function ( date ) {
			return date.valueOf() / this._themeDayMs - 0.5 + this._themeJ1970;
		},

		themeFromJulian: function ( j ) {
			return new Date( ( j + 0.5 - this._themeJ1970 ) * this._themeDayMs );
		},

		themeToDays: function ( date ) {
			return this.themeToJulian( date ) - this._themeJ2000;
		},

		themeRightAscension: function ( l, b ) {
			return Math.atan2( Math.sin( l ) * Math.cos( this._themeE ) - Math.tan( b ) * Math.sin( this._themeE ), Math.cos( l ) );
		},

		themeDeclination: function ( l, b ) {
			return Math.asin( Math.sin( b ) * Math.cos( this._themeE ) + Math.cos( b ) * Math.sin( this._themeE ) * Math.sin( l ) );
		},

		themeSolarMeanAnomaly: function ( d ) {
			return this._themeRad * ( 357.5291 + 0.98560028 * d );
		},

		themeEclipticLongitude: function ( M ) {
			var C = this._themeRad * ( 1.9148 * Math.sin( M ) + 0.02 * Math.sin( 2 * M ) + 0.0003 * Math.sin( 3 * M ) );
			var P = this._themeRad * 102.9372;
			return M + C + P + Math.PI;
		},

		themeSunCoords: function ( d ) {
			var M = this.themeSolarMeanAnomaly( d );
			var L = this.themeEclipticLongitude( M );
			return {
				dec: this.themeDeclination( L, 0 ),
				ra: this.themeRightAscension( L, 0 )
			};
		},

		themeJulianCycle: function ( d, lw ) {
			return Math.round( d - 0.0009 - lw / ( 2 * Math.PI ) );
		},

		themeApproxTransit: function ( Ht, lw, n ) {
			return 0.0009 + ( Ht + lw ) / ( 2 * Math.PI ) + n;
		},

		themeSolarTransitJ: function ( ds, M, L ) {
			return this._themeJ2000 + ds + 0.0053 * Math.sin( M ) - 0.0069 * Math.sin( 2 * L );
		},

		themeHourAngle: function ( h, phi, d ) {
			return Math.acos( ( Math.sin( h ) - Math.sin( phi ) * Math.sin( d ) ) / ( Math.cos( phi ) * Math.cos( d ) ) );
		},

		themeGetSetJ: function ( h, lw, phi, dec, n, M, L ) {
			var w = this.themeHourAngle( h, phi, dec );
			var a = this.themeApproxTransit( w, lw, n );
			return this.themeSolarTransitJ( a, M, L );
		},

		getSunTimes: function ( date, lat, lng ) {
			try {
				var lw = this._themeRad * -lng;
				var phi = this._themeRad * lat;
				var d = this.themeToDays( date );
				var n = this.themeJulianCycle( d, lw );
				var ds = this.themeApproxTransit( 0, lw, n );
				var M = this.themeSolarMeanAnomaly( ds );
				var L = this.themeEclipticLongitude( M );
				var dec = this.themeSunCoords( ds ).dec;
				var Jnoon = this.themeSolarTransitJ( ds, M, L );
				var Jset = this.themeGetSetJ( -0.833 * this._themeRad, lw, phi, dec, n, M, L );
				var Jrise = Jnoon - ( Jset - Jnoon );
				var sunrise = this.themeFromJulian( Jrise );
				var sunset = this.themeFromJulian( Jset );
				if ( isNaN( sunrise.getTime() ) || isNaN( sunset.getTime() ) ) {
					return null;
				}
				return { sunrise: sunrise, sunset: sunset };
			} catch ( e ) {
				return null;
			}
		},

		formatThemeClock: function ( date ) {
			try {
				return date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
			} catch ( e ) {
				var h = date.getHours();
				var m = date.getMinutes();
				return ( h < 10 ? '0' : '' ) + h + ':' + ( m < 10 ? '0' : '' ) + m;
			}
		},

		themeFromSolar: function () {
			var coords = this.getThemeCoords();
			var now = new Date();
			var times = this.getSunTimes( now, coords.lat, coords.lng );
			if ( ! times ) {
				var hour = now.getHours();
				return ( hour >= 7 && hour < 20 ) ? 'day' : 'night';
			}
			return ( now >= times.sunrise && now < times.sunset ) ? 'day' : 'night';
		},

		resolveTheme: function ( preference ) {
			if ( preference === 'day' || preference === 'night' ) {
				return preference;
			}
			return this.themeFromSolar();
		},

		themeUiText: function ( key, fallback ) {
			if ( typeof tsoliinData !== 'undefined' && tsoliinData.i18n && tsoliinData.i18n[ key ] ) {
				return tsoliinData.i18n[ key ];
			}
			return fallback;
		},

		applyTheme: function ( preference ) {
			var self = this;
			if ( preference !== 'day' && preference !== 'night' && preference !== 'auto' ) {
				preference = 'auto';
			}
			localStorage.setItem( 'tsoliin_ui_theme', preference );

			var resolved = this.resolveTheme( preference );
			var $wrap = $( '.tsoliin-wrap' );
			$wrap.attr( 'data-theme', resolved );
			$wrap.attr( 'data-theme-pref', preference );
			try {
				document.documentElement.setAttribute( 'data-tsoliin-theme', resolved );
				document.documentElement.setAttribute( 'data-tsoliin-theme-pref', preference );
				if ( document.body ) {
					document.body.setAttribute( 'data-tsoliin-theme', resolved );
				}
			} catch ( e ) { /* ignore */ }

			var $btn = $( '#tsoliin-theme-toggle' );
			var labelKey = preference === 'auto' ? 'themeAuto' : ( preference === 'day' ? 'themeDay' : 'themeNight' );
			var labelFallback = preference === 'auto' ? 'Auto mode' : ( preference === 'day' ? 'Day mode' : 'Night mode' );
			var icon = preference === 'auto' ? '🌓' : ( resolved === 'night' ? '🌙' : '☀️' );
			var label = this.themeUiText( labelKey, labelFallback );
			var title = label;
			if ( preference === 'auto' ) {
				title = label + ' — ' + this.themeUiText( 'themeAutoHint', 'Follows sunrise and sunset (changes with the seasons)' );
				var coords = this.getThemeCoords();
				var times = this.getSunTimes( new Date(), coords.lat, coords.lng );
				if ( times ) {
					title += ' · ' + this.formatThemeClock( times.sunrise ) + '–' + this.formatThemeClock( times.sunset );
				}
			}

			$btn.attr( 'aria-pressed', preference === 'auto' ? 'mixed' : ( resolved === 'night' ? 'true' : 'false' ) );
			$btn.attr( 'title', title );
			$btn.find( '.tsoliin-theme-icon' ).text( icon );
			$btn.find( '.tsoliin-theme-label' ).text( label );

			if ( this._themeAutoTimer ) {
				clearInterval( this._themeAutoTimer );
				this._themeAutoTimer = null;
			}
			if ( preference === 'auto' ) {
				this._themeAutoTimer = setInterval( function () {
					if ( self.readThemePreference() !== 'auto' ) {
						return;
					}
					var nextResolved = self.themeFromSolar();
					if ( $( '.tsoliin-wrap' ).attr( 'data-theme' ) !== nextResolved ) {
						self.applyTheme( 'auto' );
					}
				}, 60000 );
			}
		},

		nextThemePreference: function ( current ) {
			if ( current === 'auto' ) {
				return 'day';
			}
			if ( current === 'day' ) {
				return 'night';
			}
			return 'auto';
		},

		initThemeSwitcher: function () {
			var self = this;
			this.applyTheme( this.readThemePreference() );
			$( document ).on( 'click', '#tsoliin-theme-toggle', function () {
				self.applyTheme( self.nextThemePreference( self.readThemePreference() ) );
			} );
		}
	};

	// Apply stored/auto theme ASAP when the wrap is already in the DOM.
	( function () {
		var wrap = document.querySelector( '.tsoliin-wrap' );
		if ( ! wrap || typeof LC === 'undefined' || ! LC.resolveTheme || ! LC.readThemePreference ) {
			return;
		}
		var pref = LC.readThemePreference();
		var resolved = LC.resolveTheme( pref );
		wrap.setAttribute( 'data-theme', resolved );
		wrap.setAttribute( 'data-theme-pref', pref );
		try {
			document.documentElement.setAttribute( 'data-tsoliin-theme', resolved );
			document.documentElement.setAttribute( 'data-tsoliin-theme-pref', pref );
			if ( document.body ) {
				document.body.setAttribute( 'data-tsoliin-theme', resolved );
			}
		} catch ( e ) { /* ignore */ }
	} )();

	$( document ).ready( function () { LC.init(); } );

} )( jQuery );
