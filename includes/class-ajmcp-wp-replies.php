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

    /** Conversations that have at least one AI reply, newest activity first, with all their messages. */
    private static function conversations( int $page ): array {
        $db   = AJMCP_WP_Chat::db();
        $uuid = AJMCP_WP_Client::site_uuid();
        $m    = $db ? AJMCP_WP_Chat::table( $db, 'aj_portal_chat_messages' ) : '';
        $s    = $db ? AJMCP_WP_Chat::table( $db, 'aj_portal_chat_sessions' ) : '';
        if ( ! $m || ! $s || '' === $uuid ) {
            return array( 'total' => 0, 'rows' => array(), 'ready' => false );
        }
        $has_ai = "EXISTS (SELECT 1 FROM `{$m}` a WHERE a.session_id = s.id AND a.sender_type = 'staff' AND a.sender_name = %s)";
        $total  = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$s}` s WHERE s.site_uuid = %s AND {$has_ai}", $uuid, 'AJMCP AI' ) );
        $rows   = $db->get_results( $db->prepare(
            "SELECT s.id, s.visitor_name, s.status, s.created_at, s.last_message_at FROM `{$s}` s
             WHERE s.site_uuid = %s AND {$has_ai} ORDER BY s.last_message_at DESC, s.id DESC LIMIT %d OFFSET %d",
            $uuid, 'AJMCP AI', self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE
        ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $by   = array();
        if ( $rows ) {
            $ids = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
            $in  = implode( ',', $ids ); // Integers only.
            $msgs = $db->get_results( "SELECT session_id, sender_type, sender_name, body, created_at FROM `{$m}` WHERE session_id IN ({$in}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ( (array) $msgs as $msg ) {
                $by[ (int) $msg['session_id'] ][] = $msg;
            }
        }
        foreach ( $rows as &$row ) {
            $row['messages'] = $by[ (int) $row['id'] ] ?? array();
        }
        unset( $row );
        return array( 'total' => $total, 'rows' => $rows, 'ready' => true );
    }

    private static function bubble( array $msg ): void {
        $type  = (string) $msg['sender_type'];
        $name  = (string) $msg['sender_name'];
        $class = 'visitor' === $type ? 'is-visitor' : ( 'AJMCP AI' === $name ? 'is-ai' : 'is-staff' );
        echo '<div class="ajmcp-msg ' . esc_attr( $class ) . '"><div class="ajmcp-body">' . nl2br( esc_html( (string) $msg['body'] ) ) . '</div><div class="ajmcp-meta">' . esc_html( $name . ' · ' . $msg['created_at'] ) . '</div></div>';
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $data = self::conversations( $page );
        $fail = get_option( self::FAILURES_OPTION, array() );
        $fail = is_array( $fail ) ? $fail : array();
        ?>
        <style>
            .ajmcp-conv{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:0 0 8px;max-width:900px}
            .ajmcp-conv>summary{cursor:pointer;padding:10px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
            .ajmcp-conv .ajmcp-sub{color:#646970;font-size:12px}
            .ajmcp-thread{padding:6px 14px 14px;display:flex;flex-direction:column;gap:8px;border-top:1px solid #f0f0f1}
            .ajmcp-msg{max-width:78%;padding:8px 12px;border-radius:10px;background:#f0f0f1}
            .ajmcp-msg.is-ai{align-self:flex-end;background:#e7ecff}
            .ajmcp-msg.is-staff{align-self:flex-end;background:#e6f4ea}
            .ajmcp-meta{color:#646970;font-size:11px;margin-top:2px}
            .ajmcp-pill{font-size:11px;padding:1px 8px;border-radius:10px;border:1px solid #dcdcde}
            .ajmcp-pill.open{color:#1e8449;border-color:#1e8449}
        </style>
        <div class="wrap">
            <h1>AJMCP Conversations <small><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></small></h1>
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
            <h2>Chats answered by AI</h2>
            <?php if ( ! $data['rows'] ) : ?>
                <p>No AI conversations yet.</p>
            <?php endif; ?>
            <?php foreach ( $data['rows'] as $i => $conv ) :
                $count = count( $conv['messages'] );
                $ai    = count( array_filter( $conv['messages'], static function ( $m ) { return 'AJMCP AI' === $m['sender_name']; } ) );
                ?>
                <details class="ajmcp-conv"<?php echo ( 0 === $i && 1 === $page ) ? ' open' : ''; ?>>
                    <summary>
                        <strong>#<?php echo esc_html( (string) $conv['id'] ); ?> <?php echo esc_html( (string) $conv['visitor_name'] ); ?></strong>
                        <span class="ajmcp-pill <?php echo esc_attr( (string) $conv['status'] ); ?>"><?php echo esc_html( (string) $conv['status'] ); ?></span>
                        <span class="ajmcp-sub"><?php echo esc_html( $count . ' messages · ' . $ai . ' by AI · ' . $conv['last_message_at'] ); ?></span>
                    </summary>
                    <div class="ajmcp-thread">
                        <?php foreach ( $conv['messages'] as $msg ) { self::bubble( $msg ); } ?>
                    </div>
                </details>
            <?php endforeach; ?>
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
