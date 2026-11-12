<?php
/** Saved Assistant conversations: per-user ownership, limits, continue-where-you-left-off. Loaded by run.php. */

// A tiny in-memory stand-in for WordPress posts.
$GLOBALS['chat_posts'] = array();
$GLOBALS['chat_next_id'] = 500;
function wp_slash( $v ) { return $v; }
function wp_insert_post( $arr, $wp_error = false ) {
    $id = $GLOBALS['chat_next_id']++;
    $GLOBALS['chat_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $arr['post_type'], 'post_status' => $arr['post_status'], 'post_author' => $arr['post_author'], 'post_title' => $arr['post_title'], 'post_content' => $arr['post_content'], 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) );
    return $id;
}
function wp_update_post( $arr, $wp_error = false ) {
    $p = $GLOBALS['chat_posts'][ $arr['ID'] ];
    $p->post_content = $arr['post_content']; $p->post_title = $arr['post_title']; $p->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
    return $arr['ID'];
}
function get_post( $id ) { return $GLOBALS['chat_posts'][ $id ] ?? null; }
function get_posts( $args ) {
    return array_values( array_filter( $GLOBALS['chat_posts'], static function ( $p ) use ( $args ) { return $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'] && (int) $p->post_author === (int) $args['author']; } ) );
}
function wp_trash_post( $id ) { $GLOBALS['chat_posts'][ $id ]->post_status = 'trash'; }

$as = static function ( $user_id ) { $GLOBALS['test_user_id'] = $user_id; };
$req = static function ( array $params ) { $r = new WP_REST_Request( 'POST', '/x' ); foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } return $r; };

$history = array(
    array( 'role' => 'user', 'content' => "Write a blog post about registered agent fees in North Carolina, with a short FAQ at the end and a call to action." ),
    array( 'role' => 'assistant', 'content' => '{"tool":"get_design_catalog","arguments":{}}' ),
    array( 'role' => 'tool', 'name' => 'get_design_catalog', 'content' => '{"patterns":[]}' ),
    array( 'role' => 'assistant', 'content' => 'Here is the draft outline.' ),
);
$log = array( array( 'kind' => 'user', 'text' => 'Write a blog post…' ), array( 'kind' => 'system', 'text' => 'Using get_design_catalog…' ), array( 'kind' => 'assistant', 'text' => 'Here is the draft outline.' ) );

$as( 5 );
$saved = AJMCP_WP_Assistant::chat_save( $req( array( 'history' => $history, 'log' => $log ) ) );
$id = $saved->get_data()['id'];
assert_test( $id > 0 && 60 >= strlen( $saved->get_data()['title'] ) && 0 === strpos( $saved->get_data()['title'], 'Write a blog post about registered agent fees' ), 'saved chat: created, titled from the first message' );

$got = AJMCP_WP_Assistant::chat_get( $req( array( 'id' => $id ) ) )->get_data();
assert_test( $got['history'] == $history && $got['log'] == $log, 'saved chat: history and screen log come back exactly, so it can be continued' );
assert_test( 'get_design_catalog' === $got['history'][2]['name'], 'saved chat: tool results are kept' );

$more = array_merge( $history, array( array( 'role' => 'user', 'content' => 'Make it shorter.' ), array( 'role' => 'assistant', 'content' => 'Shortened.' ) ) );
$again = AJMCP_WP_Assistant::chat_save( $req( array( 'id' => $id, 'history' => $more, 'log' => $log ) ) );
assert_test( $again->get_data()['id'] === $id && 0 === strpos( $again->get_data()['title'], 'Write a blog post' ), 'continuing a chat updates it and keeps its title' );
assert_test( 6 === count( AJMCP_WP_Assistant::chat_get( $req( array( 'id' => $id ) ) )->get_data()['history'] ), 'continuing a chat: the new messages are saved' );
assert_test( 1 === count( AJMCP_WP_Assistant::chats_list()->get_data()['chats'] ), 'list: one chat, not a copy per save' );

// Another administrator cannot see, open, change or delete it.
$as( 6 );
assert_test( array() === AJMCP_WP_Assistant::chats_list()->get_data()['chats'], 'privacy: another user sees an empty list' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_get( $req( array( 'id' => $id ) ) ) ), 'privacy: another user cannot open it' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_save( $req( array( 'id' => $id, 'history' => $history, 'log' => $log ) ) ) ), 'privacy: another user cannot overwrite it' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_delete( $req( array( 'id' => $id ) ) ) ), 'privacy: another user cannot delete it' );
assert_test( 'publish' === $GLOBALS['chat_posts'][ $id ]->post_status, 'privacy: the chat is untouched' );

// Bad input is refused.
$as( 5 );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_save( $req( array( 'history' => array( array( 'role' => 'system', 'content' => 'x' ) ), 'log' => array() ) ) ) ), 'limits: unknown role refused' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_save( $req( array( 'history' => array(), 'log' => array() ) ) ) ), 'limits: empty conversation refused' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_save( $req( array( 'history' => array( array( 'role' => 'user', 'content' => str_repeat( 'x', 80000 ) ) ), 'log' => array() ) ) ) ), 'limits: oversize conversation refused' );
assert_test( is_wp_error( AJMCP_WP_Assistant::chat_get( $req( array( 'id' => 99999 ) ) ) ), 'unknown chat id: not found' );

// A saved log line can't carry anything but plain kinds and text.
$odd = AJMCP_WP_Assistant::chat_save( $req( array( 'history' => array( array( 'role' => 'user', 'content' => 'Hi' ) ), 'log' => array( array( 'kind' => 'script', 'text' => '<b>x</b>' ), array( 'kind' => 'user', 'text' => 'Hi' ) ) ) ) );
$odd_log = AJMCP_WP_Assistant::chat_get( $req( array( 'id' => $odd->get_data()['id'] ) ) )->get_data()['log'];
assert_test( 1 === count( $odd_log ) && 'user' === $odd_log[0]['kind'], 'log: unknown line kinds are dropped' );

// Delete (to the WordPress trash) and it leaves the list.
$del = AJMCP_WP_Assistant::chat_delete( $req( array( 'id' => $id ) ) );
assert_test( true === $del->get_data()['ok'] && 'trash' === $GLOBALS['chat_posts'][ $id ]->post_status, 'delete: moved to the trash' );
assert_test( 1 === count( AJMCP_WP_Assistant::chats_list()->get_data()['chats'] ), 'delete: gone from the list' );
$as( 0 );
