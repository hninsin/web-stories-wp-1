<?php
/**
 * Class Stories_Controller
 *
 * @package   Google\Web_Stories
 * @copyright 2020 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://github.com/google/web-stories-wp
 */

/**
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Web_Stories\REST_API;

use Google\Web_Stories\Demo_Content;
use Google\Web_Stories\Story_Post_Type;
use WP_Query;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Post_Type;

/**
 * Stories_Controller class.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Stories_Controller extends Stories_Base_Controller {

	/**
	 * Query args.
	 *
	 * @var array
	 */
	private $args = [];

	/**
	 * Default style presets to pass if not set.
	 */
	const EMPTY_STYLE_PRESETS = [
		'colors'     => [],
		'textStyles' => [],
	];

	/**
	 * Prepares a single story output for response. Add post_content_filtered field to output.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post         $post Post object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $post, $request ) {
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';

		if ( 'auto-draft' === $post->post_status && wp_validate_boolean( $request['web_stories_demo'] ) ) {
			$demo         = new Demo_Content();
			$demo_content = $demo->get_content();
			if ( ! empty( $demo_content ) ) {
				$post->post_title            = $demo->get_title();
				$post->post_content_filtered = $demo_content;
			}
		}

		$response = parent::prepare_item_for_response( $post, $request );
		$fields   = $this->get_fields_for_response( $request );
		$data     = $response->get_data();

		if ( rest_is_field_included( 'style_presets', $fields ) ) {
			$style_presets         = get_option( Story_Post_Type::STYLE_PRESETS_OPTION, self::EMPTY_STYLE_PRESETS );
			$data['style_presets'] = is_array( $style_presets ) ? $style_presets : self::EMPTY_STYLE_PRESETS;
		}

		if ( rest_is_field_included( 'preview_link', $fields ) ) {
			// Based on https://github.com/WordPress/wordpress-develop/blob/8153c8ba020c4aec0b9d94243cd39c689a0730f7/src/wp-admin/includes/post.php#L1445-L1457.
			if ( 'draft' === $post->post_status || empty( $post->post_name ) ) {
				$view_link = get_preview_post_link( $post );
			} elseif ( 'publish' === $post->post_status ) {
				$view_link = get_permalink( $post );
			} else {
				if ( ! function_exists( 'get_sample_permalink' ) ) {
					require_once ABSPATH . 'wp-admin/includes/post.php';
				}

				list ( $permalink ) = get_sample_permalink( $post->ID, $post->post_title, '' );

				// Allow non-published (private, future) to be viewed at a pretty permalink, in case $post->post_name is set.
				$view_link = str_replace( [ '%pagename%', '%postname%' ], $post->post_name, $permalink );
			}

			$data['preview_link'] = $view_link;
		}

		if ( rest_is_field_included( 'edit_link', $fields ) ) {
			$edit_link = get_edit_post_link( $post, 'rest-api' );
			if ( $edit_link ) {
				$data['edit_link'] = $edit_link;
			}
		}

		if ( rest_is_field_included( 'embed_post_link', $fields ) && current_user_can( 'edit_posts' ) ) {
			$data['embed_post_link'] = add_query_arg( [ 'from-web-story' => $post->ID ], admin_url( 'post-new.php' ) );
		}

		$data  = $this->filter_response_by_context( $data, $context );
		$links = $response->get_links();

		$response = new WP_REST_Response( $data );
		foreach ( $links as $rel => $rel_links ) {
			foreach ( $rel_links as $link ) {
				$response->add_link( $rel, $link['href'], $link['attributes'] );
			}
		}

		/**
		 * Filters the post data for a response.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WP_Post $post Post object.
		 * @param WP_REST_Request $request Request object.
		 */
		return apply_filters( "rest_prepare_{$this->post_type}", $response, $post, $request );
	}

	/**
	 * Updates a single post.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$response = parent::update_item( $request );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( $response );
		}

		// If style presets are set.
		$style_presets = $request->get_param( 'style_presets' );
		if ( is_array( $style_presets ) ) {
			update_option( Story_Post_Type::STYLE_PRESETS_OPTION, $style_presets );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Retrieves the story's schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema as an array.
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = parent::get_item_schema();

		$schema['properties']['style_presets'] = [
			'description' => __( 'Style presets used by all stories', 'web-stories' ),
			'type'        => 'object',
			'context'     => [ 'view', 'edit' ],
		];

		$schema['properties']['preview_link'] = [
			'description' => __( 'Preview Link.', 'web-stories' ),
			'type'        => 'string',
			'context'     => [ 'edit' ],
			'format'      => 'uri',
			'default'     => '',
		];

		$schema['properties']['edit_link'] = [
			'description' => _x( 'Edit Link', 'compound noun', 'web-stories' ),
			'type'        => 'string',
			'context'     => [ 'edit' ],
			'format'      => 'uri',
			'default'     => '',
		];

		$schema['properties']['embed_post_link'] = [
			'description' => __( 'Embed Post Edit Link.', 'web-stories' ),
			'type'        => 'string',
			'context'     => [ 'edit' ],
			'format'      => 'uri',
			'default'     => '',
		];

		$schema['properties']['status']['enum'][] = 'auto-draft';

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Filters query clauses to sort posts by the author's display name.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $clauses Associative array of the clauses for the query.
	 * @param WP_Query $query   The WP_Query instance.
	 *
	 * @return array Filtered query clauses.
	 */
	public function filter_posts_clauses( $clauses, $query ) {
		global $wpdb;

		if ( $this->post_type !== $query->get( 'post_type' ) ) {
			return $clauses;
		}
		if ( 'story_author' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		$order              = $query->get( 'order' );
		$clauses['join']   .= " LEFT JOIN {$wpdb->users} ON {$wpdb->posts}.post_author={$wpdb->users}.ID";
		$clauses['orderby'] = "{$wpdb->users}.display_name $order, " . $clauses['orderby'];
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

		return $clauses;
	}

	/**
	 * Filter the query to cache the value to a class property.
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array Current args.
	 */
	public function filter_query( $args, $request ) {
		$this->args = $this->prepare_tax_query( $args, $request );

		return $args;
	}
	/**
	 * Retrieves a collection of web stories.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		add_filter( "rest_{$this->post_type}_query", [ $this, 'filter_query' ], 100, 2 );
		add_filter( 'posts_clauses', [ $this, 'filter_posts_clauses' ], 10, 2 );
		$response = parent::get_items( $request );
		remove_filter( 'posts_clauses', [ $this, 'filter_posts_clauses' ], 10 );
		remove_filter( "rest_{$this->post_type}_query", [ $this, 'filter_query' ], 100 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'edit' !== $request['context'] ) {
			return $response;
		}

		$response = $this->add_response_headers( $response, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $request['_web_stories_envelope'] ) {
			$embed    = isset( $request['_embed'] ) ? rest_parse_embed_param( $request['_embed'] ) : false;
			$response = rest_get_server()->envelope_response( $response, $embed );
		}

		return $response;
	}

	/**
	 * Prepares the 'tax_query' for a collection of posts.
	 *
	 * @todo Remove this method once WordPress 5.7 becomes minimum required version.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @since 1.12.0
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Updated query arguments.
	 */
	private function prepare_tax_query( array $args, WP_REST_Request $request ) {
		$relation = $request['tax_relation'];

		if ( $relation ) {
			$args['tax_query'] = [ 'relation' => $relation ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$taxonomies = wp_list_filter(
			get_object_taxonomies( $this->post_type, 'objects' ),
			[ 'show_in_rest' => true ]
		);

		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			$tax_include = $request[ $base ];
			$tax_exclude = $request[ $base . '_exclude' ];

			if ( $tax_include ) {
				$terms            = [];
				$include_children = false;
				$operator         = 'IN';

				if ( rest_is_array( $tax_include ) ) {
					$terms = $tax_include;
				} elseif ( rest_is_object( $tax_include ) ) {
					$terms            = empty( $tax_include['terms'] ) ? [] : $tax_include['terms'];
					$include_children = ! empty( $tax_include['include_children'] );

					if ( isset( $tax_include['operator'] ) && 'AND' === $tax_include['operator'] ) {
						$operator = 'AND';
					}
				}

				if ( $terms ) {
					$args['tax_query'][] = [
						'taxonomy'         => $taxonomy->name,
						'field'            => 'term_id',
						'terms'            => $terms,
						'include_children' => $include_children,
						'operator'         => $operator,
					];
				}
			}

			if ( $tax_exclude ) {
				$terms            = [];
				$include_children = false;

				if ( rest_is_array( $tax_exclude ) ) {
					$terms = $tax_exclude;
				} elseif ( rest_is_object( $tax_exclude ) ) {
					$terms            = empty( $tax_exclude['terms'] ) ? [] : $tax_exclude['terms'];
					$include_children = ! empty( $tax_exclude['include_children'] );
				}

				if ( $terms ) {
					$args['tax_query'][] = [
						'taxonomy'         => $taxonomy->name,
						'field'            => 'term_id',
						'terms'            => $terms,
						'include_children' => $include_children,
						'operator'         => 'NOT IN',
					];
				}
			}
		}

		return $args;
	}

	/**
	 * Add response headers, with post counts.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @since 1.12.0
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_REST_Request  $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function add_response_headers( WP_REST_Response $response, WP_REST_Request $request ) {
		// Add counts for other statuses.
		$statuses = [
			'publish' => 'publish',
		];

		$post_type = get_post_type_object( $this->post_type );

		if ( ! ( $post_type instanceof WP_Post_Type ) ) {
			return $response;
		}

		if ( current_user_can( $post_type->cap->edit_posts ) ) {
			$statuses['draft']   = 'draft';
			$statuses['future']  = 'future';
			$statuses['pending'] = 'pending';
		}

		if ( current_user_can( $post_type->cap->publish_posts ) ) {
			$statuses['private'] = 'private';
		}

		$edit_others_posts  = current_user_can( $post_type->cap->edit_others_posts );
		$edit_private_posts = current_user_can( $post_type->cap->edit_private_posts );

		$statuses_count = [ 'all' => 0 ];
		$total_posts    = 0;

		$query_args = $this->prepare_items_query( $this->args, $request );

		// Strip down query for speed.
		$query_args['fields']                 = 'ids';
		$query_args['posts_per_page']         = 1;
		$query_args['paged']                  = 1;
		$query_args['update_post_meta_cache'] = false;
		$query_args['update_post_term_cache'] = false;

		foreach ( $statuses as $key => $status ) {
			$posts_query               = new WP_Query();
			$query_args['post_status'] = $status;
			if ( in_array( $status, [ 'draft', 'future', 'pending' ], true ) && ! $edit_others_posts ) {
				$query_args['author'] = get_current_user_id();
			}
			if ( 'private' === $status && ! $edit_private_posts ) {
				$query_args['author'] = get_current_user_id();
			}
			$posts_query->query( $query_args );
			$statuses_count[ $key ] = absint( $posts_query->found_posts );
			$statuses_count['all'] += $statuses_count[ $key ];
			if ( in_array( $status, $this->args['post_status'], true ) ) {
				$total_posts += $statuses_count[ $key ];
			}
		}

		// Encode the array as headers do not support passing an array.
		$encoded_statuses = wp_json_encode( $statuses_count );
		if ( $encoded_statuses ) {
			$response->header( 'X-WP-TotalByStatus', $encoded_statuses );
		}

		$page      = (int) $posts_query->query_vars['paged'];
		$max_pages = ceil( $total_posts / (int) $this->args['posts_per_page'] );

		if ( $page > $max_pages && $total_posts > 0 ) {
			return new WP_Error(
				'rest_post_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'web-stories' ),
				[ 'status' => 400 ]
			);
		}

		$response->header( 'X-WP-Total', (string) $total_posts );
		$response->header( 'X-WP-TotalPages', (string) $max_pages );

		return $response;
	}

	/**
	 * Prepares links for the request.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return array Links for the given post.
	 */
	protected function prepare_links( $post ): array {
		// Workaround so that WP_REST_Posts_Controller::prepare_links() does not call wp_get_post_revisions(),
		// avoiding a currently unneeded database query.
		// TODO(#85): Remove if proper revisions support is ever needed.
		remove_post_type_support( $this->post_type, 'revisions' );
		$links = parent::prepare_links( $post );
		add_post_type_support( $this->post_type, 'revisions' );

		$links = $this->add_post_locking_link( $links, $post );
		$links = $this->add_publisher_logo_link( $links, $post );

		return $links;
	}

	/**
	 * Retrieves the query params for the posts collection.
	 *
	 * @since 1.0.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params(): array {
		$query_params = parent::get_collection_params();

		$query_params['_web_stories_envelope'] = [
			'description' => __( 'Envelope request for preloading.', 'web-stories' ),
			'type'        => 'boolean',
			'default'     => false,
		];

		$query_params['web_stories_demo'] = [
			'description' => __( 'Load demo data.', 'web-stories' ),
			'type'        => 'boolean',
			'default'     => false,
		];

		if ( ! empty( $query_params['orderby'] ) ) {
			$query_params['orderby']['enum'][] = 'story_author';
		}

		return $query_params;
	}

	/**
	 * Adds a REST API link if the story is locked.
	 *
	 * @since 1.12.0
	 *
	 * @param array   $links Links for the given post.
	 * @param WP_Post $post Post object.
	 *
	 * @return array Modified list of links.
	 */
	private function add_post_locking_link( array $links, WP_Post $post ): array {
		$base     = sprintf( '%s/%s', $this->namespace, $this->rest_base );
		$lock_url = rest_url( trailingslashit( $base ) . $post->ID . '/lock' );

		$links['https://api.w.org/lock'] = [
			'href'       => $lock_url,
			'embeddable' => true,
		];

		$lock = get_post_meta( $post->ID, '_edit_lock', true );

		if ( $lock ) {
			list ( $time, $user ) = explode( ':', $lock );

			/** This filter is documented in wp-admin/includes/ajax-actions.php */
			$time_window = apply_filters( 'wp_check_post_lock_window', 150 );

			if ( $time && $time > time() - $time_window ) {
				$links['https://api.w.org/lockuser'] = [
					'href'       => rest_url( sprintf( '%s/%s', $this->namespace, 'users/' ) . $user ),
					'embeddable' => true,
				];
			}
		}

		return $links;
	}

	/**
	 * Adds a REST API link for the story's publisher logo.
	 *
	 * @since 1.12.0
	 *
	 * @param array   $links Links for the given post.
	 * @param WP_Post $post Post object.
	 *
	 * @return array Modified list of links.
	 */
	private function add_publisher_logo_link( array $links, WP_Post $post ): array {
		$publisher_logo_id = get_post_meta( $post->ID, Story_Post_Type::PUBLISHER_LOGO_META_KEY, true );

		if ( $publisher_logo_id ) {
			$links['https://api.w.org/publisherlogo'] = [
				'href'       => rest_url( sprintf( '%s/%s/%s', $this->namespace, 'media', $publisher_logo_id ) ),
				'embeddable' => true,
			];
		}

		return $links;
	}
}
