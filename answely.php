<?php
/**
 * Plugin Name:       Answely – AI Answers for Site Search
 * Plugin URI:        https://answely.com/
 * Description:       Short AI answers with sources above your site search results. Adds the Answely script with your site key, an answer field block and shortcode, consent handling and an optional synchronization of public content.
 * Version:           1.5.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Answely
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       answely
 *
 * @package Answely
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ANSWELY_VERSION = '1.5.0';
const ANSWELY_OPTION  = 'answely';

/**
 * Addresses of the Answely service this site talks to.
 *
 * Always the answely.com hosts. Only a developer can point a test site at
 * another Answely installation by defining ANSWELY_API_BASE in wp-config.php
 * as its HTTPS address (scheme and host, no path); any other value is
 * ignored.
 *
 * @return array{environment:string, api:string, app:string, script:string}
 */
function answely_service() {
	$base = defined( 'ANSWELY_API_BASE' ) && is_string( ANSWELY_API_BASE ) ? answely_service_base( ANSWELY_API_BASE ) : '';
	if ( '' !== $base ) {
		return array(
			'environment' => 'custom',
			'api'         => $base,
			'app'         => $base,
			'script'      => $base . '/cdn/answely.js',
		);
	}
	return array(
		'environment' => 'live',
		'api'         => 'https://api.answely.com',
		'app'         => 'https://answely.com',
		'script'      => 'https://cdn.answely.com/answely.js',
	);
}

/**
 * Validates the ANSWELY_API_BASE constant: an HTTPS address with a host and
 * nothing else.
 *
 * @param string $value Value of the constant.
 * @return string The address without trailing slash, '' if not usable.
 */
function answely_service_base( $value ) {
	$parts = wp_parse_url( trim( $value ) );
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] )
		|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
		|| ( isset( $parts['path'] ) && '' !== trim( $parts['path'], '/' ) ) ) {
		return '';
	}
	$base = 'https://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	return in_array( $base, array( 'https://answely.com', 'https://api.answely.com', 'https://cdn.answely.com' ), true ) ? '' : $base;
}

/**
 * Is this a well-formed Answely site key?
 *
 * @param mixed $key Value to test.
 * @return bool
 */
function answely_key_ok( $key ) {
	return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_-]{8,64}$/D', $key );
}

/**
 * Consent categories of the WP Consent API.
 *
 * @return array<string,string>
 */
function answely_consent_categories() {
	return array(
		'functional'           => __( 'Functional', 'answely' ),
		'preferences'          => __( 'Preferences', 'answely' ),
		'statistics-anonymous' => __( 'Anonymous statistics', 'answely' ),
		'statistics'           => __( 'Statistics', 'answely' ),
		'marketing'            => __( 'Marketing', 'answely' ),
	);
}

/**
 * Saved settings, validated on every read.
 *
 * The keys "key" and "zustimmung" are kept from version 1.x so that existing
 * installations keep their settings.
 *
 * @return array{key:string, zustimmung:bool, load:string, locale:string, wp_consent:bool, consent_category:string}
 */
function answely_einstellungen() {
	$o = get_option( ANSWELY_OPTION, array() );
	$o = is_array( $o ) ? $o : array();

	$load     = isset( $o['load'] ) && 'search' === $o['load'] ? 'search' : 'everywhere';
	$locale   = isset( $o['locale'] ) && is_string( $o['locale'] ) && 1 === preg_match( '/^[a-z]{2}$/D', $o['locale'] ) ? $o['locale'] : '';
	$category = isset( $o['consent_category'] ) && is_string( $o['consent_category'] ) && isset( answely_consent_categories()[ $o['consent_category'] ] ) ? $o['consent_category'] : 'functional';

	return array(
		'key'              => isset( $o['key'] ) && answely_key_ok( $o['key'] ) ? $o['key'] : '',
		'zustimmung'       => ! empty( $o['zustimmung'] ),
		'load'             => $load,
		'locale'           => $locale,
		'wp_consent'       => ! isset( $o['wp_consent'] ) || ! empty( $o['wp_consent'] ),
		'consent_category' => $category,
	);
}

/**
 * Sanitize callback of the settings form. Each tab sends only its own fields;
 * fields of other tabs keep their saved values.
 *
 * @param mixed $roh Submitted values.
 * @return array
 */
function answely_saeubern( $roh ) {
	$roh = is_array( $roh ) ? $roh : array();
	$alt = answely_einstellungen();
	$neu = $alt;
	$tab = isset( $roh['_tab'] ) && is_string( $roh['_tab'] ) ? $roh['_tab'] : 'connection';

	if ( 'connection' === $tab && isset( $roh['key'] ) && is_string( $roh['key'] ) ) {
		$key = trim( sanitize_text_field( $roh['key'] ) );
		if ( '' === $key || answely_key_ok( $key ) ) {
			if ( $key !== $alt['key'] ) {
				delete_option( 'answely_status' );
			}
			$neu['key'] = $key;
		} else {
			add_settings_error( ANSWELY_OPTION, 'answely_key', __( 'The site key is invalid. Copy it from the "Embed" tab of your website in your Answely account. The previous key was kept.', 'answely' ) );
		}
	}

	if ( 'display' === $tab ) {
		$neu['load'] = isset( $roh['load'] ) && 'search' === $roh['load'] ? 'search' : 'everywhere';

		$locale        = isset( $roh['locale'] ) && is_string( $roh['locale'] ) ? strtolower( trim( sanitize_text_field( $roh['locale'] ) ) ) : '';
		$neu['locale'] = 1 === preg_match( '/^[a-z]{2}$/D', $locale ) ? $locale : '';

		$neu['zustimmung'] = ! empty( $roh['zustimmung'] ) && ! is_array( $roh['zustimmung'] );
		$neu['wp_consent'] = ! empty( $roh['wp_consent'] ) && ! is_array( $roh['wp_consent'] );

		$category                = isset( $roh['consent_category'] ) && is_string( $roh['consent_category'] ) ? $roh['consent_category'] : '';
		$neu['consent_category'] = isset( answely_consent_categories()[ $category ] ) ? $category : 'functional';
	}

	return $neu;
}

/*
 * ---------------------------------------------------------------------------
 * Front end: the Answely script.
 * ---------------------------------------------------------------------------
 */

/**
 * Does the current request show an Answely answer field (block or shortcode)?
 *
 * @return bool
 */
function answely_page_has_field() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_post();
	if ( ! $post ) {
		return false;
	}
	return has_shortcode( $post->post_content, 'answely' ) || has_block( 'answely/ask', $post );
}

/**
 * Should the script load on this request?
 *
 * @param array $e Settings.
 * @return bool
 */
function answely_load_here( array $e ) {
	if ( '' === $e['key'] ) {
		return false;
	}
	$load = 'everywhere' === $e['load'] || is_search() || answely_page_has_field();
	/**
	 * Filters whether the Answely script loads on the current request.
	 *
	 * @param bool  $load     Whether to load the script.
	 * @param array $settings The plugin settings.
	 */
	return (bool) apply_filters( 'answely_load_script', $load, $e );
}

/**
 * Inline script printed before answely.js: the queue stub and the bridges to
 * consent tools that do not talk to Answely by themselves.
 *
 * @param array $e Settings.
 * @return string JavaScript.
 */
