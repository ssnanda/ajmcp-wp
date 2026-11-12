<?php
/**
 * AJ WP AI: pending proposals to approve, the connector address, connected apps (with
 * disconnect), and the activity log. Terse by design: values, tables and before/after diffs.
 *
 * @package AJWPAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJWPAI_Admin {

	const SLUG = 'ajwpai';

	public static function init() {
		// The menu entry is registered by AJMCP_WP_Settings::menu() so everything sits under the one AJMCP menu.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'ajwpai-admin', plugins_url( 'modules/ai/assets/admin.css', AJWPAI_FILE ), array(), AJWPAI_VERSION );
		wp_enqueue_script( 'ajwpai-admin', plugins_url( 'modules/ai/assets/admin.js', AJWPAI_FILE ), array(), AJWPAI_VERSION, true );
		wp_localize_script(
			'ajwpai-admin',
			'AJWPAICode',
			array(
				'root'      => (string) apply_filters( 'ajwpai_code_root', '/Users/sandip/Projects/ajwp/ajmcp-wp' ),
				'endpoint'  => 'http://127.0.0.1:8765',
				'restUrl'   => esc_url_raw( rest_url( 'ajwpai/v1/code/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'site'      => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url(),
					'path' => (string) apply_filters( 'ajwpai_site_root', '/Users/sandip/Projects/sites/ncllc' ),
				),
			)
		);
	}

	/** Adds the Chat screen under the given parent menu (called by AJMCP_WP_Settings::menu()). */
	public static function register_menus( $parent ) {
		add_submenu_page( $parent, 'AJMCP Chat', __( 'Chat', 'ajwpai' ), 'manage_options', self::SLUG, array( __CLASS__, 'render_chat' ) );
	}

	public static function render_chat() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Chat</h1>
			<?php if ( 0 !== strpos( home_url(), 'https://' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not on HTTPS.', 'ajwpai' ); ?></p></div>
			<?php endif; ?>
			<section class="ajwpai-code" id="ajwpai-code">
				<div class="ajwpai-code-head">
					<div><h2><?php esc_html_e( 'AI assistant', 'ajwpai' ); ?></h2><code id="ajwpai-code-root"></code></div>
					<a href="#ajwpai-connect" id="ajwpai-code-status" class="ajwpai-status is-offline"><?php esc_html_e( 'Offline', 'ajwpai' ); ?></a>
				</div>
				<details id="ajwpai-connect" class="ajwpai-connect-box">
					<summary><?php esc_html_e( 'Companion', 'ajwpai' ); ?></summary>
					<div class="ajwpai-connect">
						<label><span><?php esc_html_e( 'Address', 'ajwpai' ); ?></span><input id="ajwpai-code-endpoint" class="regular-text code" value="http://127.0.0.1:8765"></label>
						<label><span><?php esc_html_e( 'Token', 'ajwpai' ); ?></span><input id="ajwpai-code-token" type="password" class="regular-text" autocomplete="off"></label>
						<button type="button" class="button button-primary" id="ajwpai-code-connect"><?php esc_html_e( 'Save and connect', 'ajwpai' ); ?></button>
					</div>
				</details>
				<div class="ajwpai-code-meta">
					<label><span><?php esc_html_e( 'Mode', 'ajwpai' ); ?></span><select id="ajwpai-code-mode"><option value="edit" selected><?php esc_html_e( 'Edit', 'ajwpai' ); ?></option><option value="plan"><?php esc_html_e( 'Plan', 'ajwpai' ); ?></option></select></label>
					<button type="button" class="button" id="ajwpai-code-new" disabled><?php esc_html_e( 'New chat', 'ajwpai' ); ?></button>
				</div>
				<div id="ajwpai-code-messages" class="ajwpai-messages" aria-live="polite"></div>
				<div id="ajwpai-code-approvals" class="ajwpai-approvals"></div>
				<form id="ajwpai-code-form" class="ajwpai-prompt">
					<textarea id="ajwpai-code-prompt" rows="3" placeholder="<?php echo esc_attr__( 'Ask the AI to write a blog post, draft a page, or look up a lead…', 'ajwpai' ); ?>" disabled></textarea>
					<button class="button button-primary" type="submit" disabled><?php esc_html_e( 'Send', 'ajwpai' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}
}
