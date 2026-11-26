<?php
/**
 * Optional synchronization of public content to the Answely Push API.
 *
 * Off by default. Only published, public, password-free content of the
 * selected post types without noindex flag is sent; changes, permalink
 * changes and withdrawals are delivered through WP-Cron with retries.
 *
 * Stored state (option "answely_sync", not autoloaded). The German keys are
 * kept from version 1.x so that existing queues continue:
 *   an           bool   synchronization switched on
 *   types        array  selected post types
 *   pfade        array  excluded URL path prefixes
 *   queue        array  post ID => time it was queued
 *   gesendet     array  post ID => URL accepted by Answely
 *   failed       array  post ID => error code of a rejected document
 *   vollseite    int    page of the running full scan (0 = none)
 *   pruef_ids    array  previously sent IDs to re-check after a settings change
 *   fehler       string last error code ('' = none)
 *   versuche     int    consecutive failed runs (backoff)
 *   bindung      string service|site key the documents belong to
 *
 * @package Answely
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ANSWELY_SYNC_OPTION = 'answely_sync';
const ANSWELY_SYNC_BATCH  = 5;
const ANSWELY_SYNC_MAX    = 1000;

/**
 * Stored synchronization state.
 *
 * @return array
 */
function answely_sync_lies() {
	$v = get_option( ANSWELY_SYNC_OPTION, array() );
	return is_array( $v ) ? $v : array();
}

/**
 * Saves the synchronization state (never autoloaded).
 *
 * @param array $s State.
 */
function answely_sync_schreib( array $s ) {
	update_option( ANSWELY_SYNC_OPTION, $s, false );
}

/**
 * Push API base address.
 *
 * @return string
 */
function answely_sync_api() {
	return answely_service()['api'];
}

/**
 * Is the push token defined in wp-config.php?
 *
 * @return bool
 */
function answely_sync_token_constant() {
	return defined( 'ANSWELY_PUSH_TOKEN' ) && is_string( ANSWELY_PUSH_TOKEN ) && '' !== ANSWELY_PUSH_TOKEN;
}

/**
 * Push token: the ANSWELY_PUSH_TOKEN constant if defined, else the token
 * saved on the settings page. Never printed to a page.
 *
 * @return string
 */
function answely_sync_token() {
	$t = answely_sync_token_constant() ? ANSWELY_PUSH_TOKEN : get_option( 'answely_push_token', '' );
	return is_string( $t ) && 1 === preg_match( '/^[A-Za-z0-9_-]{32,200}$/D', $t ) ? $t : '';
}

/**
 * Public post types that can be synchronized.
 *
 * @return array<string,string> name => label
 */
function answely_sync_available_types() {
	$out = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
		if ( 'attachment' === $name ) {
			continue;
		}
		$out[ $name ] = $obj->labels->name;
	}
	return $out;
}

/**
 * Selected post types (default: posts, pages and WooCommerce products).
 *
 * @param array|null $s State.
 * @return string[]
 */
function answely_sync_types( $s = null ) {
	$s = is_array( $s ) ? $s : answely_sync_lies();
	if ( isset( $s['types'] ) && is_array( $s['types'] ) ) {
		return array_values( array_filter( $s['types'], 'is_string' ) );
	}
	return array( 'post', 'page', 'product' );
}

/**
 * Why a post is not sent ('' = it is sent).
 *
 * @param WP_Post|null $p Post.
 * @param array|null   $s State.
 * @return string Reason code.
 */
function answely_sync_grund( $p, $s = null ) {
	$s = is_array( $s ) ? $s : answely_sync_lies();
	if ( ! $p || ! is_object( $p ) ) {
		return 'missing';
	}
	if ( 'publish' !== $p->post_status ) {
		return 'not_published';
	}
	if ( '' !== $p->post_password ) {
		return 'password';
	}
	if ( ! (int) get_option( 'blog_public', 1 ) ) {
		return 'site_hidden';
	}
	if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $p ) ) {
		return 'not_public';
	}
	$type = get_post_type_object( $p->post_type );
	if ( ! $type || ! $type->public || ! in_array( $p->post_type, answely_sync_types( $s ), true ) ) {
		return 'type';
	}
	if ( '1' === (string) get_post_meta( $p->ID, '_answely_exclude', true ) ) {
		return 'excluded';
	}
	if ( '1' === (string) get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
		return 'noindex';
	}
	$rank = get_post_meta( $p->ID, 'rank_math_robots', true );
	if ( is_array( $rank ) && in_array( 'noindex', $rank, true ) ) {
		return 'noindex';
	}
	if ( 'yes' === (string) get_post_meta( $p->ID, '_seopress_robots_index', true ) ) {
		return 'noindex';
	}
	$path = (string) wp_parse_url( (string) get_permalink( $p ), PHP_URL_PATH );
	foreach ( isset( $s['pfade'] ) && is_array( $s['pfade'] ) ? $s['pfade'] : array() as $prefix ) {
		if ( is_string( $prefix ) && '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
			return 'path';
		}
	}
	/**
	 * Filters whether a public post may be sent to Answely. Return false for
	 * content behind a membership or paywall plugin.
	 *
	 * @param bool    $public Whether the post may be sent.
	 * @param WP_Post $post   The post.
	 */
	if ( ! apply_filters( 'answely_sync_oeffentlich', true, $p ) ) {
		return 'filter';
	}
	return '';
}

/**
 * May the post be sent?
 *
 * @param WP_Post|null $p Post.
 * @return bool
 */
