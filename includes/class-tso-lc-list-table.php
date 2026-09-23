<?php
/**
 * WP_List_Table subclass for link results.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class TSOLIIN_List_Table
 */
class TSOLIIN_List_Table extends WP_List_Table {

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_HTTP|null */
	private $http;

	/** @var array<string, mixed>|null Cached tab nav context for one render pass. */
	private $list_tabnav_context = null;

	public function __construct( TSOLIIN_DB $db, ?TSOLIIN_HTTP $http = null ) {
		$this->db   = $db;
		$this->http = $http;
		parent::__construct( array(
			'singular' => __( 'Link', 'tso-link-inspector' ),
			'plural'   => __( 'Links', 'tso-link-inspector' ),
			'ajax'     => false,
		) );
	}

	/**
	 * Internal/external scope from list-table GET filters.
	 *
	 * @return string
	 */
	private function read_request_scope() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list tab filter.
		$scope = isset( $_REQUEST['scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $scope ) {
			return 'all';
		}
		return $this->db->sanitize_scope_input( $scope );
	}

	/**
	 * Quality filter keys (optional second dimension).
	 *
	 * @return string[]
	 */
	private function get_quality_filter_keys() {
		return array( 'empty_anchor', 'generic_anchor', 'unpublished_target' );
	}

