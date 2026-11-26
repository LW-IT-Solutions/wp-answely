<?php
/**
 * Optional WooCommerce customer panel: signed-in customers can open "My
 * orders" next to the Answely box and see their last five orders.
 *
 * Order data never leaves this site: the panel reads it from WordPress with
 * the customer's own login session, without asking Answely. Answely only
 * learns that the connection exists (pairing), once that the panel works for
 * a signed-in customer, and when it is disconnected (signed requests without
 * order data).
 *
 * @package Answely
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Texts of the customer panel (English source; translations come from
 * translate.wordpress.org).
 *
 * @return array<string,string>
 */
function answely_customer_texts() {
	return array(
		'ss_orders'            => __( 'My orders', 'answely' ),
		'ss_pair_success'      => __( 'The shop is connected. Return to Answely to check the setup status.', 'answely' ),
		'ss_wp_install'        => __( 'In your Answely account, create a one-time connection code for WooCommerce and paste it here.', 'answely' ),
		'ss_pair_code'         => __( 'One-time connection code', 'answely' ),
		'ss_pair_action'       => __( 'Connect WooCommerce', 'answely' ),
		'ss_plugin_disconnect' => __( 'Disconnect customer orders', 'answely' ),
		'ss_error'             => __( 'The connection could not be completed. Check the settings and try again.', 'answely' ),
		'ss_close'             => __( 'Close', 'answely' ),
		'ss_login'             => __( 'Sign in to view your orders', 'answely' ),
		'ss_empty'             => __( 'You are signed in. No orders were found for your account.', 'answely' ),
		'ss_order'             => __( 'Order', 'answely' ),
		'ss_date'              => __( 'Date', 'answely' ),
		'ss_status'            => __( 'Status', 'answely' ),
		'ss_total'             => __( 'Total', 'answely' ),
		'ss_session_note'      => __( 'Order details are loaded only after you request them and are cleared when the panel closes.', 'answely' ),
		'ss_logout'            => __( 'Sign out', 'answely' ),
	);
}

/**
 * One text of the customer panel.
 *
 * @param string $key Text key.
 * @return string
 */
function answely_customer_t( $key ) {
	$t = answely_customer_texts();
	return isset( $t[ $key ] ) ? $t[ $key ] : $key;
}

/**
 * Stored connection.
 *
 * @return array
 */
function answely_customer_get() {
	$c = get_option( 'answely_customer', array() );
	return is_array( $c ) ? $c : array();
}

/**
 * Is the panel connected for the current site key?
 *
 * @return bool
 */
function answely_customer_active() {
	$c = answely_customer_get();
	$e = answely_einstellungen();
	return ! empty( $c['connected'] ) && '' !== $e['key'] && ( $c['site_key'] ?? '' ) === $e['key'] && function_exists( 'wc_get_orders' );
}

/**
 * Tab "Customer orders".
 */
function answely_customer_seite() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$c  = answely_customer_get();
	$e  = answely_einstellungen();
	$wc = function_exists( 'wc_get_orders' );

	echo '<div class="answely-card"><h2>' . esc_html__( 'Customer orders in the answer box', 'answely' ) . '</h2>';
	echo '<p>' . esc_html__( 'Signed-in customers can open "My orders" next to the Answely box and see their last five orders (number, date, status, total). The data is read from WooCommerce only when the customer opens the panel, only for the signed-in customer, and is not sent to Answely.', 'answely' ) . '</p>';

	if ( ! empty( $c['connected'] ) ) {
		$same = ( $c['site_key'] ?? '' ) === $e['key'];
		echo '<div class="notice ' . ( $same && $wc ? 'notice-success' : 'notice-warning' ) . ' inline"><p>';
		if ( ! $wc ) {
			echo esc_html__( 'Connected, but WooCommerce is not active: the panel is hidden.', 'answely' );
		} elseif ( ! $same ) {
			echo esc_html__( 'Connected for a different site key: the panel is hidden. Disconnect and connect again.', 'answely' );
		} else {
			echo esc_html( answely_customer_t( 'ss_pair_success' ) );
		}
		echo '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="answely_customer_disconnect">';
		wp_nonce_field( 'answely_customer_disconnect' );
		submit_button( answely_customer_t( 'ss_plugin_disconnect' ), 'secondary' );
		echo '</form></div>';
		return;
	}

	if ( ! $wc ) {
		echo '<p>' . esc_html__( 'Requires WooCommerce.', 'answely' ) . '</p></div>';
		return;
	}
	if ( '' === $e['key'] ) {
		echo '<p>' . esc_html__( 'Save your site key on the Connection tab first.', 'answely' ) . '</p></div>';
		return;
	}
	if ( ! is_ssl() ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Connecting requires that this admin page is opened over HTTPS.', 'answely' ) . '</p></div>';
	}
	echo '<p>' . esc_html( answely_customer_t( 'ss_wp_install' ) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="answely_customer_pair">';
	wp_nonce_field( 'answely_customer_pair' );
	echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="answely-pair-code">' . esc_html( answely_customer_t( 'ss_pair_code' ) ) . '</label></th><td>';
	echo '<input type="password" id="answely-pair-code" name="code" class="regular-text code" maxlength="64" autocomplete="off" spellcheck="false" required>';
	echo '<p class="description">' . esc_html__( 'The code is valid once and for a short time. During connecting, Answely checks this site through its REST API (/wp-json/answely/v1/connection).', 'answely' ) . '</p>';
	echo '</td></tr></table>';
	submit_button( answely_customer_t( 'ss_pair_action' ) );
	echo '</form></div>';
}

