<?php
/**
 * Page caching functionality
 *
 * @package  simple-cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper for advanced cache functionality
 */
class SC_Advanced_Cache {

	/**
	 * Setup hooks/filters
	 *
	 * @since 1.0
	 */
	public function setup() {

		add_action( 'admin_notices', array( $this, 'print_notice' ) );
		add_action( 'pre_post_update', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'save_post', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'wp_set_comment_status', array( $this, 'purge_post_on_comment_status_change' ), 10 );
		add_action( 'set_comment_cookies', array( $this, 'set_comment_cookie_exceptions' ), 10 );
	}

	/**
	 * When user posts a comment, set a cookie so we don't show them page cache
	 *
	 * @param  WP_Comment $comment Comment to check.
	 * @since  1.3
	 */
	public function set_comment_cookie_exceptions( $comment ) {
		$config = SC_Config::factory()->get();

		// File based caching only.
		if ( ! empty( $config['enable_page_caching'] ) && empty( $config['enable_in_memory_object_caching'] ) ) {
			$post_id = $comment->comment_post_ID;

			setcookie( 'sc_commented_posts[' . $post_id . ']', parse_url( get_permalink( $post_id ), PHP_URL_PATH ), ( time() + HOUR_IN_SECONDS * 24 * 30 ) );
		}
	}

	/**
	 * Every time a comments status changes, purge it's parent posts cache
	 *
	 * @param  int $comment_id Comment ID.
	 * @since  1.3
	 */
	public function purge_post_on_comment_status_change( $comment_id ) {
		$config = SC_Config::factory()->get();

		// File based caching only.
		if ( ! empty( $config['enable_page_caching'] ) && empty( $config['enable_in_memory_object_caching'] ) ) {
			$comment = get_comment( $comment_id );
			$post_id = $comment->comment_post_ID;

			$path = sc_get_cache_path() . '/' . preg_replace( '#https?://#i', '', get_permalink( $post_id ) );

			@unlink( untrailingslashit( $path ) . '/index.html' );
			@unlink( untrailingslashit( $path ) . '/index.gzip.html' );
		}
	}

	/**
	 * Purge post cache when there is a new approved comment
	 *
	 * @param  int   $comment_id Comment ID.
	 * @param  int   $approved Comment approved status.
	 * @param  array $commentdata Comment data array.
	 * @since  1.3
	 */
	public function purge_post_on_comment( $comment_id, $approved, $commentdata ) {
		if ( empty( $approved ) ) {
			return;
		}

		$config = SC_Config::factory()->get();

		// File based caching only.
		if ( ! empty( $config['enable_page_caching'] ) && empty( $config['enable_in_memory_object_caching'] ) ) {
			$post_id = $commentdata['comment_post_ID'];

			$path = sc_get_cache_path() . '/' . preg_replace( '#https?://#i', '', get_permalink( $post_id ) );

			@unlink( untrailingslashit( $path ) . '/index.html' );
			@unlink( untrailingslashit( $path ) . '/index.gzip.html' );
		}
	}

