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
		// Menus are registered by AJMCP_WP_Settings::menu() so everything sits under the one AJMCP menu.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ajwpai_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_ajwpai_decide', array( __CLASS__, 'handle_decide' ) );
		add_action( 'admin_post_ajwpai_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( self::SLUG, self::SLUG . '-settings' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'ajwpai-admin', plugins_url( 'modules/ai/assets/admin.css', AJWPAI_FILE ), array(), AJWPAI_VERSION );
		wp_enqueue_script( 'ajwpai-admin', plugins_url( 'modules/ai/assets/admin.js', AJWPAI_FILE ), array(), AJWPAI_VERSION, true );
		wp_localize_script(
			'ajwpai-admin',
			'AJWPAICode',
			array(
				'root'     => (string) apply_filters( 'ajwpai_code_root', '/Users/sandip/Projects/ajwp/ajmcp-wp' ),
				'endpoint' => 'http://127.0.0.1:8765',
				'restUrl'  => esc_url_raw( rest_url( 'ajwpai/v1/code/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'site'     => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url(),
					'path' => (string) apply_filters( 'ajwpai_site_root', '/Users/sandip/Projects/sites/ncllc' ),
				),
			)
		);
	}

	/** Adds the Chat and Claude screens under the given parent menu (called by AJMCP_WP_Settings::menu()). */
	public static function register_menus( $parent ) {
		$pending = AJWPAI_Changes::pending_count();
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_submenu_page( $parent, 'AJMCP Chat', __( 'Chat', 'ajwpai' ) . $badge, 'manage_options', self::SLUG, array( __CLASS__, 'render_chat' ) );
		add_submenu_page( $parent, 'AJMCP Claude', __( 'Claude', 'ajwpai' ), 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	private static function back( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG . '-settings' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function settings_url() {
		return add_query_arg( array( 'page' => self::SLUG . '-settings' ), admin_url( 'admin.php' ) );
	}

	// ── Actions ─────────────────────────────────────────────────────────────────

	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajwpai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ajwpai_revoke' );
		$id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
		if ( $id ) {
			AJWPAI_Store::revoke( $id );
		} else {
			AJWPAI_Store::revoke_all();
		}
		self::notice( 'success', __( 'Disconnected.', 'ajwpai' ) );
		wp_safe_redirect( self::back() );
		exit;
	}

	/** Approve or reject a proposal. */
	public static function handle_decide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajwpai' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_POST['change_id'] ) ? absint( $_POST['change_id'] ) : 0;
		check_admin_referer( 'ajwpai_decide_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( 'reject' === $decision ) {
			$result = AJWPAI_Changes::reject( $id, get_current_user_id() );
			self::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Rejected.', 'ajwpai' ) );
		} elseif ( 'approve' === $decision ) {
			$result = AJWPAI_Changes::apply( $id, get_current_user_id(), ! empty( $_POST['force'] ) );
			if ( is_wp_error( $result ) ) {
				self::notice( 'error', $result->get_error_message() );
			} else {
				$link = (int) $result > 0 ? '<a href="' . esc_url( get_edit_post_link( (int) $result, 'raw' ) ) . '">' . esc_html__( 'Open', 'ajwpai' ) . '</a>' : '';
				self::notice( 'success', esc_html__( 'Applied.', 'ajwpai' ) . ' ' . $link, true );
			}
		}
		wp_safe_redirect( self::back() );
		exit;
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajwpai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ajwpai_save_settings' );
		AJWPAI_Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in save().
		self::notice( 'success', __( 'Saved.', 'ajwpai' ) );
		wp_safe_redirect( self::back() );
		exit;
	}

	private static function notice( $type, $message, $html = false ) {
		set_transient( 'ajwpai_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message, 'html' => $html ), MINUTE_IN_SECONDS );
	}

	// ── Page ────────────────────────────────────────────────────────────────────

	public static function render_chat() {
		self::render( false );
	}

	public static function render_settings() {
		self::render( true );
	}

	private static function render( $settings ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$https   = 0 === strpos( home_url(), 'https://' );
		$tokens  = AJWPAI_Store::active_tokens();
		$log     = AJWPAI_Store::recent_log( 50 );
		$pending = AJWPAI_Changes::listing( 'pending', 50 );
		$decided = AJWPAI_Changes::listing( 'decided', 20 );
		$mcp     = AJWPAI_OAuth::mcp_url();
		$flash   = get_transient( 'ajwpai_notice_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'ajwpai_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $settings ? __( 'AJ WP AI Settings', 'ajwpai' ) : 'AJ WP AI' ); ?></h1>
			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo ! empty( $flash['html'] ) ? wp_kses_post( $flash['message'] ) : esc_html( $flash['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $https ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not on HTTPS.', 'ajwpai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $settings ) : ?>
			<section class="ajwpai-code" id="ajwpai-code">
				<div class="ajwpai-code-head">
					<div><h2><?php esc_html_e( 'Code', 'ajwpai' ); ?></h2><code id="ajwpai-code-root"></code></div>
					<a href="<?php echo esc_url( self::settings_url() ); ?>" id="ajwpai-code-status" class="ajwpai-status is-offline"><?php esc_html_e( 'Offline', 'ajwpai' ); ?></a>
				</div>
				<div class="ajwpai-code-meta">
					<label><span><?php esc_html_e( 'Mode', 'ajwpai' ); ?></span><select id="ajwpai-code-mode"><option value="edit" selected><?php esc_html_e( 'Edit', 'ajwpai' ); ?></option><option value="plan"><?php esc_html_e( 'Plan', 'ajwpai' ); ?></option></select></label>
					<button type="button" class="button" id="ajwpai-code-new" disabled><?php esc_html_e( 'New chat', 'ajwpai' ); ?></button>
				</div>
				<div id="ajwpai-code-messages" class="ajwpai-messages" aria-live="polite"></div>
				<div id="ajwpai-code-approvals" class="ajwpai-approvals"></div>
				<form id="ajwpai-code-form" class="ajwpai-prompt">
					<textarea id="ajwpai-code-prompt" rows="3" placeholder="<?php echo esc_attr__( 'Ask Codex about this plugin…', 'ajwpai' ); ?>" disabled></textarea>
					<button class="button button-primary" type="submit" disabled><?php esc_html_e( 'Send', 'ajwpai' ); ?></button>
				</form>
			</section>
			<?php else : ?>
			<section class="ajwpai-settings-card">
				<div class="ajwpai-code-head"><h2><?php esc_html_e( 'Companion', 'ajwpai' ); ?></h2><span id="ajwpai-code-status" class="ajwpai-status is-offline"><?php esc_html_e( 'Offline', 'ajwpai' ); ?></span></div>
				<div class="ajwpai-connect">
					<label><span><?php esc_html_e( 'Address', 'ajwpai' ); ?></span><input id="ajwpai-code-endpoint" class="regular-text code" value="http://127.0.0.1:8765"></label>
					<label><span><?php esc_html_e( 'Token', 'ajwpai' ); ?></span><input id="ajwpai-code-token" type="password" class="regular-text" autocomplete="off"></label>
					<button type="button" class="button button-primary" id="ajwpai-code-connect"><?php esc_html_e( 'Save and connect', 'ajwpai' ); ?></button>
				</div>
				<code id="ajwpai-code-root"></code>
				<div id="ajwpai-code-messages" aria-live="polite"></div>
			</section>

			<h2><?php echo esc_html( sprintf( 'Pending (%d)', count( $pending ) ) ); ?></h2>
			<?php if ( ! $pending ) : ?>
				<p>&mdash;</p>
			<?php else : ?>
				<?php foreach ( $pending as $change ) { self::render_change( $change ); } ?>
			<?php endif; ?>

			<?php if ( $decided ) : ?>
				<details style="margin:12px 0"><summary><strong><?php esc_html_e( 'History', 'ajwpai' ); ?></strong></summary>
					<table class="widefat striped" style="max-width:900px;margin-top:8px">
						<thead><tr><th>When</th><th>Kind</th><th>Title</th><th>Result</th></tr></thead>
						<tbody>
						<?php foreach ( $decided as $c ) : ?>
							<tr>
								<td><?php echo esc_html( get_date_from_gmt( $c->decided ? $c->decided : $c->created, 'Y-m-d H:i' ) ); ?></td>
								<td><?php echo esc_html( in_array( $c->kind, array( 'ajcore', 'file' ), true ) ? $c->kind : $c->kind . ' ' . $c->post_type ); ?></td>
								<td><?php echo esc_html( self::change_title( $c ) ); ?></td>
								<td><?php
									echo esc_html( $c->status );
									if ( 'applied' === $c->status && $c->result_post_id ) {
										echo ' &middot; <a href="' . esc_url( get_edit_post_link( (int) $c->result_post_id, 'raw' ) ) . '">' . esc_html__( 'open', 'ajwpai' ) . '</a>';
									}
									if ( $c->error ) {
										echo ' &middot; ' . esc_html( $c->error );
									}
								?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection', 'ajwpai' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th><?php esc_html_e( 'Address', 'ajwpai' ); ?></th>
					<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $mcp ); ?>" onfocus="this.select()"></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'ajwpai' ); ?></th>
					<td><?php echo $https ? '<span style="color:#16a34a">&#10003; HTTPS</span>' : '<span style="color:#dc2626">&#10007; HTTPS</span>'; ?>
					&nbsp; <?php echo count( $tokens ) ? '<span style="color:#16a34a">&#10003; ' . (int) count( $tokens ) . ' connected</span>' : '<span style="color:#646970">Not connected</span>'; ?></td></tr>
			</tbody></table>

			<h2>AJCore</h2>
			<?php $opts = AJWPAI_Settings::get(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ajwpai_save_settings' ); ?>
				<input type="hidden" name="action" value="ajwpai_save_settings">
				<table class="form-table" role="presentation"><tbody>
					<tr><th><?php esc_html_e( 'Share with Claude', 'ajwpai' ); ?></th>
						<td><label><input type="checkbox" name="ajcore_enabled" value="1" <?php checked( ! empty( $opts['ajcore_enabled'] ) ); ?> <?php disabled( ! class_exists( 'AJCore_REST_API' ) ); ?>> <?php esc_html_e( 'Customers, leads, requests, files', 'ajwpai' ); ?></label>
						<?php if ( ! class_exists( 'AJCore_REST_API' ) ) : ?><p class="description"><?php esc_html_e( 'AJCore is not active.', 'ajwpai' ); ?></p><?php endif; ?></td></tr>
					<tr><th><?php esc_html_e( 'AJ Ops address', 'ajwpai' ); ?></th>
						<td><input type="url" name="ajops_url" class="regular-text code" value="<?php echo esc_attr( $opts['ajops_url'] ); ?>" placeholder="<?php echo esc_attr( AJWPAI_Settings::ajops_base() ); ?>"></td></tr>
				</tbody></table>
				<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save', 'ajwpai' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Apps', 'ajwpai' ); ?></h2>
			<?php if ( ! $tokens ) : ?>
				<p>&mdash;</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>App</th><th>User</th><th>Permissions</th><th>Connected</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $tokens as $t ) : $client = AJWPAI_Store::get_client( $t->client_id ); $user = get_userdata( (int) $t->user_id ); ?>
						<tr>
							<td><?php echo esc_html( $client ? $client['name'] : $t->client_id ); ?></td>
							<td><?php echo esc_html( $user ? $user->user_login : '#' . (int) $t->user_id ); ?></td>
							<td><code><?php echo esc_html( $t->scope ); ?></code></td>
							<td><?php echo esc_html( get_date_from_gmt( $t->created, 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo $t->last_used ? esc_html( human_time_diff( strtotime( $t->last_used . ' UTC' ) ) . ' ago' ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $t->refresh_expires, 'Y-m-d' ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
									<?php wp_nonce_field( 'ajwpai_revoke' ); ?>
									<input type="hidden" name="action" value="ajwpai_revoke">
									<input type="hidden" name="token_id" value="<?php echo (int) $t->id; ?>">
									<button class="button button-small" type="submit"><?php esc_html_e( 'Disconnect', 'ajwpai' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect every app?', 'ajwpai' ) ); ?>');">
					<?php wp_nonce_field( 'ajwpai_revoke' ); ?>
					<input type="hidden" name="action" value="ajwpai_revoke">
					<button class="button" type="submit"><?php esc_html_e( 'Disconnect all', 'ajwpai' ); ?></button>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Activity', 'ajwpai' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p>&mdash;</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>Time</th><th>Tool</th><th>Arguments</th><th>Result</th><th>ms</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->created, 'Y-m-d H:i:s' ) ); ?></td>
							<td><code><?php echo esc_html( $row->tool ); ?></code></td>
							<td><code><?php echo esc_html( $row->args ); ?></code></td>
							<td><?php echo $row->ok ? '<span style="color:#16a34a">ok</span>' : '<span style="color:#dc2626">error</span>'; ?></td>
							<td><?php echo (int) $row->ms; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function change_title( $c ) {
		if ( isset( $c->payload['summary'] ) && '' !== $c->payload['summary'] ) {
			return $c->payload['summary'];
		}
		if ( isset( $c->payload['title'] ) && '' !== $c->payload['title'] ) {
			return $c->payload['title'];
		}
		return isset( $c->base['title'] ) ? $c->base['title'] : '#' . (int) $c->post_id;
	}

	/** One proposal as a card: what it is, before/after, and Approve / Reject. */
	private static function render_change( $c ) {
		if ( in_array( $c->kind, array( 'ajcore', 'file' ), true ) ) {
			self::render_action_change( $c );
			return;
		}
		$is_edit  = 'edit' === $c->kind;
		$post     = $is_edit ? get_post( (int) $c->post_id ) : null;
		$conflict = $is_edit && $post && $c->base_modified && $post->post_modified_gmt !== $c->base_modified;
		$live     = $is_edit && $post && 'publish' === $post->post_status;
		$fields   = $c->payload;
		$labels   = array( 'title' => 'Title', 'content' => 'Content', 'excerpt' => 'Excerpt', 'slug' => 'Slug', 'categories' => 'Categories', 'tags' => 'Tags', 'seo_description' => 'SEO description' );
		?>
		<div id="ajwpai-change-<?php echo (int) $c->id; ?>" class="postbox" style="max-width:900px;margin:0 0 14px">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px">
				<?php
				echo esc_html( $is_edit ? sprintf( 'Edit %s #%d', $c->post_type, $c->post_id ) : sprintf( 'New %s', $c->post_type ) );
				echo ' &mdash; ' . esc_html( self::change_title( $c ) );
				?>
			</h2></div>
			<div class="inside">
				<p style="margin-top:0;color:#646970"><?php echo esc_html( get_date_from_gmt( $c->created, 'Y-m-d H:i' ) ); ?><?php echo $is_edit && $post ? ' &middot; <a href="' . esc_url( get_edit_post_link( $post->ID, 'raw' ) ) . '">' . esc_html__( 'open', 'ajwpai' ) . '</a>' : ''; ?></p>
				<?php if ( $c->note ) : ?>
					<p><?php echo esc_html( $c->note ); ?></p>
				<?php endif; ?>
				<?php if ( $live ) : ?>
					<p style="background:#fcf9e8;border-left:4px solid #dba617;padding:6px 10px"><?php esc_html_e( 'Published: approving changes the live page.', 'ajwpai' ); ?></p>
				<?php endif; ?>
				<?php if ( $conflict ) : ?>
					<p style="background:#fcf0f1;border-left:4px solid #d63638;padding:6px 10px"><?php esc_html_e( 'Post changed since this was proposed.', 'ajwpai' ); ?></p>
				<?php endif; ?>
				<?php if ( $is_edit && ! $post ) : ?>
					<p style="color:#d63638"><?php esc_html_e( 'Post no longer exists.', 'ajwpai' ); ?></p>
				<?php endif; ?>

				<?php foreach ( $labels as $key => $label ) : if ( ! isset( $fields[ $key ] ) ) { continue; } ?>
					<?php $new = $fields[ $key ]; $old = $is_edit && isset( $c->base[ $key ] ) ? $c->base[ $key ] : ( 'categories' === $key || 'tags' === $key ? array() : '' ); ?>
					<details <?php echo 'content' === $key ? '' : 'open'; ?> style="margin:6px 0">
						<summary><strong><?php echo esc_html( $label ); ?></strong><?php echo 'content' === $key ? ' <span style="color:#646970">(' . (int) strlen( (string) $new ) . ' chars)</span>' : ''; ?></summary>
						<?php if ( is_array( $new ) ) : ?>
							<p style="margin:4px 0">
								<?php if ( $is_edit ) : ?><span style="color:#646970"><?php echo esc_html( implode( ', ', (array) $old ) ?: '—' ); ?></span> &rarr; <?php endif; ?>
								<?php foreach ( $new as $name ) {
									$known = 'categories' !== $key || get_term_by( 'slug', sanitize_title( $name ), 'category' ) || get_term_by( 'name', $name, 'category' );
									echo '<span style="display:inline-block;margin:0 6px 2px 0;padding:1px 8px;border-radius:10px;background:' . ( $known ? '#e7f5ec' : '#fcf0f1' ) . '">' . esc_html( $name ) . ( $known ? '' : ' &#10007; not found, skipped' ) . '</span>';
								} ?>
							</p>
						<?php elseif ( $is_edit && function_exists( 'wp_text_diff' ) ) : ?>
							<div style="max-height:360px;overflow:auto"><?php echo wp_text_diff( (string) $old, (string) $new, array( 'title_left' => 'Current', 'title_right' => 'Proposed' ) ) ?: '<p>' . esc_html__( 'No difference.', 'ajwpai' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php else : ?>
							<pre style="white-space:pre-wrap;max-height:360px;overflow:auto;background:#f6f7f7;padding:8px;margin:4px 0"><?php echo esc_html( (string) $new ); ?></pre>
						<?php endif; ?>
					</details>
				<?php endforeach; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;margin-top:10px;align-items:center">
					<?php wp_nonce_field( 'ajwpai_decide_' . (int) $c->id ); ?>
					<input type="hidden" name="action" value="ajwpai_decide">
					<input type="hidden" name="change_id" value="<?php echo (int) $c->id; ?>">
					<?php if ( $conflict ) : ?><input type="hidden" name="force" value="1"><?php endif; ?>
					<?php if ( ! $is_edit || $post ) : ?>
						<button class="button button-primary" type="submit" name="decision" value="approve"
							<?php echo $live ? 'onclick="return confirm(\'' . esc_js( __( 'Update the live page?', 'ajwpai' ) ) . '\');"' : ''; ?>>
							<?php echo $conflict ? esc_html__( 'Apply anyway', 'ajwpai' ) : ( $is_edit ? esc_html__( 'Apply', 'ajwpai' ) : esc_html__( 'Create draft', 'ajwpai' ) ); ?>
						</button>
					<?php endif; ?>
					<button class="button" type="submit" name="decision" value="reject"><?php esc_html_e( 'Reject', 'ajwpai' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/** A proposed AJCore action or file fix: what will run, against what, then Approve / Reject. */
	private static function render_action_change( $c ) {
		$p      = $c->payload;
		$target = isset( $p['target'] ) ? (array) $p['target'] : array();
		$url    = ! empty( $target['url'] ) ? $target['url'] : ( ! empty( $p['url'] ) ? $p['url'] : '' );
		$action = isset( $p['action'] ) ? $p['action'] : '';
		$confirm = '';
		$label   = __( 'Apply', 'ajwpai' );
		if ( 'lead_email' === $action ) {
			$label   = __( 'Send email', 'ajwpai' );
			$confirm = sprintf( __( 'Send this email to %s?', 'ajwpai' ), isset( $target['email'] ) ? $target['email'] : '' );
		} elseif ( 'chat_reply' === $action ) {
			$label   = __( 'Send reply', 'ajwpai' );
			$confirm = __( 'Send this reply to the visitor now?', 'ajwpai' );
		}
		$names = array( 'note' => 'Note', 'tags' => 'Tags', 'status' => 'Status', 'follow_up_at' => 'Follow up', 'subject' => 'Subject', 'body' => 'Message' );
		?>
		<div id="ajwpai-change-<?php echo (int) $c->id; ?>" class="postbox" style="max-width:900px;margin:0 0 14px">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px"><?php echo esc_html( self::change_title( $c ) ); ?></h2></div>
			<div class="inside">
				<p style="margin-top:0;color:#646970"><?php echo esc_html( get_date_from_gmt( $c->created, 'Y-m-d H:i' ) ); ?><?php echo $url ? ' &middot; <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'AJ Ops', 'ajwpai' ) . '</a>' : ''; ?></p>
				<?php if ( $c->note ) : ?><p><?php echo esc_html( $c->note ); ?></p><?php endif; ?>
				<table class="widefat striped" style="margin:6px 0">
					<tbody>
					<?php if ( 'file' === $c->kind ) : ?>
						<tr><th style="width:120px">File</th><td><?php echo esc_html( isset( $p['file_title'] ) ? $p['file_title'] : '' ); ?> <span style="color:#646970">#<?php echo (int) ( isset( $p['file_id'] ) ? $p['file_id'] : 0 ); ?></span></td></tr>
						<tr><th>Fix</th><td><?php echo esc_html( isset( $p['fix'] ) ? $p['fix'] : '' ); ?></td></tr>
						<?php if ( ! empty( $p['key'] ) ) : ?><tr><th>Object</th><td><code style="word-break:break-all"><?php echo esc_html( $p['key'] ); ?></code></td></tr><?php endif; ?>
					<?php else : ?>
						<?php if ( ! empty( $target['name'] ) || ! empty( $target['email'] ) ) : ?>
							<tr><th style="width:120px"><?php echo 'lead_email' === $action ? 'To' : 'Lead'; ?></th><td><?php echo esc_html( trim( ( isset( $target['name'] ) ? $target['name'] : '' ) . ' ' . ( ! empty( $target['email'] ) ? '<' . $target['email'] . '>' : '' ) ) ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $names as $key => $name ) : if ( ! isset( $p['params'][ $key ] ) || '' === $p['params'][ $key ] || array() === $p['params'][ $key ] ) { continue; } $v = $p['params'][ $key ]; ?>
							<tr><th style="width:120px"><?php echo esc_html( $name ); ?></th><td><?php echo is_array( $v ) ? esc_html( implode( ', ', $v ) ) : '<div style="white-space:pre-wrap">' . esc_html( $v ) . '</div>'; ?></td></tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;margin-top:10px;align-items:center">
					<?php wp_nonce_field( 'ajwpai_decide_' . (int) $c->id ); ?>
					<input type="hidden" name="action" value="ajwpai_decide">
					<input type="hidden" name="change_id" value="<?php echo (int) $c->id; ?>">
					<button class="button button-primary" type="submit" name="decision" value="approve"<?php echo $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>><?php echo esc_html( 'file' === $c->kind ? __( 'Apply fix', 'ajwpai' ) : $label ); ?></button>
					<button class="button" type="submit" name="decision" value="reject"><?php esc_html_e( 'Reject', 'ajwpai' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
