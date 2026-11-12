<?php
/** Lead capture: matching rules and the chat-to-AJCore flow. Loaded by run.php (shares its stubs). */

// ── Matching rules ─────────────────────────────────────────────────────────────
$existing = array(
    array( 'id' => 5, 'name' => 'Pat Smith', 'email' => 'pat@old.com', 'phone' => '(980) 111-2222', 'company' => 'Smith Holdings LLC' ),
    array( 'id' => 9, 'name' => 'Taylor Lee', 'email' => 'Taylor@Example.com', 'phone' => '', 'company' => '' ),
);
$m = AJMCP_WP_Lead_Match::find( array( 'name' => 'T. Lee', 'email' => 'taylor@example.com' ), $existing );
assert_test( $m && 9 === $m['id'] && 'email' === $m['by'], 'lead match: same email in any case' );
$m = AJMCP_WP_Lead_Match::find( array( 'name' => 'X', 'email' => 'new@new.com', 'phone' => '980.111.2222' ), $existing );
assert_test( $m && 5 === $m['id'] && 'phone' === $m['by'], 'lead match: same phone in another format' );
$m = AJMCP_WP_Lead_Match::find( array( 'name' => 'pat smith', 'email' => 'x@y.com', 'phone' => '7045559999', 'company' => 'smith holdings, l.l.c.' ), $existing );
assert_test( $m && 5 === $m['id'] && 'company' === $m['by'], 'lead match: same business and same contact name' );
assert_test( null === AJMCP_WP_Lead_Match::find( array( 'name' => 'Jordan Reed', 'email' => 'j@y.com', 'company' => 'Smith Holdings LLC' ), $existing ), 'lead match: same business but a different person is a new lead' );
assert_test( null === AJMCP_WP_Lead_Match::find( array( 'name' => 'N', 'email' => 'none@none.com' ), $existing ), 'lead match: no match means create' );
assert_test( AJMCP_WP_Lead_Match::company_key( 'Lux Venturez, L.L.C.' ) === AJMCP_WP_Lead_Match::company_key( 'LUX VENTUREZ LLC' ), 'lead match: company names ignore case, punctuation and LLC' );