function answely_sync_oeffentlich( $p ) {
	return '' === answely_sync_grund( $p );
}

/**
 * Human-readable reason.
 *
 * @param string $code Reason code.
 * @return string
 */
function answely_sync_grund_text( $code ) {
	$map = array(
		'missing'       => __( 'The content no longer exists.', 'answely' ),
		'not_published' => __( 'Not published.', 'answely' ),
		'password'      => __( 'Password protected.', 'answely' ),
		'site_hidden'   => __( 'The site asks search engines not to index it (Settings → Reading).', 'answely' ),
		'not_public'    => __( 'Not publicly viewable.', 'answely' ),
		'type'          => __( 'This content type is not selected for synchronization.', 'answely' ),
		'excluded'      => __( 'Excluded from Answely on this page.', 'answely' ),
		'noindex'       => __( 'Marked as noindex by an SEO plugin.', 'answely' ),
		'path'          => __( 'The address matches an excluded path.', 'answely' ),
		'filter'        => __( 'Excluded by another plugin (filter answely_sync_oeffentlich).', 'answely' ),
		'empty'         => __( 'No text content.', 'answely' ),
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : $code;
}

/**
 * Plain text in the Push API format: paragraphs separated by blank lines,
 * headings as "#" lines with optional {#anchor}. Shortcodes are removed, not
 * executed.
 *
 * @param string $html Post content.
 * @return string
 */
function answely_sync_text( $html ) {
	$html = strip_shortcodes( (string) $html );
	$html = preg_replace( '~<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>~is', ' ', $html );
	$html = preg_replace( '~<!--.*?-->~s', ' ', $html );
	$html = preg_replace_callback(
		'~<h([1-6])\b([^>]*)>(.*?)</h\1>~is',
		static function ( $m ) {
			$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $t ) {
				return "\n\n";
			}
			$anchor = preg_match( '~\sid=["\']([A-Za-z][A-Za-z0-9_-]{0,80})["\']~', $m[2], $a ) ? ' {#' . $a[1] . '}' : '';
			return "\n\n\x02" . str_repeat( '#', (int) $m[1] ) . ' ' . $t . $anchor . "\n\n";
		},
		$html
	);
	$html = preg_replace( '~(</(?:p|div|li|ul|ol|table|tr|blockquote|section|article|figure|figcaption|pre|dd|dt)>|<br\s*/?>)~i', "$1\n\n", $html );
	$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES, 'UTF-8' );

	$out   = array();
	$blank = true;
	foreach ( preg_split( '/\R/u', $text ) as $line ) {
		$line = trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
		if ( '' === $line ) {
			if ( ! $blank ) {
				$out[] = '';
			}
			$blank = true;
			continue;
		}
		if ( "\x02" === $line[0] ) {
			$line = substr( $line, 1 );
		} else {
			// Only our own headings may start with "#".
			$line = ltrim( $line, "#\x02 " );
			if ( '' === $line ) {
				continue;
			}
		}
		$out[] = $line;
		$blank = false;
	}
	return trim( implode( "\n", $out ) );
}

/**
 * Two-letter language of a post (Polylang, WPML, else the site language).
 *
 * @param int $id Post ID.
 * @return string
 */
function answely_sync_language( $id ) {
	$lang = '';
	if ( function_exists( 'pll_get_post_language' ) ) {
		$lang = (string) pll_get_post_language( $id, 'slug' );
	}
	if ( '' === $lang ) {
		$wpml = apply_filters( 'wpml_post_language_details', null, $id );
		if ( is_array( $wpml ) && ! empty( $wpml['language_code'] ) ) {
			$lang = (string) $wpml['language_code'];
		}
	}
	if ( '' === $lang ) {
		$lang = get_locale();
	}
	$lang = strtolower( substr( $lang, 0, 2 ) );
	return 1 === preg_match( '/^[a-z]{2}$/D', $lang ) ? $lang : 'en';
}

/**
 * Cuts UTF-8 text to at most $bytes bytes without splitting a character.
 * WordPress has no fallback for mb_strcut(), so without mbstring the last
 * (possibly incomplete) multibyte character is dropped instead.
 *
 * @param string $text  Text.
 * @param int    $bytes Maximum length in bytes.
 * @return string
 */
function answely_sync_cut( $text, $bytes ) {
	if ( strlen( $text ) <= $bytes ) {
		return $text;
	}
	if ( function_exists( 'mb_strcut' ) ) {
		return mb_strcut( $text, 0, $bytes, 'UTF-8' );
	}
	return (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $text, 0, $bytes ) );
}

/**
 * The document sent for a post, or null if it has no text.
 *
 * @param WP_Post $p   Post.
 * @param string  $url Permalink.
 * @return array|null
 */
function answely_sync_dokument( $p, $url ) {
	$text = answely_sync_text( $p->post_content );
	if ( 'product' === $p->post_type && '' !== trim( (string) $p->post_excerpt ) ) {
		$text = trim( answely_sync_text( $p->post_excerpt ) . "\n\n" . $text );
	}
	if ( '' === $text ) {
		return null;
	}
	$doc = array(
		'url'          => $url,
		'titel'        => mb_substr( trim( html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ) ), 0, 300 ),
		'text'         => answely_sync_cut( $text, 900000 ),
		'sprache'      => answely_sync_language( $p->ID ),
		'sichtbarkeit' => 'oeffentlich',
	);
	/**
	 * Filters the document sent to Answely. Return null to skip the post.
	 * Only public content may be sent: the Answely answer API is public.
	 *
	 * @param array|null $doc  Document: url, titel, text, sprache, sichtbarkeit.
	 * @param WP_Post    $post The post.
	 */
	$doc = apply_filters( 'answely_sync_document', $doc, $p );
	if ( ! is_array( $doc ) || empty( $doc['text'] ) || ! is_string( $doc['text'] ) ) {
		return null;
	}
	$doc['url'] = $url; // The address cannot be changed by the filter.
	return $doc;
}

