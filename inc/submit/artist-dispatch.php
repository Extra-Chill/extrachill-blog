<?php
/**
 * Artist Dispatch submission pathway.
 *
 * WordPress owns posts, capabilities, autosaves, revisions, and previews.
 * Extra Chill Users owns access and represented-artist authorization. Blocks
 * Everywhere owns the canonical frontend editor shell.
 *
 * @package ExtraChillBlog
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_BLOG_DISPATCH_SOURCE_META       = '_ec_artist_dispatch_source';
const EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META    = '_ec_artist_dispatch_submitter';
const EXTRACHILL_BLOG_DISPATCH_ARTIST_META       = '_ec_artist_dispatch_artist';
const EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META    = '_ec_artist_dispatch_submitted_at';
const EXTRACHILL_BLOG_DISPATCH_TERMS_META        = '_ec_artist_dispatch_terms_version';
const EXTRACHILL_BLOG_DISPATCH_SOURCE            = 'artist-dispatch';
const EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION     = '2026-07-18';
const EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG     = 'artist-dispatch';
const EXTRACHILL_BLOG_DISPATCH_MIN_BE_VERSION    = '3.6.0';
const EXTRACHILL_BLOG_DISPATCH_DEFAULT_DRAFT_MAX = 3;
const EXTRACHILL_BLOG_DISPATCH_DEFAULT_QUEUE_MAX = 2;

/**
 * Provision a native page without changing an existing page.
 *
 * @param string $path Path relative to the site root.
 * @param string $title Page title.
 * @param string $sentinel Stored content sentinel.
 * @param int    $parent_id Parent page ID.
 * @return int Page ID, or zero on failure.
 */
function extrachill_blog_provision_submit_page( $path, $title, $sentinel, $parent_id = 0 ) {
	$existing = get_page_by_path( $path, OBJECT, 'page' );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_name'    => basename( $path ),
			'post_content' => $sentinel,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_parent'  => $parent_id,
		),
		true
	);

	return is_wp_error( $page_id ) ? 0 : (int) $page_id;
}

/**
 * Provision /submit and its native child pages idempotently.
 */
function extrachill_blog_provision_submit_pages() {
	$submit_id = extrachill_blog_provision_submit_page(
		'submit',
		__( 'Artist Dispatch', 'extrachill-blog' ),
		'<!-- extrachill-artist-dispatch -->'
	);

	extrachill_blog_provision_submit_page(
		'submit/guidelines',
		__( 'Artist Dispatch Guidelines', 'extrachill-blog' ),
		'<!-- extrachill-artist-dispatch-guidelines -->',
		$submit_id
	);
	extrachill_blog_provision_submit_page(
		'submit/write',
		__( 'Write an Artist Dispatch', 'extrachill-blog' ),
		'<!-- extrachill-artist-dispatch-editor -->',
		$submit_id
	);
}

/**
 * Run page provisioning once per plugin version.
 */
function extrachill_blog_maybe_provision_submit_pages() {
	if ( version_compare( get_option( 'extrachill_blog_submit_page_version', '0' ), EXTRACHILL_BLOG_VERSION, '<' ) ) {
		extrachill_blog_provision_submit_pages();
		update_option( 'extrachill_blog_submit_page_version', EXTRACHILL_BLOG_VERSION );
	}
}
add_action( 'admin_init', 'extrachill_blog_maybe_provision_submit_pages' );

/**
 * Read the exact Extra Chill Users self-access contract.
 *
 * The contract returns status, artist_id, and eligibility. Eligibility carries
 * criteria, reasons, and the operator policy. Missing data fails closed.
 *
 * @return array|WP_Error Access state.
 */
