<?php
/**
 * "Knowledge" screen: the documents the AI answers from, kept on the AJMCP server (so the server admin and this
 * screen always show the same list), plus the website-pages sync. Documents can be pasted or uploaded as text.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Knowledge {
    const PAGE       = 'ajmcp-wp-knowledge';
    const MAX_UPLOAD = 1000000;

    public static function init(): void {
        foreach ( array( 'add', 'update', 'delete', 'sync_settings', 'sync_now' ) as $action ) {
            add_action( 'admin_post_ajmcp_wp_kb_' . $action, array( __CLASS__, $action ) );
        }
    }

    private static function verify( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change the knowledge.', 'ajmcp-wp' ) );
        }
        check_admin_referer( $action );
    }

    private static function back( string $status, string $message = '', array $extra = array() ): void {
        if ( '' !== $message ) {
            set_transient( 'ajmcp_wp_notice_' . get_current_user_id(), $message, 60 );
        }
        wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE, 'ajmcp_status' => $status ), $extra ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Text from an uploaded file (plain-text formats only), or a WP_Error. */
    public static function read_upload( array $file ) {
        if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'upload_failed', 'The file could not be uploaded.' );
        }
        return self::read_text_file( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ), (int) ( $file['size'] ?? 0 ) );
    }

    /** The checks on the file itself: allowed type, size, and plain UTF-8 text. */
    public static function read_text_file( string $path, string $name, int $size ) {
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm' ), true ) ) {
            return new WP_Error( 'upload_type', 'Use a .txt, .md, .csv, .json or .html file, or paste the text.' );
        }
        if ( $size > self::MAX_UPLOAD ) {
            return new WP_Error( 'upload_size', 'That file is larger than 1 MB.' );
        }
        $text = (string) file_get_contents( $path );
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
            return new WP_Error( 'upload_encoding', 'The file is not plain UTF-8 text.' );
        }
        return in_array( $ext, array( 'html', 'htm' ), true ) ? AJMCP_WP_Site_Sync::clean_text( $text ) : $text;
    }

    private static function posted_text() {
        if ( ! empty( $_FILES['doc_file']['name'] ) ) {
            return self::read_upload( $_FILES['doc_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        return (string) wp_unslash( $_POST['doc_text'] ?? '' );
    }

    public static function add(): void {
        self::verify( 'ajmcp_wp_kb_add' );
        $text = self::posted_text();
        if ( is_wp_error( $text ) ) {
            self::back( 'error', $text->get_error_message() );
        }
        $title = sanitize_text_field( (string) wp_unslash( $_POST['doc_title'] ?? '' ) );
        if ( '' === $title && ! empty( $_FILES['doc_file']['name'] ) ) {
            $title = sanitize_text_field( pathinfo( (string) $_FILES['doc_file']['name'], PATHINFO_FILENAME ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        $result = AJMCP_WP_Client::request( '/v1/site/knowledge', 'POST', array( 'title' => $title, 'text' => $text ) );
        is_wp_error( $result ) ? self::back( 'error', AJMCP_WP_Behavior::reason( $result ) ) : self::back( 'added' );
    }

    public static function update(): void {
        self::verify( 'ajmcp_wp_kb_update' );
        $id = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['doc_id'] ?? '' ) );
        $result = AJMCP_WP_Client::request( '/v1/site/knowledge/' . $id, 'PUT', array(
            'title' => sanitize_text_field( (string) wp_unslash( $_POST['doc_title'] ?? '' ) ),
            'text'  => (string) wp_unslash( $_POST['doc_text'] ?? '' ),
        ) );
        is_wp_error( $result ) ? self::back( 'error', AJMCP_WP_Behavior::reason( $result ), array( 'edit' => $id ) ) : self::back( 'saved' );
    }

    public static function delete(): void {
        self::verify( 'ajmcp_wp_kb_delete' );
        $id = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['doc_id'] ?? '' ) );
        $result = AJMCP_WP_Client::request( '/v1/site/knowledge/' . $id, 'DELETE' );
        is_wp_error( $result ) ? self::back( 'error', AJMCP_WP_Behavior::reason( $result ) ) : self::back( 'deleted' );
    }

    public static function sync_settings(): void {
        self::verify( 'ajmcp_wp_kb_sync_settings' );
        AJMCP_WP_Site_Sync::save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in save_settings().
        self::back( 'sync_saved' );
    }

    public static function sync_now(): void {
        self::verify( 'ajmcp_wp_kb_sync_now' );
        $last = AJMCP_WP_Site_Sync::run();
        $last['ok'] ? self::back( 'synced' ) : self::back( 'error', (string) $last['error'] );
    }

    private static function kb( int $chars ): string {
        return $chars >= 1000 ? round( $chars / 1000 ) . ' KB' : $chars . ' chars';
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $status = sanitize_key( (string) ( $_GET['ajmcp_status'] ?? '' ) );
        $notice = get_transient( 'ajmcp_wp_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'ajmcp_wp_notice_' . get_current_user_id() );
        }
        $list     = AJMCP_WP_Client::request( '/v1/site/knowledge' );
        $edit_id  = preg_replace( '/[^a-f0-9]/', '', (string) ( $_GET['edit'] ?? '' ) );
        $editing  = $edit_id ? AJMCP_WP_Client::request( '/v1/site/knowledge/' . $edit_id ) : null;
        $sync     = AJMCP_WP_Site_Sync::settings();
        $messages = array( 'added' => 'Document added.', 'saved' => 'Saved.', 'deleted' => 'Document deleted.', 'sync_saved' => 'Website sync settings saved.', 'synced' => 'Website pages synced.' );
        ?>
        <div class="wrap">
            <h1>Knowledge</h1>
            <?php if ( isset( $messages[ $status ] ) ) : ?><div class="notice notice-success"><p><?php echo esc_html( $messages[ $status ] ); ?> The server admin shows the same documents.</p></div><?php endif; ?>
            <?php if ( 'error' === $status && $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
            <?php if ( is_wp_error( $list ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( AJMCP_WP_Behavior::reason( $list ) ); ?></p></div>
            </div>
            <?php return; endif; ?>

            <?php if ( is_array( $editing ) ) : ?>
                <h2>Edit document</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                    <input type="hidden" name="action" value="ajmcp_wp_kb_update" />
                    <input type="hidden" name="doc_id" value="<?php echo esc_attr( $edit_id ); ?>" />
                    <?php wp_nonce_field( 'ajmcp_wp_kb_update' ); ?>
                    <p><input type="text" name="doc_title" class="large-text" value="<?php echo esc_attr( (string) $editing['title'] ); ?>" /></p>
                    <p><textarea name="doc_text" rows="18" class="large-text code"><?php echo esc_textarea( (string) $editing['text'] ); ?></textarea></p>
                    <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ); ?>">Cancel</a>
                </form>
            <?php endif; ?>

            <h2>Documents</h2>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Title</th><th style="width:110px">Source</th><th style="width:80px">Size</th><th style="width:150px"></th></tr></thead>
                <tbody>
                <?php if ( empty( $list['documents'] ) ) : ?><tr><td colspan="4">No documents yet.</td></tr><?php endif; ?>
                <?php foreach ( (array) ( $list['documents'] ?? array() ) as $doc ) : $site_doc = 'website' === $doc['source']; ?>
                    <tr>
                        <td><?php echo esc_html( $doc['title'] ); ?></td>
                        <td><?php echo esc_html( $site_doc ? 'Website' : 'Manual' ); ?></td>
                        <td><?php echo esc_html( self::kb( (int) $doc['chars'] ) ); ?></td>
                        <td>
                            <?php if ( ! $site_doc ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => $doc['id'] ), admin_url( 'admin.php' ) ) ); ?>">Edit</a>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Delete this document?');">
                                    <input type="hidden" name="action" value="ajmcp_wp_kb_delete" /><input type="hidden" name="doc_id" value="<?php echo esc_attr( $doc['id'] ); ?>" />
                                    <?php wp_nonce_field( 'ajmcp_wp_kb_delete' ); ?>
                                    <button class="button button-small" type="submit">Delete</button>
                                </form>
                            <?php else : ?>
                                <span style="color:#646970">synced from the site</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Add a document</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="ajmcp_wp_kb_add" />
                <?php wp_nonce_field( 'ajmcp_wp_kb_add' ); ?>
                <p><input type="text" name="doc_title" class="large-text" placeholder="Title (e.g. Pricing, FAQ, Services)" /></p>
                <p><input type="file" name="doc_file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm,text/*" /> <span style="color:#646970">or paste the text below</span></p>
                <p><textarea name="doc_text" rows="8" class="large-text"></textarea></p>
                <?php submit_button( 'Add document', 'primary', 'submit', false ); ?>
            </form>

            <h2>Website pages</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="ajmcp_wp_kb_sync_settings" />
                <?php wp_nonce_field( 'ajmcp_wp_kb_sync_settings' ); ?>
                <p>
                    <?php foreach ( AJMCP_WP_Site_Sync::available_types() as $slug => $label ) : ?>
                        <label style="margin-right:14px"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $sync['types'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </p>
                <p><label>Up to <input type="number" name="max" min="1" max="150" value="<?php echo esc_attr( (string) $sync['max'] ); ?>" style="width:70px" /> newest items</label>
                   &nbsp; <label>Skip IDs <input type="text" name="exclude" value="<?php echo esc_attr( (string) $sync['exclude'] ); ?>" placeholder="12, 40" style="width:140px" /></label></p>
                <p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $sync['enabled'] ) ); ?> /> Keep in sync automatically when a page or post changes</label></p>
                <?php submit_button( 'Save sync settings', 'secondary', 'submit', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
                <input type="hidden" name="action" value="ajmcp_wp_kb_sync_now" />
                <?php wp_nonce_field( 'ajmcp_wp_kb_sync_now' ); ?>
                <?php submit_button( 'Sync now', 'secondary', 'submit', false ); ?>
                <?php if ( ! empty( $sync['last'] ) ) : ?>
                    <span style="color:#646970">Last sync <?php echo esc_html( human_time_diff( (int) $sync['last']['time'] ) ); ?> ago:
                        <?php echo ! empty( $sync['last']['ok'] ) ? esc_html( $sync['last']['count'] . ' pages' . ( $sync['last']['removed'] ? ', ' . $sync['last']['removed'] . ' removed' : '' ) ) : esc_html( (string) ( $sync['last']['error'] ?? 'failed' ) ); ?></span>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}
