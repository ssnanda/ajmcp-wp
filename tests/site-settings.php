<?php
/** AI Behavior / Knowledge: what the plugin may ask the server, uploads, and the website-pages text cleaning. */

$GLOBALS['test_options']['ajmcp_wp_settings'] = array( 'enabled' => true, 'chat_enabled' => true, 'site_token' => str_repeat( 'a', 48 ), 'server_url' => 'https://mcp.example.com', 'ops_user_id' => 3, 'site_uuid' => 'ours' );
$last_call = static function () { $calls = $GLOBALS['test_http_calls']; return end( $calls ); };

// ── Which server paths and methods are allowed ────────────────────────────────
$GLOBALS['test_http_response'] = array( 'status' => 200, 'body' => json_encode( array( 'instructions' => 'x' ) ) );
$ok = AJMCP_WP_Client::request( '/v1/site/config', 'PUT', array( 'instructions' => 'New rules.', 'strict' => true ) );
$call = $last_call();
assert_test( ! is_wp_error( $ok ) && 'PUT' === $call['args']['method'] && 'https://mcp.example.com/v1/site/config' === $call['url'], 'site config can be read and updated with the site token' );
assert_test( 'New rules.' === json_decode( $call['args']['body'], true )['instructions'], 'site config update sends the new text' );
assert_test( false !== strpos( $call['args']['headers']['Authorization'], str_repeat( 'a', 48 ) ), 'the site token is sent from the server side only' );
foreach ( array( '/v1/site/knowledge', '/v1/site/knowledge/sync', '/v1/site/knowledge/0123456789ab' ) as $path ) {
    assert_test( ! is_wp_error( AJMCP_WP_Client::request( $path, 'GET' ) ), "allowed path $path" );
}
foreach ( array( '/v1/site/knowledge/zzzz', '/v1/site/../admin', '/v1/site/keys', '/v1/admin/tools', '/v1/agents/run', '/v1/site/knowledge/0123456789ab/extra' ) as $path ) {
    assert_test( is_wp_error( AJMCP_WP_Client::request( $path, 'GET' ) ), "refused path $path" );
}
assert_test( is_wp_error( AJMCP_WP_Client::request( '/v1/site/config', 'PATCH' ) ), 'unsupported method refused' );

// Size limits: a website sync may be large, a chat message may not.
$big = array( 'source' => 'website', 'docs' => array( array( 'ref' => 'p1', 'title' => 'T', 'text' => str_repeat( 'x', 500000 ) ) ) );
assert_test( ! is_wp_error( AJMCP_WP_Client::request( '/v1/site/knowledge/sync', 'PUT', $big ) ), 'a website sync of 500 KB is allowed' );
assert_test( is_wp_error( AJMCP_WP_Client::request( '/v1/chat', 'POST', array( 'messages' => str_repeat( 'x', 40000 ) ) ) ), 'chat requests stay small' );

// The server's short error code is passed on, nothing else from its reply.
$GLOBALS['test_http_response'] = array( 'status' => 413, 'body' => json_encode( array( 'error' => 'document_too_large', 'secret' => 'nope' ) ) );
$err = AJMCP_WP_Client::request( '/v1/site/knowledge', 'POST', array( 'title' => 'A', 'text' => 'B' ) );
assert_test( is_wp_error( $err ) && 'document_too_large' === $err->get_error_data()['reason'], 'the server error code is kept' );
assert_test( false !== strpos( AJMCP_WP_Behavior::reason( $err ), '200,000 characters' ) && false === strpos( AJMCP_WP_Behavior::reason( $err ), 'nope' ), 'the error is shown in plain words, without the raw reply' );
$GLOBALS['test_http_response'] = array( 'status' => 200, 'body' => '{}' );

// ── Uploads: plain text only ──────────────────────────────────────────────────
$tmp = static function ( $content, $ext ) { $f = tempnam( sys_get_temp_dir(), 'ajmcp' ); file_put_contents( $f, $content ); return array( $f, "doc.$ext", strlen( $content ) ); };
$read = static function ( array $f ) { return AJMCP_WP_Knowledge::read_text_file( $f[0], $f[1], $f[2] ); };
assert_test( "Hello\nWorld" === $read( $tmp( "\xEF\xBB\xBFHello\nWorld", 'txt' ) ), 'upload: a text file is read (BOM removed)' );
assert_test( is_wp_error( $read( $tmp( 'MZ', 'exe' ) ) ), 'upload: programs and unknown types are refused' );
assert_test( is_wp_error( $read( $tmp( 'x', 'pdf' ) ) ), 'upload: PDFs are refused (paste the text instead)' );
assert_test( is_wp_error( $read( $tmp( "\xff\xfe\x00bad", 'txt' ) ) ), 'upload: non-UTF-8 files are refused' );
$huge = $tmp( 'x', 'txt' ); $huge[2] = 2000000;
assert_test( is_wp_error( $read( $huge ) ), 'upload: more than 1 MB is refused' );
assert_test( "Prices\nRA is \$98." === $read( $tmp( '<h1>Prices</h1><p>RA is $98.</p><script>evil()</script>', 'html' ) ), 'upload: HTML becomes readable text without scripts' );
assert_test( is_wp_error( AJMCP_WP_Knowledge::read_upload( array( 'name' => 'a.txt', 'tmp_name' => $tmp( 'x', 'txt' )[0], 'error' => UPLOAD_ERR_PARTIAL, 'size' => 1 ) ) ), 'upload: a failed upload is refused' );
assert_test( is_wp_error( AJMCP_WP_Knowledge::read_upload( array( 'name' => 'a.txt', 'tmp_name' => $tmp( 'x', 'txt' )[0], 'error' => UPLOAD_ERR_OK, 'size' => 1 ) ) ), 'upload: a file that did not come from an HTTP upload is refused' );

// ── Website pages become plain text ───────────────────────────────────────────
$html = "<!-- wp:heading --><h2 class=\"x\">Registered Agent</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>We serve as your <strong>NC</strong> registered agent&nbsp;for \$98 per year.</p><!-- /wp:paragraph -->\n<ul><li>Fast</li><li>Reliable</li></ul><style>.a{color:red}</style>";
$text = AJMCP_WP_Site_Sync::clean_text( $html );
assert_test( false === strpos( $text, '<' ) && false === strpos( $text, 'color:red' ) && false === strpos( $text, 'wp:' ), 'website text: no tags, styles or block comments' );
assert_test( false !== strpos( $text, "Registered Agent\nWe serve as your NC registered agent for \$98 per year." ), 'website text: paragraphs are kept apart and readable' );
assert_test( false !== strpos( $text, "Fast\nReliable" ), 'website text: list items on their own lines' );
assert_test( '' === AJMCP_WP_Site_Sync::clean_text( '<div></div><!-- empty -->' ), 'website text: an empty page produces nothing' );