function extrachill_blog_dispatch_access() {
	if ( ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return new WP_Error( 'artist_dispatch_logged_out', __( 'Please log in to continue.', 'extrachill-blog' ) );
	}

	$ability = wp_get_ability( 'extrachill/get-artist-dispatch-access' );
	if ( ! $ability ) {
		return new WP_Error( 'artist_dispatch_dependency_missing', __( 'Artist Dispatch access is temporarily unavailable.', 'extrachill-blog' ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) || ! isset( $result['status'], $result['eligibility']['criteria'], $result['eligibility']['policy']['pilot_enabled'] ) ) {
		return new WP_Error( 'artist_dispatch_invalid_contract', __( 'Artist Dispatch access is temporarily unavailable.', 'extrachill-blog' ) );
	}

	return $result;
}

/**
 * Determine whether the current user has approved native write access.
 *
 * @param array|WP_Error|null $access Optional resolved access state.
 * @return bool Whether access is approved.
 */
function extrachill_blog_dispatch_is_approved( $access = null ) {
	$access = null === $access ? extrachill_blog_dispatch_access() : $access;
	return ! is_wp_error( $access )
		&& ! empty( $access['eligibility']['policy']['pilot_enabled'] )
		&& 'approved' === $access['status']
		&& current_user_can( 'edit_posts' )
		&& current_user_can( 'submit_for_review' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered by the required Extra Chill Users dependency.
}

/**
 * Confirm an artist ID against the Users ability response.
 *
 * @param int        $artist_id Artist profile ID.
 * @param array|null $access Access state.
 * @return bool Whether the current user may represent the artist.
 */
function extrachill_blog_dispatch_can_represent_artist( $artist_id, $access = null ) {
	$access = null === $access ? extrachill_blog_dispatch_access() : $access;
	if ( is_wp_error( $access ) || ! extrachill_blog_dispatch_is_approved( $access ) ) {
		return false;
	}

	$artist_id = absint( $artist_id );
	if ( 0 >= $artist_id || absint( isset( $access['artist_id'] ) ? $access['artist_id'] : 0 ) !== $artist_id ) {
		return false;
	}

	$artist_ids = isset( $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		? array_map( 'absint', (array) $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		: array();
	return in_array( $artist_id, $artist_ids, true );
}

/**
 * Check the deployed Blocks Everywhere dependency.
 *
 * @return bool Whether the canonical editor API is available.
 */
function extrachill_blog_dispatch_has_editor_dependency() {
	return class_exists( '\\Automattic\\Blocks_Everywhere\\Blocks_Everywhere' )
		&& version_compare( \Automattic\Blocks_Everywhere\Blocks_Everywhere::VERSION, EXTRACHILL_BLOG_DISPATCH_MIN_BE_VERSION, '>=' );
}

/**
 * Test whether a post belongs to this intake pathway.
 *
 * @param int $post_id Post ID.
 * @return bool Whether this is an Artist Dispatch.
 */
function extrachill_blog_is_artist_dispatch( $post_id ) {
	return EXTRACHILL_BLOG_DISPATCH_SOURCE === get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_SOURCE_META, true );
}

/**
 * Get active post limits.
 *
 * @return array{draft:int,pending:int} Limits.
 */
function extrachill_blog_dispatch_limits() {
	return array(
		'draft'   => max( 1, (int) apply_filters( 'extrachill_blog_artist_dispatch_draft_limit', EXTRACHILL_BLOG_DISPATCH_DEFAULT_DRAFT_MAX ) ),
		'pending' => max( 1, (int) apply_filters( 'extrachill_blog_artist_dispatch_pending_limit', EXTRACHILL_BLOG_DISPATCH_DEFAULT_QUEUE_MAX ) ),
	);
}

/**
 * Count the current user's marked posts in one status.
 *
 * @param string $status Post status.
 * @param int    $exclude Optional post ID to exclude.
 * @return int Count.
 */
function extrachill_blog_dispatch_count( $status, $exclude = 0 ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => $status,
			'author'         => get_current_user_id(),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post__not_in'   => $exclude ? array( $exclude ) : array(),
			'meta_key'       => EXTRACHILL_BLOG_DISPATCH_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded author/status provenance query.
			'meta_value'     => EXTRACHILL_BLOG_DISPATCH_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded author/status provenance query.
			'no_found_rows'  => false,
		)
	);
	return (int) $query->found_posts;
}

/**
 * Register private provenance fields exposed only in edit context.
 */
function extrachill_blog_dispatch_register_meta() {
	$fields = array(
		EXTRACHILL_BLOG_DISPATCH_SOURCE_META    => 'string',
		EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META => 'integer',
		EXTRACHILL_BLOG_DISPATCH_ARTIST_META    => 'integer',
		EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META => 'string',
		EXTRACHILL_BLOG_DISPATCH_TERMS_META     => 'string',
	);

	foreach ( $fields as $key => $type ) {
		register_post_meta(
			'post',
			$key,
			array(
				'single'        => true,
				'type'          => $type,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
add_action( 'init', 'extrachill_blog_dispatch_register_meta' );

/**
 * Recursively validate the text-first block and embed policy.
 *
 * @param array $blocks Parsed blocks.
 * @return true|WP_Error Validation result.
 */
function extrachill_blog_dispatch_validate_blocks( array $blocks ) {
	$allowed = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/embed' );
	$hosts   = array( 'youtube.com', 'www.youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com', 'soundcloud.com', 'www.soundcloud.com', 'open.spotify.com', 'bandcamp.com' );

	foreach ( $blocks as $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
		if ( null === $name && '' !== trim( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
			return new WP_Error( 'artist_dispatch_unstructured_content', __( 'Artist Dispatch content must use supported blocks.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( null !== $name && ! in_array( $name, $allowed, true ) ) {
			return new WP_Error( 'artist_dispatch_disallowed_block', __( 'This draft contains a block that Artist Dispatch does not support.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( 'core/embed' === $name ) {
			$url             = isset( $block['attrs']['url'] ) ? esc_url_raw( $block['attrs']['url'] ) : '';
			$host            = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$host_is_allowed = in_array( $host, $hosts, true ) || ( strlen( $host ) > 9 && '.bandcamp.com' === substr( $host, -13 ) );
			if ( ! $url || ! $host_is_allowed ) {
				return new WP_Error( 'artist_dispatch_disallowed_embed', __( 'That embed provider is not supported.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$valid = extrachill_blog_dispatch_validate_blocks( $block['innerBlocks'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
	}

	return true;
}

/**
 * Enforce Artist Dispatch policy through the native posts controller.
 *
 * @param stdClass        $prepared_post Prepared post.
 * @param WP_REST_Request $request REST request.
 * @return stdClass|WP_Error Prepared post or error.
 */
function extrachill_blog_dispatch_rest_pre_insert( $prepared_post, $request ) {
	$post_id  = absint( $request->get_param( 'id' ) );
	$meta     = (array) $request->get_param( 'meta' );
	$creating = 0 === $post_id && EXTRACHILL_BLOG_DISPATCH_SOURCE === ( isset( $meta[ EXTRACHILL_BLOG_DISPATCH_SOURCE_META ] ) ? $meta[ EXTRACHILL_BLOG_DISPATCH_SOURCE_META ] : '' );
	$marked   = $post_id > 0 && extrachill_blog_is_artist_dispatch( $post_id );

	if ( ! $creating && ! $marked ) {
		return $prepared_post;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'artist_dispatch_forbidden', __( 'You cannot edit Artist Dispatches.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}

	$is_editor = current_user_can( 'edit_others_posts' );
	if ( ! $is_editor ) {
		$allowed_params = array( 'id', 'title', 'content', 'status', 'meta', 'context', '_locale' );
		foreach ( array_keys( $request->get_params() ) as $param ) {
			if ( ! in_array( $param, $allowed_params, true ) ) {
				return new WP_Error( 'artist_dispatch_forbidden_field', __( 'That post field is managed by the editorial team.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
	}
	if ( $creating ) {
		$access    = extrachill_blog_dispatch_access();
		$artist_id = absint( isset( $meta[ EXTRACHILL_BLOG_DISPATCH_ARTIST_META ] ) ? $meta[ EXTRACHILL_BLOG_DISPATCH_ARTIST_META ] : 0 );
		$terms     = sanitize_text_field( isset( $meta[ EXTRACHILL_BLOG_DISPATCH_TERMS_META ] ) ? $meta[ EXTRACHILL_BLOG_DISPATCH_TERMS_META ] : '' );
		if ( array_diff( array_keys( $meta ), array( EXTRACHILL_BLOG_DISPATCH_SOURCE_META, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, EXTRACHILL_BLOG_DISPATCH_TERMS_META ) ) ) {
			return new WP_Error( 'artist_dispatch_forbidden_meta', __( 'That post metadata is managed by the editorial team.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( ! extrachill_blog_dispatch_can_represent_artist( $artist_id, $access ) || EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION !== $terms ) {
			return new WP_Error( 'artist_dispatch_invalid_provenance', __( 'The represented artist or accepted guidelines are invalid.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( extrachill_blog_dispatch_count( 'draft' ) >= extrachill_blog_dispatch_limits()['draft'] ) {
			return new WP_Error( 'artist_dispatch_draft_limit', __( 'Finish or submit an existing draft before starting another.', 'extrachill-blog' ), array( 'status' => 429 ) );
		}
		$prepared_post->post_author = get_current_user_id();
		$prepared_post->post_status = 'draft';
	}

	if ( $marked && ! $is_editor ) {
		$post = get_post( $post_id );
		if ( ! $post || get_current_user_id() !== (int) $post->post_author || 'draft' !== $post->post_status ) {
			return new WP_Error( 'artist_dispatch_locked', __( 'Only your draft Artist Dispatches can be edited.', 'extrachill-blog' ), array( 'status' => 403 ) );
		}
		foreach ( $meta as $meta_key => $meta_value ) {
			$stored = get_post_meta( $post_id, $meta_key, true );
			if ( ! in_array( $meta_key, array( EXTRACHILL_BLOG_DISPATCH_SOURCE_META, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, EXTRACHILL_BLOG_DISPATCH_TERMS_META ), true ) || (string) $stored !== (string) $meta_value ) {
				return new WP_Error( 'artist_dispatch_immutable_provenance', __( 'Artist Dispatch provenance cannot be changed.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
		$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : 'draft';
		if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
			return new WP_Error( 'artist_dispatch_invalid_status', __( 'Artist Dispatches can only be saved or submitted for review.', 'extrachill-blog' ), array( 'status' => 403 ) );
		}
		if ( 'pending' === $status && extrachill_blog_dispatch_count( 'pending', $post_id ) >= extrachill_blog_dispatch_limits()['pending'] ) {
			return new WP_Error( 'artist_dispatch_pending_limit', __( 'Wait for an editor to review an existing submission before sending another.', 'extrachill-blog' ), array( 'status' => 429 ) );
		}
	}

	$content = isset( $prepared_post->post_content ) ? $prepared_post->post_content : '';
	if ( '' !== $content ) {
		$valid = extrachill_blog_dispatch_validate_blocks( parse_blocks( $content ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}

	return $prepared_post;
}
add_filter( 'rest_pre_insert_post', 'extrachill_blog_dispatch_rest_pre_insert', 10, 2 );

/**
 * Apply the same policy to core's autosave controller.
 *
 * Core autosaves use the native parent permission callback but do not run the
 * posts controller's rest_pre_insert_post filter before writing the revision.
 *
 * @param mixed           $result Pre-dispatch result.
 * @param WP_REST_Server  $server REST server.
 * @param WP_REST_Request $request REST request.
 * @return mixed|WP_Error Result or policy error.
 */
function extrachill_blog_dispatch_rest_pre_autosave( $result, $server, $request ) {
	unset( $server );
	if ( null !== $result || ! preg_match( '#^/wp/v2/posts/(?P<id>\d+)/autosaves$#', $request->get_route(), $matches ) ) {
		return $result;
	}

	$post_id = absint( $matches['id'] );
	if ( ! extrachill_blog_is_artist_dispatch( $post_id ) ) {
		return $result;
	}
	$post      = get_post( $post_id );
	$submitter = absint( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) );
	if ( ! $post || 'draft' !== $post->post_status || get_current_user_id() !== $submitter || ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'artist_dispatch_autosave_forbidden', __( 'This Artist Dispatch cannot be autosaved.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}
	$allowed_params = array( 'id', 'title', 'content', 'excerpt', 'status', 'meta', 'context', '_locale' );
	foreach ( array_keys( $request->get_params() ) as $param ) {
		if ( ! in_array( $param, $allowed_params, true ) ) {
			return new WP_Error( 'artist_dispatch_forbidden_field', __( 'That post field is managed by the editorial team.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
	}

	$meta = (array) $request->get_param( 'meta' );
	foreach ( $meta as $meta_key => $meta_value ) {
		if ( ! in_array( $meta_key, array( EXTRACHILL_BLOG_DISPATCH_SOURCE_META, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, EXTRACHILL_BLOG_DISPATCH_TERMS_META ), true ) || (string) get_post_meta( $post_id, $meta_key, true ) !== (string) $meta_value ) {
			return new WP_Error( 'artist_dispatch_immutable_provenance', __( 'Artist Dispatch provenance cannot be changed.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
	}
	if ( $request->has_param( 'excerpt' ) && '' !== trim( (string) $request->get_param( 'excerpt' ) ) ) {
		return new WP_Error( 'artist_dispatch_forbidden_field', __( 'That post field is managed by the editorial team.', 'extrachill-blog' ), array( 'status' => 400 ) );
	}
	if ( $request->has_param( 'content' ) ) {
		$valid = extrachill_blog_dispatch_validate_blocks( parse_blocks( (string) $request->get_param( 'content' ) ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}

	return $result;
}
add_filter( 'rest_pre_dispatch', 'extrachill_blog_dispatch_rest_pre_autosave', 10, 3 );

/**
 * Stamp trusted provenance after native draft creation.
 *
 * @param WP_Post         $post Inserted post.
 * @param WP_REST_Request $request REST request.
 * @param bool            $creating Whether the post was created.
 */
function extrachill_blog_dispatch_rest_after_insert( $post, $request, $creating ) {
	$meta = (array) $request->get_param( 'meta' );
	if ( $creating && EXTRACHILL_BLOG_DISPATCH_SOURCE === ( isset( $meta[ EXTRACHILL_BLOG_DISPATCH_SOURCE_META ] ) ? $meta[ EXTRACHILL_BLOG_DISPATCH_SOURCE_META ] : '' ) ) {
		update_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SOURCE_META, EXTRACHILL_BLOG_DISPATCH_SOURCE );
		update_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, get_current_user_id() );
		update_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, absint( $meta[ EXTRACHILL_BLOG_DISPATCH_ARTIST_META ] ) );
		update_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_TERMS_META, EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION );
		extrachill_blog_dispatch_ensure_category( $post->ID );
		extrachill_blog_dispatch_emit_event( 'draft_created', $post->ID );
	}
}
add_action( 'rest_after_insert_post', 'extrachill_blog_dispatch_rest_after_insert', 10, 3 );

/**
 * Lock contributor access to pending and published Dispatches in native caps.
 *
 * @param string[] $caps Primitive capabilities.
 * @param string   $cap Requested capability.
 * @param int      $user_id User ID.
 * @param array    $args Capability arguments.
 * @return string[] Filtered capabilities.
 */
function extrachill_blog_dispatch_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'read_post' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}
	$post = get_post( absint( $args[0] ) );
	if ( ! $post || ! extrachill_blog_is_artist_dispatch( $post->ID ) || user_can( $user_id, 'edit_others_posts' ) ) {
		return $caps;
	}
	$submitter = absint( get_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) );
	if ( $submitter !== (int) $user_id || 'draft' !== $post->post_status ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
add_filter( 'map_meta_cap', 'extrachill_blog_dispatch_map_meta_cap', 10, 4 );

/**
 * Stamp the first review time and lifecycle analytics.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post Post.
 */
function extrachill_blog_dispatch_transition( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post || ! extrachill_blog_is_artist_dispatch( $post->ID ) || $new_status === $old_status ) {
		return;
	}
	if ( 'pending' === $new_status && 'draft' === $old_status ) {
		if ( ! get_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, true ) ) {
			update_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, current_time( 'mysql', true ) );
		}
		extrachill_blog_dispatch_emit_event( 'submitted', $post->ID );
	}
	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		extrachill_blog_dispatch_ensure_category( $post->ID );
		extrachill_blog_dispatch_emit_event( 'published', $post->ID );
	}
}
add_action( 'transition_post_status', 'extrachill_blog_dispatch_transition', 10, 3 );

/**
 * Resolve lifecycle event names at one replaceable analytics boundary.
 *
 * @param string $event Lifecycle key.
 * @return string Event name.
 */
function extrachill_blog_dispatch_event_name( $event ) {
	$events = array(
		'access_requested' => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED : 'artist_dispatch_access_requested',
		'draft_created'    => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED : 'artist_dispatch_draft_created',
		'submitted'        => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED : 'artist_dispatch_submitted',
		'published'        => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED : 'artist_dispatch_published',
	);
	return isset( $events[ $event ] ) ? $events[ $event ] : '';
}

/**
 * Emit a bounded lifecycle event through the analytics ability.
 *
 * @param string $event Lifecycle key.
 * @param int    $post_id Optional post ID.
 */
function extrachill_blog_dispatch_emit_event( $event, $post_id = 0 ) {
	$name    = extrachill_blog_dispatch_event_name( $event );
	$ability = $name && function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( ! $ability ) {
		return;
	}
	$data = array( 'surface' => 'artist_dispatch' );
	if ( $post_id ) {
		$data['post_id'] = absint( $post_id );
	}
	$ability->execute(
		array(
			'event_type' => $name,
			'event_data' => $data,
		)
	);
}

/**
 * Ensure the native Artist Dispatch category is assigned.
 *
 * @param int $post_id Post ID.
 */
function extrachill_blog_dispatch_ensure_category( $post_id ) {
	$term = get_term_by( 'slug', EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG, 'category' );
	if ( ! $term ) {
		$created = wp_insert_term( __( 'Artist Dispatch', 'extrachill-blog' ), 'category', array( 'slug' => EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG ) );
		if ( is_wp_error( $created ) ) {
			return;
		}
		$term_id = (int) $created['term_id'];
	} else {
		$term_id = (int) $term->term_id;
	}
	wp_set_post_categories( $post_id, array( $term_id ), true );
}

/**
 * Register the generic publication notification descriptor.
 */
function extrachill_blog_dispatch_register_notification() {
	if ( function_exists( 'ec_users_register_publish_notify_source' ) ) {
		ec_users_register_publish_notify_source(
			'artist-dispatch',
			array(
				'meta_key'       => EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- notification descriptor, not a query.
				'user_id_field'  => '',
				'type'           => 'artist_dispatch_published',
				/* translators: %s: published post title. */
				'title_template' => __( 'Your Artist Dispatch “%s” is live', 'extrachill-blog' ),
			)
		);
	}
}
add_action( 'init', 'extrachill_blog_dispatch_register_notification', 20 );

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/presentation.php';