/**
 * Schedules a synchronization run.
 *
 * @param int $nach Seconds from now.
 */
function answely_sync_plan( $nach = 5 ) {
	if ( ! wp_next_scheduled( 'answely_sync_lauf' ) ) {
		wp_schedule_single_event( time() + (int) $nach, 'answely_sync_lauf' );
	}
}

/**
 * Queues a post after a change (only when synchronization is on or the post
 * was sent before, so that a withdrawal can be delivered).
 *
 * @param int $id Post ID.
 */
function answely_sync_vormerken( $id ) {
	$id = (int) $id;
	if ( $id <= 0 || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
		return;
	}
	$s = answely_sync_lies();
	if ( empty( $s['an'] ) && empty( $s['gesendet'][ $id ] ) ) {
		return;
	}
	$s['queue'] = isset( $s['queue'] ) && is_array( $s['queue'] ) ? $s['queue'] : array();
	if ( count( $s['queue'] ) >= ANSWELY_SYNC_MAX && ! isset( $s['queue'][ $id ] ) ) {
		$s['fehler'] = 'queue_voll';
		answely_sync_schreib( $s );
		return;
	}
	$s['queue'][ $id ] = microtime( true );
	answely_sync_schreib( $s );
	answely_sync_plan();
}

/**
 * Queues a deleted or trashed post.
 *
 * @param int $id Post ID.
 */
function answely_sync_entfernen( $id ) {
	answely_sync_vormerken( $id );
}

/**
 * Cron run with a lock against parallel runs.
 */
function answely_sync_lauf() {
	if ( ! add_option( 'answely_sync_sperre', time() + 300, '', 'no' ) ) {
		if ( (int) get_option( 'answely_sync_sperre', 0 ) < time() ) {
			delete_option( 'answely_sync_sperre' );
		}
		answely_sync_plan( 60 );
		return;
	}
	try {
		answely_sync_arbeit();
	} finally {
		delete_option( 'answely_sync_sperre' );
	}
}

/**
 * Error code of a Push API response body.
 *
 * @param array|WP_Error $res Response.
 * @return string
 */
function answely_sync_error_code( $res ) {
	if ( is_wp_error( $res ) ) {
		return 'netz';
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$err  = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) && 1 === preg_match( '/^[a-z_]{1,40}$/D', $body['error'] ) ? '_' . $body['error'] : '';
	return 'http_' . $code . $err;
}

/**
 * One request to the Push API.
 *
 * @param string $method  POST|DELETE|GET.
 * @param string $key     Site key.
 * @param string $token   Push token.
 * @param array  $body    JSON body (POST).
 * @param string $url     Document address (DELETE/GET).
 * @return array|WP_Error
 */
function answely_sync_request( $method, $key, $token, array $body = array(), $url = '' ) {
	$addr = answely_sync_api() . '/v1/sites/' . rawurlencode( $key ) . '/dokumente';
	if ( '' !== $url ) {
		$addr .= '?url=' . rawurlencode( $url );
	}
	$args = array(
		'method'      => $method,
		'timeout'     => 8,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);
	if ( 'POST' === $method ) {
		$args['body'] = wp_json_encode( $body );
	}
	return wp_remote_request( $addr, $args );
}

/**
 * Processes up to ANSWELY_SYNC_BATCH queued posts.
 */
