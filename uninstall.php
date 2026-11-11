<?php
/** Remove credentials if the plugin is permanently uninstalled. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
delete_option( 'ajmcp_wp_settings' );
