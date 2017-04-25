<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class Fetch_WPCom {

	/**
	 * Fetch a post from WordPress.com and insert into the database.
	 *
	 * <url>
	 * : Post permalink to download.
	 *
	 * [--force]
	 * : Delete current post if it exists.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;
		list( $url ) = $args;

		if ( ! preg_match( '#^https?://([^/]+)/[\d]{4}/[\d]{2}/[\d]{2}/([^/]+)#', $url, $matches ) ) {
			WP_CLI::error( "Couldn't parse host and slug from URL." );
		}
		$site = $matches[1];
		$slug = $matches[2];

		if ( $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type='post'", $slug ) ) ) {
			if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'force' ) ) {
				WP_CLI::error( "Post already exists as id {$post_id}" );
			}
			wp_delete_post( $post_id, true );
			WP_CLI::log( "Deleted existing post {$post_id}" );
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
			'post_excerpt' => $body->excerpt,
			'post_date'    => date( 'Y-m-d H:i:s', strtotime( $body->date ) ),
		);
		if ( $user = get_user_by( 'login', $body->author->login ) ) {
			$post['post_author'] = $user->ID;
		}
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( $post_id );
		}
		if ( ! empty( $body->terms ) ) {
			foreach( $body->terms as $taxonomy => $terms ) {
				$terms = (array) $terms;
				if ( empty( $terms ) ) {
					continue;
				}
				$terms = array_values( wp_list_pluck( $terms, 'name' ) );
				if ( is_taxonomy_hierarchical( $taxonomy ) ) {
					$term_ids = array();
					foreach( $terms as $term ) {
						$obj = get_term_by( 'name', $term, $taxonomy );
						if ( $obj ) {
							$term_ids[] = (int) $obj->term_id;
						}
					}
					$terms = $term_ids;
				}
				wp_set_post_terms( $post_id, $terms, $taxonomy );
			}
		}
		if ( ! empty( $body->featured_image ) ) {
			$attachment_id = self::media_sideload_image( $body->featured_image, $post_id );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}
		WP_CLI::success( "Fetched {$slug} as post id {$post_id}" );
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