/**
 * Redirects back to the tab with a notice.
 *
 * @param string $type    Notice type.
 * @param string $message Text.
 */
function answely_customer_back( $type, $message ) {
	answely_flash( $type, $message );
	wp_safe_redirect( admin_url( 'options-general.php?page=answely#customers' ) );
	exit;
}

/**
 * Pairing (admin-post.php?action=answely_customer_pair).
 */
function answely_customer_pair() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'answely' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'answely_customer_pair' );
	$code = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
	$e    = answely_einstellungen();
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $code ) ) {
		answely_customer_back( 'error', __( 'The connection code has an invalid format. Copy it again from your Answely account.', 'answely' ) );
	}
	if ( ! function_exists( 'wc_get_orders' ) || ! is_ssl() || '' === $e['key'] ) {
		answely_customer_back( 'error', answely_customer_t( 'ss_error' ) );
	}
	$app = answely_service()['app'];
	// The proof lets Answely confirm that this site holds the code; it is
	// readable through the REST route only during these few seconds.
	set_transient( 'answely_customer_pair_proof', hash( 'sha256', $code ), 600 );
	$response = wp_remote_post(
		$app . '/integration-pair.php',
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode(
				array(
					'code' => $code,
					'home' => untrailingslashit( home_url( '/' ) ),
				)
			),
		)
	);
	delete_transient( 'answely_customer_pair_proof' );
	$data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['ok'] ) || ( $data['site_key'] ?? '' ) !== $e['key'] ) {
		answely_customer_back( 'error', answely_customer_t( 'ss_error' ) );
	}
	// Shared key for signed status requests, stored encrypted with the site's auth salt.
	$salt   = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv     = random_bytes( 12 );
	$tag    = '';
	$cipher = openssl_encrypt( hash( 'sha256', 'pair:' . $code ), 'aes-256-gcm', $salt, OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $cipher ) {
		answely_customer_back( 'error', answely_customer_t( 'ss_error' ) );
	}
	update_option(
		'answely_customer',
		array(
			'connected'   => true,
			'site_key'    => $e['key'],
			'environment' => $app,
			'pair_secret' => bin2hex( $iv . $tag . $cipher ),
		),
		false
	);
	answely_customer_back( 'success', answely_customer_t( 'ss_pair_success' ) );
}
add_action( 'admin_post_answely_customer_pair', 'answely_customer_pair' );

/**
 * Disconnecting (admin-post.php?action=answely_customer_disconnect). The
 * local connection is removed even if Answely cannot be reached.
 */
function answely_customer_disconnect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'answely' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'answely_customer_disconnect' );
	$central = answely_customer_central( answely_customer_get(), 'disconnect' );
	delete_option( 'answely_customer' );
	if ( $central ) {
		answely_customer_back( 'success', __( 'Customer orders disconnected.', 'answely' ) );
	}
	answely_customer_back( 'warning', __( 'Disconnected on this site. Answely could not be reached; remove the connection in your Answely account as well.', 'answely' ) );
}
add_action( 'admin_post_answely_customer_disconnect', 'answely_customer_disconnect' );

/**
 * REST route used by Answely while pairing. It is public on purpose: Answely
 * reads it from its server. It returns only the public site key, whether
 * WooCommerce is active, whether the panel is connected and, during the few
 * seconds of a pairing started by an administrator, the hash of the code.
 */
function answely_customer_rest() {
	register_rest_route(
		'answely/v1',
		'/connection',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'answely_customer_rest_connection',
		)
	);
}
add_action( 'rest_api_init', 'answely_customer_rest' );

/**
 * Response of the REST route.
 *
 * @return WP_REST_Response
 */
function answely_customer_rest_connection() {
	$c = answely_customer_get();
	$e = answely_einstellungen();
	$r = new WP_REST_Response(
		array(
			'site_key'    => $e['key'],
			'proof'       => (string) get_transient( 'answely_customer_pair_proof' ),
			'woocommerce' => function_exists( 'wc_get_orders' ),
			'connected'   => ! empty( $c['connected'] ) && ( $c['site_key'] ?? '' ) === $e['key'],
		),
		200
	);
	$r->header( 'Cache-Control', 'no-store' );
	return $r;
}

/**
 * Stored ciphertext as binary. Version 1.3 stores hex; version 1.2 stored
 * Base64, which is still read.
 *
 * @param string $stored Stored value.
 * @return string Binary, '' if unreadable.
 */
