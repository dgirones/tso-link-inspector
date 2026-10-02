<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * ACF field helpers: ID-based values and Options pages.
 *
 * @package TSOLIIN_Link_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Acf
 */
class TSOLIIN_Acf {

	/**
	 * Whether Advanced Custom Fields is available.
	 *
	 * @return bool
	 */
	public static function is_plugin_active() {
		return function_exists( 'get_field_objects' ) && function_exists( 'acf_get_field' );
	}

	/**
	 * Whether a stored source_key belongs to the ACF module.
	 *
	 * @param string $source_key DB source_key.
	 * @return bool
	 */
	public static function is_acf_source_key( $source_key ) {
		return 0 === strpos( (string) $source_key, 'acf' );
	}

	/**
	 * Collect ID-resolved ACF URLs for a post (image/file/gallery/post object/page link/relationship/taxonomy).
	 *
	 * String URL / Link / WYSIWYG fields stay in the generic meta scanner.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Items: url, anchor, type, source_key.
	 */
	public static function collect_post_id_items( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::is_plugin_active() ) {
			return array();
		}
		$objects = get_field_objects( $post_id );
		if ( ! is_array( $objects ) || empty( $objects ) ) {
			return array();
		}
		$out = array();
		foreach ( $objects as $field ) {
			self::collect_from_field( $field, isset( $field['value'] ) ? $field['value'] : null, $post_id, 'p', $out, true );
		}
		return $out;
	}

	/**
	 * Collect all ACF Options page URLs (strings and ID-resolved).
	 *
	 * @return array[] Items: url, anchor, type, source_key.
	 */
	public static function collect_options_items() {
		if ( ! self::is_plugin_active() ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( self::get_options_post_ids() as $acf_id ) {
			$key = is_numeric( $acf_id ) ? (string) absint( $acf_id ) : sanitize_key( (string) $acf_id );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$objects      = get_field_objects( $acf_id );
			if ( ! is_array( $objects ) || empty( $objects ) ) {
				continue;
			}
			foreach ( $objects as $field ) {
				self::collect_from_field( $field, isset( $field['value'] ) ? $field['value'] : null, 0, 'o' . $key, $out, false );
			}
		}
		return $out;
	}

	/**
	 * Whether the stored URL is still produced by this ACF source_key.
	 *
	 * @param object $link DB row.
	 * @return bool
	 */
	public static function source_has_url( $link ) {
		if ( ! $link || empty( $link->link_url ) || empty( $link->source_key ) ) {
			return false;
		}
		$url = (string) $link->link_url;
		$sk  = (string) $link->source_key;
		if ( ! self::is_acf_source_key( $sk ) ) {
			return false;
		}
		$items = array();
		$pid   = isset( $link->post_id ) ? absint( $link->post_id ) : 0;
		if ( $pid > 0 ) {
			$items = self::collect_post_id_items( $pid );
		} elseif ( 'acf' === ( isset( $link->link_type ) ? (string) $link->link_type : '' ) ) {
			$items = self::collect_options_items();
		}
		foreach ( $items as $item ) {
			if ( isset( $item['source_key'] ) && (string) $item['source_key'] === $sk ) {
				return isset( $item['url'] ) && (string) $item['url'] === $url;
			}
		}
		return false;
	}

	/**
	 * Admin URL for ACF Options (or field groups as fallback).
	 *
	 * @param string $source_key Optional row source_key.
	 * @return string
	 */
	public static function get_options_admin_edit_url( $source_key = '' ) {
		if ( ! self::is_plugin_active() ) {
			return '';
		}
		$fallback = '';
		$token    = '';
		if ( preg_match( '/^acf-o([a-z0-9]+)-/', sanitize_key( (string) $source_key ), $m ) ) {
			$token = (string) $m[1];
		}
		if ( function_exists( 'acf_get_options_pages' ) ) {
			$pages = acf_get_options_pages();
			if ( is_array( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( ! is_array( $page ) || empty( $page['menu_slug'] ) ) {
						continue;
					}
					$url = admin_url( 'admin.php?page=' . sanitize_key( (string) $page['menu_slug'] ) );
					if ( '' === $fallback ) {
						$fallback = $url;
					}
					if ( '' === $token ) {
						continue;
					}
					$pid = isset( $page['post_id'] ) ? (string) $page['post_id'] : '';
					$ids = array( sanitize_key( $pid ) );
					if ( 'options' === $pid ) {
						$ids[] = 'option';
					} elseif ( 'option' === $pid ) {
						$ids[] = 'options';
					}
					if ( in_array( $token, $ids, true ) ) {
						return $url;
					}
				}
			}
		}
		if ( '' !== $fallback ) {
			return $fallback;
		}
		return admin_url( 'edit.php?post_type=acf-field-group' );
	}

	/**
	 * Get the options post IDs.
	 *
	 * @return array<int|string>
	 */
	private static function get_options_post_ids() {
		$ids = array( 'option' );
		if ( function_exists( 'acf_get_options_pages' ) ) {
			$pages = acf_get_options_pages();
			if ( is_array( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( is_array( $page ) && isset( $page['post_id'] ) && '' !== (string) $page['post_id'] ) {
						$ids[] = $page['post_id'];
					}
				}
			}
		}
		return $ids;
	}

	/**
	 * Walk one ACF field (including repeater / flexible / group).
	 *
	 * @param array  $field           ACF field array.
	 * @param mixed  $value           Field value (formatted).
	 * @param int    $post_id         WP post ID (0 for options).
	 * @param string $scope           Source-key scope (p or o{id}).
	 * @param array  $out             Items (by ref).
	 * @param bool   $ids_only        When true, skip string URL fields (post meta scanner covers them).
	 * @return void
	 */
	private static function collect_from_field( $field, $value, $post_id, $scope, array &$out, $ids_only ) {
		if ( ! is_array( $field ) ) {
			return;
		}
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( '' === $type ) {
			return;
		}

		if ( in_array( $type, array( 'repeater', 'group', 'clone' ), true ) ) {
			$subs = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
			$rows = is_array( $value ) ? $value : array();
			if ( in_array( $type, array( 'group', 'clone' ), true ) ) {
				// Clone/group store one associative row (or nested arrays), not a list of rows.
				$is_list = array() !== $rows && array_keys( $rows ) === range( 0, count( $rows ) - 1 );
				if ( ! $is_list ) {
					$rows = array( $rows );
				}
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $subs as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$subname = isset( $sub['name'] ) ? (string) $sub['name'] : '';
					$subval  = ( '' !== $subname && array_key_exists( $subname, $row ) ) ? $row[ $subname ] : null;
					self::collect_from_field( $sub, $subval, $post_id, $scope, $out, $ids_only );
				}
			}
			return;
		}

		if ( 'flexible_content' === $type ) {
			$layouts = isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array();
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$layout_name = isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
				$subs        = array();
				foreach ( $layouts as $layout ) {
					if ( ! is_array( $layout ) ) {
						continue;
					}
					$lname = isset( $layout['name'] ) ? (string) $layout['name'] : '';
					if ( $lname === $layout_name && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$subs = $layout['sub_fields'];
						break;
					}
				}
				foreach ( $subs as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$subname = isset( $sub['name'] ) ? (string) $sub['name'] : '';
					$subval  = ( '' !== $subname && array_key_exists( $subname, $row ) ) ? $row[ $subname ] : null;
					self::collect_from_field( $sub, $subval, $post_id, $scope, $out, $ids_only );
				}
			}
			return;
		}

		$label = isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $name;
		$label = sanitize_text_field( $label );

		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			if ( $ids_only && self::value_already_stores_url_string( $value ) ) {
				return;
			}
			self::push_item( $out, self::attachment_url_from_value( $value ), $label, 'image' === $type ? 'image' : 'link', $post_id, $scope, $name );
			return;
		}
		if ( 'gallery' === $type ) {
			foreach ( (array) $value as $entry ) {
				if ( $ids_only && self::value_already_stores_url_string( $entry ) ) {
					continue;
				}
				self::push_item( $out, self::attachment_url_from_value( $entry ), $label, 'image', $post_id, $scope, $name );
			}
			return;
		}
		if ( in_array( $type, array( 'post_object', 'page_link', 'relationship' ), true ) ) {
			$entries = is_array( $value ) ? $value : array( $value );
			if ( 'page_link' === $type && is_string( $value ) ) {
				$entries = array( $value );
			}
			foreach ( $entries as $entry ) {
				if ( $ids_only && self::value_already_stores_url_string( $entry ) ) {
					continue;
				}
				self::push_item( $out, self::permalink_from_value( $entry ), $label, 'link', $post_id, $scope, $name );
			}
			return;
		}
		if ( 'taxonomy' === $type ) {
			$entries = is_array( $value ) ? $value : array( $value );
			foreach ( $entries as $entry ) {
				if ( $ids_only && self::value_already_stores_url_string( $entry ) ) {
					continue;
				}
				self::push_item( $out, self::term_url_from_value( $entry ), $label, 'link', $post_id, $scope, $name );
			}
			return;
		}

		if ( $ids_only ) {
			return;
		}

		if ( 'url' === $type && is_string( $value ) ) {
			self::push_item( $out, $value, $label, 'link', $post_id, $scope, $name );
			return;
		}
		if ( 'link' === $type ) {
			if ( is_array( $value ) && ! empty( $value['url'] ) ) {
				$anchor = ! empty( $value['title'] ) ? sanitize_text_field( (string) $value['title'] ) : $label;
				self::push_item( $out, (string) $value['url'], $anchor, 'link', $post_id, $scope, $name );
				return;
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				self::push_item( $out, $value, $label, 'link', $post_id, $scope, $name );
				return;
			}
		}
		if ( in_array( $type, array( 'wysiwyg', 'textarea', 'oembed' ), true ) && is_string( $value ) && '' !== $value ) {
			foreach ( self::extract_http_urls_from_string( $value ) as $found_url ) {
				self::push_item( $out, $found_url, $label, 'link', $post_id, $scope, $name );
			}
		}
	}

	/**
	 * Raw value already contains an http(s) string the generic meta scanner would find.
	 *
	 * @param mixed $value Field value.
	 * @return bool
	 */
	private static function value_already_stores_url_string( $value ) {
		if ( is_string( $value ) && (bool) preg_match( '#\Ahttps?://#i', trim( $value ) ) ) {
			return true;
		}
		if ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) && (bool) preg_match( '#\Ahttps?://#i', trim( $value['url'] ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Attachment URL from value.
	 *
	 * @param mixed $value Image/file value.
	 * @return string
	 */
	private static function attachment_url_from_value( $value ) {
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( absint( $value ) );
			return is_string( $url ) ? $url : '';
		}
		if ( is_array( $value ) ) {
			if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
				return $value['url'];
			}
			$id = 0;
			if ( ! empty( $value['ID'] ) ) {
				$id = absint( $value['ID'] );
			} elseif ( ! empty( $value['id'] ) ) {
				$id = absint( $value['id'] );
			}
			if ( $id > 0 ) {
				$url = wp_get_attachment_url( $id );
				return is_string( $url ) ? $url : '';
			}
		}
		if ( is_object( $value ) && isset( $value->ID ) ) {
			$url = wp_get_attachment_url( absint( $value->ID ) );
			return is_string( $url ) ? $url : '';
		}
		if ( is_string( $value ) && (bool) preg_match( '#\Ahttps?://#i', trim( $value ) ) ) {
			return trim( $value );
		}
		return '';
	}

	/**
	 * Permalink from value.
	 *
	 * @param mixed $value Post/page_link value.
	 * @return string
	 */
	private static function permalink_from_value( $value ) {
		if ( is_string( $value ) && (bool) preg_match( '#\Ahttps?://#i', trim( $value ) ) ) {
			return trim( $value );
		}
		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			return $id > 0 ? (string) get_permalink( $id ) : '';
		}
		if ( $value instanceof WP_Post ) {
			return (string) get_permalink( (int) $value->ID );
		}
		if ( is_array( $value ) && ! empty( $value['ID'] ) ) {
			return (string) get_permalink( absint( $value['ID'] ) );
		}
		if ( is_object( $value ) && isset( $value->ID ) ) {
			return (string) get_permalink( absint( $value->ID ) );
		}
		return '';
	}

	/**
	 * Term URL from value.
	 *
	 * @param mixed $value Term ID, slug, or WP_Term.
	 * @return string
	 */
	private static function term_url_from_value( $value ) {
		if ( $value instanceof WP_Term ) {
			$link = get_term_link( $value );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ) );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : (string) $link;
			}
		}
		if ( is_string( $value ) && (bool) preg_match( '#\Ahttps?://#i', trim( $value ) ) ) {
			return trim( $value );
		}
		return '';
	}

	/**
	 * Extract HTTP URLs from string.
	 *
	 * @param string $html HTML or text.
	 * @return string[]
	 */
	private static function extract_http_urls_from_string( $html ) {
		$found = array();
		if ( preg_match_all( '#https?://[^\s"\'<>\\\\]+#i', (string) $html, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = trim( (string) $url, ".,);]\"'" );
				if ( '' !== $url ) {
					$found[] = $url;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Push item.
	 *
	 * @param array  $out     Items (by ref).
	 * @param string $url     URL.
	 * @param string $anchor  Anchor.
	 * @param string $type    link|image.
	 * @param int    $post_id Post ID.
	 * @param string $scope   Scope token.
	 * @param string $name    Field name.
	 * @return void
	 */
	private static function push_item( array &$out, $url, $anchor, $type, $post_id, $scope, $name ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url || ! preg_match( '#\Ahttps?://#i', $url ) ) {
			return;
		}
		$db = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->db : null;
		$sk = 'acf-' . sanitize_key( (string) $scope ) . '-' . sanitize_key( (string) $name ) . '-' . md5( $url );
		if ( $db ) {
			$sk = $db->sanitize_source_key( $sk );
		}
		$out[] = array(
			'url'        => $url,
			'anchor'     => $anchor,
			'type'       => ( 'image' === $type ) ? 'image' : 'link',
			'source_key' => $sk,
			'post_id'    => absint( $post_id ),
		);
	}
}