function answely_inline_before( array $e ) {
	$js = 'window.Answely=window.Answely||function(){(Answely.q=Answely.q||[]).push(arguments)};';
	if ( $e['wp_consent'] && function_exists( 'wp_has_consent' ) ) {
		// WP Consent API (used by Complianz, CookieYes, Cookiebot and others).
		// Initial state: only a granted consent is passed on, so that IAB TCF and
		// consented.eu, which answely.js reads itself, are not overruled by a default "no".
		$js .= '(function(w,d,c){'
			. 'function now(){if(typeof w.wp_has_consent==="function"&&w.wp_has_consent(c)){w.Answely("zustimmung",true);}}'
			. 'd.addEventListener("wp_listen_for_consent_change",function(e){var x=e&&e.detail;'
			. 'if(x&&Object.prototype.hasOwnProperty.call(x,c)){w.Answely("zustimmung",x[c]==="allow");}});'
			. 'if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",now);}else{now();}'
			. '})(window,document,' . wp_json_encode( $e['consent_category'] ) . ');';
	}
	/**
	 * Filters the inline JavaScript printed before answely.js.
	 *
	 * Use it to connect a consent tool that is neither IAB TCF, consented.eu
	 * nor the WP Consent API: call Answely('zustimmung', true) on consent and
	 * Answely('zustimmung', false) on withdrawal.
	 *
	 * @param string $js       JavaScript code.
	 * @param array  $settings The plugin settings.
	 */
	return (string) apply_filters( 'answely_inline_script', $js, $e );
}

/**
 * Registers and enqueues answely.js.
 */
function answely_enqueue_script() {
	$e = answely_einstellungen();
	if ( ! answely_load_here( $e ) ) {
		return;
	}
	$service = answely_service();
	wp_register_script(
		'answely',
		$service['script'],
		array(),
		ANSWELY_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'async',
		)
	);
	wp_add_inline_script( 'answely', answely_inline_before( $e ), 'before' );
	wp_enqueue_script( 'answely' );
}
add_action( 'wp_enqueue_scripts', 'answely_enqueue_script' );

/**
 * Attributes of the answely.js tag.
 *
 * @param array $e Settings.
 * @return array<string,string>
 */
function answely_script_attributes( array $e ) {
	$service = answely_service();
	$attr    = array( 'data-site-key' => $e['key'] );
	if ( 'custom' === $service['environment'] ) {
		$attr['data-api'] = $service['api'];
	}
	if ( '' !== $e['locale'] ) {
		$attr['data-locale'] = $e['locale'];
	}
	if ( $e['zustimmung'] ) {
		$attr['data-consent'] = 'required';
	}
	return $attr;
}

/**
 * Adds the data attributes (and async, if the tag has none) to the tag.
 *
 * @param string $tag    Script HTML.
 * @param string $handle Handle.
 * @return string
 */
function answely_script_tag( $tag, $handle ) {
	if ( 'answely' !== $handle ) {
		return $tag;
	}
	$e   = answely_einstellungen();
	$add = '';
	foreach ( answely_script_attributes( $e ) as $name => $value ) {
		$add .= ' ' . $name . '="' . esc_attr( $value ) . '"';
	}
	$script = preg_quote( answely_service()['script'], '~' );
	return (string) preg_replace_callback(
		'~<script\b([^>]*\ssrc=["\']' . $script . '(?:\?[^"\']*)?["\'][^>]*)>~',
		static function ( $m ) use ( $add ) {
			$async = preg_match( '~\sasync\b~', $m[1] ) ? '' : ' async';
			return '<script' . $m[1] . $add . $async . '>';
		},
		$tag,
		1
	);
}
add_filter( 'script_loader_tag', 'answely_script_tag', 10, 2 );

/*
 * Declare support of the WP Consent API.
 */
add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );

/*
 * ---------------------------------------------------------------------------
 * Answer field: block "answely/ask" and shortcode [answely].
 * ---------------------------------------------------------------------------
 */

/**
 * HTML of the answer field. Without JavaScript or without Answely, the form
 * falls back to the normal WordPress search results page.
 *
 * @param array $a Attributes: label, placeholder, button.
 * @return string
 */
function answely_field_html( $a ) {
	static $n = 0;
	++$n;
	$a = is_array( $a ) ? $a : array();

	$label       = isset( $a['label'] ) && is_string( $a['label'] ) && '' !== trim( $a['label'] ) ? $a['label'] : __( 'Ask a question about this website', 'answely' );
	$placeholder = isset( $a['placeholder'] ) && is_string( $a['placeholder'] ) && '' !== trim( $a['placeholder'] ) ? $a['placeholder'] : __( 'Your question', 'answely' );
	$button      = isset( $a['button'] ) && is_string( $a['button'] ) && '' !== trim( $a['button'] ) ? $a['button'] : __( 'Ask', 'answely' );
	$id          = 'answely-ask-' . $n;

	wp_enqueue_script( 'answely-ask' );
	wp_enqueue_style( 'answely-ask' );

	return '<div class="answely-ask">'
		. '<form class="answely-ask__form" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '" data-answely-ask>'
		. '<label class="answely-ask__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
		. '<div class="answely-ask__row">'
		. '<input class="answely-ask__input" id="' . esc_attr( $id ) . '" type="text" name="answely_q" maxlength="300" required autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '">'
		. '<button class="answely-ask__button wp-element-button" type="submit">' . esc_html( $button ) . '</button>'
		. '</div></form>'
		. '<div class="answely-ask__answer" aria-live="polite"></div>'
		. '</div>';
}

/**
 * Shortcode [answely label="" placeholder="" button=""].
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function answely_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'label'       => '',
			'placeholder' => '',
			'button'      => '',
		),
		$atts,
		'answely'
	);
	return answely_field_html( array_map( 'sanitize_text_field', $atts ) );
}
add_shortcode( 'answely', 'answely_shortcode' );

/**
 * Front-end assets of the answer field and the block.
 */
