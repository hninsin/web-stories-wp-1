<?php
/**
 * Class Editor
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

namespace Google\Web_Stories\Admin;

use Google\Web_Stories\Context;
use Google\Web_Stories\Decoder;
use Google\Web_Stories\Experiments;
use Google\Web_Stories\Infrastructure\HasRequirements;
use Google\Web_Stories\Locale;
use Google\Web_Stories\Assets;
use Google\Web_Stories\Model\Story;
use Google\Web_Stories\Service_Base;
use Google\Web_Stories\Story_Post_Type;
use Google\Web_Stories\Page_Template_Post_Type;
use Google\Web_Stories\Tracking;
use Google\Web_Stories\Media\Types;
use WP_Post;

/**
 * Class Editor
 *
 * @package Google\Web_Stories\Admin
 */
class Editor extends Service_Base implements HasRequirements {

	/**
	 * Web Stories editor script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wp-story-editor';

	/**
	 * AMP validator script handle.
	 *
	 * @var string
	 */
	const AMP_VALIDATOR_SCRIPT_HANDLE = 'amp-validator';

	/**
	 * Experiments instance.
	 *
	 * @var Experiments Experiments instance.
	 */
	private $experiments;

	/**
	 * Decoder instance.
	 *
	 * @var Decoder Decoder instance.
	 */
	private $decoder;

	/**
	 * Meta boxes instance.
	 *
	 * @var Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Locale instance.
	 *
	 * @var Locale Locale instance.
	 */
	private $locale;

	/**
	 * Google_Fonts instance.
	 *
	 * @var Google_Fonts Google_Fonts instance.
	 */
	private $google_fonts;

	/**
	 * Assets instance.
	 *
	 * @var Assets Assets instance.
	 */
	private $assets;

	/**
	 * Story_Post_Type instance.
	 *
	 * @var Story_Post_Type Story_Post_Type instance.
	 */
	private $story_post_type;

	/**
	 * Page_Template_Post_Type instance.
	 *
	 * @var Page_Template_Post_Type Page_Template_Post_Type instance.
	 */
	private $page_template_post_type;

	/**
	 * Context instance.
	 *
	 * @var Context Context instance.
	 */
	private $context;

	/**
	 * Types instance.
	 *
	 * @var Types Types instance.
	 */
	private $types;

	/**
	 * Dashboard constructor.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @since 1.0.0
	 *
	 * @param Experiments             $experiments     Experiments instance.
	 * @param Meta_Boxes              $meta_boxes      Meta_Boxes instance.
	 * @param Decoder                 $decoder         Decoder instance.
	 * @param Locale                  $locale          Locale instance.
	 * @param Google_Fonts            $google_fonts    Google_Fonts instance.
	 * @param Assets                  $assets          Assets instance.
	 * @param Story_Post_Type         $story_post_type Story_Post_Type instance.
	 * @param Page_Template_Post_Type $page_template_post_type Page_Template_Post_Type instance.
	 * @param Context                 $context         Context instance.
	 * @param Types                   $types           Types instance.
	 */
	public function __construct(
		Experiments $experiments,
		Meta_Boxes $meta_boxes,
		Decoder $decoder,
		Locale $locale,
		Google_Fonts $google_fonts,
		Assets $assets,
		Story_Post_Type $story_post_type,
		Page_Template_Post_Type $page_template_post_type,
		Context $context,
		Types $types
	) {
		$this->experiments             = $experiments;
		$this->meta_boxes              = $meta_boxes;
		$this->decoder                 = $decoder;
		$this->locale                  = $locale;
		$this->google_fonts            = $google_fonts;
		$this->assets                  = $assets;
		$this->story_post_type         = $story_post_type;
		$this->page_template_post_type = $page_template_post_type;
		$this->context                 = $context;
		$this->types                   = $types;
	}

	/**
	 * Initializes the Editor logic.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_filter( 'replace_editor', [ $this, 'replace_editor' ], 10, 2 );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'filter_use_block_editor_for_post_type' ], 10, 2 );
	}

	/**
	 * Get the list of service IDs required for this service to be registered.
	 *
	 * Needed because the story and page template post types need to be registered first.
	 *
	 * @since 1.14.0
	 *
	 * @return string[] List of required services.
	 */
	public static function get_requirements(): array {
		return [ 'page_template_post_type', 'story_post_type' ];
	}

