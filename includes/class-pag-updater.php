<?php
/**
 * GitHub-powered auto-updater for Product Archive Grid.
 *
 * Lets WordPress fetch new versions of this plugin straight from GitHub
 * Releases. The plugin author publishes a release with a versioned tag
 * (e.g. v1.1.0) and uploads `product-archive-grid.zip` as a release asset.
 * The site then sees an "Update available" notice in the standard
 * Plugins -> Updates UI.
 *
 * No third-party libraries. Public GitHub repo only (no token needed).
 *
 * Mirror of zymarg-algolia-search's updater, adapted for PAG. Only the
 * plugin-specific identifiers (slug, constants, cache key, action names,
 * display name) differ; the hook signatures and update-injection logic are
 * deliberately identical so behaviour stays in sync between the two plugins.
 *
 * @package ProductArchiveGrid
 */

namespace PAG;

defined( 'ABSPATH' ) || exit;

/**
 * Class \PAG\Updater
 */
class Updater {

	/**
	 * Plugin file (basename relative to plugins dir).
	 *
	 * @var string e.g. product-archive-grid/product-archive-grid.php
	 */
	protected $plugin_file;

	/**
	 * Plugin slug (folder name).
	 *
	 * @var string
	 */
	protected $plugin_slug;

	/**
	 * Currently installed plugin version.
	 *
	 * @var string
	 */
	protected $current_version;

	/**
	 * GitHub repo owner.
	 *
	 * @var string
	 */
	protected $owner;

	/**
	 * GitHub repo name.
	 *
	 * @var string
	 */
	protected $repo;

	/**
	 * GitHub branch fallback (used if no releases exist yet).
	 *
	 * @var string
	 */
	protected $branch;

