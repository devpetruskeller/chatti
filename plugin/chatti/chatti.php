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

function ptc_chatti_maybe_upgrade_schema() {
	if ( get_option( 'ptc_chatti_schema_version' ) !== '2' ) {
		ptc_chatti_install_schema();
		update_option( 'ptc_chatti_schema_version', '2', false );
	}
}
add_action( 'plugins_loaded', 'ptc_chatti_maybe_upgrade_schema', 5 );

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
		onboarding_step varchar(32) NOT NULL DEFAULT 'set_workspace',
		timezone varchar(64) NULL,
		working_hours varchar(32) NULL,
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

function ptc_chatti_workspace_for_event( $event ) {
	$workflow = (string) ( $event['workflowId'] ?? '' );
	return preg_match( '/^chatti-onboarding:([0-9a-f-]{36})$/i', $workflow, $match ) ? $match[1] : '';
}

function ptc_chatti_send_message( $workspace_id, $channel, $address, $step, $message, $variables = array(), $wait = true ) {
	$url = defined( 'PTC_CHATTI_COMMUNICATIONS_URL' ) ? trim( PTC_CHATTI_COMMUNICATIONS_URL ) : '';
	$key = defined( 'PTC_CHATTI_APP_API_KEY' ) ? trim( PTC_CHATTI_APP_API_KEY ) : '';
	$key_id = defined( 'PTC_CHATTI_APP_KEY_ID' ) ? trim( PTC_CHATTI_APP_KEY_ID ) : '';
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! $key || ! $key_id ) return new WP_Error( 'chatti_not_configured' );
	$body = array( 'appId' => 'chatti', 'workflowId' => 'chatti-onboarding:' . $workspace_id, 'stepId' => $step,
		'idempotencyKey' => 'chatti-onboarding:' . $workspace_id . ':' . $step, 'messageGroup' => 'postoochat_chatti',
		'messageName' => $message, 'channel' => $channel, 'recipient' => array( 'address' => $address ), 'variables' => $variables );
	if ( $wait ) $body['waitFor'] = array( 'type' => 'reply', 'timeoutSeconds' => 86400 );
	$response = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'X-PosToo-Key-Id' => $key_id, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	return is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ? new WP_Error( 'onboarding_send_failed' ) : true;
}

/** Calls a private Supabase service using the Chatti application credential. */
function ptc_chatti_call_service( $url, $body ) {
	$key = defined( 'PTC_CHATTI_APP_API_KEY' ) ? trim( PTC_CHATTI_APP_API_KEY ) : '';
	$key_id = defined( 'PTC_CHATTI_APP_KEY_ID' ) ? trim( PTC_CHATTI_APP_KEY_ID ) : '';
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! $key || ! $key_id ) return new WP_Error( 'chatti_service_not_configured' );
	$response = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'X-PosToo-Key-Id' => $key_id, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $response ) ) return new WP_Error( 'chatti_service_unavailable' );
	$status = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['ok'] ) ) return new WP_Error( sanitize_key( (string) ( $data['error'] ?? 'chatti_service_failed' ) ) ?: 'chatti_service_failed' );
	return $data;
}

function ptc_chatti_invitation_link( $workspace_id, $role, $channel ) {
	$url = defined( 'PTC_CHATTI_INVITATIONS_URL' ) ? trim( PTC_CHATTI_INVITATIONS_URL ) : '';
	$result = ptc_chatti_call_service( $url, array( 'workspaceId' => $workspace_id, 'role' => $role, 'channel' => $channel, 'ttlSeconds' => 604800 ) );
	return is_wp_error( $result ) || empty( $result['link'] ) ? $result : esc_url_raw( $result['link'] );
}

