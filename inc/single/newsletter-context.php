<?php
/**
 * Contextual newsletter copy for single posts.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tailor the existing post-content newsletter form to its primary entity.
 *
 * Only heading and description are changed. The content context and all other
 * form arguments remain owned by Extra Chill Newsletter.
 *
 * @param array  $args    Newsletter form arguments.
 * @param string $context Newsletter form context.
 * @return array
 */
function extrachill_blog_contextualize_newsletter_form( $args, $context ) {
	if (
		'content' !== $context ||
		! is_singular( 'post' ) ||
		! function_exists( 'taxonomy_exists' ) ||
		! function_exists( 'wp_get_post_terms' )
	) {
		return $args;
	}

	$entities = array(
		'artist'   => array(
			'heading'     => /* translators: %s: artist name. */ __( 'Keep up with %s', 'extrachill-blog' ),
			'description' => /* translators: %s: artist name. */ __( 'Get the latest stories about %s, plus independent music coverage from Extra Chill.', 'extrachill-blog' ),
		),
		'festival' => array(
			'heading'     => /* translators: %s: festival name. */ __( 'Stay in the %s loop', 'extrachill-blog' ),
			'description' => /* translators: %s: festival name. */ __( 'Get %s news and festival updates, plus the best of Extra Chill.', 'extrachill-blog' ),
		),
		'location' => array(
			'heading'     => /* translators: %s: location name. */ __( 'Stay connected to the %s music scene', 'extrachill-blog' ),
			'description' => /* translators: %s: location name. */ __( 'Get music news from %s and beyond, delivered by Extra Chill.', 'extrachill-blog' ),
		),
	);

	foreach ( $entities as $taxonomy => $copy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = wp_get_post_terms(
			get_the_ID(),
			$taxonomy,
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! isset( $terms[0]->name ) ) {
			continue;
		}

		$args['heading']     = sprintf( $copy['heading'], $terms[0]->name );
		$args['description'] = sprintf( $copy['description'], $terms[0]->name );
		break;
	}

	return $args;
}
add_filter( 'extrachill_newsletter_form_args', 'extrachill_blog_contextualize_newsletter_form', 10, 2 );
