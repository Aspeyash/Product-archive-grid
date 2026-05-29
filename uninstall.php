<?php
/**
 * Uninstall handler — runs only when the plugin is deleted via the admin UI.
 * Cleans up all options, transients, and per-user metadata created by the plugin.
 *
 * @package ProductArchiveGrid
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Plugin options.
delete_option( 'pag_installed_at' );
delete_option( 'pag_settings' );

// Delete all per-user wishlist data and Buy Now snapshots.
delete_metadata( 'user', 0, 'pag_wishlist', '', true );
delete_metadata( 'user', 0, 'pag_buy_now_snapshots', '', true );

// Purge any transients we may have created (rate limiter, etc.).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pag\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_pag\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_pag\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