// ── A pretend AJCore (its existing lead endpoints) ─────────────────────────────
$GLOBALS['aj'] = array( 'leads' => array(), 'notes' => array(), 'calls' => array(), 'next' => 100, 'fail_create' => false, 'down' => false );
$GLOBALS['test_rest_handler'] = static function ( $request ) {
    $aj    = &$GLOBALS['aj'];
    $route = str_replace( '/ajcore/v1', '', $request->get_route() );
    $method = $request->get_method();
    if ( false !== strpos( $route, '/auto-reply' ) ) {
        $GLOBALS['test_internal_replies'][] = array( 'route' => $request->get_route(), 'body' => $request->get_param( 'body' ), 'user' => $GLOBALS['test_user_id'] );
        return new WP_REST_Response( array( 'message' => array( 'id' => 12 ) ) );
    }
    if ( $aj['down'] ) { return new WP_Error( 'rest_no_route', 'down' ); }
    $aj['calls'][] = array( 'method' => $method, 'route' => $route, 'params' => array(), 'user' => $GLOBALS['test_user_id'] );
    $index = count( $aj['calls'] ) - 1;
    foreach ( array( 'search', 'name', 'email', 'phone', 'company', 'source', 'status', 'site_uuid', 'note', 'per_page' ) as $key ) {
        if ( null !== $request->get_param( $key ) ) { $aj['calls'][ $index ]['params'][ $key ] = $request->get_param( $key ); }
    }
    if ( 'GET' === $method && '/ops/leads' === $route ) {
        $needle = strtolower( (string) $request->get_param( 'search' ) );
        $found  = array_values( array_filter( $aj['leads'], static function ( $l ) use ( $needle ) { return false !== strpos( strtolower( json_encode( $l ) ), $needle ); } ) );
        return new WP_REST_Response( array( 'leads' => $found ) );
    }
    if ( 'POST' === $method && '/ops/leads' === $route ) {
        if ( $aj['fail_create'] ) { return new WP_REST_Response( array( 'code' => 'ajcore_lead_create_failed' ), 500 ); }
        $id = $aj['next']++;
        $aj['leads'][ $id ] = array( 'id' => $id, 'name' => (string) $request->get_param( 'name' ), 'email' => (string) $request->get_param( 'email' ), 'phone' => (string) $request->get_param( 'phone' ), 'company' => (string) $request->get_param( 'company' ), 'status' => 'new', 'merged_into_lead_id' => 0 );
        return new WP_REST_Response( array( 'success' => true, 'lead' => array( 'id' => $id ) ) );
    }
    if ( 'PATCH' === $method && preg_match( '#^/ops/leads/(\d+)$#', $route, $mm ) ) {
        foreach ( array( 'name', 'email', 'phone', 'company' ) as $f ) { if ( null !== $request->get_param( $f ) ) { $aj['leads'][ (int) $mm[1] ][ $f ] = $request->get_param( $f ); } }
        return new WP_REST_Response( array( 'success' => true ) );
    }
    if ( 'POST' === $method && preg_match( '#^/ops/leads/(\d+)/notes$#', $route, $mm ) ) {
        $aj['notes'][] = array( 'lead_id' => (int) $mm[1], 'note' => (string) $request->get_param( 'note' ) );
        return new WP_REST_Response( array( 'note' => array( 'id' => count( $aj['notes'] ) ) ) );
    }
    return new WP_REST_Response( array(), 404 );
};
$lead = array(
    'name' => 'Taylor Lee', 'email' => 'taylor@example.com', 'phone' => '704-555-0101', 'company' => 'Lee Georgia LLC',
    'entity_status' => 'existing_other_state', 'formation_jurisdiction' => 'Georgia', 'service_interest' => array( 'foreign_qualification', 'registered_agent' ),
    'service_preference' => 'assisted', 'preferred_contact_method' => 'email', 'conversation_summary' => 'Georgia LLC wants NC foreign qualification with team help.',
    'marketing_consent' => false, 'consent_given' => true,
);
$reset_chat = static function ( $message_id ) use ( $db ) {
    $db->session = array( 'site_uuid' => 'ours', 'status' => 'open', 'assigned_staff_id' => null, 'assigned_staff_name' => '', 'automation_muted' => 0 );
    $db->messages = array( array( 'session_id' => 7, 'id' => $message_id, 'sender_type' => 'visitor', 'body' => 'Yes, share my details.' ) );
    $GLOBALS['test_internal_replies'] = array();
    $GLOBALS['test_http_callback'] = null;
};
$chat_with = static function ( $message_id, $server_response ) use ( $reset_chat ) {
    $reset_chat( $message_id );
    $GLOBALS['test_http_response'] = array( 'status' => 200, 'body' => json_encode( array_merge( array( 'sessionId' => 7 ), $server_response ) ) );
    AJMCP_WP_Chat::process( 7, $message_id );
    return $GLOBALS['test_internal_replies'];
};
$ai_text = 'Thanks Taylor. I will pass your details to our team.';

