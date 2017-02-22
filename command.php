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
	public function __invoke( $args ) {
		global $wpdb;
		list( $site, $slug ) = $args;
		if ( $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type='post'", $slug ) ) ) {
			WP_CLI::error( "Post already exists as id {$post_id}" );
		}

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
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
			'post_date'    => date( 'Y-m-d H:i:s', strtotime( $body->date ) ),
		);
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( $post_id );
		}
		if ( ! empty( $body->featured_image ) ) {
			$attachment_id = self::media_sideload_image( $body->featured_image, $post_id );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}
		WP_CLI::success( "Fetched {$slug} as post id {$post_id}." );
	}

	private static function media_sideload_image( $file, $post_id, $desc = null, $return = 'html' ) {
		if ( ! empty( $file ) ) {
			// Set variables for storage, fix file filename for query strings.
			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
			if ( ! $matches ) {
				return new WP_Error( 'image_sideload_failed', __( 'Invalid image URL' ) );
			}

			$file_array = array();
			$file_array['name'] = basename( $matches[0] );

			// Download file to temp location.
			$file_array['tmp_name'] = download_url( $file );

			// If error storing temporarily, return the error.
			if ( is_wp_error( $file_array['tmp_name'] ) ) {
				return $file_array['tmp_name'];
			}

			// Do the validation and storage stuff.
			$id = media_handle_sideload( $file_array, $post_id, $desc );

			// If error storing permanently, unlink.
			if ( is_wp_error( $id ) ) {
				@unlink( $file_array['tmp_name'] );
				return $id;
			}
			return $id;
		}
		return false;
	}

}

WP_CLI::add_command( 'fetch-wpcom', 'Fetch_WPCom' );