	/**
	 * Replace default post editor with our own implementation.
	 *
	 * @codeCoverageIgnore
	 *
	 * @since 1.0.0
	 *
	 * @param bool|mixed $replace Bool if to replace editor or not.
	 * @param WP_Post    $post    Current post object.
	 *
	 * @return bool|mixed Whether the editor has been replaced.
	 */
	public function replace_editor( $replace, $post ) {
		if ( $this->story_post_type->get_slug() === get_post_type( $post ) ) {

			// Since the 'replace_editor' filter can be run multiple times, only load the
			// custom editor after the 'current_screen' action and when we can be certain the
			// $post_type, $post_type_object, $post globals are all set by WordPress.
			if ( isset( $GLOBALS['post'] ) && $post === $GLOBALS['post'] && did_action( 'current_screen' ) ) {
				require_once WEBSTORIES_PLUGIN_DIR_PATH . 'includes/templates/admin/edit-story.php';
			}

			return true;
		}

		return $replace;
	}

	/**
	 * Filters whether post type supports the block editor.
	 *
	 * Disables the block editor and associated logic (like enqueueing assets)
	 * for the story post type.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|mixed $use_block_editor  Whether the post type can be edited or not. Default true.
	 * @param string     $post_type         The post type being checked.
	 *
	 * @return false|mixed Whether to use the block editor.
	 */
	public function filter_use_block_editor_for_post_type( $use_block_editor, $post_type ) {
		if ( $this->story_post_type->get_slug() === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 *
	 * Enqueue scripts for the element editor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( ! $this->context->is_story_editor() ) {
			return;
		}

		// Only output scripts and styles where in edit screens.
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Force media model to load.
		wp_enqueue_media();

		wp_enqueue_script(
			self::AMP_VALIDATOR_SCRIPT_HANDLE,
			'https://cdn.ampproject.org/v0/validator_wasm.js',
			[],
			WEBSTORIES_VERSION,
			true
		);

		$script_dependencies = [ Tracking::SCRIPT_HANDLE, 'postbox', self::AMP_VALIDATOR_SCRIPT_HANDLE ];

		$this->assets->enqueue_script_asset( self::SCRIPT_HANDLE, $script_dependencies, false );
		$this->assets->enqueue_style_asset( self::SCRIPT_HANDLE, [ $this->google_fonts::SCRIPT_HANDLE ] );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'webStoriesEditorSettings',
			$this->get_editor_settings()
		);

		// Dequeue forms.css, see https://github.com/google/web-stories-wp/issues/349 .
		$this->assets->remove_admin_style( [ 'forms' ] );
	}

