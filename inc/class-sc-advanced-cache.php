<?php
defined( 'ABSPATH' ) || exit;

class SC_Advanced_Cache {

	/**
	 * Setup hooks/filters
	 *
	 * @since 1.0
	 */
	public function setup() {

		add_action( 'admin_notices', array( $this, 'print_notice' ) );
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
			// Not turned on do nothing
			return;
		}

		$config_file_bad = true;
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
					<?php esc_html_e( 'define("WP_CACHE", true); is not in wp-config.php.' ); ?>
				<?php endif; ?>

				<?php if ( $advanced_cache_file_bad ) : ?>
					<?php esc_html_e( 'wp-content/advanced-cache.php was edited or deleted.' ); ?>
				<?php endif; ?>

				<?php esc_html_e( 'Simple Cache is not able to utilize page caching.', 'simple-cache' ); ?>

				<a href="options-general.php?page=simple-cache&amp;wp_http_referer=<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>&amp;action=sc_update&amp;sc_settings_nonce=<?php echo wp_create_nonce( 'sc_update_settings' ); ?>" class="button button-primary" style="margin-left: 5px;"><?php esc_html_e( 'Fix', 'simple-cache' ); ?></a>
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

		global $wp_filesystem;

		$file = untrailingslashit( WP_CONTENT_DIR )  . '/advanced-cache.php';

		$ret = true;

		if ( ! $wp_filesystem->delete( $file ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR )  . '/cache/simple-cache';

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
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

		global $wp_filesystem;

		$file = untrailingslashit( WP_CONTENT_DIR )  . '/advanced-cache.php';

		$config = SC_Config::factory()->get();

		$file_string = '';

		if ( ! empty( $config['enable_page_caching'] ) ) {
			$cache_file = 'file-based-page-cache.php';

			if ( ! empty( $config['enable_in_memory_object_caching'] ) && ! empty( $config['advanced_mode'] ) ) {
				$cache_file = 'batcache.php';
			}

			$file_string = '<?php ' .
			"\n\r" . "defined( 'ABSPATH' ) || exit;" .
			"\n\r" . "define( 'SC_ADVANCED_CACHE', true );" .
			"\n\r" . 'if ( is_admin() ) { return; }' .
			"\n\r" . "if ( ! @file_exists( WP_CONTENT_DIR . '/sc-config/config-' . \$_SERVER['HTTP_HOST'] . '.php' ) ) { return; }" .
			"\n\r" . "\$GLOBALS['sc_config'] = include( WP_CONTENT_DIR . '/sc-config/config-' . \$_SERVER['HTTP_HOST'] . '.php' );" .
			"\n\r" . "if ( empty( \$GLOBALS['sc_config'] ) || empty( \$GLOBALS['sc_config']['enable_page_caching'] ) ) { return; }" .
			"\n\r" . "if ( @file_exists( '" . untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/dropins/' . $cache_file . "' ) ) { include_once( '" . untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/dropins/' . $cache_file . "' ); }" . "\n\r";

		}

		if ( ! $wp_filesystem->put_contents( $file, $file_string, FS_CHMOD_FILE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Toggle WP_CACHE on or off in wp-config.php
	 *
	 * @param  boolean $status
	 * @since  1.0
	 * @return boolean
	 */
	public function toggle_caching( $status ) {

		global $wp_filesystem;

		if ( defined( 'WP_CACHE' ) && WP_CACHE === $status ) {
			return;
		}

		// Lets look 4 levels deep for wp-config.php
		$levels = 4;

		$file = '/wp-config.php';
		$config_path = false;

		for ( $i = 1; $i <= 3; $i++ ) {
			if ( $i > 1 ) {
				$file = '/..' . $file;
			}

			if ( $wp_filesystem->exists( untrailingslashit( ABSPATH )  . $file ) ) {
				$config_path = untrailingslashit( ABSPATH )  . $file;
				break;
			}
		}

		// Couldn't find wp-config.php
		if ( ! $config_path ) {
			return false;
		}

		$config_file_string = $wp_filesystem->get_contents( $config_path );

		// Config file is empty. Maybe couldn't read it?
		if ( empty( $config_file_string ) ) {
			return false;
		}

		$config_file = preg_split( "#(\n|\r)#", $config_file_string );
		$line_key = false;

		foreach ( $config_file as $key => $line ) {
			if ( ! preg_match( '/^\s*define\(\s*(\'|")([A-Z_]+)(\'|")(.*)/', $line, $match ) ) {
				continue;
			}

			if ( $match[2] == 'WP_CACHE' ) {
				$line_key = $key;
			}
		}

		if ( $line_key !== false ) {
			unset( $config_file[ $line_key ] );
		}

		$status_string = ( $status ) ? 'true' : 'false';

		array_shift( $config_file );
		array_unshift( $config_file, '<?php', "define( 'WP_CACHE', $status_string ); // Simple Cache" );

		if ( ! $wp_filesystem->put_contents( $config_path, implode( "\n\r", $config_file ), FS_CHMOD_FILE ) ) {
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
