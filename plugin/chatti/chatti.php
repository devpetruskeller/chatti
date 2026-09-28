<?php
/**
 * Plugin Name: PosTooChat Chatti
 * Description: Provides Chatti workspace onboarding and routed-chat workflow foundations.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: PosTooChat
 * Text Domain: chatti
 */

defined( 'ABSPATH' ) || exit;

/** Bootstrap Chatti's schema, admin page, and private Supabase callback route. */
function ptc_chatti_bootstrap() {
	add_action( 'admin_menu', 'ptc_chatti_add_admin_page' );
	add_action( 'rest_api_init', 'ptc_chatti_register_rest_routes' );
}
add_action( 'plugins_loaded', 'ptc_chatti_bootstrap' );
register_activation_hook( __FILE__, 'ptc_chatti_install_schema' );

/** Creates only Chatti-owned tables, derived from the active WordPress prefix. */
function ptc_chatti_install_schema() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$workspaces = $wpdb->prefix . 'chatti_workspaces';
	$members = $wpdb->prefix . 'chatti_workspace_members';
	$events = $wpdb->prefix . 'chatti_callback_events';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE $workspaces (
		workspace_id char(36) NOT NULL,
		workspace_label varchar(120) NOT NULL,
		owner_channel varchar(16) NOT NULL,
		owner_address varchar(191) NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (workspace_id),
		KEY owner_identity (owner_channel, owner_address)
	) $charset;" );
	dbDelta( "CREATE TABLE $members (
		membership_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		workspace_id char(36) NOT NULL,
		channel varchar(16) NOT NULL,
		channel_address varchar(191) NOT NULL,
		roles longtext NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (membership_id),
		UNIQUE KEY workspace_member (workspace_id, channel, channel_address)
	) $charset;" );
	dbDelta( "CREATE TABLE $events (
		event_id char(36) NOT NULL,
		received_at datetime NOT NULL,
		workspace_id char(36) NULL,
		PRIMARY KEY  (event_id)
	) $charset;" );
}

function ptc_chatti_add_admin_page() {
	add_management_page( __( 'Chatti', 'chatti' ), __( 'Chatti', 'chatti' ), 'manage_options', 'chatti', 'ptc_chatti_render_admin_page' );
}

function ptc_chatti_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage Chatti.', 'chatti' ) );
	global $wpdb;
	$workspaces = $wpdb->prefix . 'chatti_workspaces';
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $workspaces" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<div class="wrap"><h1><?php esc_html_e( 'Chatti', 'chatti' ); ?></h1>
	<p><?php printf( esc_html__( '%d workspace(s) created through Chatti onboarding.', 'chatti' ), $count ); ?></p>
	<p><?php esc_html_e( 'A verified Suite user selects Chatti, then replies to CHATTI_ONBOARDING with the workspace name.', 'chatti' ); ?></p></div>
	<?php
}

function ptc_chatti_register_rest_routes() {
	register_rest_route( 'chatti/v1', '/events', array(
		'methods' => 'POST',
		'callback' => 'ptc_chatti_handle_callback',
		'permission_callback' => '__return_true', // Authentication is the exact-body HMAC below.
	) );
}

/** The callback signing secret is deployment-managed and must match Supabase. */
function ptc_chatti_callback_secret() {
	return defined( 'PTC_CHATTI_CALLBACK_SIGNING_SECRET' ) ? trim( PTC_CHATTI_CALLBACK_SIGNING_SECRET ) : '';
}