function answely_register_field_assets() {
	wp_register_script( 'answely-ask', plugins_url( 'answer-field.js', __FILE__ ), array(), ANSWELY_VERSION, true );
	wp_register_style( 'answely-ask', false, array(), ANSWELY_VERSION );
	wp_add_inline_style(
		'answely-ask',
		'.answely-ask{margin:1.5em 0;max-width:100%}'
		. '.answely-ask__label{display:block;font-weight:600;margin-bottom:.5em}'
		. '.answely-ask__row{display:flex;flex-wrap:wrap;gap:.5em}'
		. '.answely-ask__input{flex:1 1 12em;min-width:0;max-width:100%;min-height:2.75em;padding:.6em .85em;font:inherit;color:inherit;background:transparent;border:1px solid currentColor;border-color:color-mix(in srgb,currentColor 45%,transparent);border-radius:6px;box-sizing:border-box}'
		. '.answely-ask__input::placeholder{color:inherit;opacity:.65}'
		. '.answely-ask__input:focus-visible,.answely-ask__button:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
		. '.answely-ask__button{flex:0 0 auto;min-height:2.75em;cursor:pointer}'
		. '.answely-ask__answer:empty{display:none}'
		. '.answely-ask__answer{margin-top:.75em}'
		. '@media (max-width:480px){.answely-ask__button{flex:1 1 100%}}'
	);

	wp_register_script( 'answely-block-editor', plugins_url( 'block-editor.js', __FILE__ ), array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ), ANSWELY_VERSION, true );
	$texts = array(
		'title'       => __( 'Answely answer field', 'answely' ),
		'description' => __( 'A question field that shows the Answely answer right below it. Without Answely it opens the normal search results.', 'answely' ),
		'label'       => __( 'Label', 'answely' ),
		'placeholder' => __( 'Placeholder', 'answely' ),
		'button'      => __( 'Button text', 'answely' ),
		'defLabel'    => __( 'Ask a question about this website', 'answely' ),
		'defPh'       => __( 'Your question', 'answely' ),
		'defButton'   => __( 'Ask', 'answely' ),
		'note'        => __( 'Answers appear on the published page.', 'answely' ),
		'settings'    => __( 'Answer field', 'answely' ),
	);
	wp_add_inline_script( 'answely-block-editor', 'window.answelyBlockTexts = ' . wp_json_encode( $texts ) . ';', 'before' );

	register_block_type(
		'answely/ask',
		array(
			'api_version'     => 3,
			'editor_script'   => 'answely-block-editor',
			'editor_style'    => 'answely-ask',
			'render_callback' => 'answely_render_block',
			'attributes'      => array(
				'label'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '',
				),
				'button'      => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}
add_action( 'init', 'answely_register_field_assets' );

/**
 * Render callback of the block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function answely_render_block( $attributes ) {
	$a = array();
	foreach ( array( 'label', 'placeholder', 'button' ) as $k ) {
		$a[ $k ] = isset( $attributes[ $k ] ) && is_string( $attributes[ $k ] ) ? sanitize_text_field( $attributes[ $k ] ) : '';
	}
	return answely_field_html( $a );
}

/**
 * Query variable of the answer field (fallback without JavaScript).
 *
 * @param array $vars Public query variables.
 * @return array
 */
function answely_query_vars( $vars ) {
	$vars[] = 'answely_q';
	return $vars;
}
add_filter( 'query_vars', 'answely_query_vars' );

/**
 * Without JavaScript the answer field submits ?answely_q=…; send the visitor
 * to the normal search results page, where Answely answers the search term.
 */
function answely_field_fallback() {
	$q = get_query_var( 'answely_q' );
	if ( ! is_string( $q ) || '' === trim( $q ) || is_search() ) {
		return;
	}
	$q = mb_substr( sanitize_text_field( $q ), 0, 300 );
	wp_safe_redirect( add_query_arg( 's', rawurlencode( $q ), home_url( '/' ) ) );
	exit;
}
add_action( 'template_redirect', 'answely_field_fallback' );

/*
 * ---------------------------------------------------------------------------
 * Activation, deactivation, plugin list.
 * ---------------------------------------------------------------------------
 */

/**
 * Link "Settings" in the plugin list.
 *
 * @param array $links Action links.
 * @return array
 */
function answely_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=answely' ) ) . '">' . esc_html__( 'Settings', 'answely' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'answely_action_links' );

/**
 * Deactivation: stop the scheduled synchronization run. Queued changes stay
 * stored and continue after reactivation.
 */
function answely_deactivate() {
	wp_clear_scheduled_hook( 'answely_sync_lauf' );
}
register_deactivation_hook( __FILE__, 'answely_deactivate' );

/**
 * Activation: continue a pending synchronization queue.
 */
function answely_activate() {
	if ( function_exists( 'answely_sync_lies' ) ) {
		$s = answely_sync_lies();
		if ( ! empty( $s['queue'] ) || ! empty( $s['vollseite'] ) || isset( $s['pruef_ids'] ) ) {
			answely_sync_plan( 30 );
		}
	}
}
register_activation_hook( __FILE__, 'answely_activate' );

/**
 * WooCommerce: the plugin does not touch orders or the checkout; order data
 * for the optional customer panel is read through wc_get_orders().
 */
function answely_woocommerce_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'answely_woocommerce_compat' );

/*
 * ---------------------------------------------------------------------------
 * Administration: Settings → Answely.
 * ---------------------------------------------------------------------------
 */

/**
 * Registers the setting.
 */