	/**
	 * Cache key + TTL.
	 */
	const CACHE_KEY = 'pag_gh_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Plugin main file path.
	 * @param array  $args        owner, repo, branch.
	 */
	public function __construct( $plugin_file, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'owner'  => 'Aspeyash',
				'repo'   => 'Product-archive-grid',
				'branch' => 'main',
			)
		);

		$this->plugin_file     = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_file );
		$this->current_version = defined( 'PAG_VERSION' ) ? PAG_VERSION : '0.0.0';
		$this->owner           = $args['owner'];
		$this->repo            = $args['repo'];
		$this->branch          = $args['branch'];

		// Only run in admin / cron contexts.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------- */
	/* GitHub remote calls.                                                    */
	/* ---------------------------------------------------------------------- */

	/**
	 * Fetch the latest release from GitHub (cached).
	 *
	 * @param bool $force Bypass cache.
	 * @return array|null Decoded release object or null on failure.
	 */
	protected function get_latest_release( $force = false ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached && ! $force ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'PAG-Updater/' . $this->current_version,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			// 404 = no releases yet; cache the negative result briefly so we don't hammer the API.
			set_transient( self::CACHE_KEY, array( '_no_release' => true ), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Normalize tag name to plain version string (strip leading "v").
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	protected function normalize_version( $tag ) {
		$tag = (string) $tag;
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Find the best download URL from a release object.
	 * Prefers an attached asset named `<slug>.zip`; falls back to source zipball.
	 *
	 * @param array $release Release.
	 * @return string
	 */
	protected function pick_zip_url( $release ) {
		$preferred = $this->plugin_slug . '.zip';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && $asset['name'] === $preferred ) {
					return $asset['browser_download_url'];
				}
			}
		}
		// Fallback: GitHub-generated source zip.
		return ! empty( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	/* ---------------------------------------------------------------------- */
	/* WordPress integration.                                                  */
	/* ---------------------------------------------------------------------- */

	/**
	 * Inject our plugin's "update available" entry into the WP transient.
	 *
	 * @param object $transient WP transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || ! empty( $release['_no_release'] ) ) {
			return $transient;
		}

		$tag = isset( $release['tag_name'] ) ? $release['tag_name'] : '';
		if ( ! $tag ) {
			return $transient;
		}

		$new_version = $this->normalize_version( $tag );
		if ( version_compare( $new_version, $this->current_version, '<=' ) ) {
			// Already up to date.
			return $transient;
		}

		$package = $this->pick_zip_url( $release );
		if ( ! $package ) {
			return $transient;
		}

		$item = (object) array(
			'id'            => $this->plugin_file,
			'slug'          => $this->plugin_slug,
			'plugin'        => $this->plugin_file,
			'new_version'   => $new_version,
			'url'           => sprintf( 'https://github.com/%s/%s', $this->owner, $this->repo ),
			'package'       => $package,
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => isset( $release['target_commitish'] ) ? '6.6' : '',
			'requires_php'  => '7.4',
			'compatibility' => new \stdClass(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->plugin_file ] = $item;

		return $transient;
	}

	/**
	 * Provide details for the "View version X.Y.Z details" lightbox.
	 *
	 * @param mixed  $result False or response.
	 * @param string $action API action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || ! empty( $release['_no_release'] ) ) {
			return $result;
		}

		$new_version = $this->normalize_version( isset( $release['tag_name'] ) ? $release['tag_name'] : '' );
		$body        = isset( $release['body'] ) ? (string) $release['body'] : '';
		$published   = isset( $release['published_at'] ) ? strtotime( $release['published_at'] ) : 0;

		$info                = new \stdClass();
		$info->name          = 'Product Archive Grid';
		$info->slug          = $this->plugin_slug;
		$info->version       = $new_version;
		$info->author        = '<a href="https://zymarg.com">ZYMARG</a>';
		$info->homepage      = sprintf( 'https://github.com/%s/%s', $this->owner, $this->repo );
		$info->requires      = '6.0';
		$info->tested        = '6.6';
		$info->requires_php  = '7.4';
		$info->download_link = $this->pick_zip_url( $release );
		$info->trunk         = $info->download_link;
		$info->last_updated  = $published ? gmdate( 'Y-m-d H:i:s', $published ) : '';
		$info->sections      = array(
			'description' => 'Premium Elementor widget for WooCommerce product archives. Renders a fully-customisable responsive grid with discount/stock badges, quick view, custom wishlist, AJAX add-to-cart, Buy Now, and Astra/Dokan compatibility. Optional Algolia data source for sub-100ms queries on large catalogs.',
			'changelog'   => $this->markdown_to_html( $body ),
		);
		$info->banners = array();

		return $info;
	}

	/**
	 * Very small Markdown -> HTML helper for release notes display.
	 *
	 * @param string $md Markdown text.
	 * @return string
	 */
	protected function markdown_to_html( $md ) {
		$html = wp_kses_post( $md );
		$html = preg_replace( '/^### (.*)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^## (.*)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^# (.*)$/m', '<h2>$1</h2>', $html );
		$html = preg_replace( '/^\* (.*)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		$html = nl2br( $html );
		return $html;
	}

	/**
	 * After WP unzips the GitHub package, the folder will be named
	 * something like "Aspeyash-Product-archive-grid-abc1234" (source zipball)
	 * or "product-archive-grid" (asset zip).
	 *
	 * If the folder is already named correctly (asset zip case), DO NOTHING.
	 * Only rename when the source basename differs from our slug. This avoids
	 * a move-onto-self call that can leave the upgrade folder in a broken
	 * state on certain hosts (Hostinger Business / cgroup-isolated /tmp).
	 *
	 * @param string       $source        Path to extracted folder.
	 * @param string       $remote_source Remote source.
	 * @param \WP_Upgrader $upgrader      Upgrader.
	 * @param array        $hook_extra    Extras.
	 * @return string|\WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		// Only intervene for our plugin's update.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $source;
		}

		// If the extracted folder is already named correctly, leave $source untouched.
		// This is the common case when our pre-built `product-archive-grid.zip`
		// asset is attached to the GitHub Release.
		$current_basename = basename( untrailingslashit( $source ) );
		if ( $current_basename === $this->plugin_slug ) {
			return $source;
		}

		// Source is misnamed (e.g. GitHub-generated zipball "owner-repo-sha").
		// Rename it to our slug.
		$desired = trailingslashit( $remote_source ) . $this->plugin_slug;

		// Make sure the destination doesn't already exist before renaming.
		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Add a "View on GitHub" + "Check for updates" link on the Plugins screen row.
	 *
	 * @param array  $links Existing links.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( $file !== $this->plugin_file ) {
			return $links;
		}
		$gh_url  = sprintf( 'https://github.com/%s/%s', $this->owner, $this->repo );
		$links[] = '<a href="' . esc_url( $gh_url ) . '" target="_blank" rel="noopener">GitHub</a>';
		$check   = wp_nonce_url(
			admin_url( 'admin-post.php?action=pag_check_updates' ),
			'pag_check_updates'
		);
		$links[] = '<a href="' . esc_url( $check ) . '">Check for updates</a>';
		return $links;
	}

	/**
	 * Force a fresh release lookup. Hooked from the Plugins-screen action link.
	 */
	public static function handle_check_updates() {
		check_admin_referer( 'pag_check_updates' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Forbidden' );
		}
		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}

add_action( 'admin_post_pag_check_updates', array( '\\PAG\\Updater', 'handle_check_updates' ) );