function answely_customer_unseal_text( $stored ) {
	if ( '' !== $stored && 0 === strlen( $stored ) % 2 && ctype_xdigit( $stored ) ) {
		return (string) hex2bin( $stored );
	}
	try {
		return function_exists( 'sodium_base642bin' ) ? (string) sodium_base642bin( $stored, SODIUM_BASE64_VARIANT_ORIGINAL ) : '';
	} catch ( Exception $ex ) {
		return '';
	}
}

/**
 * Signed request to Answely: "verify" (sent once, after the panel worked for
 * a signed-in customer for the first time, so that the setup status in the
 * Answely account shows it) or "disconnect". Contains no order or customer
 * data. The result never decides whether orders are shown.
 *
 * @param array  $c         Stored connection.
 * @param string $operation verify|disconnect.
 * @return bool
 */
function answely_customer_central( array $c, $operation ) {
	$allowed = array_unique( array( 'https://answely.com', answely_service()['app'] ) );
	if ( ! in_array( $c['environment'] ?? '', $allowed, true ) || empty( $c['pair_secret'] ) || ! is_string( $c['pair_secret'] ) ) {
		return false;
	}
	$sealed = answely_customer_unseal_text( $c['pair_secret'] );
	$secret = $sealed && strlen( $sealed ) > 28
		? openssl_decrypt( substr( $sealed, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $sealed, 0, 12 ), substr( $sealed, 12, 16 ) )
		: false;
	if ( ! $secret ) {
		return false;
	}
	$body   = wp_json_encode(
		array(
			'site_key'  => $c['site_key'] ?? '',
			'time'      => time(),
			'operation' => $operation,
			'verified'  => 'verify' === $operation,
		)
	);
	$result = wp_remote_post(
		$c['environment'] . '/integration-test.php',
		array(
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'        => 'application/json',
				'X-Answely-Signature' => hash_hmac( 'sha256', $body, $secret ),
			),
			'body'        => $body,
		)
	);
	return ! is_wp_error( $result ) && 200 === (int) wp_remote_retrieve_response_code( $result );
}

/**
 * AJAX endpoint of the panel: the last five orders of the signed-in
 * customer. The customer cannot be chosen by the request.
 */
function answely_customer_orders() {
	nocache_headers();
	if ( ! is_user_logged_in() ) {
		wp_send_json( array( 'error' => 'login_required' ), 401 );
		return;
	}
	check_ajax_referer( 'answely_customer_orders', 'nonce' );
	$c = answely_customer_get();
	$e = answely_einstellungen();
	if ( empty( $c['connected'] ) || ( $c['site_key'] ?? '' ) !== $e['key'] || ! function_exists( 'wc_get_orders' ) ) {
		wp_send_json( array( 'error' => 'unavailable' ), 403 );
		return;
	}
	$uid    = get_current_user_id();
	$orders = wc_get_orders(
		array(
			'customer_id' => $uid,
			'limit'       => 5,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		)
	);
	$rows   = array();
	foreach ( $orders as $order ) {
		if ( (int) $order->get_customer_id() !== $uid ) {
			wp_send_json( array( 'error' => 'unavailable' ), 403 );
			return;
		}
		$created = $order->get_date_created();
		$rows[]  = array(
			'id'         => (string) $order->get_id(),
			'status'     => (string) $order->get_status(),
			'created_at' => $created ? $created->date( 'c' ) : '',
			'total'      => (string) $order->get_total(),
			'currency'   => (string) $order->get_currency(),
		);
	}
	if ( empty( $c['login_tested'] ) && answely_customer_central( $c, 'verify' ) ) {
		$c['login_tested'] = true;
		update_option( 'answely_customer', $c, false );
	}
	wp_send_json(
		array(
			'orders'        => $rows,
			'authenticated' => true,
		)
	);
}
add_action( 'wp_ajax_answely_customer_orders', 'answely_customer_orders' );
add_action( 'wp_ajax_nopriv_answely_customer_orders', 'answely_customer_orders' );

/**
 * Loads the panel script for connected shops.
 */
function answely_customer_enqueue() {
	if ( ! answely_customer_active() || ! answely_load_here( answely_einstellungen() ) ) {
		return;
	}
	wp_enqueue_script( 'answely-customer-panel', plugins_url( 'customer-panel.js', __FILE__ ), array(), ANSWELY_VERSION, true );
	// wp_logout_url() returns the address HTML-escaped ("&amp;"); the script needs it plain.
	$data = array(
		'endpoint' => html_entity_decode( admin_url( 'admin-ajax.php' ), ENT_QUOTES, 'UTF-8' ),
		'action'   => 'answely_customer_orders',
		'nonce'    => wp_create_nonce( 'answely_customer_orders' ),
		'login'    => html_entity_decode( (string) wc_get_page_permalink( 'myaccount' ), ENT_QUOTES, 'UTF-8' ),
		'logout'   => is_user_logged_in() ? html_entity_decode( wp_logout_url( home_url( '/' ) ), ENT_QUOTES, 'UTF-8' ) : '',
		'labels'   => answely_customer_texts(),
	);
	wp_add_inline_script( 'answely-customer-panel', 'window.AnswelyCustomerPanel = ' . wp_json_encode( $data ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'answely_customer_enqueue' );
