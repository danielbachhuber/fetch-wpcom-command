<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class Fetch_WPCom {

	/**
	 * Fetch a post from WordPress.com and insert into the database.
	 *
	 * <site>
	 * : Site URL to download from.
	 *
	 * <slug>
	 * : Post slug to download.
	 */
	public function __invoke() {
		global $wpdb;
		list( $site, $slug ) = $args;
		if ( $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type='post'", $slug ) ) ) {
			WP_CLI::error( "Post already exists as id {$post_id}" );
		}

		$url = sprintf( 'https://public-api.wordpress.com/rest/v1.1/sites/%s/posts/slug:%s', $site, $slug );
		$response = WP_CLI\Utils\http_request( 'GET', $url );
		if ( 200 !== $response->status_code ) {
			WP_CLI::error( "HTTP status {$response->status_code}" );
		}
		$body = json_decode( $response->body );
		$post = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => $body->title,
			'post_name'    => $body->slug,
			'post_content' => $body->content,
		);
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( $post_id );
		}
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $post_id ) );
		if ( ! empty( $body->featured_image ) ) {
			$html = media_sideload_image( $body->featured_image, $post_id, '' );
			WP_CLI::log( $html );
		}
		WP_CLI::success( "Fetched {$slug} as post id {$post_id}." );
	}

}

WP_CLI::add_command( 'fetch-wpcom', 'Fetch_WPCom' );