function ptc_chatti_resolve_timezone( $workspace_id, $channel, $latitude, $longitude ) {
	$url = defined( 'PTC_CHATTI_TIMEZONE_LOOKUP_URL' ) ? trim( PTC_CHATTI_TIMEZONE_LOOKUP_URL ) : '';
	$result = ptc_chatti_call_service( $url, array( 'latitude' => $latitude, 'longitude' => $longitude, 'scopeType' => 'workspace', 'scopeId' => $workspace_id, 'purpose' => 'workspace_base', 'channel' => $channel, 'source' => 'channel_pin', 'consentBasis' => 'owner_shared' ) );
	return is_wp_error( $result ) || empty( $result['timezone'] ) ? $result : sanitize_text_field( $result['timezone'] );
}

/** Accepts catalog action keys plus the documented 08:00 - 17:00 reply format. */
function ptc_chatti_parse_hours( $value ) {
	$value = trim( wp_strip_all_tags( (string) $value ) );
	if ( preg_match( '/^(\d{2})(\d{2})_(\d{2})(\d{2})$/', $value, $match ) ) $match = array( '', $match[1], $match[2], $match[3], $match[4] );
	elseif ( ! preg_match( '/^(\d{1,2}):(\d{2})\s*(?:-|–|to)\s*(\d{1,2}):(\d{2})$/i', $value, $match ) ) return '';
	$start = (int) $match[1] * 60 + (int) $match[2];
	$end = (int) $match[3] * 60 + (int) $match[4];
	if ( (int) $match[1] > 23 || (int) $match[3] > 23 || (int) $match[2] > 59 || (int) $match[4] > 59 || $end <= $start ) return '';
	return sprintf( '%02d:%02d-%02d:%02d', (int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4] );
}