	/**
	 * Get editor settings as an array.
	 *
	 * @since 1.0.0
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @return array
	 */
	public function get_editor_settings(): array {
		$post                 = get_post();
		$story_id             = $post->ID ?? null;
		$general_settings_url = admin_url( 'options-general.php' );

		if ( $story_id ) {
			$this->setup_lock( $story_id );
		}

		// Media settings.
		$max_upload_size = wp_max_upload_size();
		if ( ! $max_upload_size ) {
			$max_upload_size = 0;
		}

		$is_demo       = ( isset( $_GET['web-stories-demo'] ) && (bool) $_GET['web-stories-demo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dashboard_url = add_query_arg(
			[
				'post_type' => $this->story_post_type->get_slug(),
				'page'      => 'stories-dashboard',
			],
			admin_url( 'edit.php' )
		);

		$dashboard_settings_url = add_query_arg(
			[
				'post_type' => $this->story_post_type->get_slug(),
				'page'      => 'stories-dashboard#/editor-settings',
			],
			admin_url( 'edit.php' )
		);

		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$time_window = apply_filters( 'wp_check_post_lock_window', 150 );
		$user        = wp_get_current_user();

		/** This filter is documented in wp-admin/includes/post.php */
		$show_locked_dialog = apply_filters( 'show_post_locked_dialog', true, $post, $user );
		$nonce              = wp_create_nonce( 'wp_rest' );
		$mime_types         = $this->types->get_allowed_mime_types();
		$image_mime_types   = $this->types->get_allowed_image_mime_types();
		$audio_mime_types   = $this->types->get_allowed_audio_mime_types();

		$story = new Story();
		$story->load_from_post( $post );

		$settings = [
			'id'         => 'web-stories-editor',
			'config'     => [
				'autoSaveInterval'             => defined( 'AUTOSAVE_INTERVAL' ) ? AUTOSAVE_INTERVAL : null,
				'isRTL'                        => is_rtl(),
				'locale'                       => $this->locale->get_locale_settings(),
				'allowedFileTypes'             => $this->types->get_allowed_file_types(),
				'allowedTranscodableMimeTypes' => $this->types->get_allowed_transcodable_mime_types(),
				'allowedImageFileTypes'        => $this->types->get_file_type_exts( $image_mime_types ),
				'allowedImageMimeTypes'        => $image_mime_types,
				'allowedAudioFileTypes'        => $this->types->get_file_type_exts( $audio_mime_types ),
				'allowedAudioMimeTypes'        => $audio_mime_types,
				'allowedMimeTypes'             => $mime_types,
				'postType'                     => $this->story_post_type->get_slug(),
				'storyId'                      => $story_id,
				'dashboardLink'                => $dashboard_url,
				'dashboardSettingsLink'        => $dashboard_settings_url,
				'generalSettingsLink'          => $general_settings_url,
				'cdnURL'                       => trailingslashit( WEBSTORIES_CDN_URL ),
				'maxUpload'                    => $max_upload_size,
				'isDemo'                       => $is_demo,
				'capabilities'                 => [
					'hasUploadMediaAction' => current_user_can( 'upload_files' ),
					'canManageSettings'    => current_user_can( 'manage_options' ),
				],
				'api'                          => [
					'users'          => '/web-stories/v1/users/',
					'currentUser'    => '/web-stories/v1/users/me/',
					'stories'        => trailingslashit( $this->story_post_type->get_rest_url() ),
					'pageTemplates'  => trailingslashit( $this->page_template_post_type->get_rest_url() ),
					'media'          => '/web-stories/v1/media/',
					'hotlink'        => '/web-stories/v1/hotlink/validate/',
					'publisherLogos' => '/web-stories/v1/publisher-logos/',
					'proxy'          => rest_url( '/web-stories/v1/hotlink/proxy/' ),
					'link'           => '/web-stories/v1/link/',
					'statusCheck'    => '/web-stories/v1/status-check/',
					'taxonomies'     => '/web-stories/v1/taxonomies/',
					'metaBoxes'      => $this->meta_boxes->get_meta_box_url( (int) $story_id ),
					'storyLocking'   => rest_url( sprintf( '%s/%s/lock/', $this->story_post_type->get_rest_url(), $story_id ) ),
				],
				'metadata'                     => [
					'publisher' => $story->get_publisher_name(),
				],
				'postLock'                     => [
					'interval'         => $time_window,
					'showLockedDialog' => $show_locked_dialog,
				],
				'version'                      => WEBSTORIES_VERSION,
				'nonce'                        => $nonce,
				'showMedia3p'                  => true,
				'encodeMarkup'                 => $this->decoder->supports_decoding(),
				'metaBoxes'                    => $this->meta_boxes->get_meta_boxes_per_location(),
				'ffmpegCoreUrl'                => trailingslashit( WEBSTORIES_CDN_URL ) . 'js/@ffmpeg/core@0.10.0/dist/ffmpeg-core.js',
				'localeData'                   => $this->assets->get_translations( self::SCRIPT_HANDLE ),
				'flags'                        => array_merge(
					$this->experiments->get_experiment_statuses( 'general' ),
					$this->experiments->get_experiment_statuses( 'editor' )
				),
			],
			'publicPath' => $this->assets->get_base_url( 'assets/js/' ),
		];

		/**
		 * Filters settings passed to the web stories editor.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Array of settings passed to web stories editor.
		 */
		return apply_filters( 'web_stories_editor_settings', $settings );
	}

	/**
	 * Setup up post lock.
	 *
	 * @since 1.5.0
	 *
	 * @param int $story_id Post id of story.
	 *
	 * @return void
	 */
	protected function setup_lock( int $story_id ) {
		if ( ! $this->experiments->is_experiment_enabled( 'enablePostLocking' ) ) {
			return;
		}

		if ( ! $this->story_post_type->has_cap( 'edit_posts' ) ) {
			return;
		}

		// Make sure these functions are loaded.
		if ( ! function_exists( 'wp_check_post_lock' ) || ! function_exists( 'wp_set_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		// Check current lock.
		$lock_user_id = wp_check_post_lock( $story_id );
		if ( ! $lock_user_id ) {
			// If no lock set, create new lock.
			wp_set_post_lock( $story_id );
		}
	}
}
