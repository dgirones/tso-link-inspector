<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * HTML email notifications for broken links.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Email
 */
class TSOLIIN_Email {

	/**
	 * Send a broken-links report as HTML.
	 *
	 * @param string $to         Recipient email.
	 * @param string $subject    Email subject (already translated).
	 * @param string $intro      Intro paragraph (plain text, escaped in template).
	 * @param array  $items      List of rows: link_url, status_code, post_id, post_title.
	 * @param int    $more_count Additional broken links not listed (digest only).
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send_broken_links_report( $to, $subject, $intro, array $items, $more_count = 0 ) {
		$to = sanitize_email( (string) $to );
		if ( '' === $to || empty( $items ) ) {
			return false;
		}

		$html = self::build_broken_links_html( $intro, $items, $more_count );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );

		return (bool) $sent;
	}

	/**
	 * Display name for outgoing plugin emails.
	 *
	 * @return string
	 */
	public static function mail_from_name() {
		return 'TSO Link Inspector';
	}

	/**
	 * Normalize a queue item or DB row into a standard item array.
	 *
	 * @param array|object $row Raw item.
	 * @return array
	 */
	public static function normalize_broken_item( $row ) {
		if ( is_object( $row ) ) {
			$row = (array) $row;
		}
		$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
		$title   = isset( $row['post_title'] ) ? (string) $row['post_title'] : '';
		if ( '' === $title && $post_id > 0 ) {
			$title = (string) get_the_title( $post_id );
		}
		return array(
			'link_url'    => isset( $row['link_url'] ) ? (string) $row['link_url'] : '',
			'status_code' => isset( $row['status_code'] ) ? (int) $row['status_code'] : 0,
			'post_id'     => $post_id,
			'post_title'  => $title,
		);
	}

	/**
	 * Admin URL for the broken-links filter tab.
	 *
	 * @return string
	 */
	public static function inspector_broken_url() {
		return admin_url( 'tools.php?page=tso-link-inspector&filter=broken' );
	}

	/**
	 * Build broken links HTML.
	 *
	 * @param string $intro      Intro text.
	 * @param array  $items      Normalized items.
	 * @param int    $more_count Extra rows not shown.
	 * @return string HTML document.
	 */
	private static function build_broken_links_html( $intro, array $items, $more_count ) {
		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url    = home_url( '/' );
		$inspector   = self::inspector_broken_url();
		$brand       = 'TSO Link Inspector';
		$btn_label   = __( 'Open broken links in inspector', 'tso-link-inspector' );
		$footer_line = sprintf(
			/* translators: 1: plugin name, 2: site name */
			__( 'This notification was sent by %1$s on %2$s.', 'tso-link-inspector' ),
			$brand,
			$site_name
		);

		$rows_html = '';
		foreach ( $items as $raw ) {
			$item        = self::normalize_broken_item( $raw );
			$link_url    = $item['link_url'];
			$status_code = (int) $item['status_code'];
			$post_id     = (int) $item['post_id'];
			$post_title  = $item['post_title'];

			if ( '' === $link_url ) {
				continue;
			}

			$status_label = TSOLIIN_HTTP::status_label( $status_code, $link_url );
			$url_display  = self::truncate_url( $link_url, 72 );

			$post_block = '';
			if ( $post_id > 0 ) {
				$edit_url = get_edit_post_link( $post_id, 'raw' );
				if ( $edit_url ) {
					$title_text = '' !== $post_title ? $post_title : sprintf(
						/* translators: %d: post ID */
						__( 'Post #%d', 'tso-link-inspector' ),
						$post_id
					);
					$post_block = '<p style="margin:8px 0 0;font-size:13px;color:#50575e;">'
						. esc_html__( 'Article:', 'tso-link-inspector' ) . ' '
						. '<a href="' . esc_url( $edit_url ) . '" style="color:#1d4ed8;text-decoration:none;">'
						. esc_html( $title_text ) . '</a></p>';
				}
			}

			$rows_html .= '<tr><td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;">'
				. '<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.02em;">'
				. esc_html__( 'Broken URL:', 'tso-link-inspector' ) . '</p>'
				. '<p style="margin:0;font-size:14px;line-height:1.45;word-break:break-all;">'
				. '<a href="' . esc_url( $link_url ) . '" style="color:#1d4ed8;text-decoration:underline;">'
				. esc_html( $url_display ) . '</a></p>'
				. '<p style="margin:8px 0 0;font-size:13px;color:#50575e;">'
				. esc_html__( 'Status code:', 'tso-link-inspector' ) . ' '
				. '<span style="display:inline-block;background:#fee2e2;color:#7f1d1d;padding:2px 8px;border-radius:4px;font-weight:600;">'
				. esc_html( (string) $status_code ) . ' — ' . esc_html( $status_label )
				. '</span></p>'
				. $post_block
				. '</td></tr>';
		}

		$more_html = '';
		if ( $more_count > 0 ) {
			$more_html = '<p style="margin:16px 0 0;font-size:13px;color:#646970;font-style:italic;">'
				. esc_html(
					sprintf(
						/* translators: %d: extra rows not listed */
						__( '...and %d more broken links.', 'tso-link-inspector' ),
						$more_count
					)
				)
				. '</p>';
		}

		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #dcdcde;">'
			. '<tr><td style="background:linear-gradient(135deg,#1e40af 0%,#1d4ed8 100%);color:#ffffff;padding:22px 24px;">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.9;">' . esc_html( $brand ) . '</p>'
			. '<h1 style="margin:0;font-size:22px;font-weight:700;line-height:1.3;">' . esc_html__( 'Broken links report', 'tso-link-inspector' ) . '</h1>'
			. '<p style="margin:10px 0 0;font-size:14px;opacity:.92;"><a href="' . esc_url( $site_url ) . '" style="color:#ffffff;text-decoration:underline;">'
			. esc_html( $site_name ) . '</a></p>'
			. '</td></tr>'
			. '<tr><td style="padding:24px 24px 8px;">'
			. '<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#1d2327;">' . esc_html( $intro ) . '</p>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">'
			. $rows_html
			. '</table>'
			. $more_html
			. '<p style="margin:24px 0 0;text-align:center;">'
			. '<a href="' . esc_url( $inspector ) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 22px;border-radius:6px;">'
			. esc_html( $btn_label ) . '</a></p>'
			. '</td></tr>'
			. '<tr><td style="padding:16px 24px 20px;background:#f6f7f7;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#646970;text-align:center;">'
			. esc_html( $footer_line )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return $html;
	}

	/**
	 * Shorten long URLs for display (full href unchanged).
	 *
	 * @param string $url     URL.
	 * @param int    $max_len Max characters.
	 * @return string
	 */
	private static function truncate_url( $url, $max_len ) {
		$url = (string) $url;
		if ( strlen( $url ) <= $max_len ) {
			return $url;
		}
		return substr( $url, 0, $max_len - 1 ) . '…';
	}
}
