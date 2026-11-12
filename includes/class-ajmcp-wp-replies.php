<?php
/**
 * "Replies" screen: every AI reply posted to AJCore Live Chat, with the visitor message it answered,
 * plus the latest failures. Reads AJCore's own chat tables; nothing extra is stored except the failure list.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Replies {
    const FAILURES_OPTION = 'ajmcp_wp_failures';
    const MAX_FAILURES    = 30;
    const PER_PAGE        = 25;

    /** Records a reply that was given up on (after the last retry). */
    public static function note_failure( int $session_id, int $message_id, string $reason ): void {
        $list = get_option( self::FAILURES_OPTION, array() );
        $list = is_array( $list ) ? $list : array();
        array_unshift( $list, array(
            'time'    => time(),
            'session' => $session_id,
            'message' => $message_id,
            'reason'  => sanitize_key( $reason ),
        ) );
        update_option( self::FAILURES_OPTION, array_slice( $list, 0, self::MAX_FAILURES ), false );
    }

    private static function rows( int $page ): array {
        $db   = AJMCP_WP_Chat::db();
        $uuid = AJMCP_WP_Client::site_uuid();
        $m    = $db ? AJMCP_WP_Chat::table( $db, 'aj_portal_chat_messages' ) : '';
        $s    = $db ? AJMCP_WP_Chat::table( $db, 'aj_portal_chat_sessions' ) : '';
        if ( ! $m || ! $s || '' === $uuid ) {
            return array( 'total' => 0, 'rows' => array(), 'ready' => false );
        }
        $where = "m.sender_type = 'staff' AND m.sender_name = %s AND s.site_uuid = %s";
        $total = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$m}` m JOIN `{$s}` s ON s.id = m.session_id WHERE {$where}", 'AJMCP AI', $uuid ) );
        $rows  = $db->get_results( $db->prepare(
            "SELECT m.id, m.session_id, m.body, m.created_at, s.visitor_name,
                (SELECT v.body FROM `{$m}` v WHERE v.session_id = m.session_id AND v.id < m.id AND v.sender_type = 'visitor' ORDER BY v.id DESC LIMIT 1) AS question
             FROM `{$m}` m JOIN `{$s}` s ON s.id = m.session_id
             WHERE {$where} ORDER BY m.id DESC LIMIT %d OFFSET %d",
            'AJMCP AI', $uuid, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE
        ), ARRAY_A );
        return array( 'total' => $total, 'rows' => is_array( $rows ) ? $rows : array(), 'ready' => true );
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $data = self::rows( $page );
        $fail = get_option( self::FAILURES_OPTION, array() );
        $fail = is_array( $fail ) ? $fail : array();
        ?>
        <div class="wrap">
            <h1>AJMCP Replies <small><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></small></h1>
            <?php if ( ! $data['ready'] ) : ?>
                <div class="notice notice-warning"><p>AJCore chat data is not available here. Needs AJCore active and the site UUID set.</p></div>
            <?php endif; ?>
            <?php if ( $fail ) : ?>
                <h2>Failed replies</h2>
                <table class="widefat striped" style="max-width:720px">
                    <thead><tr><th>When</th><th>Chat</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach ( array_slice( $fail, 0, 10 ) as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( human_time_diff( (int) $row['time'] ) . ' ago' ); ?></td>
                            <td>#<?php echo esc_html( (string) $row['session'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['reason'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <h2>AI replies</h2>
            <table class="widefat striped">
                <thead><tr><th style="width:130px">Time</th><th style="width:70px">Chat</th><th style="width:20%">Visitor</th><th>Asked</th><th>AI replied</th></tr></thead>
                <tbody>
                <?php if ( ! $data['rows'] ) : ?>
                    <tr><td colspan="5">No AI replies yet.</td></tr>
                <?php endif; ?>
                <?php foreach ( $data['rows'] as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
                        <td>#<?php echo esc_html( (string) $row['session_id'] ); ?></td>
                        <td><?php echo esc_html( (string) $row['visitor_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $row['question'] ); ?></td>
                        <td><?php echo nl2br( esc_html( (string) $row['body'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $pages = (int) ceil( $data['total'] / self::PER_PAGE );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $pages,
                ) ) ) . '</div></div>';
            }
            ?>
        </div>
        <?php
    }
}
