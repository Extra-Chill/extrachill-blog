<?php
/**
 * Public Artist Dispatch presentation.
 *
 * @package ExtraChillBlog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve canonical represented artist display data.
 *
 * @param int $artist_id Artist profile ID on the Artist Platform site.
 * @return array{name:string,url:string}|array Empty on failure.
 */
function extrachill_blog_dispatch_artist_display( $artist_id ) {
	$artist_id = absint( $artist_id );
	$blog_id   = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
	if ( ! $artist_id || ! $blog_id ) {
		return array();
	}

	switch_to_blog( $blog_id );
	try {
		$post = get_post( $artist_id );
		if ( ! $post || 'artist_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
			return array();
		}
		return array(
			'name' => get_the_title( $post ),
			'url'  => get_permalink( $post ),
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Prepend the visible label and first-party disclosure to published Dispatches.
 *
 * @param string $content Post content.
 * @return string Filtered content.
 */
function extrachill_blog_dispatch_public_disclosure( $content ) {
	if ( ! is_singular( 'post' ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	$post_id = get_the_ID();
	if ( ! extrachill_blog_is_artist_dispatch( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return $content;
	}

	$artist = extrachill_blog_dispatch_artist_display( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, true ) );
	if ( empty( $artist ) ) {
		return $content;
	}
	$author = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) );
	$notice = '<aside class="artist-dispatch-disclosure" aria-label="' . esc_attr__( 'Artist Dispatch disclosure', 'extrachill-blog' ) . '"><span class="artist-dispatch-label">' . esc_html__( 'Artist Dispatch', 'extrachill-blog' ) . '</span><p>' . sprintf(
		/* translators: 1: contributor name, 2: linked artist name. */
		wp_kses_post( __( 'A first-person story by %1$s, who is a member of or directly connected to <a href="%3$s">%2$s</a>. Edited and published by Extra Chill.', 'extrachill-blog' ) ),
		esc_html( $author ),
		esc_html( $artist['name'] ),
		esc_url( $artist['url'] )
	) . '</p></aside>';

	return $notice . $content;
}
add_filter( 'the_content', 'extrachill_blog_dispatch_public_disclosure', 8 );

/**
 * Add a public post class for marked Dispatches.
 *
 * @param string[] $classes Post classes.
 * @return string[] Classes.
 */
function extrachill_blog_dispatch_post_class( $classes ) {
	if ( extrachill_blog_is_artist_dispatch( get_the_ID() ) ) {
		$classes[] = 'artist-dispatch-post';
	}
	return $classes;
}
add_filter( 'post_class', 'extrachill_blog_dispatch_post_class' );
