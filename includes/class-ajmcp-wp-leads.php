<?php
/**
 * Saves a chat lead in AJCore, on this same site, using AJCore's existing lead endpoints (search, create,
 * update, notes) as the configured service user. Nothing here is exposed to the browser, and nothing was
 * added to AJCore for it.
 *
 * What AJCore keeps as lead fields: name, email, phone, company, source. Everything else the AI learned
 * (consent, services, customer type, preference, conversation summary) goes into a structured note on the
 * lead, so staff see it in AJCore and AJOps and it is never overwritten.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Leads {
    const SOURCE = 'ajmcp-chat';

    const SERVICES = array( 'registered_agent' => 'Registered Agent', 'domestic_formation' => 'LLC formation', 'foreign_qualification' => 'Foreign registration', 'agent_change' => 'Agent change', 'other' => 'Other' );
    const ENTITY   = array( 'startup' => 'Starting a business', 'existing_nc' => 'Existing NC business', 'existing_other_state' => 'Existing business in another state', 'existing_other_country' => 'Existing business outside the US', 'unknown' => 'Unknown' );

    public static function success_line(): string {
        return 'Your details have been shared with our team.';
    }

    public static function failure_line(): string {
        return "I couldn't submit your details just now. Please contact our team directly and they will help you right away.";
    }

    /**
     * @param array $lead  The server-checked lead (visitor_name-style keys are already mapped to name, email, ...).
     * @return array{status:string,lead_id?:int,error?:string}
     */
    public static function save( array $lead, int $session_id, string $site_uuid, int $service_user_id ): array {
        // Defense in depth: the server already checked these, so refuse anything that arrives without them.
        $email = AJMCP_WP_Lead_Match::email( $lead['email'] ?? '' );
        $name  = trim( wp_strip_all_tags( (string) ( $lead['name'] ?? '' ) ) );
        if ( empty( $lead['consent_given'] ) ) {
            return array( 'status' => 'failed', 'error' => 'consent_required' );
        }
        if ( '' === $email || strlen( $name ) < 2 ) {
            return array( 'status' => 'failed', 'error' => 'name_and_email_required' );
        }
        if ( ! self::lock() ) {
            return array( 'status' => 'failed', 'error' => 'busy' );
        }
        try {
            $result = AJMCP_WP_Chat::as_service_user( $service_user_id, static function () use ( $lead, $email, $name, $session_id, $site_uuid ) {
                return self::save_as_user( $lead, $email, $name, $session_id, $site_uuid );
            } );
        } finally {
            self::unlock();
        }
        return is_wp_error( $result )
            ? array( 'status' => 'failed', 'error' => sanitize_key( $result->get_error_code() ) )
            : $result;
    }

    /** One call to AJCore's own REST API, as the current user. Returns the data array or a WP_Error. */
    private static function ajcore( string $method, string $route, array $params = array() ) {
        $request = new WP_REST_Request( $method, '/ajcore/v1' . $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_do_request( $request );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( $response->get_status() >= 300 ) {
            $data = (array) $response->get_data();
            return new WP_Error( 'ajcore_' . ( $data['code'] ?? 'error_' . $response->get_status() ), 'AJCore refused the request.' );
        }
        return (array) $response->get_data();
    }

    private static function save_as_user( array $lead, string $email, string $name, int $session_id, string $site_uuid ) {
        $phone   = (string) ( $lead['phone'] ?? '' );
        $company = trim( wp_strip_all_tags( (string) ( $lead['company'] ?? '' ) ) );

        $existing = self::find_existing( array( 'name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company ) );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }
        if ( $existing ) {
            $lead_id = (int) $existing['match']['id'];
            // Only blank fields are filled in: nobody's own edits are overwritten.
            $blank = array();
            foreach ( array( 'name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company ) as $field => $value ) {
                if ( '' !== $value && '' === trim( (string) ( $existing['row'][ $field ] ?? '' ) ) ) {
                    $blank[ $field ] = $value;
                }
            }
            if ( $blank ) {
                $updated = self::ajcore( 'PATCH', '/ops/leads/' . $lead_id, array_merge( array( 'id' => $lead_id ), $blank ) );
                if ( is_wp_error( $updated ) ) {
                    return $updated;
                }
            }
            $operation = 'updated';
        } else {
            $args = array( 'name' => $name, 'email' => $email, 'source' => self::SOURCE, 'status' => 'new' );
            if ( '' !== $phone ) {
                $args['phone'] = $phone;
            }
            if ( '' !== $company ) {
                $args['company'] = $company;
            }
            if ( '' !== $site_uuid ) {
                $args['site_uuid'] = $site_uuid;
            }
            $created = self::ajcore( 'POST', '/ops/leads', $args );
            if ( is_wp_error( $created ) ) {
                return $created;
            }
            $lead_id = (int) ( $created['lead']['id'] ?? 0 );
            if ( $lead_id < 1 ) {
                return new WP_Error( 'ajcore_no_lead_id', 'AJCore did not return a lead id.' );
            }
            $operation = 'created';
        }
        $noted = self::ajcore( 'POST', '/ops/leads/' . $lead_id . '/notes', array( 'id' => $lead_id, 'note' => self::note( $lead, $session_id, 'updated' === $operation ) ) );
        if ( is_wp_error( $noted ) ) {
            // The lead itself is saved; only the note failed. Report success but keep the reason visible to staff.
            AJMCP_WP_Replies::note_failure( $session_id, 0, 'lead_note_failed' );
        }
        return array( 'status' => $operation, 'lead_id' => $lead_id );
    }

    /** Looks for the same person or business among AJCore's leads (read-only search, then the pure matcher). */
    private static function find_existing( array $incoming ) {
        $terms = array( $incoming['email'] );
        $digits = AJMCP_WP_Lead_Match::phone_key( $incoming['phone'] );
        if ( '' !== $digits ) {
            $terms[] = substr( $digits, -4 );   // stored formats vary, so search the last four digits and compare properly below
        }
        $company = AJMCP_WP_Lead_Match::company_key( $incoming['company'] );
        if ( '' !== $company ) {
            $terms[] = (string) strtok( $company, ' ' );
        }
        $rows = array();
        foreach ( array_unique( array_filter( $terms ) ) as $term ) {
            $data = self::ajcore( 'GET', '/ops/leads', array( 'search' => $term, 'per_page' => 50 ) );
            if ( is_wp_error( $data ) ) {
                return $data;
            }
            foreach ( (array) ( $data['leads'] ?? array() ) as $row ) {
                if ( ! empty( $row['merged_into_lead_id'] ) || 'duplicate' === ( $row['status'] ?? '' ) ) {
                    continue;
                }
                $rows[ (int) $row['id'] ] = $row;
            }
        }
        ksort( $rows );
        $match = AJMCP_WP_Lead_Match::find( $incoming, array_values( $rows ) );
        return $match ? array( 'match' => $match, 'row' => $rows[ $match['id'] ] ) : null;
    }

    /** The structured note: consent, what they want, how they were classified, and the AI's summary. */
    public static function note( array $lead, int $session_id, bool $is_update ): string {
        $services = array();
        foreach ( (array) ( $lead['service_interest'] ?? array() ) as $slug ) {
            $services[] = self::SERVICES[ $slug ] ?? ucwords( str_replace( '_', ' ', (string) $slug ) );
        }
        $preference = array( 'essentials' => 'Cost-conscious (essentials / self-file)', 'assisted' => 'White-glove (team assistance)' )[ $lead['service_preference'] ?? '' ] ?? 'Not stated';
        $lines = array(
            ( $is_update ? 'AI chat lead update' : 'AI chat lead' ) . ' (conversation ' . $session_id . ', ' . gmdate( 'Y-m-d H:i' ) . ' UTC)',
            'Consent: yes, the visitor agreed in chat to share their details with the team for follow-up. Marketing messages: ' . ( ! empty( $lead['marketing_consent'] ) ? 'yes' : 'no' ),
            'Customer: ' . ( self::ENTITY[ $lead['entity_status'] ?? 'unknown' ] ?? 'Unknown' ),
        );
        foreach ( array( 'formation_jurisdiction' => 'Formed in', 'business_type' => 'Business type', 'company' => 'Business' ) as $key => $label ) {
            if ( ! empty( $lead[ $key ] ) ) {
                $lines[] = $label . ': ' . sanitize_text_field( (string) $lead[ $key ] );
            }
        }
        $lines[] = 'Service interest: ' . ( $services ? implode( ', ', $services ) : 'not stated' );
        $lines[] = 'Service preference (internal): ' . $preference;
        $lines[] = 'Preferred contact: ' . ( $lead['preferred_contact_method'] ?? 'unspecified' );
        $lines[] = 'Summary: ' . sanitize_textarea_field( (string) ( $lead['conversation_summary'] ?? '' ) );
        return implode( "\n", $lines );
    }

    /** One AI lead save at a time, so two quick messages cannot create two leads for the same person. */
    private static function lock(): bool {
        for ( $i = 0; $i < 25; $i++ ) {
            $held = (int) get_option( 'ajmcp_wp_lead_lock', 0 );
            if ( $held && $held < time() - 60 ) {
                delete_option( 'ajmcp_wp_lead_lock' );
            }
            if ( add_option( 'ajmcp_wp_lead_lock', time(), '', false ) ) {
                return true;
            }
            usleep( 200000 );
        }
        return false;
    }

    private static function unlock(): void {
        delete_option( 'ajmcp_wp_lead_lock' );
    }
}
