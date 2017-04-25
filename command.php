<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( ! class_exists( 'Fetch_WPCom' ) ) {
	require_once __DIR__ . '/src/Fetch_WPCom.php';
}

WP_CLI::add_command( 'fetch-wpcom', 'Fetch_WPCom' );