// ── New lead ───────────────────────────────────────────────────────────────────
$sent = $chat_with( 30, array( 'reply' => $ai_text, 'lead' => $lead ) );
$aj = $GLOBALS['aj'];
$posts = array_values( array_filter( $aj['calls'], static function ( $c ) { return 'POST' === $c['method'] && '/ops/leads' === $c['route']; } ) );
assert_test( 1 === count( $posts ) && 'ajmcp-chat' === $posts[0]['params']['source'] && 'ours' === $posts[0]['params']['site_uuid'], 'new lead: created in AJCore with the chat source and this website' );
assert_test( 'taylor@example.com' === $posts[0]['params']['email'] && 'Lee Georgia LLC' === $posts[0]['params']['company'], 'new lead: contact and business saved' );
assert_test( 3 === $posts[0]['user'], 'new lead: saved as the AJCore service user' );
assert_test( 1 === count( $aj['notes'] ) && false !== strpos( $aj['notes'][0]['note'], 'AI chat lead (conversation 7' ), 'new lead: a structured note is added' );
assert_test( false !== strpos( $aj['notes'][0]['note'], 'Consent: yes' ) && false !== strpos( $aj['notes'][0]['note'], 'Marketing messages: no' ), 'new lead: consent and marketing status recorded' );
assert_test( false !== strpos( $aj['notes'][0]['note'], 'White-glove' ) && false !== strpos( $aj['notes'][0]['note'], 'Foreign registration, Registered Agent' ) && false !== strpos( $aj['notes'][0]['note'], 'Existing business in another state' ), 'new lead: customer type, preference and services recorded' );
assert_test( false !== strpos( $aj['notes'][0]['note'], 'Summary: Georgia LLC' ), 'new lead: AI conversation summary kept' );
assert_test( 1 === count( $sent ) && 0 === strpos( $sent[0]['body'], $ai_text ) && false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::success_line() ), 'new lead: the visitor is told it worked only after AJCore confirmed' );
assert_test( 0 === $GLOBALS['test_user_id'] && false === get_option( 'ajmcp_wp_lead_lock', false ), 'new lead: identity and lock released' );

// ── Same person chats again: updated, never duplicated ────────────────────────
$GLOBALS['aj']['calls'] = array();
$sent = $chat_with( 31, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'conversation_summary' => 'Asked again about pricing.' ) ) ) );
$aj = $GLOBALS['aj'];
assert_test( 1 === count( $aj['leads'] ), 'repeat visitor: still one lead (matched by email)' );
assert_test( 0 === count( array_filter( $aj['calls'], static function ( $c ) { return 'POST' === $c['method'] && '/ops/leads' === $c['route']; } ) ), 'repeat visitor: no second lead is created' );
assert_test( 2 === count( $aj['notes'] ) && 0 === strpos( $aj['notes'][1]['note'], 'AI chat lead update' ), 'repeat visitor: an update note is added, history is kept' );
assert_test( false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::success_line() ), 'repeat visitor: success line shown' );

// ── Different email, same phone: also the same person ─────────────────────────
$GLOBALS['aj']['leads'][100]['phone'] = '+17045550101';
$GLOBALS['aj']['calls'] = array();
$chat_with( 32, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'email' => 'taylor.new@example.com' ) ) ) );
assert_test( 1 === count( $GLOBALS['aj']['leads'] ), 'same phone, new email: matched, not duplicated' );

// ── Blank fields are filled; existing ones are never overwritten ──────────────
$GLOBALS['aj']['leads'][100]['company'] = '';
$GLOBALS['aj']['leads'][100]['name'] = 'Taylor L.';
$GLOBALS['aj']['calls'] = array();
$chat_with( 33, array( 'reply' => $ai_text, 'lead' => $lead ) );
$patch = array_values( array_filter( $GLOBALS['aj']['calls'], static function ( $c ) { return 'PATCH' === $c['method']; } ) );
assert_test( 1 === count( $patch ) && array( 'company' ) === array_values( array_intersect( array_keys( $patch[0]['params'] ), array( 'name', 'email', 'phone', 'company' ) ) ), 'update: only the blank field (company) is filled in' );
assert_test( 'Taylor L.' === $GLOBALS['aj']['leads'][100]['name'], 'update: a staff-edited name is not overwritten' );

// ── Missing phone is fine ──────────────────────────────────────────────────────
$GLOBALS['aj']['calls'] = array();
$no_phone = array_merge( $lead, array( 'email' => 'jo@example.org', 'name' => 'Jo Park', 'phone' => '', 'company' => '' ) );
$sent = $chat_with( 34, array( 'reply' => $ai_text, 'lead' => $no_phone ) );
$created = array_values( array_filter( $GLOBALS['aj']['calls'], static function ( $c ) { return 'POST' === $c['method'] && '/ops/leads' === $c['route']; } ) );
assert_test( 1 === count( $created ) && ! isset( $created[0]['params']['phone'] ), 'missing phone: lead is saved without one' );

