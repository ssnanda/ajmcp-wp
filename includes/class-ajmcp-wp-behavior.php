<?php
/**
 * "AI Behavior" screen. The settings live on the AJMCP server (the single source of truth); this screen reads
 * them fresh on every load and writes changes straight back, so the server admin and WordPress always agree.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Behavior {
    const PAGE = 'ajmcp-wp-behavior';

    public static function init(): void {
        add_action( 'admin_post_ajmcp_wp_save_behavior', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_ajmcp_wp_try', array( __CLASS__, 'try_it' ) );
    }

    private static function verify( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change the AI.', 'ajmcp-wp' ) );
        }
        check_admin_referer( $action );
    }

    private static function back( string $status ): void {
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'ajmcp_status' => $status ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Human wording for the server's short error codes. */
    public static function reason( $error ): string {
        $data = $error instanceof WP_Error ? (array) $error->get_error_data() : array();
        $map  = array(
            'document_too_large' => 'That document is too large (200,000 characters at most).', 'knowledge_full' => 'The knowledge base is full (500,000 characters in total).',
            'too_many_documents' => 'Too many documents (250 at most).', 'title_required' => 'A title is required.', 'text_required' => 'The document has no text.',
            'system_prompt_too_long' => 'The instructions are too long.', 'lead_instructions_too_long' => 'The lead instructions are too long.', 'fallback_too_long' => 'The fallback reply is too long.',
        );
        $reason = (string) ( $data['reason'] ?? '' );
        if ( isset( $map[ $reason ] ) ) {
            return $map[ $reason ];
        }
        return 'ajmcp_not_configured' === ( $error instanceof WP_Error ? $error->get_error_code() : '' ) ? 'The AJMCP server or token is not set up (AJMCP → Settings).' : 'The AJMCP server could not do that' . ( '' !== $reason ? ' (' . $reason . ')' : '' ) . '.';
    }

    public static function save(): void {
        self::verify( 'ajmcp_wp_save_behavior' );
        $result = AJMCP_WP_Client::request( '/v1/site/config', 'PUT', array(
            'instructions'     => (string) wp_unslash( $_POST['instructions'] ?? '' ),
            'strict'           => ! empty( $_POST['strict'] ),
            'fallback'         => sanitize_text_field( (string) wp_unslash( $_POST['fallback'] ?? '' ) ),
            'leadCapture'      => ! empty( $_POST['lead_capture'] ),
            'leadInstructions' => (string) wp_unslash( $_POST['lead_instructions'] ?? '' ),
        ) );
        if ( is_wp_error( $result ) ) {
            set_transient( 'ajmcp_wp_notice_' . get_current_user_id(), self::reason( $result ), 60 );
            self::back( 'error' );
        }
        self::back( 'saved' );
    }

    /** Asks the AI one question exactly as a visitor would (no lead capture). */
    public static function try_it(): void {
        self::verify( 'ajmcp_wp_try' );
        $question = trim( (string) wp_unslash( $_POST['question'] ?? '' ) );
        if ( '' !== $question ) {
            $result = AJMCP_WP_Client::request( '/v1/chat', 'POST', array( 'messages' => array( array( 'role' => 'user', 'content' => mb_substr( $question, 0, 2000 ) ) ) ) );
            set_transient( 'ajmcp_wp_try_' . get_current_user_id(), array(
                'q' => $question,
                'a' => is_wp_error( $result ) ? 'Error: ' . self::reason( $result ) : (string) ( $result['reply'] ?? '' ),
            ), 5 * MINUTE_IN_SECONDS );
        }
        self::back( 'tried' );
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $cfg    = AJMCP_WP_Client::request( '/v1/site/config' );
        $status = sanitize_key( (string) ( $_GET['ajmcp_status'] ?? '' ) );
        $notice = get_transient( 'ajmcp_wp_notice_' . get_current_user_id() );
        $try    = get_transient( 'ajmcp_wp_try_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'ajmcp_wp_notice_' . get_current_user_id() );
        }
        ?>
        <div class="wrap">
            <h1>AI Behavior</h1>
            <?php if ( 'saved' === $status ) : ?><div class="notice notice-success"><p>Saved. The server admin shows the same settings.</p></div><?php endif; ?>
            <?php if ( 'error' === $status && $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
            <?php if ( is_wp_error( $cfg ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( self::reason( $cfg ) ); ?></p></div>
            </div>
            <?php return; endif; ?>
            <p style="color:#646970">Stored on the AJMCP server: <?php echo esc_html( $cfg['provider'] . ( '' !== $cfg['model'] ? ' · ' . $cfg['model'] : '' ) ); ?>. Changes here and in the server admin are the same settings.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="ajmcp_wp_save_behavior" />
                <?php wp_nonce_field( 'ajmcp_wp_save_behavior' ); ?>
                <h2>Instructions</h2>
                <textarea name="instructions" rows="12" class="large-text code"><?php echo esc_textarea( (string) $cfg['instructions'] ); ?></textarea>
                <p><label><input type="checkbox" name="strict" value="1" <?php checked( ! empty( $cfg['strict'] ) ); ?> /> Answer only from the knowledge</label></p>
                <p><label>Fallback reply when the answer is not in the knowledge<br /><input type="text" name="fallback" class="large-text" value="<?php echo esc_attr( (string) $cfg['fallback'] ); ?>" /></label></p>
                <h2>Lead capture</h2>
                <p><label><input type="checkbox" name="lead_capture" value="1" <?php checked( ! empty( $cfg['leadCapture'] ) ); ?> /> Capture leads from chat (the AI asks permission first; leads go to AJCore → Leads)</label></p>
                <p><label>Qualification instructions<br /><textarea name="lead_instructions" rows="5" class="large-text"><?php echo esc_textarea( (string) $cfg['leadInstructions'] ); ?></textarea></label></p>
                <?php submit_button( 'Save' ); ?>
            </form>
            <h2>Try it</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="ajmcp_wp_try" />
                <?php wp_nonce_field( 'ajmcp_wp_try' ); ?>
                <input type="text" name="question" class="large-text" placeholder="Ask as a visitor would…" />
                <?php submit_button( 'Ask', 'secondary' ); ?>
            </form>
            <?php if ( is_array( $try ) ) : ?>
                <div style="max-width:900px;background:#fff;border:1px solid #dcdcde;padding:10px 14px">
                    <p style="margin-top:0"><strong>You:</strong> <?php echo esc_html( $try['q'] ); ?></p>
                    <p style="margin-bottom:0;white-space:pre-wrap"><strong>AI:</strong> <?php echo esc_html( $try['a'] ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