function answely_admin_init() {
	register_setting(
		ANSWELY_OPTION,
		ANSWELY_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'answely_saeubern',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'answely_admin_init' );

/**
 * Adds the settings page.
 */
function answely_admin_menu() {
	$hook = add_options_page( 'Answely', 'Answely', 'manage_options', 'answely', 'answely_seite' );
	add_action( 'admin_enqueue_scripts', 'answely_admin_assets' );
	add_action( 'load-' . $hook, 'answely_admin_help' );
}
add_action( 'admin_menu', 'answely_admin_menu' );

/**
 * One-time notice for the current user after a form action on the page.
 *
 * @param string $type    success|error|warning|info.
 * @param string $message Text.
 */
function answely_flash( $type, $message ) {
	set_transient(
		'answely_notice_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		120
	);
}

/**
 * Prints and removes the one-time notice.
 */
function answely_flash_print() {
	$key = 'answely_notice_' . get_current_user_id();
	$n   = get_transient( $key );
	if ( ! is_array( $n ) || empty( $n['message'] ) ) {
		return;
	}
	delete_transient( $key );
	$type = in_array( $n['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $n['type'] : 'info';
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) $n['message'] ) . '</p></div>';
}

/**
 * Contextual help tab ("Help" at the top of the page).
 */
function answely_admin_help() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	$screen->add_help_tab(
		array(
			'id'      => 'answely-overview',
			'title'   => __( 'Overview', 'answely' ),
			'content' => '<p>' . esc_html__( 'Answely adds a short AI answer with links to your own pages to your site search. The answers, the design of the box and its texts are managed in your Answely account; this plugin connects your WordPress site to it.', 'answely' ) . '</p>'
				. '<p>' . esc_html__( 'Answely only answers from pages of your verified domain that back the answer. Without such a source the box shows your notice text instead of an invented answer.', 'answely' ) . '</p>',
		)
	);
	$screen->add_help_tab(
		array(
			'id'      => 'answely-developers',
			'title'   => __( 'Developers', 'answely' ),
			'content' => '<p>' . esc_html__( 'Filters: answely_load_script (load the script on a request), answely_inline_script (connect another consent tool), answely_sync_oeffentlich (exclude content from synchronization, for example for membership plugins), answely_sync_document (change a document before it is sent), answely_source_hosts (allowed link targets in the test answer).', 'answely' ) . '</p>'
				. '<p>' . esc_html__( 'JavaScript: Answely.frage(text, element) asks a question, Answely("zustimmung", true|false) passes consent, Answely.diagnose() reports the state of the installation.', 'answely' ) . '</p>',
		)
	);
	$screen->set_help_sidebar( '<p><a href="' . esc_url( answely_service()['app'] . '/en/docs' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Answely developer docs', 'answely' ) . '</a></p>' );
}

/**
 * Admin CSS and JavaScript (only on the plugin page).
 *
 * @param string $hook Current admin page.
 */
function answely_admin_assets( $hook ) {
	if ( 'settings_page_answely' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'answely-admin', plugins_url( 'admin.css', __FILE__ ), array( 'dashicons' ), ANSWELY_VERSION );

	wp_register_script( 'answely-admin', plugins_url( 'admin.js', __FILE__ ), array(), ANSWELY_VERSION, true );
	wp_enqueue_script( 'answely-admin' );
	$texts = array(
		'ajax'       => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'answely_admin' ),
		'hosts'      => answely_source_hosts(),
		'working'    => __( 'Working…', 'answely' ),
		'failed'     => __( 'The request failed. Check your connection and try again.', 'answely' ),
		'sources'    => __( 'Sources', 'answely' ),
		'copied'     => __( 'Copied', 'answely' ),
		'copyFailed' => __( 'Copying failed. The text is selected; press Ctrl+C to copy it.', 'answely' ),
		/* translators: 1: number of characters typed, 2: maximum number of characters */
		'count'      => __( '%1$d of %2$d characters', 'answely' ),
		'cancel'     => __( 'Cancel', 'answely' ),
		/* translators: 1: seconds, 2: number of sources, 3: number of words */
		'meta'       => __( 'Time: %1$s s · Sources: %2$d · Words: %3$d', 'answely' ),
		'reuse'      => __( 'Put this question in the field again', 'answely' ),
		/* translators: 1: number of matching entries, 2: number of entries tested */
		'pathSum'    => __( 'Excluded by these paths: %1$d of the %2$d most recently changed published entries.', 'answely' ),
		'pathNone'   => __( 'None of the most recently changed published entries is excluded by these paths.', 'answely' ),
		/* translators: %d: number of further entries */
		'pathMore'   => __( 'and %d more', 'answely' ),
	);
	wp_add_inline_script( 'answely-admin', 'window.answelyAdmin = ' . wp_json_encode( $texts ) . ';', 'before' );
}

/**
 * Hosts that the test answer may link to: the site's own host, with and
 * without "www.".
 *
 * @return string[]
 */
function answely_source_hosts() {
	$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$hosts = array();
	if ( '' !== $host ) {
		$hosts[] = $host;
		$hosts[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
	}
	/**
	 * Filters the hosts that answers shown in the admin test may link to.
	 *
	 * @param string[] $hosts Lower-case host names.
	 */
	$hosts = (array) apply_filters( 'answely_source_hosts', $hosts );
	return array_values( array_unique( array_filter( array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) ) ) );
}

/**
 * Language code sent with checks and test questions.
 *
 * @return string Two letters.
 */
function answely_admin_locale() {
	$e = answely_einstellungen();
	if ( '' !== $e['locale'] ) {
		return $e['locale'];
	}
	return strtolower( substr( get_locale(), 0, 2 ) );
}

/**
 * Status check against the public configuration endpoint of the site key.
 * Runs only when an administrator clicks "Check connection". Uses no answer
 * from the quota.
 *
 * @return array{level:string, message:string, details:string[]}
 */
function answely_check_connection() {
	$e = answely_einstellungen();
	if ( '' === $e['key'] ) {
		return array(
			'level'   => 'bad',
			'message' => __( 'Enter and save your site key first.', 'answely' ),
			'details' => array(),
		);
	}
	$service = answely_service();
	$res     = wp_remote_get(
		$service['api'] . '/v1/search/' . rawurlencode( $e['key'] ) . '?locale=' . rawurlencode( answely_admin_locale() ),
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array( 'Accept' => 'application/json' ),
		)
	);
	$status  = array(
		'time'  => time(),
		'key'   => $e['key'],
		'state' => 'error',
	);
	$details = array();

	if ( is_wp_error( $res ) ) {
		$out = array(
			'level'   => 'bad',
			'message' => sprintf(
				/* translators: %s: technical error message */
				__( 'Answely could not be reached from this server: %s', 'answely' ),
				$res->get_error_message()
			),
		);
	} else {
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$body = is_array( $body ) ? $body : array();
		if ( 404 === $code ) {
			$status['state'] = 'unknown';
			$out             = array(
				'level'   => 'bad',
				'message' => __( 'Answely does not know this site key. Copy it again from the "Embed" tab of your website in your Answely account.', 'answely' ),
			);
		} elseif ( 429 === $code ) {
			$out = array(
				'level'   => 'warn',
				'message' => __( 'Too many requests right now. Wait a minute and check again.', 'answely' ),
			);
		} elseif ( 200 === $code && isset( $body['aktiv'] ) && false === $body['aktiv'] ) {
			$status['state'] = 'inactive';
			$out             = array(
				'level'   => 'warn',
				'message' => __( 'The site key is valid, but Answely does not answer for this website yet. Possible reasons: the domain is not verified, the box is switched off in your account, or the monthly answer quota is used up.', 'answely' ),
			);
		} elseif ( 200 === $code && ! empty( $body['aktiv'] ) ) {
			$status['state']   = 'ok';
			$k                 = isset( $body['konfig'] ) && is_array( $body['konfig'] ) ? $body['konfig'] : array();
			$out               = array(
				'level'   => 'ok',
				'message' => __( 'Connected. Answely answers for this website.', 'answely' ),
			);
			$account_consent   = isset( $k['einwilligung'] ) && 'pflicht' === $k['einwilligung'];
			$status['consent'] = $account_consent;
			if ( isset( $body['sprache'] ) && is_string( $body['sprache'] ) && 1 === preg_match( '/^[a-z]{2}$/D', $body['sprache'] ) ) {
				/* translators: %s: language code such as "en" */
				$details[] = sprintf( __( 'Answer language for this check: %s', 'answely' ), $body['sprache'] );
			}
			if ( isset( $k['eingabe'] ) && is_string( $k['eingabe'] ) && '' !== $k['eingabe'] ) {
				$selector = mb_substr( wp_strip_all_tags( $k['eingabe'] ), 0, 200 );
				/* translators: %s: CSS selector */
				$details[] = sprintf( __( 'Search fields the box attaches to (set in your account): %s', 'answely' ), $selector );
				if ( false === strpos( $selector, 'name=s' ) && false === strpos( $selector, 'type=search' ) && false === strpos( $selector, 'name="s"' ) ) {
					$details[] = __( 'The standard WordPress search field may not match this selector. Adjust it in your account, or use the Answely answer field block, which works independently of it.', 'answely' );
				}
			}
			if ( $account_consent && ! $e['zustimmung'] ) {
				$out['level'] = 'warn';
				$details[]    = __( 'Your Answely account requires consent before answers. Also switch on "Wait for consent" under Display & privacy, so that the box fetches neither its configuration nor answers before the visitor agrees.', 'answely' );
			}
		} else {
			$out = array(
				'level'   => 'bad',
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'Unexpected answer from Answely (HTTP %d). Try again later.', 'answely' ), $code ),
			);
		}
	}
	update_option( 'answely_status', $status, false );
	$out['details'] = $details;
	return $out;
}

/**
 * Parses the answer stream (server-sent events) of a test question.
 *
 * The answer is generated by a language model and therefore treated as
 * untrusted data: only plain text and validated source links are returned,
 * the admin page renders them as text.
 *
 * @param string $raw Response body.
 * @return array{level:string, message?:string, answer?:string, sources?:array, note?:string}
 */
function answely_parse_answer_stream( $raw ) {
	$last = null;
	foreach ( preg_split( '/\r?\n\r?\n/', (string) $raw ) as $block ) {
		$event = '';
		$data  = '';
		foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
			if ( 0 === strpos( $line, 'event:' ) ) {
				$event = trim( substr( $line, 6 ) );
			} elseif ( 0 === strpos( $line, 'data:' ) ) {
				$data .= ltrim( substr( $line, 5 ) );
			}
		}
		if ( 'fertig' === $event || 'fehler' === $event ) {
			$json = json_decode( $data, true );
			$last = array( $event, is_array( $json ) ? $json : array() );
		}
	}
	if ( null === $last ) {
		return array(
			'level'   => 'bad',
			'message' => __( 'Answely sent no complete answer. Try again later.', 'answely' ),
		);
	}
	list( $event, $d ) = $last;

	if ( 'fehler' === $event ) {
		$code = isset( $d['code'] ) && is_string( $d['code'] ) ? $d['code'] : '';
		$why  = isset( $d['grund'] ) && is_string( $d['grund'] ) ? $d['grund'] : '';
		$map  = array(
			'aus'          => __( 'Answely is switched off for this website, or the domain is not verified.', 'answely' ),
			'gesperrt'     => 'kontingent' === $why ? __( 'The monthly answer quota is used up.', 'answely' ) : __( 'This website is blocked in Answely.', 'answely' ),
			'drossel'      => __( 'Too many requests right now. Wait a minute and try again.', 'answely' ),
			'motor'        => __( 'The answer service is disrupted at the moment. Your search keeps working; try again later.', 'answely' ),
			'zeit'         => __( 'The answer took too long. Try again later.', 'answely' ),
			'einwilligung' => __( 'The answer requires visitor consent.', 'answely' ),
			'ungueltig'    => __( 'Answely did not accept the question. Questions must have 1 to 300 characters; depending on your account settings, only questions are answered, not single keywords.', 'answely' ),
		);
		return array(
			'level'   => 'bad',
			'message' => isset( $map[ $code ] ) ? $map[ $code ] : __( 'Answely could not answer.', 'answely' ),
		);
	}

	$text = isset( $d['text'] ) && is_string( $d['text'] ) ? $d['text'] : '';
	$text = trim( preg_replace( '/[ \t]+/', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
	$text = mb_substr( $text, 0, 4000 );

	$hosts   = answely_source_hosts();
	$sources = array();
	foreach ( isset( $d['quellen'] ) && is_array( $d['quellen'] ) ? array_slice( $d['quellen'], 0, 4 ) : array() as $q ) {
		if ( ! is_array( $q ) ) {
			continue;
		}
		$title     = isset( $q['titel'] ) && is_string( $q['titel'] ) ? mb_substr( trim( wp_strip_all_tags( $q['titel'] ) ), 0, 200 ) : '';
		$url       = isset( $q['url'] ) && is_string( $q['url'] ) ? $q['url'] : '';
		$parts     = wp_parse_url( $url );
		$ok        = is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && in_array( strtolower( $parts['host'] ), $hosts, true );
		$sources[] = array(
			'title' => '' !== $title ? $title : ( $ok ? $url : __( '(source on another host, link not shown)', 'answely' ) ),
			'url'   => $ok ? esc_url_raw( $url ) : '',
		);
	}

	$no_answer = isset( $d['ergebnis'] ) && 'keine_antwort' === $d['ergebnis'];
	return array(
		'level'   => $no_answer ? 'warn' : 'ok',
		'answer'  => '' !== $text ? $text : __( '(empty answer)', 'answely' ),
		'sources' => $sources,
		'note'    => $no_answer
			? __( 'No page of your website backs an answer to this question, so the box shows your notice text. This does not count towards your quota.', 'answely' )
			: ( isset( $d['herkunft'] ) && 'festantwort' === $d['herkunft']
				? __( 'Approved answer from your editorial settings.', 'answely' )
				: __( 'AI-generated answer. It counts as one answer of your monthly quota.', 'answely' ) ),
	);
}

/**
 * Capability check of an admin AJAX request (the nonce is checked in each handler).
 */
function answely_ajax_guard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array(
				'level'   => 'bad',
				'message' => __( 'You are not allowed to do this.', 'answely' ),
			),
			403
		);
	}
}