function answely_sync_arbeit() {
	$s          = answely_sync_lies();
	$basis_q    = isset( $s['queue'] ) && is_array( $s['queue'] ) ? $s['queue'] : array();
	$basis_an   = ! empty( $s['an'] );
	$basis_voll = (int) ( $s['vollseite'] ?? 0 );
	$e          = answely_einstellungen();
	$token      = answely_sync_token();

	if ( empty( $s['an'] ) && empty( $basis_q ) && ! isset( $s['pruef_ids'] ) ) {
		return; // Nothing to send and nothing to withdraw.
	}
	if ( ! $token || ! $e['key'] ) {
		$s['fehler'] = 'verbindung_fehlt';
		answely_sync_schreib( $s );
		return;
	}
	$bindung = answely_sync_api() . '|' . $e['key'];
	if ( ! empty( $s['bindung'] ) && $s['bindung'] !== $bindung ) {
		$s['fehler'] = 'verbindung_geaendert';
		answely_sync_schreib( $s );
		return;
	}
	$s['bindung'] = $bindung;
	$s['queue']   = $basis_q;
	$s['failed']  = isset( $s['failed'] ) && is_array( $s['failed'] ) ? $s['failed'] : array();

	// Re-check previously sent posts after a settings change.
	if ( isset( $s['pruef_ids'] ) && count( $s['queue'] ) < 100 ) {
		$cursor = (int) ( $s['pruef_cursor'] ?? 0 );
		$batch  = array_slice( (array) $s['pruef_ids'], $cursor, 20 );
		foreach ( $batch as $id ) {
			$s['queue'][ (int) $id ] = microtime( true );
		}
		$s['pruef_cursor'] = $cursor + count( $batch );
		if ( $s['pruef_cursor'] >= count( (array) $s['pruef_ids'] ) ) {
			unset( $s['pruef_ids'], $s['pruef_cursor'] );
		}
	}

	// Full scan after switching on or changing the selection.
	if ( ! empty( $s['vollseite'] ) && ! empty( $s['an'] ) && count( $s['queue'] ) < 100 ) {
		$ids = get_posts(
			array(
				'post_type'        => answely_sync_types( $s ),
				'post_status'      => 'publish',
				'posts_per_page'   => 20,
				'paged'            => (int) $s['vollseite'],
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $ids as $id ) {
			if ( count( $s['queue'] ) >= ANSWELY_SYNC_MAX ) {
				$s['fehler'] = 'queue_voll';
				answely_sync_schreib( $s );
				answely_sync_plan( 60 );
				return;
			}
			$s['queue'][ (int) $id ] = microtime( true );
		}
		$s['vollseite'] = 20 === count( $ids ) ? (int) $s['vollseite'] + 1 : 0;
	}

	$s['fehler'] = '';
	$n           = 0;
	foreach ( $s['queue'] as $id => $eingang ) {
		if ( ++$n > ANSWELY_SYNC_BATCH ) {
			break;
		}
		$id  = (int) $id;
		$p   = get_post( $id );
		$url = $p ? get_permalink( $id ) : false;
		$doc = null;
		if ( ! empty( $s['an'] ) && '' === answely_sync_grund( $p, $s ) && is_string( $url ) ) {
			$doc = answely_sync_dokument( $p, $url );
		}
		if ( answely_einstellungen()['key'] !== $e['key'] ) {
			$s['fehler'] = 'verbindung_geaendert';
			break;
		}
		$alt = $s['gesendet'][ $id ] ?? '';

		// Withdrawal or permalink change: remove the old document first.
		if ( $alt && ( ! $doc || $alt !== $url ) ) {
			$res  = answely_sync_request( 'DELETE', $e['key'], $token, array(), $alt );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( ! in_array( $code, array( 200, 202, 204, 404 ), true ) ) {
				$s['fehler'] = 'loeschung_' . answely_sync_error_code( $res );
				break;
			}
			unset( $s['gesendet'][ $id ] );
		}

		if ( $doc ) {
			if ( ! preg_match( '~^https://~', $url ) || null !== wp_parse_url( $url, PHP_URL_QUERY ) ) {
				// Push needs a stable HTTPS address without parameters.
				$s['failed'][ $id ] = 'url';
				unset( $s['queue'][ $id ] );
				continue;
			}
			$res  = answely_sync_request( 'POST', $e['key'], $token, $doc );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( in_array( $code, array( 400, 413 ), true ) ) {
				// This document is rejected; the others can still go.
				$s['failed'][ $id ] = answely_sync_error_code( $res );
				unset( $s['queue'][ $id ] );
				continue;
			}
			if ( ! in_array( $code, array( 200, 202 ), true ) ) {
				$s['fehler'] = 'uebertragung_' . answely_sync_error_code( $res );
				break;
			}
			$s['gesendet'][ $id ] = $url;
		}
		unset( $s['queue'][ $id ], $s['failed'][ $id ] );
		$s['letzte_annahme'] = time();
	}
	if ( count( $s['failed'] ) > 200 ) {
		$s['failed'] = array_slice( $s['failed'], -200, null, true );
	}

	// Changes queued while the HTTP requests ran must not get lost.
	$aktuell   = answely_sync_lies();
	$typen_neu = isset( $aktuell['types'] ) && ( $s['types'] ?? null ) !== $aktuell['types'];
	if ( ( ! empty( $aktuell['an'] ) !== $basis_an ) || $typen_neu ) {
		foreach ( array( 'an', 'types', 'pfade', 'vollseite' ) as $k ) {
			if ( array_key_exists( $k, $aktuell ) ) {
				$s[ $k ] = $aktuell[ $k ];
			}
		}
		$s['pruef_ids']    = array_keys( $s['gesendet'] ?? array() );
		$s['pruef_cursor'] = 0;
	} elseif ( (int) ( $aktuell['vollseite'] ?? 0 ) !== $basis_voll ) {
		$s['vollseite']    = $aktuell['vollseite'];
		$s['pruef_ids']    = array_keys( $s['gesendet'] ?? array() );
		$s['pruef_cursor'] = 0;
	}
	if ( isset( $aktuell['pfade'] ) ) {
		$s['pfade'] = $aktuell['pfade'];
	}
	foreach ( isset( $aktuell['queue'] ) && is_array( $aktuell['queue'] ) ? $aktuell['queue'] : array() as $id => $t ) {
		if ( ! isset( $basis_q[ $id ] ) || $t !== $basis_q[ $id ] ) {
			$s['queue'][ $id ] = $t;
		}
	}
	$s['versuche'] = ! empty( $s['fehler'] ) ? min( 8, (int) ( $s['versuche'] ?? 0 ) + 1 ) : 0;
	answely_sync_schreib( $s );
	if ( ! empty( $s['queue'] ) || ! empty( $s['vollseite'] ) || isset( $s['pruef_ids'] ) ) {
		answely_sync_plan( empty( $s['fehler'] ) ? 5 : min( 3600, 30 * ( 2 ** $s['versuche'] ) ) );
	}
}

/**
 * Human-readable text of a synchronization error code.
 *
 * @param string $code Error code.
 * @return string
 */
function answely_sync_fehler_text( $code ) {
	$code = (string) $code;
	if ( '' === $code ) {
		return '';
	}
	if ( 'verbindung_fehlt' === $code ) {
		return __( 'Site key or push token missing.', 'answely' );
	}
	if ( 'verbindung_geaendert' === $code ) {
		return __( 'The site key changed since content was sent. Documents already sent belong to the previous Answely website. Reset the synchronization state to start over with the new site key.', 'answely' );
	}
	if ( 'queue_voll' === $code ) {
		return __( 'The queue is full (1,000 items). It continues automatically as items are delivered.', 'answely' );
	}
	if ( 'url' === $code ) {
		return __( 'The address is not HTTPS or contains parameters. Content sync needs HTTPS and pretty permalinks.', 'answely' );
	}
	$texts = array(
		'netz'                   => __( 'Answely could not be reached. Delivery is retried automatically.', 'answely' ),
		'http_0'                 => __( 'Answely could not be reached. Delivery is retried automatically.', 'answely' ),
		'http_401'               => __( 'The push token is wrong. Create a new token in your Answely account (Website → Index) and save it here.', 'answely' ),
		'http_403_push_aus'      => __( 'Push is not available: no push token is set in your Answely account, or your plan does not include push (Pro or higher).', 'answely' ),
		'http_403_gesperrt'      => __( 'This website is blocked in Answely.', 'answely' ),
		'http_409_seitenlimit'   => __( 'The page limit of your plan is reached.', 'answely' ),
		'http_409_kein_nachweis' => __( 'The domain of this website is not verified in your Answely account yet.', 'answely' ),
		'http_409_motor'         => __( 'Push is not available for this website type.', 'answely' ),
		'http_413'               => __( 'The document is larger than 1 MB.', 'answely' ),
		'http_429'               => __( 'Too many requests. Delivery continues automatically after a pause.', 'answely' ),
	);
	$plain = preg_replace( '/^(uebertragung|loeschung)_/', '', $code );
	if ( isset( $texts[ $plain ] ) ) {
		return $texts[ $plain ];
	}
	$short = preg_replace( '/^(http_\d+).*/', '$1', $plain );
	if ( isset( $texts[ $short ] ) ) {
		return $texts[ $short ];
	}
	if ( preg_match( '/^http_(5\d\d)/', $plain ) ) {
		return __( 'Answely is temporarily unavailable. Delivery is retried automatically.', 'answely' );
	}
	if ( preg_match( '/^http_400/', $plain ) ) {
		return __( 'Answely rejected this document (invalid address, title, text or language).', 'answely' );
	}
	/* translators: %s: technical error code */
	return sprintf( __( 'Delivery failed (%s). It is retried automatically.', 'answely' ), $code );
}

/**
 * Settings form handler (admin-post.php?action=answely_sync).
 */
function answely_sync_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'answely' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'answely_sync' );

	$s          = answely_sync_lies();
	$vorher     = array( ! empty( $s['an'] ), answely_sync_types( $s ), $s['pfade'] ?? array() );
	$s['an']    = ! empty( $_POST['an'] );
	$verfuegbar = array_keys( answely_sync_available_types() );
	$types      = isset( $_POST['types'] ) && is_array( $_POST['types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['types'] ) ) : array();
	$s['types'] = array_values( array_intersect( $types, $verfuegbar ) );

	$paths = isset( $_POST['pfade'] ) && is_string( $_POST['pfade'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pfade'] ) ) : '';
	$list  = array();
	foreach ( preg_split( '/\R/', $paths ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$path = (string) wp_parse_url( $line, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $path && count( $list ) < 50 ) {
			$list[] = $path;
		}
	}
	$s['pfade'] = array_values( array_unique( $list ) );

	if ( ! answely_sync_token_constant() ) {
		if ( ! empty( $_POST['token_remove'] ) ) {
			delete_option( 'answely_push_token' );
		}
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' !== $token ) {
			if ( 1 === preg_match( '/^[A-Za-z0-9_-]{32,200}$/D', $token ) ) {
				update_option( 'answely_push_token', $token, false );
			} else {
				answely_flash( 'error', __( 'The push token has an invalid format and was not saved.', 'answely' ) );
				answely_sync_schreib( $s );
				wp_safe_redirect( admin_url( 'options-general.php?page=answely#sync' ) );
				exit;
			}
		}
	}

	if ( array( $s['an'], answely_sync_types( $s ), $s['pfade'] ) !== $vorher || ! empty( $_POST['rescan'] ) ) {
		$s['vollseite']    = $s['an'] ? 1 : 0;
		$s['pruef_ids']    = array_keys( $s['gesendet'] ?? array() );
		$s['pruef_cursor'] = 0;
		$s['failed']       = array();
	}
	if ( ! empty( $s['an'] ) && ! answely_sync_token() ) {
		answely_flash( 'warning', __( 'Settings saved. Synchronization starts as soon as a push token is set.', 'answely' ) );
	} else {
		answely_flash( 'success', $s['an'] ? __( 'Settings saved. Content is being sent in the background.', 'answely' ) : __( 'Settings saved.', 'answely' ) );
	}
	answely_sync_schreib( $s );
	answely_sync_plan( 2 );
	wp_safe_redirect( admin_url( 'options-general.php?page=answely#sync' ) );
	exit;
}
add_action( 'admin_post_answely_sync', 'answely_sync_start' );

/**
 * Resets the local state after a site key change (admin-post.php?action=answely_sync_reset).
 * Documents already sent stay at the previous Answely website.
 */
function answely_sync_reset() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'answely' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'answely_sync_reset' );
	if ( get_option( 'answely_sync_sperre' ) ) {
		answely_flash( 'warning', __( 'A delivery is running right now. Try again in a minute.', 'answely' ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=answely#sync' ) );
		exit;
	}
	$s = answely_sync_lies();
	foreach ( array( 'queue', 'gesendet', 'failed', 'bindung', 'fehler', 'versuche', 'pruef_ids', 'pruef_cursor', 'letzte_annahme' ) as $k ) {
		unset( $s[ $k ] );
	}
	$s['vollseite'] = ! empty( $s['an'] ) ? 1 : 0;
	answely_sync_schreib( $s );
	answely_sync_plan( 2 );
	answely_flash( 'success', __( 'Synchronization state reset.', 'answely' ) );
	wp_safe_redirect( admin_url( 'options-general.php?page=answely#sync' ) );
	exit;
}
add_action( 'admin_post_answely_sync_reset', 'answely_sync_reset' );

/**
 * AJAX: process the queue now (for sites where WP-Cron runs rarely).
 */
function answely_ajax_sync_now() {
	check_ajax_referer( 'answely_admin', 'nonce' );
	answely_ajax_guard();
	$ende = time() + 20;
	do {
		answely_sync_lauf();
		$s = answely_sync_lies();
	} while ( time() < $ende && empty( $s['fehler'] ) && ( ! empty( $s['queue'] ) || ! empty( $s['vollseite'] ) || isset( $s['pruef_ids'] ) ) );

	$pending = count( $s['queue'] ?? array() );
	$out     = array(
		'level'   => empty( $s['fehler'] ) ? 'ok' : 'bad',
		'message' => empty( $s['fehler'] )
			/* translators: 1: number of accepted documents, 2: number of pending items */
			? sprintf( __( 'Done for now. Accepted documents: %1$d, still pending: %2$d.', 'answely' ), count( $s['gesendet'] ?? array() ), $pending )
			: answely_sync_fehler_text( $s['fehler'] ),
		'details' => array(),
	);
	wp_send_json_success( $out );
}
add_action( 'wp_ajax_answely_sync_now', 'answely_ajax_sync_now' );

/**
 * AJAX: index status of pushed documents at Answely.
 */
function answely_ajax_index() {
	check_ajax_referer( 'answely_admin', 'nonce' );
	answely_ajax_guard();
	$e     = answely_einstellungen();
	$token = answely_sync_token();
	if ( ! $e['key'] || ! $token ) {
		wp_send_json_success(
			array(
				'level'   => 'bad',
				'message' => __( 'Site key or push token missing.', 'answely' ),
				'details' => array(),
			)
		);
	}
	$res  = answely_sync_request( 'GET', $e['key'], $token );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 === $code && is_array( $body ) && isset( $body['dokumente'] ) ) {
		$details = array(
			/* translators: %d: number */
			sprintf( __( 'Pushed documents in the index: %d', 'answely' ), (int) $body['dokumente'] ),
			/* translators: %d: number */
			sprintf( __( 'Waiting to be indexed: %d', 'answely' ), (int) ( $body['wartend'] ?? 0 ) ),
		);
		if ( isset( $body['grenze'] ) ) {
			/* translators: %d: number */
			$details[] = sprintf( __( 'Page limit of your plan: %d', 'answely' ), (int) $body['grenze'] );
		}
		wp_send_json_success(
			array(
				'level'   => 'ok',
				'message' => __( 'Index status from Answely:', 'answely' ),
				'details' => $details,
			)
		);
	}
	wp_send_json_success(
		array(
			'level'   => 'bad',
			'message' => answely_sync_fehler_text( answely_sync_error_code( $res ) ),
			'details' => array(),
		)
	);
}
add_action( 'wp_ajax_answely_index', 'answely_ajax_index' );

/**
 * Tab "Content sync".
 */
function answely_sync_seite() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = answely_sync_lies();
	$e       = answely_einstellungen();
	$types   = answely_sync_types( $s );
	$token   = answely_sync_token();
	$pending = count( $s['queue'] ?? array() );
	$sent    = count( $s['gesendet'] ?? array() );
	$failed  = isset( $s['failed'] ) && is_array( $s['failed'] ) ? $s['failed'] : array();

	if ( empty( $s['an'] ) ) {
		$pill = array( 'todo', 'controls-pause', __( 'Off', 'answely' ) );
	} elseif ( ! $token || ! $e['key'] ) {
		$pill = array( 'warn', 'warning', __( 'Waiting for push token', 'answely' ) );
	} elseif ( ! empty( $s['fehler'] ) ) {
		$pill = array( 'bad', 'dismiss', __( 'Delivery problem', 'answely' ) );
	} else {
		$pill = array( 'ok', 'yes-alt', __( 'On', 'answely' ) );
	}
	echo '<div class="answely-card"><h2 class="answely-card__title">' . esc_html__( 'Content sync', 'answely' );
	echo '<span class="answely-pill is-' . esc_attr( $pill[0] ) . '"><span class="dashicons dashicons-' . esc_attr( $pill[1] ) . '" aria-hidden="true"></span>' . esc_html( $pill[2] ) . '</span></h2>';
	echo '<p>' . esc_html__( 'Answely indexes your public pages by itself. With content sync, this site also sends new, changed and removed content right away through the Answely Push API, including content the crawler cannot read. Requires the Pro plan or higher and a verified domain.', 'answely' ) . '</p>';
	echo '<p>' . esc_html__( 'Only published, public content of the selected types is sent: no drafts, no private or password-protected content, nothing marked as noindex (Yoast SEO, Rank Math, SEOPress), nothing excluded on the page itself. Shortcodes are removed, not executed.', 'answely' ) . '</p>';

	$parts = array(
		array( 'ok', 'yes-alt', $sent, __( 'accepted documents', 'answely' ) ),
		array( 'pending', 'clock', $pending, __( 'pending', 'answely' ) ),
		array( 'bad', 'dismiss', count( $failed ), __( 'rejected', 'answely' ) ),
	);
	echo '<div class="answely-stats">';
	foreach ( $parts as $part ) {
		echo '<div class="answely-stat is-' . esc_attr( $part[0] ) . '"><span class="dashicons dashicons-' . esc_attr( $part[1] ) . '" aria-hidden="true"></span><strong>' . esc_html( number_format_i18n( $part[2] ) ) . '</strong>' . esc_html( $part[3] ) . '</div>';
	}
	echo '</div>';
	$all = $sent + $pending + count( $failed );
	if ( $all > 0 ) {
		/* translators: 1: accepted, 2: pending, 3: rejected */
		$label = sprintf( __( 'Share of documents: %1$d accepted, %2$d pending, %3$d rejected', 'answely' ), $sent, $pending, count( $failed ) );
		echo '<div class="answely-bar" role="img" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $parts as $part ) {
			if ( $part[2] > 0 ) {
				echo '<span class="answely-bar__part is-' . esc_attr( $part[0] ) . '" style="' . esc_attr( 'flex-grow:' . (int) $part[2] ) . '"></span>';
			}
		}
		echo '</div>';
	}
	if ( ! empty( $s['letzte_annahme'] ) ) {
		/* translators: %s: date and time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Last delivery: %s', 'answely' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $s['letzte_annahme'] ) ) ) . '</p>';
	}
	if ( ! empty( $s['fehler'] ) && ( ! empty( $s['an'] ) || $pending ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( answely_sync_fehler_text( $s['fehler'] ) ) . '</p></div>';
	}
	if ( $failed ) {
		echo '<details class="answely-rejected"><summary>' . esc_html__( 'Rejected content', 'answely' ) . '</summary>';
		echo '<table class="widefat striped answely-table"><thead><tr><th scope="col">' . esc_html__( 'Content', 'answely' ) . '</th><th scope="col">' . esc_html__( 'Reason', 'answely' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $failed, 0, 20, true ) as $id => $code ) {
			$title = get_the_title( (int) $id );
			$title = '' !== $title ? $title : '#' . (int) $id;
			$edit  = get_edit_post_link( (int) $id );
			echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td><td>' . esc_html( answely_sync_fehler_text( (string) $code ) ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
	}
	echo '<p class="answely-inline">';
	echo '<button type="button" class="button" data-answely-action="sync_now" aria-controls="answely-sync-out"' . disabled( ! $token || ! $e['key'], true, false ) . '><span class="dashicons dashicons-controls-play" aria-hidden="true"></span>' . esc_html__( 'Process queue now', 'answely' ) . '</button>';
	echo '<button type="button" class="button" data-answely-action="index" aria-controls="answely-sync-out"' . disabled( ! $token || ! $e['key'], true, false ) . '><span class="dashicons dashicons-search" aria-hidden="true"></span>' . esc_html__( 'Check index status', 'answely' ) . '</button>';
	echo '</p><div id="answely-sync-out" class="answely-result" role="status" hidden></div>';
	echo '<p class="description">' . esc_html__( '"Accepted" means Answely took the document; indexing usually follows within seconds. WP-Cron must run on this site for automatic delivery.', 'answely' ) . '</p>';
	echo '</div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="answely_sync">';
	wp_nonce_field( 'answely_sync' );
	echo '<div class="answely-card"><h2>' . esc_html__( 'Settings', 'answely' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Content sync', 'answely' ) . '</th><td><label><input type="checkbox" name="an" value="1"' . checked( ! empty( $s['an'] ), true, false ) . '> ' . esc_html__( 'Send public content to Answely', 'answely' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Switching off removes the documents this site has sent from Answely.', 'answely' ) . '</p></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Content types', 'answely' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Content types', 'answely' ) . '</legend>';
	foreach ( answely_sync_available_types() as $name => $label ) {
		echo '<label><input type="checkbox" name="types[]" value="' . esc_attr( $name ) . '"' . checked( in_array( $name, $types, true ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
	}
	echo '</fieldset></td></tr>';

	echo '<tr><th scope="row"><label for="answely-exclude">' . esc_html__( 'Excluded paths', 'answely' ) . '</label></th><td>';
	echo '<textarea id="answely-exclude" name="pfade" rows="4" class="large-text code" placeholder="/members/&#10;/shop/wholesale/">' . esc_textarea( implode( "\n", isset( $s['pfade'] ) && is_array( $s['pfade'] ) ? $s['pfade'] : array() ) ) . '</textarea>';
	echo '<p class="description">' . esc_html__( 'One path per line. Content whose address starts with one of these paths is not sent. Single pages can be excluded in the editor (box "Answely").', 'answely' ) . '</p></td></tr>';

	echo '<tr><th scope="row"><label for="answely-token">' . esc_html__( 'Push token', 'answely' ) . '</label></th><td>';
	if ( answely_sync_token_constant() ) {
		echo '<p>' . esc_html( $token ? __( 'Set in wp-config.php (ANSWELY_PUSH_TOKEN).', 'answely' ) : __( 'ANSWELY_PUSH_TOKEN in wp-config.php has an invalid format.', 'answely' ) ) . '</p>';
	} else {
		$saved = get_option( 'answely_push_token', '' );
		echo '<input type="password" id="answely-token" name="token" class="regular-text code" autocomplete="new-password" spellcheck="false" value="" placeholder="' . esc_attr( $token ? __( 'Saved – enter a new token to replace it', 'answely' ) : '' ) . '">';
		if ( is_string( $saved ) && '' !== $saved ) {
			echo ' <label><input type="checkbox" name="token_remove" value="1"> ' . esc_html__( 'Remove saved token', 'answely' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Create the token in your Answely account under Website → Index; Answely shows it only once. It is stored in the WordPress database and never shown again. Alternatively define ANSWELY_PUSH_TOKEN in wp-config.php.', 'answely' ) . '</p>';
	}
	echo '</td></tr></table>';
	echo '<p><label><input type="checkbox" name="rescan" value="1"> ' . esc_html__( 'Check all content again after saving', 'answely' ) . '</label></p>';
	submit_button( __( 'Save sync settings', 'answely' ) );
	echo '</div></form>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Before removing the plugin', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Switch content sync off and wait until "pending" is 0, so that the documents sent by this site are removed from Answely. Deleting the plugin removes its settings from WordPress but does not contact Answely.', 'answely' ) . '</p>';
	if ( ! empty( $s['bindung'] ) || $sent ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-answely-confirm="' . esc_attr__( 'Reset the synchronization state? This site forgets which documents it has sent; they stay at the previous Answely website and are not removed there.', 'answely' ) . '" data-answely-confirm-button="' . esc_attr__( 'Reset state', 'answely' ) . '"><input type="hidden" name="action" value="answely_sync_reset">';
		wp_nonce_field( 'answely_sync_reset' );
		submit_button( __( 'Reset synchronization state', 'answely' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Only needed after changing the site key. Forgets which documents were sent; they are not removed from the previous Answely website.', 'answely' ) . '</p></form>';
	}
	echo '</div>';
}

/**
 * Meta box "Answely" on synchronized content types.
 *
 * @param string $post_type Post type of the edit screen.
 */
function answely_sync_meta_box( $post_type ) {
	if ( ! in_array( $post_type, answely_sync_types(), true ) ) {
		return;
	}
	add_meta_box( 'answely-sync', 'Answely', 'answely_sync_meta_box_html', $post_type, 'side', 'low' );
}
add_action( 'add_meta_boxes', 'answely_sync_meta_box' );

/**
 * Content of the meta box.
 *
 * @param WP_Post $post Post.
 */
function answely_sync_meta_box_html( $post ) {
	$s = answely_sync_lies();
	wp_nonce_field( 'answely_exclude_' . $post->ID, 'answely_exclude_nonce' );
	echo '<p><label><input type="checkbox" name="answely_exclude" value="1"' . checked( '1', (string) get_post_meta( $post->ID, '_answely_exclude', true ), false ) . '> ' . esc_html__( 'Do not send to Answely', 'answely' ) . '</label></p>';
	if ( empty( $s['an'] ) ) {
		echo '<p class="description">' . esc_html__( 'Content sync is off.', 'answely' ) . '</p>';
		return;
	}
	if ( isset( $s['queue'][ $post->ID ] ) ) {
		$text = __( 'Queued for delivery.', 'answely' );
	} elseif ( ! empty( $s['gesendet'][ $post->ID ] ) ) {
		$text = __( 'Accepted by Answely.', 'answely' );
	} elseif ( isset( $s['failed'][ $post->ID ] ) ) {
		$text = answely_sync_fehler_text( (string) $s['failed'][ $post->ID ] );
	} else {
		$grund = answely_sync_grund( $post, $s );
		$text  = '' === $grund ? __( 'Will be sent after publishing or the next change.', 'answely' ) : answely_sync_grund_text( $grund );
	}
	echo '<p class="description">' . esc_html( $text ) . '</p>';
}

/**
 * Saves the exclusion checkbox of the meta box.
 *
 * @param int $id Post ID.
 */
function answely_sync_meta_save( $id ) {
	if ( ! isset( $_POST['answely_exclude_nonce'] ) || ! is_string( $_POST['answely_exclude_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['answely_exclude_nonce'] ) ), 'answely_exclude_' . $id ) ) {
		return;
	}
	if ( wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	if ( ! empty( $_POST['answely_exclude'] ) ) {
		update_post_meta( $id, '_answely_exclude', '1' );
	} else {
		delete_post_meta( $id, '_answely_exclude' );
	}
}
add_action( 'save_post', 'answely_sync_meta_save', 5, 1 );

add_action( 'save_post', 'answely_sync_vormerken', 20, 1 );
add_action( 'deleted_post', 'answely_sync_entfernen', 10, 1 );
add_action( 'trashed_post', 'answely_sync_entfernen', 10, 1 );
add_action( 'answely_sync_lauf', 'answely_sync_lauf' );
