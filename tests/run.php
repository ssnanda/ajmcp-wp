<?php
/** Standalone, dependency-free tests for AJAX/REST integration contracts. */
define( 'ABSPATH', '/test/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    public $code; private $data;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
    private $method; private $route; private $params = array();
    public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
class WP_REST_Response {
    private $body; private $status;
    public function __construct( $body = array(), $status = 200 ) { $this->body = $body; $this->status = $status; }
    public function get_data() { return $this->body; }
    public function get_status() { return $this->status; }
}
class MockDB {
    public $prefix = 'wp_';
    public $session = array();
    public $messages = array();
    public function prepare( $query, ...$args ) { return array( $query, $args ); }
    public function get_row( $prepared, $mode ) {
        [ $sql, $args ] = $prepared;
        if ( strpos( $sql, 'chat_sessions' ) !== false ) { return $this->session; }
        foreach ( $this->messages as $msg ) {
            if ( (int) $msg['session_id'] === (int) $args[0] && (int) $msg['id'] === (int) ( $args[1] ?? 0 ) ) {
                return $msg;
            }
        }
        return null;
    }
    public function get_var( $prepared ) { return $this->messages ? max( array_column( $this->messages, 'id' ) ) : 0; }
    public function get_results( $prepared, $mode ) {
        [ $sql, $args ] = $prepared;
        return array_map( static fn( $row ) => array( 'sender_type' => $row['sender_type'], 'body' => $row['body'] ),
            array_reverse( array_filter( $this->messages, static fn( $msg ) => $msg['id'] < $args[1] && $msg['session_id'] === $args[0] ) ) );
    }
}
$GLOBALS['test_options'] = array();
$GLOBALS['test_transients'] = array();
$GLOBALS['test_events'] = array();
$GLOBALS['test_http_response'] = array( 'status' => 200, 'body' => '{}' );
$GLOBALS['test_http_calls'] = array();
$GLOBALS['test_internal_replies'] = array();
$GLOBALS['test_user_id'] = 0;
$GLOBALS['mockdb'] = new MockDB();
function __( $text, $domain = null ) { return $text; }
function add_filter() {}
function add_action() {}
function get_option( $name, $default = false ) { return $GLOBALS['test_options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['test_options'][$name] = $value; }
function delete_option( $name ) { unset( $GLOBALS['test_options'][$name] ); }
function add_option( $name, $value, $deprecated = '', $autoload = null ) { if ( isset( $GLOBALS['test_options'][$name] ) ) return false; $GLOBALS['test_options'][$name] = $value; return true; }
function set_transient( $name, $value, $time ) { $GLOBALS['test_transients'][$name] = $value; }
function get_transient( $name ) { return $GLOBALS['test_transients'][$name] ?? false; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['test_events'][] = compact( 'time', 'hook', 'args' ); return true; }
function is_wp_error( $obj ) { return $obj instanceof WP_Error; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_safe_remote_request( $url, $args ) {
    $GLOBALS['test_http_calls'][] = compact( 'url', 'args' );
    if ( isset( $GLOBALS['test_http_callback'] ) ) { return $GLOBALS['test_http_callback']( $url, $args ); }
    return $GLOBALS['test_http_response'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function sanitize_text_field( $str ) { return trim( (string) $str ); }
function sanitize_textarea_field( $str ) { return trim( (string) $str ); }
function absint( $str ) { return abs( (int) $str ); }
function get_user_by( $field, $value ) { return (object) array( 'ID' => $value, 'roles' => array( 'administrator' ) ); }
function user_can( $user, $cap ) { return $cap === 'manage_options'; }
function get_current_user_id() { return $GLOBALS['test_user_id']; }
function wp_set_current_user( $id ) { $GLOBALS['test_user_id'] = $id; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function rest_do_request( $request ) {
    if ( isset( $GLOBALS['test_rest_handler'] ) ) { return ( $GLOBALS['test_rest_handler'] )( $request ); } $GLOBALS['test_internal_replies'][] = array( 'route' => $request->get_route(), 'body' => $request->get_param( 'body' ), 'user' => $GLOBALS['test_user_id'] ); return new WP_REST_Response( array( 'message' => array( 'id' => 12 ) ) ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function ajcore_get_portal_db() { return $GLOBALS['mockdb']; }
require_once dirname( __DIR__ ) . '/includes/class-ajmcp-wp-client.php';
require_once dirname( __DIR__ ) . '/includes/class-ajmcp-wp-chat.php';
require_once dirname( __DIR__ ) . '/includes/class-ajmcp-wp-replies.php';
require_once dirname( __DIR__ ) . '/includes/class-ajmcp-wp-lead-match.php';
require_once dirname( __DIR__ ) . '/includes/class-ajmcp-wp-leads.php';
function assert_test( $condition, $what ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } echo "PASS: $what\n"; }

assert_test( AJMCP_WP_Client::valid_server_url( 'https://mcp.example.com' ), 'accept HTTPS base URL' );
assert_test( ! AJMCP_WP_Client::valid_server_url( 'http://mcp.example.com' ), 'reject HTTP' );
assert_test( ! AJMCP_WP_Client::valid_server_url( 'https://u:p@mcp.example.com' ), 'reject embedded credentials' );
assert_test( ! AJMCP_WP_Client::valid_server_url( 'https://mcp.example.com/unsafe' ), 'reject nested path' );
$GLOBALS['test_options']['ajmcp_wp_settings'] = array( 'enabled' => true, 'chat_enabled' => true, 'site_token' => str_repeat( 'a', 48 ), 'server_url' => 'https://mcp.example.com', 'ops_user_id' => 3, 'site_uuid' => 'ours' );
assert_test( is_wp_error( AJMCP_WP_Client::request( '/v1/agents/run', 'POST' ) ), 'site cannot call admin agent endpoint' );
$GLOBALS['test_http_response'] = array( 'status' => 200, 'body' => json_encode( array( 'sessionId' => 7, 'reply' => 'Welcome to our service.' ) ) );
$db = $GLOBALS['mockdb'];
$db->session = array( 'site_uuid' => 'ours', 'status' => 'open', 'assigned_staff_id' => null, 'assigned_staff_name' => '', 'automation_muted' => 0 );
$db->messages = array( array( 'session_id' => 7, 'id' => 11, 'sender_type' => 'visitor', 'body' => 'Hello, what services do you offer?' ) );
$event = new WP_REST_Request( 'POST', '/ajcore/v1/chat/sessions/7/messages' );
$response = new WP_REST_Response( array( 'message' => array( 'id' => 11, 'senderType' => 'visitor' ) ) );
AJMCP_WP_Chat::observe( $response, null, $event );
assert_test( count( $GLOBALS['test_events'] ) === 1, 'successful AJCore message creates background job' );
AJMCP_WP_Chat::process( 7, 11 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'AI response delivered through AJCore' );
$sent = json_decode( $GLOBALS['test_http_calls'][0]['args']['body'] ?? '{}', true );
assert_test( 'ours' === ( $sent['siteUuid'] ?? '' ), 'the AJCore site UUID is sent so the server can file leads against the right website' );
assert_test( isset( $sent['visitor'] ) && is_array( $sent['visitor'] ), 'known visitor details are sent along' );
assert_test( $GLOBALS['test_internal_replies'][0]['user'] === 3 && $GLOBALS['test_user_id'] === 0, 'scoped service identity restored after reply' );
assert_test( get_transient( 'ajmcp_wp_done_11' ) === 1, 'message deduplication recorded' );
AJMCP_WP_Chat::process( 7, 11 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'repeated processing does not duplicate reply' );
$db->messages = array( array( 'session_id' => 7, 'id' => 13, 'sender_type' => 'visitor', 'body' => 'Question 2' ) );
$db->session['automation_muted'] = 1;
AJMCP_WP_Chat::process( 7, 13 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'human takeover mutes bot' );
$db->session['automation_muted'] = 0;
$db->session['assigned_staff_id'] = 22;
$db->messages = array( array( 'session_id' => 7, 'id' => 14, 'sender_type' => 'visitor', 'body' => 'Question 3' ) );
AJMCP_WP_Chat::process( 7, 14 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'assigned staff prevents bot' );
$db->session['assigned_staff_id'] = null;
$db->session['site_uuid'] = 'different-site';
$db->messages = array( array( 'session_id' => 7, 'id' => 15, 'sender_type' => 'visitor', 'body' => 'Question 4' ) );
AJMCP_WP_Chat::process( 7, 15 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'other website messages are isolated' );
$db->session['site_uuid'] = 'ours';
$db->messages = array( array( 'session_id' => 7, 'id' => 16, 'sender_type' => 'visitor', 'body' => 'Question 5' ) );
$GLOBALS['test_http_callback'] = static function ( $url, $args ) use ( $db ) { $db->session['automation_muted'] = 1; return array( 'status' => 200, 'body' => json_encode( array( 'sessionId' => 7, 'reply' => 'AI reply' ) ) ); };
AJMCP_WP_Chat::process( 7, 16 );
assert_test( count( $GLOBALS['test_internal_replies'] ) === 1, 'human takeover during AI call prevents stale reply' );
assert_test( strpos( $GLOBALS['test_http_calls'][0]['args']['headers']['Authorization'], str_repeat( 'a', 48 ) ) !== false, 'requests use server-side bearer token' );
require __DIR__ . '/leads.php';
echo "All AJMCP WordPress tests passed.\n";
