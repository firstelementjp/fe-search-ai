<?php
/**
 * GitHub Releases update checker for FE Search AI
 *
 * @package FE_Search_AI
 * @subpackage Update
 */

namespace FESearchAI\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin update checks via GitHub Releases
 *
 * @since 0.9.2
 */
class FE_Search_AI_GitHub_Update_Checker {

	/**
	 * GitHub repository owner.
	 *
	 * @since 0.9.2
	 * @var string
	 */
	private $repository_owner = 'firstelementjp';

	/**
	 * GitHub repository name.
	 *
	 * @since 0.9.2
	 * @var string
	 */
	private $repository_name = 'fe-search-ai';

	/**
	 * Plugin basename.
	 *
	 * @since 0.9.2
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Current plugin version.
	 *
	 * @since 0.9.2
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Constructor.
	 *
	 * @since 0.9.2
	 */
	public function __construct() {
		$this->plugin_slug    = plugin_basename( FE_SEARCH_AI_PLUGIN_FILE );
		$this->plugin_version = FE_SEARCH_AI_VERSION;
	}

	/**
	 * Registers WordPress update hooks.
	 *
	 * @since 0.9.2
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_updates' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
	}

	/**
	 * Checks GitHub Releases for plugin updates.
	 *
	 * @since 0.9.2
	 * @param object $transient Existing update transient.
	 * @return object Modified update transient.
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( false === $release ) {
			return $transient;
		}

		$update = $this->release_to_update_object( $release );
		if ( ! isset( $update->new_version ) || '' === $update->new_version ) {
			return $transient;
		}

		if ( version_compare( $this->plugin_version, $update->new_version, '<' ) ) {
			$transient->response[ $this->plugin_slug ] = $update;
			return $transient;
		}

		$update->package                            = '';
		$transient->no_update[ $this->plugin_slug ] = $update;

		return $transient;
	}

	/**
	 * Returns plugin information for the WordPress update details modal.
	 *
	 * @since 0.9.2
	 * @param false|object|array $result Existing API result.
	 * @param string             $action Plugin API action.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array Plugin information or original result.
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( $this->plugin_slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( false === $release ) {
			return $result;
		}

		$version = $this->normalize_version( $release['tag_name'] ?? '' );
		$body    = isset( $release['body'] ) && is_string( $release['body'] ) ? $release['body'] : '';

		$information                = new \stdClass();
		$information->name          = 'FE Search AI';
		$information->slug          = dirname( $this->plugin_slug );
		$information->version       = $version;
		$information->author        = '<a href="https://www.firstelement.co.jp/">FirstElement K.K., Daijiro Miyazawa</a>';
		$information->homepage      = 'https://github.com/' . $this->repository_owner . '/' . $this->repository_name;
		$information->requires      = $release['requires'] ?? '6.0';
		$information->tested        = $release['tested'] ?? '';
		$information->requires_php  = $release['requires_php'] ?? '7.4';
		$information->download_link = $this->package_url( $release );
		$information->sections      = [
			'description' => __( 'AI-powered search for WordPress.', 'fe-search-ai' ),
			'changelog'   => wp_kses_post( nl2br( $body ) ),
		];

		return $information;
	}

	/**
	 * Retrieves the latest GitHub release.
	 *
	 * @since 0.9.2
	 * @return array|false Release data, or false on failure.
	 */
	private function latest_release() {
		$cache_key = 'fe_search_ai_github_latest_release';
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->api_url(),
			[
				'timeout' => 15,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'FE-Search-AI/' . $this->plugin_version,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return false;
		}

		set_site_transient( $cache_key, $release, HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Converts a GitHub release into a WordPress update object.
	 *
	 * @since 0.9.2
	 * @param array $release GitHub release data.
	 * @return stdClass Update object.
	 */
	private function release_to_update_object( array $release ): \stdClass {
		$update                = new \stdClass();
		$update->id            = $this->plugin_slug;
		$update->slug          = dirname( $this->plugin_slug );
		$update->plugin        = $this->plugin_slug;
		$update->new_version   = $this->normalize_version( $release['tag_name'] ?? '' );
		$update->url           = isset( $release['html_url'] ) ? (string) $release['html_url'] : 'https://github.com/' . $this->repository_owner . '/' . $this->repository_name;
		$update->package       = $this->package_url( $release );
		$update->icons         = [];
		$update->banners       = [];
		$update->banners_rtl   = [];
		$update->tested        = $release['tested'] ?? '';
		$update->requires_php  = $release['requires_php'] ?? '7.4';
		$update->compatibility = new \stdClass();

		return $update;
	}

	/**
	 * Returns the preferred update package URL.
	 *
	 * @since 0.9.2
	 * @param array $release GitHub release data.
	 * @return string Package URL.
	 */
	private function package_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
					continue;
				}

				$asset_name = (string) $asset['name'];
				if ( preg_match( '/^fe-search-ai(?:-[0-9.]+)?\.zip$/', $asset_name ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	/**
	 * Normalizes GitHub tag names into plugin version strings.
	 *
	 * @since 0.9.2
	 * @param string $version Version or tag name.
	 * @return string Normalized version.
	 */
	private function normalize_version( string $version ): string {
		return ltrim( trim( $version ), 'vV' );
	}

	/**
	 * Returns the GitHub latest release API URL.
	 *
	 * @since 0.9.2
	 * @return string API URL.
	 */
	private function api_url(): string {
		return sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->repository_owner ),
			rawurlencode( $this->repository_name )
		);
	}
}
