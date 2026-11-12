<?php
/**
 * Uninstall: remove the settings option and any saved submissions.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wcfl_settings' );

$submissions = get_posts(
    array(
        'post_type'      => 'wcfl_submission',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);
foreach ( $submissions as $post_id ) {
    wp_delete_post( $post_id, true );
}
