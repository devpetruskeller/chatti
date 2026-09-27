<?php
/**
 * Plugin Name: PosTooChat Chatti
 * Description: Provides the PosTooChat workspace, role, availability, and routed-chat workflow foundation.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: PosTooChat
 * Text Domain: chatti
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the Chatti plugin.
 */
function ptc_chatti_bootstrap() {
	add_action( 'admin_menu', 'ptc_chatti_add_admin_page' );
}

add_action( 'plugins_loaded', 'ptc_chatti_bootstrap' );

/**
 * Adds the initial Chatti administration page.
 */
function ptc_chatti_add_admin_page() {
	add_management_page(
		__( 'Chatti', 'chatti' ),
		__( 'Chatti', 'chatti' ),
		'manage_options',
		'chatti',
		'ptc_chatti_render_admin_page'
	);
}

/**
 * Renders the initial plugin administration page.
 */
function ptc_chatti_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage Chatti.', 'chatti' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Chatti', 'chatti' ); ?></h1>
		<p><?php esc_html_e( 'Workspace onboarding and routed chat will be configured here.', 'chatti' ); ?></p>
	</div>
	<?php
}