// ── Refusals and failures never read as success ───────────────────────────────
$GLOBALS['aj']['calls'] = array();
$sent = $chat_with( 35, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'consent_given' => false ) ) ) );
assert_test( 0 === count( $GLOBALS['aj']['calls'] ), 'no consent: AJCore is never called' );
assert_test( false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::failure_line() ) && false === strpos( $sent[0]['body'], AJMCP_WP_Leads::success_line() ), 'no consent: the visitor is told it was not submitted' );

$sent = $chat_with( 36, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'email' => '' ) ) ) );
assert_test( false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::failure_line() ), 'missing email: not saved, honest message' );

$GLOBALS['aj']['fail_create'] = true;
$sent = $chat_with( 37, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'email' => 'fail@example.org', 'name' => 'Fail Case', 'phone' => '', 'company' => '' ) ) ) );
$GLOBALS['aj']['fail_create'] = false;
assert_test( false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::failure_line() ) && false === strpos( $sent[0]['body'], AJMCP_WP_Leads::success_line() ), 'AJCore refuses: honest failure, no success line' );
$failures = get_option( 'ajmcp_wp_failures', array() );
assert_test( $failures && 0 === strpos( $failures[0]['reason'], 'lead_' ), 'AJCore refuses: the failure is listed for staff' );
assert_test( false === strpos( json_encode( $failures ), 'fail@example.org' ), 'failure list holds no personal data' );

$GLOBALS['aj']['down'] = true;
$sent = $chat_with( 38, array( 'reply' => $ai_text, 'lead' => array_merge( $lead, array( 'email' => 'down@example.org', 'name' => 'Down Case', 'phone' => '', 'company' => '' ) ) ) );
$GLOBALS['aj']['down'] = false;
assert_test( false !== strpos( $sent[0]['body'], AJMCP_WP_Leads::failure_line() ), 'AJCore unavailable: honest failure' );

// ── No lead in the reply: unchanged ───────────────────────────────────────────
$sent = $chat_with( 39, array( 'reply' => 'Our registered agent service is $98 per year.' ) );
assert_test( 'Our registered agent service is $98 per year.' === $sent[0]['body'], 'ordinary answer: nothing added, no lead touched' );
$GLOBALS['aj']['calls'] = array();
$sent = $chat_with( 40, array( 'reply' => 'Could I have your email first?', 'leadError' => 'valid_email_required' ) );
assert_test( 'Could I have your email first?' === $sent[0]['body'] && 0 === count( $GLOBALS['aj']['calls'] ), 'server refused the lead: reply unchanged, AJCore untouched' );

// ── The request tells the server this plugin can save leads ───────────────────
$last = json_decode( end( $GLOBALS['test_http_calls'] )['args']['body'] ?? '{}', true );
assert_test( true === ( $last['leadTool'] ?? false ), 'the request tells the server this site can save leads' );

// ── Retry of the same chat message never creates a second lead ───────────────
$before = count( $GLOBALS['aj']['leads'] );
$GLOBALS['test_transients'] = array();
$new = array_merge( $lead, array( 'email' => 'retry@example.org', 'name' => 'Retry Case', 'phone' => '', 'company' => '' ) );
$first = AJMCP_WP_Leads::save( $new, 7, 'ours', 3 );
$again = AJMCP_WP_Leads::save( $new, 7, 'ours', 3 );
assert_test( 'created' === $first['status'] && 'updated' === $again['status'] && $first['lead_id'] === $again['lead_id'] && $before + 1 === count( $GLOBALS['aj']['leads'] ), 'saving the same lead twice updates it instead of duplicating' );