function ptc_chatti_send_invitation( $workspace_id, $channel, $address, $role ) {
	$link = ptc_chatti_invitation_link( $workspace_id, $role, $channel );
	if ( is_wp_error( $link ) ) return $link;
	$variable = ( 'whatsapp' === $channel ? 'wapi' : 'tele' ) . '_chatti_invite_' . $role . '_link';
	$sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'invite-' . $role, 'CHATTI_INVITE_' . strtoupper( $role ), array(), false );
	if ( is_wp_error( $sent ) ) return $sent;
	return ptc_chatti_send_message( $workspace_id, $channel, $address, 'invite-' . $role . '-link', 'CHATTI_INVITE_' . strtoupper( $role ) . '_LINK', array( $variable => $link ), false );
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
	<p><?php esc_html_e( 'A verified Suite user selects Chatti, then completes the Chatti workspace onboarding flow.', 'chatti' ); ?></p></div>
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
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$address = sanitize_text_field( (string) ( $event['sender']['address'] ?? '' ) );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || '' === $address ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_owner' ), 422 );
		$workspace_id = wp_generate_uuid4(); $now = current_time( 'mysql', true ); $workspaces = $wpdb->prefix . 'chatti_workspaces'; $members = $wpdb->prefix . 'chatti_workspace_members';
		$created = $wpdb->insert( $workspaces, array( 'workspace_id' => $workspace_id, 'workspace_label' => 'Pending workspace', 'owner_channel' => $channel, 'owner_address' => $address, 'onboarding_step' => 'set_workspace', 'created_at' => $now, 'updated_at' => $now ) );
		$owner = $created && $wpdb->insert( $members, array( 'workspace_id' => $workspace_id, 'channel' => $channel, 'channel_address' => $address, 'roles' => wp_json_encode( array( 'owner' ) ), 'created_at' => $now ) );
		if ( ! $owner ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'workspace_create_failed' ), 500 );
		$sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'set-workspace', 'CHATTI_SET_WORKSPACE' );
		if ( is_wp_error( $sent ) ) {
			$status = (int) $sent->get_error_data();
			return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), $status ?: 503 );
		}
		$wpdb->insert( $events, array( 'event_id' => $event_id, 'received_at' => current_time( 'mysql', true ) ), array( '%s', '%s' ) );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'set-workspace' ) {
		$label = ptc_chatti_workspace_label( $event['reply']['text'] ?? '' );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$address = sanitize_text_field( (string) ( $event['reply']['senderAddress'] ?? $event['sender']['address'] ?? '' ) );
		if ( ! $label || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || '' === $address ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_workspace_reply' ), 422 );
		$workspaces = $wpdb->prefix . 'chatti_workspaces';
		$workspace_id = ptc_chatti_workspace_for_event( $event );
		if ( ! $workspace_id || ! $wpdb->update( $workspaces, array( 'workspace_label' => $label, 'onboarding_step' => 'set_timezone', 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id, 'owner_channel' => $channel, 'owner_address' => $address ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'workspace_update_failed' ), 422 );
		$sent = ptc_chatti_send_invitation( $workspace_id, $channel, $address, 'user' );
		if ( ! is_wp_error( $sent ) ) $sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'invite-user-link-copied', 'CHATTI_INVITE_USER_LINK_COPIED', array(), false );
		if ( ! is_wp_error( $sent ) ) $sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'set-timezone', 'CHATTI_SET_TIMEZONE' );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'set-timezone' ) {
		$workspace_id = ptc_chatti_workspace_for_event( $event );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$latitude = $event['reply']['latitude'] ?? null; $longitude = $event['reply']['longitude'] ?? null;
		if ( ! $workspace_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! is_numeric( $latitude ) || ! is_numeric( $longitude ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'location_required' ), 422 );
		$timezone = ptc_chatti_resolve_timezone( $workspace_id, $channel, (float) $latitude, (float) $longitude );
		if ( is_wp_error( $timezone ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $timezone->get_error_code() ), 503 );
		$workspaces = $wpdb->prefix . 'chatti_workspaces';
		$workspace = $wpdb->get_row( $wpdb->prepare( "SELECT owner_address FROM $workspaces WHERE workspace_id = %s AND owner_channel = %s", $workspace_id, $channel ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $workspace || ! $wpdb->update( $workspaces, array( 'timezone' => $timezone, 'onboarding_step' => 'set_hours', 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'timezone_update_failed' ), 422 );
		$sent = ptc_chatti_send_message( $workspace_id, $channel, $workspace['owner_address'], 'set-hours', 'CHATTI_SET_HOURS' );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'set-hours' ) {
		$workspace_id = ptc_chatti_workspace_for_event( $event );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$hours = ptc_chatti_parse_hours( $event['reply']['action'] ?? $event['reply']['text'] ?? '' );
		if ( ! $workspace_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! $hours ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_working_hours' ), 422 );
		$workspaces = $wpdb->prefix . 'chatti_workspaces';
		$workspace = $wpdb->get_row( $wpdb->prepare( "SELECT owner_address FROM $workspaces WHERE workspace_id = %s AND owner_channel = %s", $workspace_id, $channel ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $workspace || ! $wpdb->update( $workspaces, array( 'working_hours' => $hours, 'onboarding_step' => 'complete', 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'hours_update_failed' ), 422 );
		$sent = ptc_chatti_send_invitation( $workspace_id, $channel, $workspace['owner_address'], 'admin' );
		if ( ! is_wp_error( $sent ) ) $sent = ptc_chatti_send_invitation( $workspace_id, $channel, $workspace['owner_address'], 'worker' );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
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
	if ( is_wp_error( $response ) ) return new WP_Error( 'onboarding_send_failed', '', array( 'status' => 503 ) );
	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = is_array( $body ) ? sanitize_key( (string) ( $body['error'] ?? '' ) ) : '';
		// A missing active catalog record cannot recover through callback retries.
		// Return a terminal client error so Suite sends its seed fallback now.
		if ( 'message_definition_not_found' === $error ) {
			return new WP_Error( 'onboarding_message_definition_not_found', '', array( 'status' => 422 ) );
		}
		return new WP_Error( 'onboarding_send_failed', '', array( 'status' => 503 ) );
	}
	return true;
}
