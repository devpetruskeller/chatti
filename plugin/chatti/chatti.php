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
	add_action( 'admin_post_ptc_chatti_workspace', 'ptc_chatti_handle_workspace_admin_post' );
}
add_action( 'plugins_loaded', 'ptc_chatti_bootstrap' );
register_activation_hook( __FILE__, 'ptc_chatti_install_schema' );

function ptc_chatti_maybe_upgrade_schema() {
	if ( get_option( 'ptc_chatti_schema_version' ) !== '3' ) {
		ptc_chatti_install_schema();
		update_option( 'ptc_chatti_schema_version', '3', false );
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
		check_in_status varchar(16) NOT NULL DEFAULT 'checked_out',
		checked_in_at datetime NULL,
		checked_out_at datetime NULL,
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
	// Existing installations may have test duplicates from before this protocol.
	// Do not delete data during an upgrade; add the database guard only once the
	// owner identity is already clean. The application lookup above protects
	// normal repeat entries in either case.
	$duplicates = $wpdb->get_var( "SELECT owner_address FROM $workspaces GROUP BY owner_channel, owner_address HAVING COUNT(*) > 1 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $duplicates ) {
		$indexes = $wpdb->get_results( "SHOW INDEX FROM $workspaces WHERE Key_name = 'owner_workspace'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $indexes ) $wpdb->query( "ALTER TABLE $workspaces ADD UNIQUE KEY owner_workspace (owner_channel, owner_address)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

function ptc_chatti_workspace_for_event( $event ) {
	$workflow = (string) ( $event['workflowId'] ?? '' );
	return preg_match( '/^chatti-onboarding:([0-9a-f-]{36})$/i', $workflow, $match ) ? $match[1] : '';
}

function ptc_chatti_send_message( $workspace_id, $channel, $address, $step, $message, $variables = array(), $wait = true, $idempotency_suffix = '' ) {
	$url = defined( 'PTC_CHATTI_COMMUNICATIONS_URL' ) ? trim( PTC_CHATTI_COMMUNICATIONS_URL ) : '';
	$key = defined( 'PTC_CHATTI_APP_API_KEY' ) ? trim( PTC_CHATTI_APP_API_KEY ) : '';
	$key_id = defined( 'PTC_CHATTI_APP_KEY_ID' ) ? trim( PTC_CHATTI_APP_KEY_ID ) : '';
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! $key || ! $key_id ) return new WP_Error( 'chatti_not_configured' );
	$idempotency_key = 'chatti-onboarding:' . $workspace_id . ':' . $step;
	if ( '' !== $idempotency_suffix ) $idempotency_key .= ':' . sanitize_key( $idempotency_suffix );
	$body = array( 'appId' => 'chatti', 'workflowId' => 'chatti-onboarding:' . $workspace_id, 'stepId' => $step,
		'idempotencyKey' => $idempotency_key, 'messageGroup' => 'postoochat_chatti',
		'messageName' => $message, 'channel' => $channel, 'recipient' => array( 'address' => $address ), 'variables' => $variables );
	if ( $wait ) $body['waitFor'] = array( 'type' => 'reply', 'timeoutSeconds' => 86400 );
	$response = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'X-PosToo-Key-Id' => $key_id, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	return is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ? new WP_Error( 'onboarding_send_failed' ) : true;
}

/** Finds the single Workspace identity that belongs to a channel/mobile owner. */
function ptc_chatti_find_workspace( $channel, $address ) {
	global $wpdb;
	$workspaces = $wpdb->prefix . 'chatti_workspaces';
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $workspaces WHERE owner_channel = %s AND owner_address = %s ORDER BY created_at ASC LIMIT 1", $channel, $address ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** Records the owner's current availability without changing the Workspace. */
function ptc_chatti_set_owner_check_status( $workspace_id, $channel, $address, $status ) {
	global $wpdb;
	if ( ! in_array( $status, array( 'checked_in', 'checked_out' ), true ) ) return false;
	$members = $wpdb->prefix . 'chatti_workspace_members';
	$now = current_time( 'mysql', true );
	$values = array( 'check_in_status' => $status );
	if ( 'checked_in' === $status ) $values['checked_in_at'] = $now;
	else $values['checked_out_at'] = $now;
	return false !== $wpdb->update( $members, $values, array( 'workspace_id' => $workspace_id, 'channel' => $channel, 'channel_address' => $address ) );
}

/** Sends a fresh CHATTI_MENU wait; each callback event is idempotent on its own. */
function ptc_chatti_send_menu( $workspace_id, $channel, $address, $status, $event_id ) {
	$label = 'checked_in' === $status ? 'Checked-IN' : 'Checked-OUT';
	return ptc_chatti_send_message( $workspace_id, $channel, $address, 'menu', 'CHATTI_MENU', array( 'check_in_out_status' => $label ), true, 'event-' . $event_id );
}

/** Close/Exit are Suite navigation actions owned by Supabase, not by Chatti. */
function ptc_chatti_navigate_suite( $channel, $address, $action ) {
	$url = defined( 'PTC_CHATTI_SESSION_NAVIGATION_URL' ) ? trim( PTC_CHATTI_SESSION_NAVIGATION_URL ) : '';
	return ptc_chatti_call_service( $url, array( 'channel' => $channel, 'address' => $address, 'action' => $action ) );
}

function ptc_chatti_menu_action( $event ) {
	$value = strtolower( trim( (string) ( $event['reply']['action'] ?? $event['reply']['text'] ?? '' ) ) );
	$value = str_replace( array( '-', ' ' ), '_', $value );
	$map = array( '1' => 'check_in', 'check_in' => 'check_in', '2' => 'check_out', 'check_out' => 'check_out', '3' => 'close', 'close' => 'close', '4' => 'exit', 'exit' => 'exit' );
	return $map[ $value ] ?? '';
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
	// WhatsApp sends the displayed menu number rather than the action key.
	// CHATTI_SET_HOURS currently exposes one live option: 08:00 - 17:00.
	// Telegram's current catalog action key is `8_5`; WhatsApp uses the
	// displayed number `1` for this same 08:00 - 17:00 option.
	if ( in_array( $value, array( '1', '8_5' ), true ) ) return '08:00-17:00';
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
	$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : 'whatsapp';
	$channel = in_array( $channel, array( 'whatsapp', 'telegram' ), true ) ? $channel : 'whatsapp';
	$editing_id = isset( $_GET['workspace'] ) ? sanitize_text_field( wp_unslash( $_GET['workspace'] ) ) : '';
	$editing = $editing_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $workspaces WHERE workspace_id = %s AND owner_channel = %s", $editing_id, $channel ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT workspace_id, workspace_label, owner_address, timezone, working_hours, onboarding_step, updated_at FROM $workspaces WHERE owner_channel = %s ORDER BY updated_at DESC", $channel ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$base = admin_url( 'tools.php?page=chatti' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Chatti workspaces', 'chatti' ); ?></h1>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Chatti channels', 'chatti' ); ?>">
			<a class="nav-tab <?php echo 'whatsapp' === $channel ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'channel', 'whatsapp', $base ) ); ?>"><?php esc_html_e( 'WhatsApp', 'chatti' ); ?></a>
			<a class="nav-tab <?php echo 'telegram' === $channel ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'channel', 'telegram', $base ) ); ?>"><?php esc_html_e( 'Telegram', 'chatti' ); ?></a>
		</nav>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Workspace saved.', 'chatti' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Workspace removed.', 'chatti' ); ?></p></div><?php endif; ?>
		<?php if ( $editing ) : ?>
			<h2><?php esc_html_e( 'Edit workspace', 'chatti' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ptc_chatti_workspace' ); ?>
				<input type="hidden" name="action" value="ptc_chatti_workspace" />
				<input type="hidden" name="operation" value="save" />
				<input type="hidden" name="workspace_id" value="<?php echo esc_attr( $editing['workspace_id'] ); ?>" />
				<input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>" />
				<table class="form-table" role="presentation"><tbody>
					<tr><th><label for="ptc-workspace-label"><?php esc_html_e( 'Workspace', 'chatti' ); ?></label></th><td><input class="regular-text" id="ptc-workspace-label" name="workspace_label" value="<?php echo esc_attr( $editing['workspace_label'] ); ?>" required maxlength="120" /></td></tr>
					<tr><th><?php esc_html_e( 'Owner mobile', 'chatti' ); ?></th><td><code><?php echo esc_html( $editing['owner_address'] ); ?></code><p class="description"><?php esc_html_e( 'The channel identity is not changed here.', 'chatti' ); ?></p></td></tr>
					<tr><th><label for="ptc-timezone"><?php esc_html_e( 'Timezone', 'chatti' ); ?></label></th><td><input class="regular-text" id="ptc-timezone" name="timezone" value="<?php echo esc_attr( $editing['timezone'] ); ?>" placeholder="Africa/Johannesburg" /></td></tr>
					<tr><th><label for="ptc-hours"><?php esc_html_e( 'Hours', 'chatti' ); ?></label></th><td><input class="regular-text" id="ptc-hours" name="working_hours" value="<?php echo esc_attr( $editing['working_hours'] ); ?>" placeholder="08:00-17:00" /></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save workspace', 'chatti' ) ); ?> <a class="button" href="<?php echo esc_url( add_query_arg( 'channel', $channel, $base ) ); ?>"><?php esc_html_e( 'Cancel', 'chatti' ); ?></a>
			</form>
		<?php endif; ?>
		<h2><?php echo esc_html( ucfirst( $channel ) ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Workspace', 'chatti' ); ?></th><th><?php esc_html_e( 'Owner mobile', 'chatti' ); ?></th><th><?php esc_html_e( 'Timezone', 'chatti' ); ?></th><th><?php esc_html_e( 'Hours', 'chatti' ); ?></th><th><?php esc_html_e( 'Status', 'chatti' ); ?></th><th><?php esc_html_e( 'Actions', 'chatti' ); ?></th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6"><?php esc_html_e( 'No workspaces yet.', 'chatti' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $edit_url = add_query_arg( array( 'channel' => $channel, 'workspace' => $row['workspace_id'] ), $base ); ?>
			<tr><td><?php echo esc_html( $row['workspace_label'] ); ?></td><td><code><?php echo esc_html( $row['owner_address'] ); ?></code></td><td><?php echo esc_html( $row['timezone'] ?: '—' ); ?></td><td><?php echo esc_html( $row['working_hours'] ?: '—' ); ?></td><td><?php echo esc_html( $row['onboarding_step'] ); ?></td><td><a class="button button-secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'chatti' ); ?></a> <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this workspace and its memberships?', 'chatti' ) ); ?>');"><?php wp_nonce_field( 'ptc_chatti_workspace' ); ?><input type="hidden" name="action" value="ptc_chatti_workspace" /><input type="hidden" name="operation" value="delete" /><input type="hidden" name="workspace_id" value="<?php echo esc_attr( $row['workspace_id'] ); ?>" /><input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>" /><button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'chatti' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</div>
	<?php
}

function ptc_chatti_handle_workspace_admin_post() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage Chatti.', 'chatti' ) );
	check_admin_referer( 'ptc_chatti_workspace' );
	$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
	$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
	if ( ! in_array( $channel, array( 'whatsapp', 'telegram' ), true ) || ! preg_match( '/^[0-9a-f-]{36}$/i', $workspace_id ) ) wp_die( esc_html__( 'Invalid workspace request.', 'chatti' ) );
	global $wpdb;
	$workspaces = $wpdb->prefix . 'chatti_workspaces';
	$members = $wpdb->prefix . 'chatti_workspace_members';
	$base = admin_url( 'tools.php?page=chatti&channel=' . $channel );
	if ( 'delete' === ( $_POST['operation'] ?? '' ) ) {
		$wpdb->query( 'START TRANSACTION' );
		$members_deleted = $wpdb->delete( $members, array( 'workspace_id' => $workspace_id ) );
		$deleted = false !== $members_deleted && $wpdb->delete( $workspaces, array( 'workspace_id' => $workspace_id, 'owner_channel' => $channel ) );
		if ( $deleted ) $wpdb->query( 'COMMIT' ); else $wpdb->query( 'ROLLBACK' );
		wp_safe_redirect( add_query_arg( $deleted ? 'deleted' : 'error', '1', $base ) ); exit;
	}
	$label = ptc_chatti_workspace_label( wp_unslash( $_POST['workspace_label'] ?? '' ) );
	$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? '' ) );
	$hours = ptc_chatti_parse_hours( wp_unslash( $_POST['working_hours'] ?? '' ) );
	if ( ! $label || ( $timezone && ! preg_match( '/^[A-Za-z_+-]+\/[A-Za-z_+\/-]+$/', $timezone ) ) || ( ! empty( $_POST['working_hours'] ) && ! $hours ) ) wp_die( esc_html__( 'Enter a workspace name, IANA timezone, and valid hours.', 'chatti' ) );
	$updated = false !== $wpdb->update( $workspaces, array( 'workspace_label' => $label, 'timezone' => $timezone ?: null, 'working_hours' => $hours ?: null, 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id, 'owner_channel' => $channel ) );
	wp_safe_redirect( add_query_arg( $updated ? 'updated' : 'error', '1', $base ) ); exit;
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
		$existing = ptc_chatti_find_workspace( $channel, $address );
		if ( $existing ) {
			$workspace_id = $existing['workspace_id'];
			if ( ! ptc_chatti_set_owner_check_status( $workspace_id, $channel, $address, 'checked_in' ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'check_in_update_failed' ), 500 );
			$sent = ptc_chatti_send_menu( $workspace_id, $channel, $address, 'checked_in', $event_id );
			if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
			$wpdb->insert( $events, array( 'event_id' => $event_id, 'received_at' => current_time( 'mysql', true ), 'workspace_id' => $workspace_id ), array( '%s', '%s', '%s' ) );
			return new WP_REST_Response( array( 'ok' => true, 'workspaceId' => $workspace_id ), 200 );
		}
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
		if ( ! $workspace_id || ! $wpdb->update( $workspaces, array( 'workspace_label' => $label, 'onboarding_step' => 'awaiting_link_copy', 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id, 'owner_channel' => $channel, 'owner_address' => $address ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'workspace_update_failed' ), 422 );
		$sent = ptc_chatti_send_invitation( $workspace_id, $channel, $address, 'user' );
		// The owner must acknowledge that the public User link was copied before
		// the next onboarding wait opens. This preserves a single active reply
		// context in both WhatsApp and Telegram.
		if ( ! is_wp_error( $sent ) ) $sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'invite-user-link-copied', 'CHATTI_INVITE_USER_LINK_COPIED' );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'invite-user-link-copied' ) {
		$workspace_id = ptc_chatti_workspace_for_event( $event );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$address = sanitize_text_field( (string) ( $event['reply']['senderAddress'] ?? $event['sender']['address'] ?? '' ) );
		$workspaces = $wpdb->prefix . 'chatti_workspaces';
		if ( ! $workspace_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || '' === $address || ! $wpdb->update( $workspaces, array( 'onboarding_step' => 'set_timezone', 'updated_at' => current_time( 'mysql', true ) ), array( 'workspace_id' => $workspace_id, 'owner_channel' => $channel, 'owner_address' => $address ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'link_copy_confirmation_failed' ), 422 );
		$sent = ptc_chatti_send_message( $workspace_id, $channel, $address, 'set-timezone', 'CHATTI_SET_TIMEZONE' );
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
		if ( ! is_wp_error( $sent ) && ! ptc_chatti_set_owner_check_status( $workspace_id, $channel, $workspace['owner_address'], 'checked_in' ) ) $sent = new WP_Error( 'check_in_update_failed' );
		if ( ! is_wp_error( $sent ) ) $sent = ptc_chatti_send_menu( $workspace_id, $channel, $workspace['owner_address'], 'checked_in', $event_id );
		if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
	} elseif ( ( $event['eventType'] ?? '' ) === 'communication.replied' && ( $event['stepId'] ?? '' ) === 'menu' ) {
		$workspace_id = ptc_chatti_workspace_for_event( $event );
		$channel = sanitize_key( (string) ( $event['channel'] ?? '' ) );
		$address = sanitize_text_field( (string) ( $event['reply']['senderAddress'] ?? $event['sender']['address'] ?? '' ) );
		$action = ptc_chatti_menu_action( $event );
		$workspace = $workspace_id ? ptc_chatti_find_workspace( $channel, $address ) : null;
		if ( ! $workspace_id || ! $workspace || $workspace['workspace_id'] !== $workspace_id || ! $action ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_menu_reply' ), 422 );
		if ( 'check_in' === $action || 'check_out' === $action ) {
			$status = 'check_in' === $action ? 'checked_in' : 'checked_out';
			if ( ! ptc_chatti_set_owner_check_status( $workspace_id, $channel, $address, $status ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'check_status_update_failed' ), 500 );
			$sent = ptc_chatti_send_menu( $workspace_id, $channel, $address, $status, $event_id );
			if ( is_wp_error( $sent ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $sent->get_error_code() ), 503 );
		} else {
			$navigated = ptc_chatti_navigate_suite( $channel, $address, $action );
			if ( is_wp_error( $navigated ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $navigated->get_error_code() ), 503 );
		}
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