	/**
	 * Automatically purge all file based page cache on post changes
	 *
	 * @param  int $post_id Post id.
	 * @since  1.3
	 */
	public function purge_post_on_update( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === $post_type ) {
			return;
		} elseif ( ! current_user_can( 'edit_post', $post_id ) && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) ) {
			return;
		}

		$config = SC_Config::factory()->get();

		// File based caching only.
		if ( ! empty( $config['enable_page_caching'] ) && empty( $config['enable_in_memory_object_caching'] ) ) {
			sc_cache_flush();
		}
	}

	/**
	 * Print out a warning if WP_CACHE is off when it should be on or if advanced-cache.php is messed up
	 *
	 * @since 1.0
	 */
	public function print_notice() {

		$cant_write = get_option( 'sc_cant_write', false );

		if ( $cant_write ) {
			return;
		}

		$config = SC_Config::factory()->get();

		if ( empty( $config['enable_page_caching'] ) ) {
			// Not turned on do nothing.
			return;
		}

		$config_file_bad         = true;
		$advanced_cache_file_bad = true;

		if ( defined( 'SC_ADVANCED_CACHE' ) && SC_ADVANCED_CACHE ) {
			$advanced_cache_file_bad = false;
		}

		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			$config_file_bad = false;
		}

		if ( ! $config_file_bad && ! $advanced_cache_file_bad ) {
			return;
		}

		?>

		<div class="error">
			<p>
				<?php if ( $config_file_bad ) : ?>
					<?php esc_html_e( 'define("WP_CACHE", true); is not in wp-config.php.', 'simple-cache' ); ?>
				<?php endif; ?>

				<?php if ( $advanced_cache_file_bad ) : ?>
					<?php esc_html_e( 'wp-content/advanced-cache.php was edited or deleted.', 'simple-cache' ); ?>
				<?php endif; ?>

				<?php esc_html_e( 'Simple Cache is not able to utilize page caching.', 'simple-cache' ); ?>

				<a href="options-general.php?page=simple-cache&amp;wp_http_referer=<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>&amp;action=sc_update&amp;sc_settings_nonce=<?php echo esc_attr( wp_create_nonce( 'sc_update_settings' ) ); ?>" class="button button-primary" style="margin-left: 5px;"><?php esc_html_e( 'Fix', 'simple-cache' ); ?></a>
			</p>
		</div>

		<?php
	}

	/**
	 * Delete file for clean up
	 *
	 * @since  1.0
	 * @return bool
	 */
	public function clean_up() {

		$file = untrailingslashit( WP_CONTENT_DIR ) . '/advanced-cache.php';

		$ret = true;

		if ( ! @unlink( $file ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache/simple-cache';

		if ( ! @unlink( $folder, true ) ) {
			$ret = false;
		}

		return $ret;
	}

	/**
	 * Write advanced-cache.php
	 *
	 * @since  1.0
	 * @return bool
	 */
	public function write() {

		$file = untrailingslashit( WP_CONTENT_DIR ) . '/advanced-cache.php';

		$config = SC_Config::factory()->get();

		$file_string = '';

		if ( ! empty( $config['enable_page_caching'] ) ) {
			$cache_file = 'file-based-page-cache.php';

			if ( ! empty( $config['enable_in_memory_object_caching'] ) && ! empty( $config['advanced_mode'] ) ) {
				$cache_file = 'batcache.php';
			}

			// phpcs:disable
			$file_string = '<?php ' .
			"\n\r" . "defined( 'ABSPATH' ) || exit;" .
			"\n\r" . "define( 'SC_ADVANCED_CACHE', true );" .
			"\n\r" . 'if ( is_admin() ) { return; }' .
			"\n\r" . "include_once( '" . dirname( __FILE__ ) . "/pre-wp-functions.php' );" .
			"\n\r" . "\$GLOBALS['sc_config'] = sc_load_config();" .
			"\n\r" . "if ( empty( \$GLOBALS['sc_config'] ) || empty( \$GLOBALS['sc_config']['enable_page_caching'] ) ) { return; }" .
			"\n\r" . "if ( @file_exists( '" . untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/dropins/' . $cache_file . "' ) ) { include_once( '" . untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/dropins/' . $cache_file . "' ); }" . "\n\r";
			// phpcs:enable
		}

		if ( ! file_put_contents( $file, $file_string ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Toggle WP_CACHE on or off in wp-config.php
	 *
	 * @param  boolean $status Status of cache.
	 * @since  1.0
	 * @return boolean
	 */
	public function toggle_caching( $status ) {

		if ( defined( 'WP_CACHE' ) && WP_CACHE === $status ) {
			return;
		}

		$file        = '/wp-config.php';
		$config_path = false;

		for ( $i = 1; $i <= 3; $i++ ) {
			if ( $i > 1 ) {
				$file = '/..' . $file;
			}

			if ( file_exists( untrailingslashit( ABSPATH ) . $file ) ) {
				$config_path = untrailingslashit( ABSPATH ) . $file;
				break;
			}
		}

		// Couldn't find wp-config.php.
		if ( ! $config_path ) {
			return false;
		}

		$config_file_string = file_get_contents( $config_path );

		// Config file is empty. Maybe couldn't read it?
		if ( empty( $config_file_string ) ) {
			return false;
		}

		$config_file = preg_split( "#(\n|\r)#", $config_file_string );
		$line_key    = false;

		foreach ( $config_file as $key => $line ) {
			if ( ! preg_match( '/^\s*define\(\s*(\'|")([A-Z_]+)(\'|")(.*)/', $line, $match ) ) {
				continue;
			}

			if ( 'WP_CACHE' === $match[2] ) {
				$line_key = $key;
			}
		}

		if ( false !== $line_key ) {
			unset( $config_file[ $line_key ] );
		}

		$status_string = ( $status ) ? 'true' : 'false';

		array_shift( $config_file );
		array_unshift( $config_file, '<?php', "define( 'WP_CACHE', $status_string ); // Simple Cache" );

		foreach ( $config_file as $key => $line ) {
			if ( '' === $line ) {
				unset( $config_file[ $key ] );
			}
		}

		if ( ! file_put_contents( $config_path, implode( "\n\r", $config_file ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return an instance of the current class, create one if it doesn't exist
	 *
	 * @since  1.0
	 * @return object
	 */
	public static function factory() {

		static $instance;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
