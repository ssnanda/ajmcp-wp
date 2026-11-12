<?php
/**
 * "Leads" screen: leads the AI captured in chat, read straight from AJCore's own tables, with the AI's note
 * (consent, services, classification, summary) and a link to the lead in AJCore.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Leads_Page {
    const PER_PAGE = 25;

    /** Reads one visible field out of AJCore's lead_data JSON. */
    private static function field( array $data, array $keys ): string {
        foreach ( $keys as $key ) {
            foreach ( $data as $field_key => $field ) {
                if ( '_meta' === $field_key || ! is_array( $field ) ) {
                    continue;
                }
                $label = strtolower( (string) ( $field['label'] ?? '' ) );
                if ( strtolower( (string) $field_key ) === $key || $label === $key ) {
                    $value = $field['value'] ?? '';
                    return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
                }
            }
        }
        return '';
    }

    private static function rows( int $page ): array {
        $db   = AJMCP_WP_Chat::db();
        $uuid = AJMCP_WP_Client::site_uuid();
        $l    = $db ? AJMCP_WP_Chat::table( $db, 'aj_forms_leads' ) : '';
        $n    = $db ? AJMCP_WP_Chat::table( $db, 'aj_forms_lead_notes' ) : '';
        if ( ! $l || ! $n || '' === $uuid ) {
            return array( 'ready' => false, 'total' => 0, 'rows' => array() );
        }
        $like  = $db->esc_like( 'AI chat lead' ) . '%';
        $total = (int) $db->get_var( $db->prepare( "SELECT COUNT(DISTINCT l.id) FROM `{$l}` l JOIN `{$n}` n ON n.lead_id = l.id WHERE l.site_uuid = %s AND n.note LIKE %s", $uuid, $like ) );
        $ids   = $db->get_col( $db->prepare(
            "SELECT l.id FROM `{$l}` l JOIN `{$n}` n ON n.lead_id = l.id WHERE l.site_uuid = %s AND n.note LIKE %s GROUP BY l.id ORDER BY MAX(n.id) DESC LIMIT %d OFFSET %d",
            $uuid, $like, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE
        ) );
        $rows = array();
        foreach ( (array) $ids as $id ) {
            $lead = $db->get_row( $db->prepare( "SELECT id, lead_data, lead_status, created_at FROM `{$l}` WHERE id = %d", (int) $id ), ARRAY_A );
            $note = $db->get_var( $db->prepare( "SELECT note FROM `{$n}` WHERE lead_id = %d AND note LIKE %s ORDER BY id DESC LIMIT 1", (int) $id, $like ) );
            if ( ! is_array( $lead ) ) {
                continue;
            }
            $data   = json_decode( (string) $lead['lead_data'], true );
            $data   = is_array( $data ) ? $data : array();
            $rows[] = array(
                'id'      => (int) $lead['id'],
                'name'    => self::field( $data, array( 'name' ) ),
                'email'   => self::field( $data, array( 'email' ) ),
                'phone'   => self::field( $data, array( 'phone' ) ),
                'company' => self::field( $data, array( 'company' ) ),
                'status'  => (string) $lead['lead_status'],
                'created' => (string) $lead['created_at'],
                'note'    => (string) $note,
            );
        }
        return array( 'ready' => true, 'total' => $total, 'rows' => $rows );
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $data = self::rows( $page );
        $fail = array_values( array_filter( (array) get_option( AJMCP_WP_Replies::FAILURES_OPTION, array() ), static function ( $f ) {
            return 0 === strpos( (string) ( $f['reason'] ?? '' ), 'lead_' );
        } ) );
        ?>
        <style>
            .ajmcp-lead{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:0 0 8px;max-width:900px}
            .ajmcp-lead>summary{cursor:pointer;padding:10px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
            .ajmcp-lead .ajmcp-sub{color:#646970;font-size:12px}
            .ajmcp-lead pre{margin:0;padding:10px 14px 14px;border-top:1px solid #f0f0f1;white-space:pre-wrap;font:inherit}
        </style>
        <div class="wrap">
            <h1>AJMCP Leads <small><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></small></h1>
            <?php if ( ! $data['ready'] ) : ?>
                <div class="notice notice-warning"><p>AJCore lead data is not available here. Needs AJCore active and the site UUID set.</p></div>
            <?php endif; ?>
            <?php if ( $fail ) : ?>
                <h2>Failed lead saves</h2>
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
            <h2>Captured in chat</h2>
            <?php if ( ! $data['rows'] ) : ?>
                <p>No leads yet.</p>
            <?php endif; ?>
            <?php foreach ( $data['rows'] as $i => $lead ) : ?>
                <details class="ajmcp-lead"<?php echo ( 0 === $i && 1 === $page ) ? ' open' : ''; ?>>
                    <summary>
                        <strong><?php echo esc_html( '' !== $lead['name'] ? $lead['name'] : '(no name)' ); ?></strong>
                        <?php if ( '' !== $lead['company'] ) : ?><span class="ajmcp-sub"><?php echo esc_html( $lead['company'] ); ?></span><?php endif; ?>
                        <span class="ajmcp-sub"><?php echo esc_html( trim( $lead['email'] . '  ' . $lead['phone'] ) ); ?></span>
                        <span class="ajmcp-sub"><?php echo esc_html( ucwords( str_replace( '_', ' ', $lead['status'] ?: 'new' ) ) . ' · ' . $lead['created'] ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-leads', 'view' => 'detail', 'lead_id' => $lead['id'] ), admin_url( 'admin.php' ) ) ); ?>">Open in AJCore</a>
                    </summary>
                    <pre><?php echo esc_html( $lead['note'] ); ?></pre>
                </details>
            <?php endforeach; ?>
            <?php
            $pages = (int) ceil( $data['total'] / self::PER_PAGE );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</div></div>';
            }
            ?>
        </div>
        <?php
    }
}