/**
 * AJAX: check the connection.
 */
function answely_ajax_check() {
	check_ajax_referer( 'answely_admin', 'nonce' );
	answely_ajax_guard();
	wp_send_json_success( answely_check_connection() );
}
add_action( 'wp_ajax_answely_check', 'answely_ajax_check' );

/**
 * AJAX: ask a test question (server to Answely, no Origin header).
 */
function answely_ajax_test() {
	check_ajax_referer( 'answely_admin', 'nonce' );
	answely_ajax_guard();
	$q = isset( $_POST['q'] ) && is_string( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
	$e = answely_einstellungen();
	if ( '' === $e['key'] ) {
		wp_send_json_success(
			array(
				'level'   => 'bad',
				'message' => __( 'Enter and save your site key first.', 'answely' ),
			)
		);
	}
	if ( '' === $q || mb_strlen( $q ) > 300 ) {
		wp_send_json_success(
			array(
				'level'   => 'bad',
				'message' => __( 'Enter a question with 1 to 300 characters.', 'answely' ),
			)
		);
	}
	$service = answely_service();
	$res     = wp_remote_post(
		$service['api'] . '/v1/search/' . rawurlencode( $e['key'] ) . '/answer',
		array(
			'timeout'             => 30,
			'redirection'         => 0,
			'limit_response_size' => 262144,
			'headers'             => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'text/event-stream',
			),
			'body'                => wp_json_encode(
				array(
					'q'      => $q,
					'locale' => answely_admin_locale(),
				)
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		wp_send_json_success(
			array(
				'level'   => 'bad',
				/* translators: %s: technical error message */
				'message' => sprintf( __( 'Answely could not be reached from this server: %s', 'answely' ), $res->get_error_message() ),
			)
		);
	}
	wp_send_json_success( answely_parse_answer_stream( wp_remote_retrieve_body( $res ) ) );
}
add_action( 'wp_ajax_answely_test', 'answely_ajax_test' );

/**
 * Status line with an icon.
 *
 * @param string $state ok|warn|bad|todo.
 * @param string $text  Text.
 */
function answely_step( $state, $text, $jump = array() ) {
	$icons = array(
		'ok'   => 'yes-alt',
		'warn' => 'warning',
		'bad'  => 'dismiss',
		'todo' => 'marker',
	);
	$words = array(
		'ok'   => __( 'Done', 'answely' ),
		'warn' => __( 'Needs attention', 'answely' ),
		'bad'  => __( 'Problem', 'answely' ),
		'todo' => __( 'Open', 'answely' ),
	);
	echo '<li class="is-' . esc_attr( $state ) . '"><span class="dashicons dashicons-' . esc_attr( $icons[ $state ] ) . ' answely-' . esc_attr( $state ) . '" aria-hidden="true"></span><span><span class="screen-reader-text">' . esc_html( $words[ $state ] ) . ': </span>' . esc_html( $text );
	if ( 'ok' !== $state && ! empty( $jump[0] ) && ! empty( $jump[1] ) ) {
		echo ' <a class="answely-step__go" href="' . esc_attr( $jump[0] ) . '">' . esc_html( $jump[1] ) . '<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span></a>';
	}
	echo '</span></li>';
}

/**
 * Copy button for the text of an element.
 *
 * @param string $target ID of the element whose text is copied.
 */
function answely_copy_button( $target ) {
	echo '<button type="button" class="button button-small answely-copy" data-answely-action="copy" data-copy="' . esc_attr( $target ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="answely-copy__text">' . esc_html__( 'Copy', 'answely' ) . '</span></button>';
}

/**
 * Overall state shown next to the page title, from the saved settings and
 * the result of the last connection check (no request).
 *
 * @param array $e Settings.
 * @return array{0:string, 1:string, 2:string} Level, dashicon, text.
 */
function answely_header_state( array $e ) {
	if ( '' === $e['key'] ) {
		return array( 'todo', 'marker', __( 'Not set up', 'answely' ) );
	}
	$status = get_option( 'answely_status', array() );
	$state  = is_array( $status ) && isset( $status['key'], $status['state'] ) && $status['key'] === $e['key'] ? $status['state'] : '';
	if ( 'ok' === $state ) {
		return array( 'ok', 'yes-alt', __( 'Connected', 'answely' ) );
	}
	if ( 'inactive' === $state ) {
		return array( 'warn', 'warning', __( 'Not answering yet', 'answely' ) );
	}
	if ( 'unknown' === $state ) {
		return array( 'bad', 'dismiss', __( 'Unknown site key', 'answely' ) );
	}
	return array( 'todo', 'marker', __( 'Connection not checked', 'answely' ) );
}

/**
 * Progress ring: done steps of all steps, as inline SVG.
 *
 * @param int $done  Steps done.
 * @param int $total All steps.
 */
function answely_ring( $done, $total ) {
	$total = max( 1, (int) $total );
	$done  = min( $total, max( 0, (int) $done ) );
	$len   = 2 * M_PI * 22;
	$fill  = round( $len * $done / $total, 2 );
	/* translators: 1: number of steps done, 2: number of steps */
	$label = sprintf( __( '%1$d of %2$d steps done', 'answely' ), $done, $total );
	echo '<div class="answely-ring" role="img" aria-label="' . esc_attr( $label ) . '" data-answely-done="' . esc_attr( (string) $done ) . '" data-answely-total="' . esc_attr( (string) $total ) . '"><svg viewBox="0 0 52 52" aria-hidden="true" focusable="false">'
		. '<circle class="answely-ring__track" cx="26" cy="26" r="22"/>'
		. '<circle class="answely-ring__bar" cx="26" cy="26" r="22" stroke-dasharray="' . esc_attr( $fill . ' ' . round( $len, 2 ) ) . '"/>'
		. '</svg><span class="answely-ring__text" aria-hidden="true">' . esc_html( $done . '/' . $total ) . '</span></div>';
}

/**
 * The settings page.
 */
function answely_seite() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$e       = answely_einstellungen();
	$service = answely_service();
	$wc      = function_exists( 'wc_get_orders' );
	$cust    = get_option( 'answely_customer', array() );
	$tabs    = array(
		'connection' => __( 'Connection', 'answely' ),
		'display'    => __( 'Display & privacy', 'answely' ),
		'sync'       => __( 'Content sync', 'answely' ),
	);
	if ( $wc || ! empty( $cust['connected'] ) ) {
		$tabs['customers'] = __( 'Customer orders', 'answely' );
	}
	$tabs['help'] = __( 'Help', 'answely' );
	$icons        = array(
		'connection' => 'admin-links',
		'display'    => 'visibility',
		'sync'       => 'update',
		'customers'  => 'cart',
		'help'       => 'editor-help',
	);
	$badge        = answely_header_state( $e );

	echo '<div class="wrap answely-wrap"><div class="answely-head"><h1>Answely</h1>';
	echo '<span class="answely-pill is-' . esc_attr( $badge[0] ) . '"><span class="dashicons dashicons-' . esc_attr( $badge[1] ) . '" aria-hidden="true"></span>' . esc_html( $badge[2] ) . '</span>';
	echo '<span class="answely-version">' . esc_html( ANSWELY_VERSION ) . '</span>';
	if ( 'custom' === $service['environment'] ) {
		echo '<span class="answely-badge">' . esc_html__( 'Service address from ANSWELY_API_BASE', 'answely' ) . '</span>';
	}
	echo '</div><hr class="wp-header-end">';
	settings_errors( ANSWELY_OPTION );
	answely_flash_print();

	echo '<nav class="nav-tab-wrapper answely-tabs" aria-label="' . esc_attr__( 'Answely settings', 'answely' ) . '">';
	foreach ( $tabs as $id => $label ) {
		echo '<a href="#' . esc_attr( $id ) . '" class="nav-tab" id="answely-tab-' . esc_attr( $id ) . '" data-tab="' . esc_attr( $id ) . '" aria-controls="answely-' . esc_attr( $id ) . '"><span class="dashicons dashicons-' . esc_attr( $icons[ $id ] ) . '" aria-hidden="true"></span>' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	answely_tab_connection( $e, $service );
	answely_tab_display( $e );
	echo '<div class="answely-panel" id="answely-sync">';
	answely_sync_seite();
	echo '</div>';
	if ( isset( $tabs['customers'] ) ) {
		echo '<div class="answely-panel" id="answely-customers">';
		answely_customer_seite();
		echo '</div>';
	}
	answely_tab_help( $service );
	echo '</div>';
}

/**
 * Tab "Connection".
 *
 * @param array $e       Settings.
 * @param array $service Service addresses.
 */
function answely_tab_connection( array $e, array $service ) {
	$status = get_option( 'answely_status', array() );
	$status = is_array( $status ) && isset( $status['key'] ) && $status['key'] === $e['key'] ? $status : array();
	$sync   = answely_sync_lies();

	echo '<div class="answely-panel" id="answely-connection">';

	$steps = array();
	$steps[] = array( '' !== $e['key'] ? 'ok' : 'todo', '' !== $e['key'] ? __( 'Site key saved.', 'answely' ) : __( 'Create a website in your Answely account, verify your domain and paste the site key below.', 'answely' ), array( '#answely-key', __( 'Enter the site key', 'answely' ) ) );
	$state   = $status['state'] ?? '';
	if ( 'ok' === $state ) {
		$steps[] = array( 'ok', __( 'Connection checked: Answely answers for this website.', 'answely' ) );
	} elseif ( 'inactive' === $state ) {
		$steps[] = array( 'warn', __( 'Site key valid, but answers are not active yet (domain verification, box switched off or quota).', 'answely' ), array( '#answely-check', __( 'Check again', 'answely' ) ) );
	} elseif ( 'unknown' === $state ) {
		$steps[] = array( 'bad', __( 'Answely does not know the saved site key.', 'answely' ), array( '#answely-key', __( 'Correct the site key', 'answely' ) ) );
	} else {
		$steps[] = array( 'todo', __( 'Check the connection.', 'answely' ), '' !== $e['key'] ? array( '#answely-check', __( 'Check now', 'answely' ) ) : array() );
	}
	$steps[] = array( 'ok', 'everywhere' === $e['load'] ? __( 'The answer box attaches to your search fields on every page.', 'answely' ) : __( 'The answer box loads on search results and on pages with the Answely answer field.', 'answely' ) );
	$steps[] = array( ! empty( $sync['an'] ) ? 'ok' : 'todo', ! empty( $sync['an'] ) ? __( 'Content sync is on.', 'answely' ) : __( 'Optional: send new and changed content to Answely right away (Content sync, Pro plan or higher).', 'answely' ), array( '#sync', __( 'Open Content sync', 'answely' ) ) );
	$done    = count(
		array_filter(
			$steps,
			static function ( $s ) {
				return 'ok' === $s[0];
			}
		)
	);

	echo '<div class="answely-card answely-setup"><h2>' . esc_html__( 'Setup', 'answely' ) . '</h2><div class="answely-setup__body">';
	answely_ring( $done, count( $steps ) );
	echo '<ol class="answely-steps">';
	foreach ( $steps as $s ) {
		answely_step( $s[0], $s[1], $s[2] ?? array() );
	}
	echo '</ol></div></div>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Site key', 'answely' ) . '</h2>';
	echo '<form method="post" action="options.php">';
	settings_fields( ANSWELY_OPTION );
	echo '<input type="hidden" name="answely[_tab]" value="connection">';
	echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="answely-key">' . esc_html__( 'Site key', 'answely' ) . '</label></th><td>';
	echo '<input name="answely[key]" id="answely-key" type="text" class="regular-text code" value="' . esc_attr( $e['key'] ) . '" maxlength="64" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9_\-]{8,64}" aria-describedby="answely-key-help">';
	echo '<p class="description" id="answely-key-help">' . esc_html__( 'Your Answely account → Websites → your website → Embed. The site key is public (it is part of every page), it is not a password.', 'answely' ) . '</p>';
	echo '</td></tr></table>';
	submit_button( __( 'Save site key', 'answely' ) );
	echo '</form>';
	echo '<p><a class="button" href="' . esc_url( $service['app'] . '/login' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Answely account', 'answely' ) . '</a> ';
	echo '<a href="' . esc_url( $service['app'] . '/register' ) . '" target="_blank" rel="noopener">' . esc_html__( 'No account yet? Create one', 'answely' ) . '</a></p>';
	echo '</div>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Connection', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Asks Answely whether it answers for this site key. Does not use your answer quota.', 'answely' ) . '</p>';
	echo '<p><button type="button" id="answely-check" class="button button-secondary" data-answely-action="check" aria-controls="answely-check-out"' . disabled( '' === $e['key'], true, false ) . '><span class="dashicons dashicons-update" aria-hidden="true"></span>' . esc_html__( 'Check connection', 'answely' ) . '</button></p>';
	echo '<div id="answely-check-out" class="answely-result" role="status" hidden></div>';
	if ( ! empty( $status['time'] ) ) {
		/* translators: %s: date and time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Last check: %s', 'answely' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['time'] ) ) ) . '</p>';
	}
	echo '</div>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Test question', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Ask a question the way a visitor would. The answer comes from the pages Answely has indexed for your verified domain. A generated answer counts towards your monthly quota.', 'answely' ) . '</p>';
	echo '<div class="answely-inline"><label class="screen-reader-text" for="answely-test-q">' . esc_html__( 'Test question', 'answely' ) . '</label>';
	echo '<input type="text" id="answely-test-q" class="regular-text" maxlength="300" aria-describedby="answely-test-count" placeholder="' . esc_attr__( 'For example: How long does shipping take?', 'answely' ) . '"' . disabled( '' === $e['key'], true, false ) . '>';
	echo '<button type="button" class="button button-secondary" data-answely-action="test" aria-controls="answely-test-out"' . disabled( '' === $e['key'], true, false ) . '><span class="dashicons dashicons-format-chat" aria-hidden="true"></span>' . esc_html__( 'Ask', 'answely' ) . '</button></div>';
	/* translators: 1: number of characters typed, 2: maximum number of characters */
	echo '<p class="answely-count" id="answely-test-count">' . esc_html( sprintf( __( '%1$d of %2$d characters', 'answely' ), 0, 300 ) ) . '</p>';
	echo '<div class="answely-recent" id="answely-recent" hidden><span class="answely-recent__label">' . esc_html__( 'Asked on this page:', 'answely' ) . '</span></div>';
	echo '<div id="answely-test-out" class="answely-result" role="status" aria-live="polite" hidden></div>';
	echo '</div>';

	echo '</div>';
}

/**
 * Tab "Display & privacy".
 *
 * @param array $e Settings.
 */
function answely_tab_display( array $e ) {
	$languages = array(
		''   => __( 'Automatic (language of the page)', 'answely' ),
		'de' => 'Deutsch (de)',
		'en' => 'English (en)',
		'es' => 'Español (es)',
		'fr' => 'Français (fr)',
		'it' => 'Italiano (it)',
		'nl' => 'Nederlands (nl)',
		'pl' => 'Polski (pl)',
		'ru' => 'Русский (ru)',
		'uk' => 'Українська (uk)',
	);
	$wpc       = function_exists( 'wp_has_consent' );

	echo '<div class="answely-panel" id="answely-display">';
	echo '<form method="post" action="options.php">';
	settings_fields( ANSWELY_OPTION );
	echo '<input type="hidden" name="answely[_tab]" value="display">';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Display', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Design, colours, texts, position of the box and which search fields it attaches to are set in your Answely account, with a preview. The standard WordPress search field is detected automatically.', 'answely' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Load Answely', 'answely' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Load Answely', 'answely' ) . '</legend>';
	echo '<label><input type="radio" name="answely[load]" value="everywhere"' . checked( 'everywhere', $e['load'], false ) . '> ' . esc_html__( 'On every page (recommended: answers while visitors type in any search field)', 'answely' ) . '</label><br>';
	echo '<label><input type="radio" name="answely[load]" value="search"' . checked( 'search', $e['load'], false ) . '> ' . esc_html__( 'Only on search results and on pages with the Answely answer field', 'answely' ) . '</label>';
	echo '</fieldset></td></tr>';
	echo '<tr><th scope="row"><label for="answely-locale">' . esc_html__( 'Answer language', 'answely' ) . '</label></th><td><select id="answely-locale" name="answely[locale]">';
	foreach ( $languages as $code => $label ) {
		echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $e['locale'], false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select><p class="description">' . esc_html__( 'Automatic follows the language of each page, which also suits multilingual sites (Polylang, WPML). Answely answers in the language of the question where it can.', 'answely' ) . '</p></td></tr>';
	echo '</table></div>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Consent', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'The Answely box sets no cookies and stores no visitor identifier. Whether you ask for consent depends on your privacy assessment; the privacy text modules in your Answely account help.', 'answely' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Wait for consent', 'answely' ) . '</th><td><label><input type="checkbox" name="answely[zustimmung]" value="1"' . checked( $e['zustimmung'], true, false ) . '> ';
	echo esc_html__( 'Fetch no configuration and no answer from Answely until the visitor has agreed', 'answely' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'The script file is still loaded from cdn.answely.com: the browser of the visitor requests it like any file from another host, which transmits the IP address and the user agent. Before consent the script fetches neither its configuration nor any answer from api.answely.com. IAB TCF v2 (purpose 1) and consented.eu are detected automatically.', 'answely' ) . '</p></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'WP Consent API', 'answely' ) . '</th><td><label><input type="checkbox" id="answely-wp-consent" name="answely[wp_consent]" value="1" aria-controls="answely-category"' . checked( $e['wp_consent'], true, false ) . '> ';
	echo esc_html__( 'Take consent from the WP Consent API', 'answely' ) . '</label> ';
	echo '<label for="answely-category" class="screen-reader-text">' . esc_html__( 'Consent category', 'answely' ) . '</label><select id="answely-category" name="answely[consent_category]">';
	foreach ( answely_consent_categories() as $code => $label ) {
		echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $e['consent_category'], false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select><p class="description">';
	echo esc_html( $wpc ? __( 'The WP Consent API is active on this site. Consent tools such as Complianz, CookieYes or Cookiebot pass their decision through it.', 'answely' ) : __( 'The WP Consent API plugin is not active on this site; this setting takes effect once it is.', 'answely' ) );
	echo '</p></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Other consent tools', 'answely' ) . '</th><td><p>' . esc_html__( 'Call this from your consent tool when the visitor agrees or withdraws:', 'answely' ) . '</p>';
	echo '<p class="answely-snippets"><span class="answely-snippet"><code class="answely-code" id="answely-consent-yes">Answely(\'zustimmung\', true);</code>';
	answely_copy_button( 'answely-consent-yes' );
	echo '</span> <span class="answely-snippet"><code class="answely-code" id="answely-consent-no">Answely(\'zustimmung\', false);</code>';
	answely_copy_button( 'answely-consent-no' );
	echo '</span></p>';
	echo '<p class="description">' . esc_html__( 'Developers can add such a bridge with the answely_inline_script filter.', 'answely' ) . '</p></td></tr>';
	echo '</table></div>';

	answely_display_preview( $e );

	submit_button( __( 'Save display and privacy settings', 'answely' ) );
	echo '</form>';

	echo '<div class="answely-card"><h2>' . esc_html__( 'Answer field (block and shortcode)', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Add a question field that shows the answer right below it, for example on a help or FAQ page: in the block editor, insert the block "Answely answer field", or use the shortcode:', 'answely' ) . '</p>';
	echo '<p class="answely-snippet"><code class="answely-code" id="answely-shortcode">[answely label="Ask about our products" placeholder="Your question" button="Ask"]</code> ';
	answely_copy_button( 'answely-shortcode' );
	echo '</p>';
	echo '<p class="description">' . esc_html__( 'All attributes are optional. Without JavaScript, without consent or without a site key the field opens the normal search results page.', 'answely' ) . '</p>';
	answely_shortcode_builder();
	echo '</div>';

	echo '</div>';
}

/**
 * Live sketch of what a visitor's page does with the settings of the form above.
 * Rendered from the saved settings; admin.js follows the form while it is edited.
 *
 * @param array $e Settings.
 */
function answely_display_preview( array $e ) {
	$search_only = 'search' === $e['load'];
	$pages       = array(
		array( 'admin-home', __( 'Home page', 'answely' ), ! $search_only, 'any' ),
		array( 'admin-post', __( 'Blog post', 'answely' ), ! $search_only, 'any' ),
		array( 'search', __( 'Search results', 'answely' ), true, 'search' ),
		array( 'format-chat', __( 'Page with answer field', 'answely' ), true, 'search' ),
	);
	$cats = answely_consent_categories();
	$cat  = $cats[ $e['consent_category'] ] ?? '';
	echo '<div class="answely-card answely-preview" data-answely-preview>';
	echo '<h2>' . esc_html__( 'What happens on a visitor\'s page', 'answely' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Follows the choices above while they are changed, before saving.', 'answely' ) . '</p>';
	echo '<ul class="answely-pages" aria-label="' . esc_attr__( 'Where Answely loads', 'answely' ) . '">';
	foreach ( $pages as $p ) {
		echo '<li class="' . esc_attr( $p[2] ? 'is-on' : 'is-off' ) . '" data-answely-page="' . esc_attr( $p[3] ) . '"><span class="dashicons dashicons-' . esc_attr( $p[0] ) . '" aria-hidden="true"></span><span class="answely-pages__name">' . esc_html( $p[1] ) . '</span>';
		echo '<span class="answely-pages__state" data-on="' . esc_attr__( 'loads', 'answely' ) . '" data-off="' . esc_attr__( 'does not load', 'answely' ) . '">' . esc_html( $p[2] ? __( 'loads', 'answely' ) : __( 'does not load', 'answely' ) ) . '</span></li>';
	}
	echo '</ul>';
	$steps = array(
		array( 'script', 'media-code', __( 'The browser loads the script file from cdn.answely.com', 'answely' ), __( 'Transmits the IP address and the user agent, like any file from another host.', 'answely' ), true ),
		array( 'consent', 'shield', __( 'The script waits for consent', 'answely' ), $e['wp_consent'] ? sprintf( /* translators: %s: consent category */ __( 'Also read from the WP Consent API, category: %s.', 'answely' ), $cat ) : __( 'Consent comes from your consent tool, IAB TCF v2 or consented.eu.', 'answely' ), (bool) $e['zustimmung'] ),
		array( 'config', 'admin-settings', __( 'Configuration from api.answely.com', 'answely' ), __( 'Site key and language; no answer yet and no quota used.', 'answely' ), true ),
		array( 'answer', 'format-chat', __( 'Answer when the visitor searches', 'answely' ), __( 'The question goes to Answely; the answer names its sources and counts towards the quota.', 'answely' ), true ),
	);
	echo '<ol class="answely-seq">';
	foreach ( $steps as $st ) {
		echo '<li class="answely-seq__step is-' . esc_attr( $st[0] ) . '" data-answely-step="' . esc_attr( $st[0] ) . '"' . ( $st[4] ? '' : ' hidden' ) . '>';
		echo '<span class="answely-seq__icon dashicons dashicons-' . esc_attr( $st[1] ) . '" aria-hidden="true"></span>';
		echo '<span class="answely-seq__body"><strong>' . esc_html( $st[2] ) . '</strong>';
		if ( 'consent' === $st[0] ) {
			/* translators: %s: consent category */
			$template = __( 'Also read from the WP Consent API, category: %s.', 'answely' );
			echo '<span class="answely-seq__text" data-answely-cat-template="' . esc_attr( $template ) . '" data-answely-cat-off="' . esc_attr__( 'Consent comes from your consent tool, IAB TCF v2 or consented.eu.', 'answely' ) . '">';
		} else {
			echo '<span class="answely-seq__text">';
		}
		echo esc_html( $st[3] ) . '</span></span></li>';
	}
	echo '</ol></div>';
}

/**
 * Fields that build the shortcode, with a preview of the answer field in light and dark.
 * Shown by admin.js; without JavaScript the example shortcode above stays as it is.
 */
function answely_shortcode_builder() {
	$fields = array(
		'label'       => array( __( 'Label', 'answely' ), 'Ask about our products' ),
		'placeholder' => array( __( 'Placeholder', 'answely' ), 'Your question' ),
		'button'      => array( __( 'Button text', 'answely' ), 'Ask' ),
	);
	echo '<div class="answely-builder" data-answely-builder hidden>';
	echo '<h3>' . esc_html__( 'Build your shortcode', 'answely' ) . '</h3>';
	echo '<div class="answely-builder__grid"><div class="answely-builder__fields">';
	foreach ( $fields as $key => $f ) {
		echo '<p><label for="answely-b-' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . '</label><input type="text" class="regular-text" id="answely-b-' . esc_attr( $key ) . '" data-answely-attr="' . esc_attr( $key ) . '" value="' . esc_attr( $f[1] ) . '" maxlength="80"></p>';
	}
	echo '<p class="description">' . esc_html__( 'The shortcode above and its copy button follow these fields. Quotation marks and square brackets are left out.', 'answely' ) . '</p>';
	echo '</div><div class="answely-builder__previews" aria-label="' . esc_attr__( 'Preview of the answer field', 'answely' ) . '" role="group">';
	foreach ( array(
		'light' => __( 'Light theme', 'answely' ),
		'dark'  => __( 'Dark theme', 'answely' ),
	) as $mode => $name ) {
		echo '<figure class="answely-demo is-' . esc_attr( $mode ) . '"><figcaption>' . esc_html( $name ) . '</figcaption><div class="answely-demo__box" aria-hidden="true">';
		echo '<span class="answely-demo__label" data-answely-show="label">' . esc_html( $fields['label'][1] ) . '</span><span class="answely-demo__row"><span class="answely-demo__input" data-answely-show="placeholder">' . esc_html( $fields['placeholder'][1] ) . '</span><span class="answely-demo__button" data-answely-show="button">' . esc_html( $fields['button'][1] ) . '</span></span>';
		echo '</div></figure>';
	}
	echo '</div></div></div>';
}

/**
 * Tab "Help".
 *
 * @param array $service Service addresses.
 */
function answely_tab_help( array $service ) {
	echo '<div class="answely-panel" id="answely-help"><div class="answely-card">';
	echo '<h2>' . esc_html__( 'How Answely works on your site', 'answely' ) . '</h2>';
	$flow = array(
		array( 'admin-site-alt3', __( 'Index', 'answely' ), __( 'Answely reads the public pages of your verified domain (crawling, or the content sync of this plugin) and builds a search index.', 'answely' ) ),
		array( 'shield', __( 'Configuration', 'answely' ), __( 'On every page where it loads, the box fetches its configuration (site key and language) from Answely; with "Wait for consent" only after the visitor agrees.', 'answely' ) ),
		array( 'format-chat', __( 'Question and answer', 'answely' ), __( 'When a visitor searches, the box sends the question, the language, the site key, the page address and the referring page address (both without parameters) to Answely, for follow-up questions optionally also earlier questions and answers of the same box. Answely answers only from sections of your site that back the answer and lists them as sources. Every answer is labelled as AI-generated.', 'answely' ) ),
		array( 'search', __( 'Fallback', 'answely' ), __( 'Your search keeps working at all times; if Answely is unavailable, the box simply does not appear.', 'answely' ) ),
	);
	echo '<ol class="answely-flow">';
	foreach ( $flow as $step ) {
		echo '<li><span class="answely-flow__icon dashicons dashicons-' . esc_attr( $step[0] ) . '" aria-hidden="true"></span><strong class="answely-flow__title">' . esc_html( $step[1] ) . '</strong><span class="answely-flow__text">' . esc_html( $step[2] ) . '</span></li>';
	}
	echo '</ol>';
	echo '<h2>' . esc_html__( 'Troubleshooting', 'answely' ) . '</h2><ul class="ul-disc">';
	echo '<li>' . esc_html__( 'No box appears: check the connection on the first tab; the domain must be verified in your account and the first index must be ready (this can take a while for large sites).', 'answely' ) . '</li>';
	echo '<li>' . esc_html__( 'Open the browser console on a page with a search field and run Answely.diagnose(): "ready" and "selector_valid" must be true and "inputs" must be above zero.', 'answely' ) . '</li>';
	echo '<li>' . esc_html__( 'A Content Security Policy must allow the Answely script host (script-src) and the API host (connect-src).', 'answely' ) . '</li>';
	echo '<li>' . esc_html__( 'Caching and optimization plugins: exclude the Answely script from combining and deferring if the box does not start.', 'answely' ) . '</li>';
	echo '</ul>';
	echo '<p><a class="button" href="' . esc_url( $service['app'] . '/en/docs' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Developer docs', 'answely' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( $service['app'] . '/pricing' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Plans and quotas', 'answely' ) . '</a></p>';
	echo '</div></div>';
}

require_once __DIR__ . '/synchronisation.php';
require_once __DIR__ . '/customer-accounts.php';