	/**
	 * Active status filter (all, broken, ok, …) — not quality.
	 *
	 * @return string
	 */
	private function read_request_status_filter() {
		$status_allowed = array( 'all', 'broken', 'redirect', 'ok', 'unchecked', 'http_insecure', 'manual_locked' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		if ( in_array( $filter, $status_allowed, true ) ) {
			return $filter;
		}
		if ( in_array( $filter, $this->get_quality_filter_keys(), true ) ) {
			return 'all';
		}
		return 'all';
	}

	/**
	 * Optional quality filter; empty string when none selected.
	 *
	 * @return string
	 */
	private function read_request_quality_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list GET filter; read-only display.
		$quality_raw = isset( $_REQUEST['quality_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['quality_filter'] ) ) : '';
		if ( '' !== $quality_raw && in_array( $quality_raw, $this->get_quality_filter_keys(), true ) ) {
			return $quality_raw;
		}

		// Legacy: quality value stored in filter= (older URLs).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list GET filter; read-only display.
		$filter_raw = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		if ( in_array( $filter_raw, $this->get_quality_filter_keys(), true ) ) {
			return $filter_raw;
		}
		return '';
	}

	/**
	 * Active link-type filter (Type dropdown); empty string when none selected.
	 * Purely orthogonal to status/quality/scope — it only narrows the SQL WHERE
	 * with an extra "AND l.link_type = %s", same pattern as quality_filter.
	 *
	 * @return string
	 */
	private function read_request_type_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list GET filter; read-only display.
		$type_raw = isset( $_REQUEST['link_type_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['link_type_filter'] ) ) : '';
		if ( '' !== $type_raw && in_array( $type_raw, TSOLIIN_DB::allowed_link_types(), true ) ) {
			return $type_raw;
		}
		return '';
	}

	/**
	 * Append current quality_filter to tab URL args when active.
	 *
	 * @param array $args Query args (by ref).
	 * @return void
	 */
	private function merge_active_quality_into_args( array &$args ) {
		$quality = $this->read_request_quality_filter();
		if ( '' !== $quality ) {
			$args['quality_filter'] = $quality;
		}
	}

	/**
	 * Append current link_type_filter to tab URL args when active.
	 *
	 * @param array $args Query args (by ref).
	 * @return void
	 */
	private function merge_active_type_into_args( array &$args ) {
		$type = $this->read_request_type_filter();
		if ( '' !== $type ) {
			$args['link_type_filter'] = $type;
		}
	}

	/**
	 * Build an admin list-screen URL (always tools.php?page=tso-link-inspector).
	 *
	 * Avoids add_query_arg() on the current request URI, which breaks after live-search AJAX
	 * (filter tabs would point at admin-ajax.php).
	 *
	 * @param array $args      Query args to set/override.
	 * @param array $omit_keys Query args to remove (e.g. paged, s when switching tabs).
	 * @return string
	 */
	private function build_admin_list_url( array $args = array(), array $omit_keys = array() ) {
		$query = array( 'page' => 'tso-link-inspector' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		if ( $post_id > 0 && ! in_array( 'post_id', $omit_keys, true ) && ! isset( $args['post_id'] ) ) {
			$query['post_id'] = $post_id;
		}

		if ( ! isset( $args['filter'] ) && ! in_array( 'filter', $omit_keys, true ) ) {
			$filter = $this->read_request_status_filter();
			if ( 'all' !== $filter ) {
				$query['filter'] = $filter;
			}
		}

		if ( ! isset( $args['quality_filter'] ) && ! in_array( 'quality_filter', $omit_keys, true ) ) {
			$quality = $this->read_request_quality_filter();
			if ( '' !== $quality ) {
				$query['quality_filter'] = $quality;
			}
		}

		if ( ! isset( $args['link_type_filter'] ) && ! in_array( 'link_type_filter', $omit_keys, true ) ) {
			$type = $this->read_request_type_filter();
			if ( '' !== $type ) {
				$query['link_type_filter'] = $type;
			}
		}

		if ( ! isset( $args['scope'] ) && ! in_array( 'scope', $omit_keys, true ) ) {
			$scope = $this->read_request_scope();
			if ( 'all' !== $scope ) {
				$query['scope'] = $scope;
			}
		}

		if ( ! isset( $args['s'] ) && ! in_array( 's', $omit_keys, true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
			if ( '' !== $search ) {
				$query['s'] = $search;
			}
		}

		if ( ! isset( $args['paged'] ) && ! in_array( 'paged', $omit_keys, true ) ) {
			$paged = $this->get_pagenum();
			if ( $paged > 1 ) {
				$query['paged'] = $paged;
			}
		}

		if ( ! isset( $args['orderby'] ) && ! in_array( 'orderby', $omit_keys, true ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list URL args.
			if ( isset( $_REQUEST['orderby'] ) ) {
				$query['orderby'] = sanitize_key( wp_unslash( $_REQUEST['orderby'] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( ! isset( $args['order'] ) && ! in_array( 'order', $omit_keys, true ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list URL args.
			if ( isset( $_REQUEST['order'] ) ) {
				$request_order = strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) );
				if ( in_array( $request_order, array( 'ASC', 'DESC' ), true ) ) {
					$query['order'] = $request_order;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		$query = array_merge( $query, $args );
		foreach ( $omit_keys as $omit ) {
			unset( $query[ $omit ] );
		}

		return add_query_arg( $query, admin_url( 'tools.php' ) );
	}

	/**
	 * List navigation URL that restores scroll to the link table after full page load.
	 *
	 * @param array $args      Query args to set/override.
	 * @param array $omit_keys Query args to remove.
	 * @return string
	 */
	private function list_nav_url( array $args = array(), array $omit_keys = array() ) {
		return $this->build_admin_list_url( $args, $omit_keys );
	}

	/**
	 * Base URL for paginate_links() placeholders (do not pass %#% through add_query_arg).
	 *
	 * @return string
	 */
	private function build_pagination_base_url() {
		$base = $this->build_admin_list_url( array(), array( 'paged' ) );

		return $base . ( false !== strpos( $base, '?' ) ? '&' : '?' ) . 'paged=%#%';
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'link_type'    => __( 'Type', 'tso-link-inspector' ),
			'link_url'     => __( 'URL', 'tso-link-inspector' ),
			'anchor_text'  => __( 'Text', 'tso-link-inspector' ),
			'post_title'   => __( 'Article', 'tso-link-inspector' ),
			'status_code'  => __( 'Status', 'tso-link-inspector' ),
			'last_checked' => __( 'Last checked', 'tso-link-inspector' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'link_type'    => array( 'link_type', false ),
			'link_url'     => array( 'link_url', false ),
			'post_title'   => array( 'post_id', false ),
			'status_code'  => array( 'status_code', false ),
			'last_checked' => array( 'last_checked', true ),
		);
	}

	protected function get_bulk_actions() {
		$actions = array(
			'recheck'       => __( 'Recheck selected', 'tso-link-inspector' ),
			'upgrade_https' => __( 'Upgrade selected to HTTPS', 'tso-link-inspector' ),
			'unlink'        => __( 'Unlink selected', 'tso-link-inspector' ),
			'not_broken'    => __( 'Mark as OK', 'tso-link-inspector' ),
			'delete'        => __( 'Delete from list', 'tso-link-inspector' ),
		);
		if ( TSOLIIN_Support::is_relative_url_tool_enabled() ) {
			$ordered = array(
				'recheck'       => $actions['recheck'],
				'upgrade_https' => $actions['upgrade_https'],
				'unlink'        => $actions['unlink'],
				'not_broken'    => $actions['not_broken'],
				'make_relative' => __( 'Convert selected to /path', 'tso-link-inspector' ),
				'delete'        => $actions['delete'],
			);
			return $ordered;
		}
		return $actions;
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter    = $this->read_request_status_filter();
		$quality   = $this->read_request_quality_filter();
		$link_type = $this->read_request_type_filter();
		$search  = isset( $_REQUEST['s'] )        ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) )         : '';
		$orderby = isset( $_REQUEST['orderby'] )  ? sanitize_key( $_REQUEST['orderby'] )                        : 'date_found';
		$order   = isset( $_REQUEST['order'] )    ? sanitize_key( $_REQUEST['order'] )                          : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed_filters = array(
			'all',
			'broken',
			'redirect',
			'ok',
			'unchecked',
			'http_insecure',
			'manual_locked',
		);
		if ( ! in_array( $filter, $allowed_filters, true ) ) {
			$filter = 'all';
		}

		$per_page = TSOLIIN_Support::get_user_list_per_page();
		$paged    = $this->get_pagenum();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$scope   = $this->read_request_scope();
		$result = $this->db->get_links( array(
			'filter'           => $filter,
			'quality_filter'   => $quality,
			'link_type_filter' => $link_type,
			'scope'            => $scope,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'paged'    => $paged,
			'post_id'  => $post_id,
		) );

		$this->items = $result['items'];

		// Batch-prime the post cache so per-row rendering (edit links, permalinks,
		// attachment lookups, etc.) hits the request cache instead of issuing one
		// WP_Post::get_instance() query per row.
		if ( ! empty( $this->items ) && function_exists( '_prime_post_caches' ) ) {
			$post_ids = array();
			foreach ( $this->items as $row_item ) {
				if ( ! empty( $row_item->post_id ) ) {
					$post_ids[] = (int) $row_item->post_id;
				}
			}
			if ( $post_ids ) {
				$unique_post_ids = array_unique( $post_ids );

				// _prime_post_caches() only warms meta for posts it fetches fresh in
				// this same call — if the post object is already cached (e.g. the
				// per-article view already loaded it for the page heading), it skips
				// meta priming entirely even though the meta cache itself is empty.
				// update_meta_cache() checks the meta cache group directly, so call
				// it explicitly instead of relying on _prime_post_caches()'s implicit
				// (and here, incorrect) skip.
				_prime_post_caches( $unique_post_ids, false, false );
				if ( function_exists( 'update_meta_cache' ) ) {
					update_meta_cache( 'post', $unique_post_ids );
				}
			}
		}

		// Batch-prime the comment cache for 'comment' type rows so per-row
		// rendering (can_inline_edit_link()/is_url_editable_in_source() and
		// get_post_frontend_view_url_for_link(), which each call get_comment()
		// independently) hits the request cache instead of issuing two
		// WP_Comment_Query lookups per comment row.
		if ( ! empty( $this->items ) && function_exists( '_prime_comment_caches' ) ) {
			$comment_ids = array();
			foreach ( $this->items as $row_item ) {
				if ( isset( $row_item->link_type ) && 'comment' === (string) $row_item->link_type ) {
					$cid = TSOLIIN_Support::get_comment_id_from_link_row( $row_item );
					if ( $cid > 0 ) {
						$comment_ids[] = $cid;
					}
				}
			}
			if ( $comment_ids ) {
				_prime_comment_caches( array_unique( $comment_ids ), false );
			}
		}
		$this->set_pagination_args( array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $result['total'] / $per_page ) ),
		) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'link_url' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="link_ids[]" value="%d" />', absint( $item->id ) );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'link_type':
				$type   = isset( $item->link_type ) ? (string) $item->link_type : 'link';
				$icons  = array(
					'link'     => array( 'dashicons-admin-links',    __( 'Link', 'tso-link-inspector' ) ),
					'plain'    => array( 'dashicons-text',           __( 'Plain text URL', 'tso-link-inspector' ) ),
					'image'    => array( 'dashicons-format-image',   __( 'Image', 'tso-link-inspector' ) ),
					'iframe'   => array( 'dashicons-video-alt3',     __( 'Iframe', 'tso-link-inspector' ) ),
					'comment'  => array( 'dashicons-admin-comments', __( 'Comment', 'tso-link-inspector' ) ),
					'menu'     => array( 'dashicons-menu',           __( 'Menu', 'tso-link-inspector' ) ),
					'widget'   => array( 'dashicons-welcome-widgets-menus', __( 'Widget', 'tso-link-inspector' ) ),
					'term'     => array( 'dashicons-tag',            __( 'Term', 'tso-link-inspector' ) ),
					'template' => array( 'dashicons-layout',         __( 'Template / Navigation', 'tso-link-inspector' ) ),
					'wp_block' => array( 'dashicons-block-default',  __( 'Reusable block', 'tso-link-inspector' ) ),
					'acf'      => array( 'dashicons-index-card',     __( 'ACF Options', 'tso-link-inspector' ) ),
				);
				$icon  = isset( $icons[ $type ] ) ? $icons[ $type ][0] : 'dashicons-admin-links';
				$label = isset( $icons[ $type ] ) ? $icons[ $type ][1] : esc_html( $type );
				return '<span class="dashicons ' . esc_attr( $icon ) . ' tsoliin-type-icon tsoliin-type-icon--' . esc_attr( sanitize_key( $type ) ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"></span>';

			case 'anchor_text':
				return esc_html( (string) $item->anchor_text );

			case 'last_checked':
				if ( empty( $item->last_checked ) ) {
					// Legacy rows may have status_code but no last_checked; show date_found as fallback.
					if ( ! empty( $item->status_code ) && 0 !== (int) $item->status_code && ! empty( $item->date_found ) ) {
						return esc_html( wp_date( 'd/m/Y H:i', strtotime( (string) $item->date_found ) ) );
					}
					// Has status but no fallback date available.
					if ( ! empty( $item->status_code ) && 0 !== (int) $item->status_code ) {
						return '<em style="color:#646970;">' . esc_html__( 'Unknown date', 'tso-link-inspector' ) . '</em>';
					}
					return '<em>' . esc_html__( 'Never', 'tso-link-inspector' ) . '</em>';
				}
				return esc_html( wp_date( 'd/m/Y H:i', strtotime( $item->last_checked ) ) );

			default:
				return '';
		}
	}

	protected function column_link_url( $item ) {
		$nonce    = wp_create_nonce( 'tsoliin_action' );
		$url      = (string) $item->link_url;
		// Show up to 80 chars in the cell; full URL always visible on hover via title attribute.
		$display  = strlen( $url ) > 110 ? substr( $url, 0, 107 ) . '...' : $url;
		$is_broken = (int) $item->is_broken;
		$code      = (int) $item->status_code;

		$is_action = TSOLIIN_HTTP::is_action_url( $url ) || -6 === $code;
		$link_title = $is_action
			? __( 'Warning: this link logs you out. Open only if you intend to end your session.', 'tso-link-inspector' )
			: $url;

		$out  = '<span class="tsoliin-url">';
		$out .= '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $link_title ) . '" target="_blank" rel="noopener noreferrer"';
		if ( $is_action ) {
			$out .= ' data-tsoliin-action-url="1"';
		}
		$out .= '>';
		$out .= esc_html( $display );
		$out .= ' <span class="dashicons dashicons-external" style="font-size:12px;vertical-align:middle;"></span>';
		$out .= '</a></span>';

		// Show "Suggestion" for: broken links, connection errors, http:// (upgrade to https),
		// and redirects (user may want to update the link to point directly to the final URL).
		// Manually verified rows stay quiet until the URL changes or the link actually fails.
		$redirect_codes = array( 301, 302, 303, 307, 308 );
		$verified       = ! empty( $item->user_verified );
		$transparent_rd = $this->http && ! empty( $item->redirect_url )
			&& $this->http->is_transparent_redirect( $url, (string) $item->redirect_url );
		$show_suggest   = ! $verified
			&& ! $transparent_rd
			&& ! $is_action
			&& (
				$is_broken
				|| ( $code < 0 && ! in_array( $code, array( -1, -6, -7, -8, -9 ), true ) )
				|| preg_match( '#^http://#i', $url )
				|| in_array( $code, $redirect_codes, true )
				|| ! empty( $item->redirect_url )
			);

		$type    = isset( $item->link_type ) ? (string) $item->link_type : 'link';
		$sk_item = isset( $item->source_key ) ? (string) $item->source_key : '';
		$is_woo_source = class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk_item );
		$not_broken_title = __( 'Mark as OK: moves this link to Manual locks. Background checks still run; it returns to Broken/Redirect only if the URL or redirect changes, or a check finds it broken.', 'tso-link-inspector' );

		$can_inline = ( 'comment' === $type ) ? true : TSOLIIN_Support::can_inline_edit_link( $item );
		// Custom menu URLs (_menu_item_url) can be edited/suggested here; post_type menu items cannot.
		$can_edit   = $can_inline && (
			! in_array( $type, array( 'comment', 'widget', 'menu', 'term', 'acf' ), true )
			|| ( 'menu' === $type && TSOLIIN_Support::is_custom_menu_url_row( $item ) )
		);
		$can_unlink = TSOLIIN_Support::can_unlink_link( $item ) && (
			'menu' !== $type || TSOLIIN_Support::is_custom_menu_url_row( $item )
		);

		/*
		 * Unified action order (always when present):
		 * 1 Go to edit → 2 Edit link → 3 Recheck → 4 Not broken → 5 Unlink → 6 Delete → 7 Ignore domain
		 * Particularities (Convert to /path and Upgrade to HTTPS after Edit; Suggestion at end) only when applicable.
		 */
		$actions = array();

		$go_edit = TSOLIIN_Support::get_go_to_edit_row_action( $item );
		if ( is_array( $go_edit ) && ! empty( $go_edit['url'] ) ) {
			$actions['source_edit'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
				esc_url( (string) $go_edit['url'] ),
				esc_attr( isset( $go_edit['title'] ) ? (string) $go_edit['title'] : '' ),
				esc_html( isset( $go_edit['label'] ) ? (string) $go_edit['label'] : __( 'Go to edit', 'tso-link-inspector' ) )
			);
		}

		if ( $can_edit ) {
			$anchor_editable = TSOLIIN_Support::can_edit_link_anchor_in_modal( $item ) ? '1' : '0';
			$actions['edit'] = sprintf(
				'<a href="#" class="tsoliin-edit-link" data-id="%d" data-url="%s" data-post="%d" data-anchor="%s" data-type="%s" data-anchor-editable="%s" title="%s">%s</a>',
				absint( $item->id ),
				esc_attr( $url ),
				absint( $item->post_id ),
				esc_attr( (string) $item->anchor_text ),
				esc_attr( $type ),
				esc_attr( $anchor_editable ),
				esc_attr__( 'Change the URL in the stored source without leaving this screen.', 'tso-link-inspector' ),
				esc_html__( 'Edit link', 'tso-link-inspector' )
			);
			if ( TSOLIIN_Support::can_offer_convert_to_relative( $item ) ) {
				$actions['make_relative'] = sprintf(
					'<a href="#" class="tsoliin-make-relative" data-id="%d" data-nonce="%s" title="%s">%s</a>',
					absint( $item->id ),
					esc_attr( $nonce ),
					esc_attr__( 'Replace https://yoursite.com/page/ with /page/ in the post (same site only)', 'tso-link-inspector' ),
					esc_html__( 'Convert to /path', 'tso-link-inspector' )
				);
			}
			if ( TSOLIIN_HTTP::is_plain_http_url( $url ) ) {
				$actions['upgrade_https'] = sprintf(
					'<a href="#" class="tsoliin-upgrade-https" data-id="%d" data-nonce="%s" title="%s">%s</a>',
					absint( $item->id ),
					esc_attr( $nonce ),
					esc_attr__( 'Replace http:// with https:// after the plugin confirms HTTPS works', 'tso-link-inspector' ),
					esc_html__( 'Upgrade to HTTPS', 'tso-link-inspector' )
				);
			}
		}

		$actions['recheck']    = sprintf( '<a href="#" class="tsoliin-recheck" data-id="%d" data-nonce="%s">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_html__( 'Recheck', 'tso-link-inspector' ) );
		$actions['not_broken'] = sprintf( '<a href="#" class="tsoliin-not-broken" data-id="%d" data-nonce="%s" title="%s" style="color:#0a7d33;font-weight:600;">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_attr( $not_broken_title ), esc_html__( 'Not broken', 'tso-link-inspector' ) );

		if ( $can_unlink ) {
			$unlink_title = TSOLIIN_Support::is_comment_author_url_row( $item )
				? __( 'Clear the comment author website field (URL)', 'tso-link-inspector' )
				: __( 'Remove the link (or image) from the source; visible text is kept when it is a text link.', 'tso-link-inspector' );
			$actions['unlink'] = sprintf(
				'<a href="#" class="tsoliin-unlink" data-id="%d" data-url="%s" data-post="%d" data-nonce="%s" title="%s">%s</a>',
				absint( $item->id ),
				esc_attr( $url ),
				absint( $item->post_id ),
				esc_attr( $nonce ),
				esc_attr( $unlink_title ),
				esc_html__( 'Unlink', 'tso-link-inspector' )
			);
		}

		$delete_title = __( 'Delete this record from the list only. The source content is not modified.', 'tso-link-inspector' );
		if ( TSOLIIN_Support::is_comment_author_url_row( $item ) ) {
			$delete_title = __( 'Delete this record from the list only. The author URL in the comment is not cleared — use Unlink for that.', 'tso-link-inspector' );
		} elseif ( 'comment' === $type ) {
			$delete_title = __( 'Delete this record from the list only. The comment is not modified.', 'tso-link-inspector' );
		} elseif ( ! empty( $item->post_id ) && in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) ) {
			$delete_title = __( 'Delete this record from the list. The post link is not modified.', 'tso-link-inspector' );
		}
		$actions['delete'] = sprintf( '<a href="#" class="tsoliin-delete" data-id="%d" data-nonce="%s" title="%s">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_attr( $delete_title ), esc_html__( 'Delete', 'tso-link-inspector' ) );

		if ( ! TSOLIIN_HTTP::is_ignored_url( $url ) ) {
			$ignore_pattern = TSOLIIN_HTTP::suggest_ignore_pattern_from_url( $url );
			if ( '' !== $ignore_pattern ) {
				$ignore_title = sprintf(
					/* translators: %s: domain or URL prefix */
					__( 'Add %s to the ignore list (skip during scan and check)', 'tso-link-inspector' ),
					$ignore_pattern
				);
				$actions['ignore'] = sprintf(
					'<a href="#" class="tsoliin-add-ignore" data-id="%d" data-pattern="%s" data-nonce="%s" title="%s">%s</a>',
					absint( $item->id ),
					esc_attr( $ignore_pattern ),
					esc_attr( $nonce ),
					esc_attr( $ignore_title ),
					esc_html__( 'Ignore domain', 'tso-link-inspector' )
				);
			}
		}

		if ( $show_suggest ) {
			$suggest_type = $is_woo_source ? 'woocommerce' : $type;
			if ( 'menu' === $type && TSOLIIN_Support::is_custom_menu_url_row( $item ) ) {
				// Custom menu URLs can apply HTTPS suggestions; post_type menu items cannot.
				$suggest_type = 'menu_custom';
			}
			$actions['suggest'] = sprintf(
				'<a href="#" class="tsoliin-suggest" data-id="%d" data-link-type="%s" data-nonce="%s" style="color:#b45309;font-weight:600;">%s</a>',
				absint( $item->id ),
				esc_attr( $suggest_type ),
				esc_attr( $nonce ),
				esc_html__( 'Suggestion', 'tso-link-inspector' )
			);
		}

		return $out . $this->row_actions( $actions );
	}

	protected function column_post_title( $item ) {
		$type  = isset( $item->link_type ) ? (string) $item->link_type : 'link';
		$title = ! empty( $item->post_title ) ? (string) $item->post_title : __( '(no title)', 'tso-link-inspector' );

		if ( 'menu' === $type && ! empty( $item->anchor_text ) ) {
			$title = (string) $item->anchor_text;
			$sk    = isset( $item->source_key ) ? (string) $item->source_key : '';
			if ( TSOLIIN_Support::shows_menu_admin_go_to_edit_action( $item ) ) {
				$edit = TSOLIIN_Support::get_menus_admin_edit_url( $sk );
				return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
			}
			return esc_html( $title );
		}

		if ( 'widget' === $type ) {
			$title = ! empty( $item->anchor_text ) ? (string) $item->anchor_text : __( 'Widget', 'tso-link-inspector' );
			$sk    = isset( $item->source_key ) ? (string) $item->source_key : '';
			$edit  = TSOLIIN_Support::get_widgets_admin_edit_url( $sk );
			return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		}

		if ( 'acf' === $type ) {
			$title = ! empty( $item->anchor_text ) ? (string) $item->anchor_text : __( 'ACF Options', 'tso-link-inspector' );
			$sk    = isset( $item->source_key ) ? (string) $item->source_key : '';
			$edit  = TSOLIIN_Support::get_acf_options_admin_edit_url( $sk );
			if ( '' !== $edit ) {
				return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
			}
			return esc_html( $title );
		}

		if ( 'term' === $type ) {
			$title = ! empty( $item->anchor_text ) ? (string) $item->anchor_text : __( 'Term', 'tso-link-inspector' );
			$edit  = '';
			if ( ! empty( $item->source_key ) && preg_match( '/^t-(\d+)-/', (string) $item->source_key, $m ) ) {
				$term = get_term( absint( $m[1] ) );
				if ( $term && ! is_wp_error( $term ) ) {
					$edit = (string) get_edit_term_link( $term, $term->taxonomy );
				}
			}
			if ( '' !== $edit ) {
				return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
			}
			return esc_html( $title );
		}

		if ( in_array( $type, array( 'template', 'wp_block' ), true ) && ! empty( $item->post_id ) ) {
			$edit = TSOLIIN_Support::get_post_admin_edit_url_for_link( $item );
			if ( '' !== $edit ) {
				return '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
			}
		}

		if ( empty( $item->post_id ) ) {
			return esc_html( $title );
		}

		$edit_target = TSOLIIN_Support::get_cached_post_for_edit_link( absint( $item->post_id ) );
		$edit        = (string) get_edit_post_link( $edit_target );
		if ( '' === $edit ) {
			return esc_html( $title );
		}
		$view     = TSOLIIN_Support::get_post_frontend_view_url_for_link( $item );
		$post_url = esc_url( add_query_arg( array( 'page' => 'tso-link-inspector', 'post_id' => absint( $item->post_id ) ), admin_url( 'tools.php' ) ) );

		$out  = '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'Edit post', 'tso-link-inspector' ) . '">' . esc_html( $title ) . '</a>';
		// Icons row: view post + list links for this post.
		$out .= '<div class="tsoliin-post-icons">';
		$view_title = __( 'View post', 'tso-link-inspector' );
		if ( 'comment' === $type ) {
			$view_title = __( 'View post at this comment', 'tso-link-inspector' );
		} elseif ( TSOLIIN_Support::should_focus_link_in_post_content( $item ) ) {
			$view_title = __( 'View post at this link', 'tso-link-inspector' );
		}
		$out .= '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $view_title ) . '" class="tsoliin-post-icon">';
		$out .= '<span class="dashicons dashicons-external"></span></a>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_post_view = isset( $_REQUEST['post_id'] ) && absint( $_REQUEST['post_id'] ) === absint( $item->post_id );
		if ( $is_post_view ) {
			// Currently viewing this post — show "back" icon in accent colour.
			$out .= '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" title="' . esc_attr__( 'Back to all links', 'tso-link-inspector' ) . '" class="tsoliin-post-icon tsoliin-post-icon--back">';
			$out .= '<span class="dashicons dashicons-arrow-left-alt"></span></a>';
		} else {
			$out .= '<a href="' . $post_url . '" title="' . esc_attr__( 'View all links for this post', 'tso-link-inspector' ) . '" class="tsoliin-post-icon tsoliin-post-icon--list">';
			$out .= '<span class="dashicons dashicons-list-view"></span></a>';
		}
		$out .= '</div>';
		return $out;
	}

	protected function column_status_code( $item ) {
		return TSOLIIN_Support::render_link_status_html( $item, $this->http );
	}

	public function no_items() {
		esc_html_e( 'No links found. Run a scan first.', 'tso-link-inspector' );
	}

	/**
	 * URL search field above top pagination (inside the list GET form).
	 *
	 * @return void
	 */
	private function render_search_controls() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_val = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		echo '<div id="tsoliin-search-form" class="tsoliin-search-form" role="search">';
		echo '<label class="screen-reader-text" for="tsoliin-search-input">' . esc_html__( 'Search URLs', 'tso-link-inspector' ) . '</label>';
		echo '<input type="search" id="tsoliin-search-input" name="s" value="' . esc_attr( $search_val ) . '" placeholder="' . esc_attr__( 'Search URLs...', 'tso-link-inspector' ) . '" class="tsoliin-search-input" autocomplete="off" aria-controls="tsoliin-list-table-region" />';
		echo '<button type="button" class="button tsoliin-search-submit">' . esc_html__( 'Search', 'tso-link-inspector' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Shared list-tab navigation context (filters, scope, stats).
	 *
	 * @return array<string, mixed>
	 */
	private function get_list_tabnav_context() {
		if ( null !== $this->list_tabnav_context ) {
			return $this->list_tabnav_context;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id_nav = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$scope_current    = $this->read_request_scope();
		$stats            = $view_post_id_nav
			? $this->db->get_stats_for_post( $view_post_id_nav, $scope_current )
			: $this->db->get_stats( $scope_current );

		$this->list_tabnav_context = array(
			'current'          => $this->read_request_status_filter(),
			'quality_current'  => $this->read_request_quality_filter(),
			'view_post_id_nav' => $view_post_id_nav,
			'scope_current'    => $scope_current,
			'stats'            => $stats,
		);

		return $this->list_tabnav_context;
	}

	/**
	 * Status filter tabs (All, Broken, Redirect, …).
	 *
	 * @return void
	 */
	private function render_status_filter_tabs() {
		$ctx     = $this->get_list_tabnav_context();
		$current = $ctx['current'];
		$stats   = $ctx['stats'];

		$filters = array(
			/* translators: %s: number of links */
			'all'       => sprintf( __( 'All (%s)', 'tso-link-inspector' ),        TSOLIIN_Support::format_display_number( $stats['total'] ) ),
			/* translators: %s: number of broken links */
			'broken'    => sprintf( __( 'Broken (%s)', 'tso-link-inspector' ),    TSOLIIN_Support::format_display_number( $stats['broken'] ) ),
			/* translators: %s: number of redirected links */
			'redirect'  => sprintf( __( 'Redirect (%s)', 'tso-link-inspector' ),  TSOLIIN_Support::format_display_number( $stats['redirect'] ) ),
			/* translators: %s: number of OK links */
			'ok'        => sprintf( __( 'OK (%s)', 'tso-link-inspector' ),   TSOLIIN_Support::format_display_number( $stats['ok'] ) ),
			/* translators: %s: number of unchecked links */
			'unchecked'     => sprintf( __( 'Unchecked (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( $stats['unchecked'] ) ),
			/* translators: %s: number of HTTP insecure links */
			'http_insecure' => sprintf( __( 'HTTP insecure (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( isset( $stats['http_insecure'] ) ? $stats['http_insecure'] : 0 ) ),
			/* translators: %s: number of manually locked links */
			'manual_locked' => sprintf( __( 'Manual locks (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( isset( $stats['manual_locked'] ) ? $stats['manual_locked'] : 0 ) ),
		);

		echo '<div class="tsoliin-filter-tabs">';
		foreach ( $filters as $key => $label ) {
			// Switching tabs should reset active search terms from the query string.
			$tab_args = array( 'filter' => $key );
			$this->merge_active_quality_into_args( $tab_args );
			$this->merge_active_type_into_args( $tab_args );
			if ( $ctx['view_post_id_nav'] > 0 ) {
				$tab_args['post_id'] = $ctx['view_post_id_nav'];
			}
			if ( 'all' !== $ctx['scope_current'] ) {
				$tab_args['scope'] = $ctx['scope_current'];
			}
			$url    = esc_url( $this->list_nav_url( $tab_args, array( 'paged', 's' ) ) );
			$active = $current === $key ? ' class="current"' : '';
			$title  = '';
			if ( 'unchecked' === $key ) {
				$title = ' title="' . esc_attr__( 'Links found but not checked by HTTP yet. Cron will check them automatically, or click Check now.', 'tso-link-inspector' ) . '"';
			}
			if ( 'http_insecure' === $key ) {
				$title = ' title="' . esc_attr__( 'Active links using HTTP instead of HTTPS. Links that also redirect are listed here first; after you switch to HTTPS they move to Redirect.', 'tso-link-inspector' ) . '"';
			}
			if ( 'manual_locked' === $key ) {
				$title = ' title="' . esc_attr__( 'Links you marked as OK. They stay here while nothing changes. Background checks still run; they leave this list if the URL or redirect changes, or a check finds them broken.', 'tso-link-inspector' ) . '"';
			}
			echo '<a href="' . $url . '"' . $active . $title . '>' . esc_html( $label ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	/**
	 * Quality filter tabs (empty anchor, generic anchor, …).
	 *
	 * @return void
	 */
	private function render_quality_filter_tabs() {
		$ctx             = $this->get_list_tabnav_context();
		$current         = $ctx['current'];
		$quality_current = $ctx['quality_current'];
		$stats           = $ctx['stats'];

		$quality_filters = array(
			/* translators: %s: number of links with empty anchor text */
			'empty_anchor'       => sprintf( __( 'Empty anchor (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( isset( $stats['empty_anchor'] ) ? $stats['empty_anchor'] : 0 ) ),
			/* translators: %s: number of links with generic anchor text */
			'generic_anchor'     => sprintf( __( 'Generic anchor (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( isset( $stats['generic_anchor'] ) ? $stats['generic_anchor'] : 0 ) ),
			/* translators: %s: number of links to unpublished posts */
			'unpublished_target' => sprintf( __( 'Unpublished target (%s)', 'tso-link-inspector' ), TSOLIIN_Support::format_display_number( isset( $stats['unpublished_target'] ) ? $stats['unpublished_target'] : 0 ) ),
		);

		echo '<div class="tsoliin-quality-tabs" aria-label="' . esc_attr__( 'Quality filters', 'tso-link-inspector' ) . '">';
		echo '<span class="tsoliin-quality-tabs__label">' . esc_html__( 'Quality:', 'tso-link-inspector' ) . '</span> ';
		foreach ( $quality_filters as $key => $label ) {
			$tab_args = array();
			if ( 'all' !== $current ) {
				$tab_args['filter'] = $current;
			}
			$this->merge_active_type_into_args( $tab_args );
			$omit_keys = array( 'paged', 's' );
			if ( $quality_current !== $key ) {
				$tab_args['quality_filter'] = $key;
			} else {
				$omit_keys[] = 'quality_filter';
			}
			if ( $ctx['view_post_id_nav'] > 0 ) {
				$tab_args['post_id'] = $ctx['view_post_id_nav'];
			}
			if ( 'unpublished_target' !== $key && 'all' !== $ctx['scope_current'] ) {
				$tab_args['scope'] = $ctx['scope_current'];
			}
			$url    = esc_url( $this->list_nav_url( $tab_args, $omit_keys ) );
			$active = $quality_current === $key ? ' class="current"' : '';
			$title  = '';
			if ( $quality_current === $key ) {
				$title = ' title="' . esc_attr__( 'Click again to clear this quality filter.', 'tso-link-inspector' ) . '"';
			}
			if ( 'empty_anchor' === $key && '' === $title ) {
				$title = ' title="' . esc_attr__( 'Links with no visible anchor text (empty or missing).', 'tso-link-inspector' ) . '"';
			}
			if ( 'generic_anchor' === $key ) {
				$title = ' title="' . esc_attr__( 'Links using non-descriptive anchor text such as “click here” or “read more”.', 'tso-link-inspector' ) . '"';
			}
			if ( 'unpublished_target' === $key ) {
				$title = ' title="' . esc_attr__( 'Internal links pointing to draft, private, pending, or trashed posts.', 'tso-link-inspector' ) . '"';
			}
			echo '<a href="' . $url . '"' . $active . $title . '>' . esc_html( $label ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	/**
	 * Internal / external scope tabs.
	 *
	 * @return void
	 */
	private function render_scope_tabs() {
		$ctx             = $this->get_list_tabnav_context();
		$current         = $ctx['current'];
		$scope_current   = $ctx['scope_current'];

		$scope_labels = array(
			'all'      => __( 'All links', 'tso-link-inspector' ),
			'internal' => __( 'Internal', 'tso-link-inspector' ),
			'external' => __( 'External', 'tso-link-inspector' ),
		);
		echo '<div class="tsoliin-scope-tabs" aria-label="' . esc_attr__( 'Link scope', 'tso-link-inspector' ) . '">';
		foreach ( $scope_labels as $scope_key => $scope_label ) {
			$scope_args = array();
			if ( 'all' !== $current ) {
				$scope_args['filter'] = $current;
			}
			$this->merge_active_quality_into_args( $scope_args );
			$this->merge_active_type_into_args( $scope_args );
			if ( $ctx['view_post_id_nav'] > 0 ) {
				$scope_args['post_id'] = $ctx['view_post_id_nav'];
			}
			$scope_omit = array( 'paged', 's' );
			if ( 'all' !== $scope_key ) {
				$scope_args['scope'] = $scope_key;
			} else {
				$scope_omit[] = 'scope';
			}
			$scope_url    = esc_url( $this->list_nav_url( $scope_args, $scope_omit ) );
			$scope_active = $scope_current === $scope_key ? ' class="current"' : '';
			$scope_title  = '';
			if ( 'internal' === $scope_key ) {
				$scope_title = ' title="' . esc_attr__( 'Links on this site (same domain or relative paths)', 'tso-link-inspector' ) . '"';
			}
			if ( 'external' === $scope_key ) {
				$scope_title = ' title="' . esc_attr__( 'Links pointing to other domains', 'tso-link-inspector' ) . '"';
			}
			echo '<a href="' . $scope_url . '"' . $scope_active . $scope_title . '>' . esc_html( $scope_label ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	/**
	 * Type filter dropdown (Link, Image, Iframe, Comment, …).
	 *
	 * Purely orthogonal to Status/Quality/Internal-External: it only adds an
	 * extra "AND l.link_type = %s" to the list SQL (see
	 * TSOLIIN_DB::build_links_list_query_parts()). It never touches the
	 * dashboard stat cards (Total/Broken/Redirect/OK/…), which are computed by
	 * TSOLIIN_DB::get_stats()/get_stats_for_post() independently of this
	 * filter — same behavior as the existing Quality filter.
	 *
	 * @return void
	 */
	private function render_type_filter_dropdown() {
		$current = $this->read_request_type_filter();

		$type_labels = array(
			''         => __( 'All types', 'tso-link-inspector' ),
			'link'     => __( 'Link', 'tso-link-inspector' ),
			'image'    => __( 'Image', 'tso-link-inspector' ),
			'iframe'   => __( 'Iframe', 'tso-link-inspector' ),
			'plain'    => __( 'Plain text URL', 'tso-link-inspector' ),
			'comment'  => __( 'Comment', 'tso-link-inspector' ),
			'menu'     => __( 'Menu', 'tso-link-inspector' ),
			'widget'   => __( 'Widget', 'tso-link-inspector' ),
			'term'     => __( 'Term', 'tso-link-inspector' ),
			'template' => __( 'Template / Navigation', 'tso-link-inspector' ),
			'wp_block' => __( 'Reusable block', 'tso-link-inspector' ),
			'acf'      => __( 'ACF Options', 'tso-link-inspector' ),
		);

		echo '<div class="tsoliin-type-filter">';
		echo '<label class="screen-reader-text" for="tsoliin-type-filter-select">' . esc_html__( 'Filter by link type', 'tso-link-inspector' ) . '</label>';
		echo '<select id="tsoliin-type-filter-select" class="tsoliin-type-filter__select" aria-label="' . esc_attr__( 'Filter by link type', 'tso-link-inspector' ) . '">';
		foreach ( $type_labels as $key => $label ) {
			$type_args = array();
			$omit_keys = array( 'paged' );
			if ( '' === $key ) {
				$omit_keys[] = 'link_type_filter';
			} else {
				$type_args['link_type_filter'] = $key;
			}
			$option_url = esc_url( $this->list_nav_url( $type_args, $omit_keys ) );
			$selected   = ( $current === $key ) ? ' selected="selected"' : '';
			echo '<option value="' . $option_url . '"' . $selected . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option_url from esc_url().
		}
		echo '</select>';
		echo '</div>';
	}

	/**
	 * Bulk actions dropdown + Apply button using plugin text domain (not WP admin locale).
	 *
	 * @param string $which Top or bottom table nav.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();
			if ( isset( $this->screen, $this->screen->id ) ) {
				$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP_List_Table bulk_actions filter.
			}
			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) ) {
			return;
		}

		echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'tso-link-inspector' ) . '</label>';
		echo '<select name="action' . esc_attr( $two ) . '" id="bulk-action-selector-' . esc_attr( $which ) . '">';
		echo '<option value="-1">' . esc_html__( 'Bulk actions', 'tso-link-inspector' ) . '</option>';

		foreach ( $this->_actions as $key => $value ) {
			if ( is_array( $value ) ) {
				echo '<optgroup label="' . esc_attr( $key ) . '">';
				foreach ( $value as $name => $title ) {
					if ( 'edit' === $name ) {
						echo '<option value="' . esc_attr( $name ) . '" class="hide-if-no-js">' . esc_html( $title ) . '</option>';
					} else {
						echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $title ) . '</option>';
					}
				}
				echo '</optgroup>';
			} elseif ( 'edit' === $key ) {
				echo '<option value="' . esc_attr( $key ) . '" class="hide-if-no-js">' . esc_html( $value ) . '</option>';
			} else {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</option>';
			}
		}
		echo '</select>';

		$btn_name = 'doaction' . $two;
		printf(
			'<input type="submit" name="%1$s" id="%1$s" class="button action" value="%2$s" />',
			esc_attr( $btn_name ),
			esc_attr__( 'Apply', 'tso-link-inspector' )
		);
	}

	/**
	 * Resolve bulk action from top or bottom dropdown (matches core WP_List_Table behaviour).
	 *
	 * @return string|false
	 */
	public function current_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, mirrors WP core.
		if ( isset( $_REQUEST['filter_action'] ) && '' !== sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) ) ) {
			return false;
		}
		if ( isset( $_REQUEST['action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
			if ( '' !== $action && '-1' !== $action ) {
				return $action;
			}
		}
		if ( isset( $_REQUEST['action2'] ) ) {
			$action2 = sanitize_key( wp_unslash( (string) $_REQUEST['action2'] ) );
			if ( '' !== $action2 && '-1' !== $action2 ) {
				return $action2;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
	}

	/**
	 * Column header cells with sort links that always target the plugin admin screen.
	 *
	 * WordPress 6.3+ wraps this output in <thead>/<tfoot><tr>; only print <th>/<td> cells here.
	 *
	 * @param bool $with_id Whether to set IDs on the header cells.
	 * @return void
	 */
	public function print_column_headers( $with_id = true ) {
		list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_order = isset( $_REQUEST['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'desc' : 'asc';

		if ( ! empty( $columns['cb'] ) ) {
			static $cb_counter = 1;
			$columns['cb']     = '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />'
				. '<label for="cb-select-all-' . $cb_counter . '">'
				. '<span class="screen-reader-text">' . esc_html__( 'Select All', 'tso-link-inspector' ) . '</span>'
				. '</label>';
			++$cb_counter;
		}

		foreach ( $columns as $column_key => $column_display_name ) {
			$class          = array( 'manage-column', 'column-' . $column_key );
			$aria_sort_attr = '';
			$abbr_attr      = '';
			$order_text     = '';

			if ( in_array( $column_key, $hidden, true ) ) {
				$class[] = 'hidden';
			}

			if ( 'cb' === $column_key ) {
				$class[] = 'check-column';
			}

			if ( $column_key === $primary ) {
				$class[] = 'column-primary';
			}

			if ( isset( $sortable[ $column_key ] ) ) {
				$orderby       = isset( $sortable[ $column_key ][0] ) ? $sortable[ $column_key ][0] : '';
				$desc_first    = ! empty( $sortable[ $column_key ][1] );
				$abbr          = isset( $sortable[ $column_key ][2] ) ? $sortable[ $column_key ][2] : '';
				$initial_order = isset( $sortable[ $column_key ][4] ) ? $sortable[ $column_key ][4] : '';

				if ( '' === $current_orderby && $initial_order ) {
					$current_orderby = $orderby;
					$current_order   = $initial_order;
				}

				if ( $current_orderby === $orderby ) {
					if ( 'asc' === $current_order ) {
						$order          = 'desc';
						$aria_sort_attr = ' aria-sort="ascending"';
					} else {
						$order          = 'asc';
						$aria_sort_attr = ' aria-sort="descending"';
					}
					$class[] = 'sorted';
					$class[] = $current_order;
				} else {
					$order = strtolower( $desc_first ? 'desc' : 'asc' );
					if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
						$order = $desc_first ? 'desc' : 'asc';
					}
					$class[] = 'sortable';
					$class[] = 'desc' === $order ? 'asc' : 'desc';

					$order_text = 'asc' === $order
						? __( 'Sort ascending.', 'tso-link-inspector' )
						: __( 'Sort descending.', 'tso-link-inspector' );
				}

				if ( '' !== $order_text ) {
					$order_text = ' <span class="screen-reader-text">' . esc_html( $order_text ) . '</span>';
				}

				$abbr_attr = $abbr ? ' abbr="' . esc_attr( $abbr ) . '"' : '';

				$column_display_name = sprintf(
					'<a href="%1$s"><span>%2$s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>%3$s</a>',
					esc_url(
						$this->build_admin_list_url(
							array(
								'orderby' => $orderby,
								'order'   => $order,
							),
							array( 'paged' )
						)
					),
					'cb' === $column_key ? $column_display_name : esc_html( $column_display_name ),
					$order_text
				);
			} elseif ( 'cb' !== $column_key ) {
				$column_display_name = esc_html( $column_display_name );
			}

			$tag   = ( 'cb' === $column_key ) ? 'td' : 'th';
			$scope = ( 'th' === $tag ) ? 'scope="col"' : '';
			$id    = $with_id ? 'id="' . esc_attr( $column_key ) . '"' : '';

			echo '<' . $tag . ' ' . $scope . ' ' . $id . ' class="' . esc_attr( implode( ' ', $class ) ) . '"' . $aria_sort_attr . $abbr_attr . '>' . $column_display_name . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render table navigation.
	 * Keep top bulk-actions visible even when there are no rows, so the
	 * empty-state layout matches the normal table layout.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
		}

		echo '<div class="tablenav ' . esc_attr( $which ) . '">';

		if ( 'top' === $which ) {
			$this->render_status_filter_tabs();
			$this->render_quality_filter_tabs();
			echo '<div class="tsoliin-scope-search-row">';
			$this->render_scope_tabs();
			$this->render_type_filter_dropdown();
			echo '<div class="tsoliin-scope-search-row__search">';
			$this->render_search_controls();
			echo '</div>';
			echo '</div>';
			echo '<div class="tsoliin-bulk-pagination-row">';
			echo '<div class="alignleft actions bulkactions tsoliin-bulk-bar">';
			$this->bulk_actions( $which );
			echo '</div>';
			$this->pagination( $which );
			echo '</div>';
		} else {
			$this->pagination( $which );
		}

		echo '</div>';
	}

	/**
	 * Pagination links always target the plugin admin screen (not admin-ajax.php after live search).
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function pagination( $which ) {
		if ( empty( $this->_pagination_args ) ) {
			return;
		}

		$total_items = isset( $this->_pagination_args['total_items'] ) ? (int) $this->_pagination_args['total_items'] : 0;
		$total_pages = isset( $this->_pagination_args['total_pages'] ) ? (int) $this->_pagination_args['total_pages'] : 0;
		$position    = 'top' === $which ? 'top' : 'bottom';

		$page_links = '';
		if ( $total_pages > 1 ) {
			$current = $this->get_pagenum();
			$base    = $this->build_pagination_base_url();

			$page_links = paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current,
					'add_args'  => false,
				)
			);
		}

		echo '<div class="tablenav-pages tsoliin-pagination tsoliin-pagination--' . esc_attr( $position ) . '">';
		/* translators: %s: number of links in the current list view. */
		echo '<span class="displaying-num">' . esc_html( sprintf( _n( '%s item', '%s items', $total_items, 'tso-link-inspector' ), number_format_i18n( $total_items ) ) ) . '</span>';
		if ( $page_links ) {
			echo '<span class="pagination-links" aria-label="' . esc_attr__( 'Pagination', 'tso-link-inspector' ) . '">' . $page_links . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}
}
