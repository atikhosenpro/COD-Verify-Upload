<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'codv_settings' );
wp_clear_scheduled_hook( 'codv_cleanup' );
$u    = wp_upload_dir();
$base = trailingslashit( $u['basedir'] ) . 'codv-private';
if ( is_dir( $base ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
	@rmdir( $base );
}