function ptc_chatti_handle_callback( WP_REST_Request $request ) {
	$raw = $request->get_body();
	$event_id = trim( (string) $request->get_header( 'x-postoo-event-id' ) );
	$timestamp = trim( (string) $request->get_header( 'x-postoo-timestamp' ) );
	$signature = trim( (string) $request->get_header( 'x-postoo-signature' ) );
	$secret = ptc_chatti_callback_secret();
	if ( ! $secret || ! preg_match( '/^[0-9a-f-]{36}$/i', $event_id ) || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_callback_request' ), 401 );
	}
	$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
	if ( ! hash_equals( $expected, $signature ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_callback_signature' ), 401 );
	$event = json_decode( $raw, true );
	if ( ! is_array( $event ) || ( $event['appId'] ?? '' ) !== 'chatti' ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_callback_payload' ), 422 );

	global $wpdb;
	$events = $wpdb->prefix . 'chatti_callback_events';
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM $events WHERE event_id = %s", $event_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
	}
	$workspace_id = null;
	if ( ( $event['eventType'] ?? '' ) === 'channel.message.received' ) {
		$sent = ptc_chatti_send_workspace_prompt( $event, $event_id );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'onboarding_prompt_failed' ), 503 );
		$wpdb->insert( $events, array( 'event_id' => $event_id, 'received_at' => current_time( 'mysql', true ) ), array( '%s', '%s' ) );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'workspace-name' ) {
		$label = ptc_chatti_workspace_label( $event['reply']['text'] ?? '' );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$address = sanitize_text_field( (string) ( $event['reply']['senderAddress'] ?? $event['sender']['address'] ?? '' ) );
		if ( ! $label || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || '' === $address ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_workspace_reply' ), 422 );
		$workspace_id = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		$workspaces = $wpdb->prefix . 'chatti_workspaces';
		$members = $wpdb->prefix . 'chatti_workspace_members';
		$wpdb->query( 'START TRANSACTION' );
		$claimed = $wpdb->insert( $events, array( 'event_id' => $event_id, 'received_at' => $now ), array( '%s', '%s' ) );
		if ( ! $claimed ) { $wpdb->query( 'ROLLBACK' ); return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 ); }
		$created = $wpdb->insert( $workspaces, array( 'workspace_id' => $workspace_id, 'workspace_label' => $label, 'owner_channel' => $channel, 'owner_address' => $address, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$member = $created && $wpdb->insert( $members, array( 'workspace_id' => $workspace_id, 'channel' => $channel, 'channel_address' => $address, 'roles' => wp_json_encode( array( 'owner', 'admin', 'worker' ) ), 'created_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
		if ( ! $member ) { $wpdb->query( 'ROLLBACK' ); return new WP_REST_Response( array( 'ok' => false, 'error' => 'workspace_create_failed' ), 500 ); }
		$wpdb->update( $events, array( 'workspace_id' => $workspace_id ), array( 'event_id' => $event_id ), array( '%s' ), array( '%s' ) );
		$wpdb->query( 'COMMIT' );
	} else {
		$wpdb->insert( $events, array( 'event_id' => $event_id, 'received_at' => current_time( 'mysql', true ) ), array( '%s', '%s' ) );
	}
	return new WP_REST_Response( array( 'ok' => true, 'workspaceId' => $workspace_id ), 200 );
}

function ptc_chatti_workspace_label( $value ) {
	$label = trim( wp_strip_all_tags( (string) $value ) );
	return ( strlen( $label ) >= 2 && strlen( $label ) <= 120 ) ? $label : '';
}

/** Starts the catalog-managed CHATTI_ONBOARDING wait after the Suite routes a verified person to Chatti. */
function ptc_chatti_send_workspace_prompt( $event, $event_id ) {
	$url = defined( 'PTC_CHATTI_COMMUNICATIONS_URL' ) ? trim( PTC_CHATTI_COMMUNICATIONS_URL ) : '';
	$key = defined( 'PTC_CHATTI_APP_API_KEY' ) ? trim( PTC_CHATTI_APP_API_KEY ) : '';
	$key_id = defined( 'PTC_CHATTI_APP_KEY_ID' ) ? trim( PTC_CHATTI_APP_KEY_ID ) : '';
	$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
	$address = sanitize_text_field( (string) ( $event['sender']['address'] ?? '' ) );
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! $key || ! $key_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || '' === $address ) {
		return new WP_Error( 'chatti_not_configured' );
	}
	$response = wp_remote_post( $url, array(
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $key, 'X-PosToo-Key-Id' => $key_id, 'Content-Type' => 'application/json' ),
		'body' => wp_json_encode( array(
			'appId' => 'chatti', 'workflowId' => 'suite-entry:' . $event_id, 'stepId' => 'workspace-name',
			'idempotencyKey' => 'suite-entry:' . $event_id . ':workspace-name', 'messageGroup' => 'postoochat_chatti',
			'messageName' => 'CHATTI_ONBOARDING', 'channel' => $channel,
			'recipient' => array( 'address' => $address ), 'waitFor' => array( 'type' => 'reply', 'timeoutSeconds' => 900 ),
		) ),
	) );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) return new WP_Error( 'onboarding_send_failed' );
	return true;
}
