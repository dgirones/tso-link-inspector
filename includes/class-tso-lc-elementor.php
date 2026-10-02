<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Elementor / ACF dynamic tag resolution in builder JSON.
 *
 * @package TSOLIIN_Link_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Elementor
 */
class TSOLIIN_Elementor {

	/**
	 * Whether a stored source_key belongs to resolved Elementor/ACF tags.
	 *
	 * @param string $source_key DB source_key.
	 * @return bool
	 */
	public static function is_elementor_source_key( $source_key ) {
		return 0 === strpos( (string) $source_key, 'eltag' );
	}

	/**
	 * Whether a meta value may hold Elementor dynamic tags or ACF shortcodes.
	 *
	 * @param mixed $val Meta value.
	 * @return bool
	 */
	public static function value_might_contain_dynamic_tags( $val ) {
		if ( is_string( $val ) ) {
			if ( false !== strpos( $val, '[elementor-tag' ) ) {
				return true;
			}
			if ( false !== strpos( $val, '[acf ' ) || false !== strpos( $val, '[acf	' ) ) {
				return true;
			}
			if ( false !== strpos( $val, '__dynamic__' ) ) {
				return true;
			}
			return false;
		}
		if ( is_array( $val ) ) {
			if ( isset( $val['__dynamic__'] ) ) {
				return true;
			}
			foreach ( $val as $sub ) {
				if ( self::value_might_contain_dynamic_tags( $sub ) ) {
					return true;
				}
			}
		}
		if ( is_object( $val ) ) {
			foreach ( get_object_vars( $val ) as $sub ) {
				if ( self::value_might_contain_dynamic_tags( $sub ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Collect URLs resolved from Elementor dynamic tags and [acf] shortcodes.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Items: url, anchor, type, source_key.
	 */
	public static function collect_post_items( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}
		$out = array();
		self::with_post_context(
			$post_id,
			static function () use ( $post_id, &$out ) {
				foreach ( array( '_elementor_data', '_elementor_page_settings' ) as $key ) {
					self::walk_value( get_post_meta( $post_id, $key, true ), $post_id, $out );
				}
				$post = get_post( $post_id );
				if ( $post && is_string( $post->post_content ) && '' !== $post->post_content ) {
					self::walk_value( $post->post_content, $post_id, $out );
				}
			}
		);
		return $out;
	}

	/**
	 * Whether the stored URL is still produced by this Elementor tag source_key.
	 *
	 * @param object $link DB row.
	 * @return bool
	 */
	public static function source_has_url( $link ) {
		if ( ! $link || empty( $link->link_url ) || empty( $link->source_key ) ) {
			return false;
		}
		$sk = (string) $link->source_key;
		if ( ! self::is_elementor_source_key( $sk ) ) {
			return false;
		}
		$pid = isset( $link->post_id ) ? absint( $link->post_id ) : 0;
		if ( $pid <= 0 ) {
			return false;
		}
		$url   = (string) $link->link_url;
		$items = self::collect_post_items( $pid );
		foreach ( $items as $item ) {
			if ( isset( $item['source_key'] ) && (string) $item['source_key'] === $sk ) {
				return isset( $item['url'] ) && (string) $item['url'] === $url;
			}
		}
		return false;
	}

	/**
	 * Run a callback with Elementor/WordPress post context.
	 *
	 * @param int      $post_id  Post ID.
	 * @param callable $callback Callback.
	 * @return void
	 */
	private static function with_post_context( $post_id, $callback ) {
		$post_id     = absint( $post_id );
		$el_db       = null;
		$switched_el = false;
		if ( class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance )
			&& is_object( \Elementor\Plugin::$instance )
			&& isset( \Elementor\Plugin::$instance->db ) ) {
			$el_db = \Elementor\Plugin::$instance->db;
			if ( is_object( $el_db ) && method_exists( $el_db, 'switch_to_post' ) ) {
				$el_db->switch_to_post( $post_id );
				$switched_el = true;
			}
		}
		$prev_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$post_obj  = get_post( $post_id );
		if ( $post_obj ) {
			$GLOBALS['post'] = $post_obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: Elementor needs the global post while rendering; restored in finally.
			setup_postdata( $post_obj );
		}
		try {
			call_user_func( $callback );
		} finally {
			if ( $switched_el && is_object( $el_db ) && method_exists( $el_db, 'restore_current_post' ) ) {
				$el_db->restore_current_post();
			}
			if ( $prev_post instanceof WP_Post ) {
				$GLOBALS['post'] = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: Elementor needs the global post while rendering; restored in finally.
				setup_postdata( $prev_post );
			} else {
				wp_reset_postdata();
			}
		}
	}

	/**
	 * Walk value.
	 *
	 * @param mixed $value   Nested builder value.
	 * @param int   $post_id Post ID.
	 * @param array $out     Items (by ref).
	 * @return void
	 */
	private static function walk_value( $value, $post_id, array &$out ) {
		if ( is_string( $value ) ) {
			$trim = ltrim( $value );
			if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					self::walk_value( $decoded, $post_id, $out );
					return;
				}
			}
			self::collect_tags_from_string( $value, $post_id, $out );
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $sub ) {
				self::walk_value( $sub, $post_id, $out );
			}
			return;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $sub ) {
				self::walk_value( $sub, $post_id, $out );
			}
		}
	}

	/**
	 * Collect tags from string.
	 *
	 * @param string $text    Raw string.
	 * @param int    $post_id Post ID.
	 * @param array  $out     Items (by ref).
	 * @return void
	 */
	private static function collect_tags_from_string( $text, $post_id, array &$out ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return;
		}
		if ( false !== strpos( $text, '[elementor-tag' ) && preg_match_all( '/\[elementor-tag\s+[^\]]+\]/i', $text, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				foreach ( self::resolve_elementor_tag( (string) $tag, $post_id ) as $item ) {
					self::push_item( $out, $item['url'], $item['anchor'], $item['type'], $post_id, (string) $tag );
				}
			}
		}
		if ( ( false !== strpos( $text, '[acf ' ) || false !== strpos( $text, '[acf	' ) )
			&& preg_match_all( '/\[acf\s+[^\]]+\]/i', $text, $acf_matches ) ) {
			foreach ( $acf_matches[0] as $shortcode ) {
				foreach ( self::resolve_acf_shortcode( (string) $shortcode, $post_id ) as $item ) {
					self::push_item( $out, $item['url'], $item['anchor'], $item['type'], $post_id, (string) $shortcode );
				}
			}
		}
	}

	/**
	 * Resolve elementor tag.
	 *
	 * @param string $tag_text Tag markup.
	 * @param int    $post_id  Post ID.
	 * @return array[]
	 */
	private static function resolve_elementor_tag( $tag_text, $post_id ) {
		$tag_text = (string) $tag_text;
		$items    = array();

		if ( class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance )
			&& is_object( \Elementor\Plugin::$instance )
			&& isset( \Elementor\Plugin::$instance->dynamic_tags )
			&& is_object( \Elementor\Plugin::$instance->dynamic_tags ) ) {
			$manager = \Elementor\Plugin::$instance->dynamic_tags;
			if ( method_exists( $manager, 'parse_tags_text' ) ) {
				$content = (string) $manager->parse_tags_text(
					$tag_text,
					array(),
					static function ( $id, $name, $settings ) use ( $manager ) {
						if ( method_exists( $manager, 'get_tag_data_content' ) ) {
							return (string) $manager->get_tag_data_content( $id, $name, is_array( $settings ) ? $settings : array() );
						}
						return '';
					}
				);
				$anchor  = self::tag_anchor( $tag_text );
				$type    = self::tag_link_type( $tag_text );
				foreach ( self::urls_from_resolved_content( $content ) as $url ) {
					$items[] = array(
						'url'    => $url,
						'anchor' => $anchor,
						'type'   => $type,
					);
				}
				if ( ! empty( $items ) ) {
					return $items;
				}
			}
		}

		$attrs    = self::parse_tag_attributes( $tag_text );
		$name     = isset( $attrs['name'] ) ? sanitize_key( (string) $attrs['name'] ) : '';
		$settings = self::decode_tag_settings( isset( $attrs['settings'] ) ? (string) $attrs['settings'] : '' );
		$anchor   = self::tag_anchor( $tag_text );
		$type     = self::tag_link_type( $tag_text );

		if ( 0 === strpos( $name, 'acf' ) && function_exists( 'get_field' ) ) {
			$field = '';
			if ( ! empty( $settings['key'] ) ) {
				$field = (string) $settings['key'];
			} elseif ( ! empty( $settings['field'] ) ) {
				$field = (string) $settings['field'];
			}
			if ( '' !== $field ) {
				foreach ( self::urls_from_acf_value( get_field( $field, $post_id ) ) as $url ) {
					$items[] = array(
						'url'    => $url,
						'anchor' => $anchor,
						'type'   => $type,
					);
				}
			}
		}

		if ( empty( $items ) && 'internal-url' === $name ) {
			$target_id = 0;
			if ( ! empty( $settings['post_id'] ) ) {
				$target_id = absint( $settings['post_id'] );
			} elseif ( ! empty( $settings['id'] ) ) {
				$target_id = absint( $settings['id'] );
			}
			if ( $target_id > 0 ) {
				$permalink = get_permalink( $target_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$items[] = array(
						'url'    => $permalink,
						'anchor' => $anchor,
						'type'   => 'link',
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Resolve ACF shortcode.
	 *
	 * @param string $shortcode Shortcode markup.
	 * @param int    $post_id   Default post ID.
	 * @return array[]
	 */
	private static function resolve_acf_shortcode( $shortcode, $post_id ) {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}
		$atts  = self::parse_tag_attributes( $shortcode );
		$field = isset( $atts['field'] ) ? (string) $atts['field'] : '';
		if ( '' === $field ) {
			return array();
		}
		$acf_id = $post_id;
		if ( ! empty( $atts['post_id'] ) ) {
			$acf_id = is_numeric( $atts['post_id'] ) ? absint( $atts['post_id'] ) : (string) $atts['post_id'];
		}
		$anchor = sprintf(
			/* translators: %s: ACF field name */
			__( 'ACF field: %s', 'tso-link-inspector' ),
			sanitize_text_field( $field )
		);
		$items = array();
		foreach ( self::urls_from_acf_value( get_field( $field, $acf_id ) ) as $url ) {
			$items[] = array(
				'url'    => $url,
				'anchor' => $anchor,
				'type'   => 'link',
			);
		}
		return $items;
	}

	/**
	 * URLs from ACF value.
	 *
	 * @param mixed $value ACF field value.
	 * @return string[]
	 */
	private static function urls_from_acf_value( $value ) {
		$urls = array();
		if ( is_string( $value ) ) {
			return self::urls_from_resolved_content( $value );
		}
		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$att = wp_get_attachment_url( $id );
				if ( is_string( $att ) && '' !== $att ) {
					$urls[] = $att;
				} else {
					$permalink = get_permalink( $id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						$urls[] = $permalink;
					}
				}
			}
			return $urls;
		}
		if ( is_array( $value ) ) {
			if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
				return self::urls_from_resolved_content( (string) $value['url'] );
			}
			foreach ( $value as $sub ) {
				$urls = array_merge( $urls, self::urls_from_acf_value( $sub ) );
			}
		}
		if ( $value instanceof WP_Post ) {
			$permalink = get_permalink( (int) $value->ID );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}
		return $urls;
	}

	/**
	 * URLs from resolved content.
	 *
	 * @param string $content Resolved tag content.
	 * @return string[]
	 */
	private static function urls_from_resolved_content( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || false !== strpos( $content, '[elementor-tag' ) ) {
			return array();
		}
		$found = array();
		if ( preg_match( '#\Ahttps?://#i', $content ) && false === strpos( $content, '<' ) ) {
			$found[] = $content;
		} elseif ( preg_match_all( '#https?://[^\s"\'<>\\\\]+#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$found[] = trim( (string) $url, ".,);]\"'" );
			}
		} elseif ( is_numeric( $content ) ) {
			return self::urls_from_acf_value( $content );
		}
		$out = array();
		foreach ( $found as $url ) {
			$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
			if ( '' !== $url && preg_match( '#\Ahttps?://#i', $url ) ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Parse tag attributes.
	 *
	 * @param string $tag_text Tag markup.
	 * @return array<string,string>
	 */
	private static function parse_tag_attributes( $tag_text ) {
		$atts = array();
		if ( preg_match_all( '/(\w+)=(["\'])(.*?)\2/', (string) $tag_text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $row ) {
				$atts[ strtolower( (string) $row[1] ) ] = (string) $row[3];
			}
		}
		return $atts;
	}

	/**
	 * Decode tag settings.
	 *
	 * @param string $raw Encoded settings attribute.
	 * @return array
	 */
	private static function decode_tag_settings( $raw ) {
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( urldecode( $raw ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Tag anchor.
	 *
	 * @param string $tag_text Tag markup.
	 * @return string
	 */
	private static function tag_anchor( $tag_text ) {
		$attrs = self::parse_tag_attributes( $tag_text );
		$name  = isset( $attrs['name'] ) ? (string) $attrs['name'] : 'dynamic';
		$label = sanitize_text_field( str_replace( array( '-', '_' ), ' ', $name ) );
		return sprintf(
			/* translators: %s: Elementor dynamic tag name */
			__( 'Elementor tag: %s', 'tso-link-inspector' ),
			$label
		);
	}

	/**
	 * Tag link type.
	 *
	 * @param string $tag_text Tag markup.
	 * @return string
	 */
	private static function tag_link_type( $tag_text ) {
		$attrs = self::parse_tag_attributes( $tag_text );
		$name  = isset( $attrs['name'] ) ? sanitize_key( (string) $attrs['name'] ) : '';
		if ( false !== strpos( $name, 'image' ) || false !== strpos( $name, 'gallery' ) ) {
			return 'image';
		}
		return 'link';
	}

	/**
	 * Push item.
	 *
	 * @param array  $out     Items (by ref).
	 * @param string $url     URL.
	 * @param string $anchor  Anchor.
	 * @param string $type    link|image.
	 * @param int    $post_id Post ID.
	 * @param string $raw_tag Raw tag text.
	 * @return void
	 */
	private static function push_item( array &$out, $url, $anchor, $type, $post_id, $raw_tag ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url || ! preg_match( '#\Ahttps?://#i', $url ) ) {
			return;
		}
		$db = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->db : null;
		$sk = 'eltag-' . md5( (string) $raw_tag . '|' . $url );
		if ( $db ) {
			$sk = $db->sanitize_source_key( $sk );
		}
		$out[] = array(
			'url'        => $url,
			'anchor'     => sanitize_text_field( (string) $anchor ),
			'type'       => ( 'image' === $type ) ? 'image' : 'link',
			'source_key' => $sk,
			'post_id'    => absint( $post_id ),
		);
	}
}
