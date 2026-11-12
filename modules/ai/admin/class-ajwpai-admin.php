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
			'AJMCPAssistant',
			array(
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'turnUrl'   => esc_url_raw( rest_url( 'ajmcp/v1/assistant/turn' ) ),
				'configUrl' => esc_url_raw( rest_url( 'ajmcp/v1/assistant/config' ) ),
				'chatsUrl'  => esc_url_raw( rest_url( 'ajmcp/v1/assistant/chats' ) ),
				'statusUrl' => esc_url_raw( rest_url( 'ajmcp/v1/assistant/status' ) ),
				'codeUrl'   => esc_url_raw( rest_url( 'ajwpai/v1/code/' ) ),
			)
		);
	}

	/** Adds the Chat screen under the given parent menu (called by AJMCP_WP_Settings::menu()). */
	public static function register_menus( $parent ) {
		add_submenu_page( $parent, 'AJMCP Assistant', __( 'Assistant', 'ajwpai' ), 'manage_options', self::SLUG, array( __CLASS__, 'render_chat' ) );
	}

	public static function render_chat() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Assistant', 'ajwpai' ); ?></h1>
			<?php if ( 0 !== strpos( home_url(), 'https://' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not on HTTPS.', 'ajwpai' ); ?></p></div>
			<?php endif; ?>
			<section class="ajwpai-code" id="ajwpai-code">
				<div class="ajwpai-code-head">
					<h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
					<span style="display:flex;gap:12px;align-items:center">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ajmcp-wp-settings' ) ); ?>" id="ajwpai-code-status" class="ajwpai-status is-offline"><?php esc_html_e( 'Checking…', 'ajwpai' ); ?></a>
						<button type="button" class="button" id="ajwpai-code-new"><?php esc_html_e( 'New chat', 'ajwpai' ); ?></button>
					</span>
				</div>
				<div class="ajwpai-split">
					<aside class="ajwpai-history"><strong><?php esc_html_e( 'Saved chats', 'ajwpai' ); ?></strong><ul id="ajwpai-history-list"></ul></aside>
					<div class="ajwpai-main">
						<div id="ajwpai-code-messages" class="ajwpai-messages" aria-live="polite"></div>
						<div id="ajwpai-code-approvals" class="ajwpai-approvals"></div>
					</div>
				</div>
				<form id="ajwpai-code-form" class="ajwpai-prompt">
					<textarea id="ajwpai-code-prompt" rows="3" placeholder="<?php echo esc_attr__( 'Ask the AI to write a blog post, draft a page, or look up a lead…', 'ajwpai' ); ?>" disabled></textarea>
					<button class="button button-primary" type="submit" disabled><?php esc_html_e( 'Send', 'ajwpai' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}
}
