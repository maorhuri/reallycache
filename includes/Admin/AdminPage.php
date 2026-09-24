<?php
namespace ReallyCache\Admin;

use ReallyCache\Config;
use ReallyCache\Engine;
use ReallyCache\ServerDetector;

/**
 * Minimal native admin screen: status, clear cache, core settings, dev mode.
 * Deliberately plain — it is the functional stand-in until the real UI is
 * built on top of a REST API in the next phase.
 */
class AdminPage {

	private $engine;

	public function __construct( Engine $engine ) {
		$this->engine = $engine;

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );
		add_action( 'admin_post_reallycache_clear', [ $this, 'handle_clear' ] );
		add_action( 'admin_post_reallycache_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_reallycache_dev_mode', [ $this, 'handle_dev_mode' ] );
	}

	public function register_menu() {
		add_menu_page(
			'ReallyCache',
			'ReallyCache',
			'manage_options',
			'reallycache',
			[ $this, 'render' ],
			'dashicons-performance',
			81
		);
	}

	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'reallycache-clear',
			'title' => __( 'Clear Cache', 'reallycache' ),
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_clear' ), 'reallycache_clear' ),
		] );
	}

	public function handle_clear() {
		$this->authorize( 'reallycache_clear' );

		$this->engine->purge_all();

		$this->redirect_back( 'cleared' );
	}

	public function handle_save() {
		$this->authorize( 'reallycache_save' );

		Config::save( [
			'cache_enabled'        => ! empty( $_POST['cache_enabled'] ),
			'ttl'                  => max( 600, (int) ( $_POST['ttl'] ?? 43200 ) ),
			'separate_mobile'      => ! empty( $_POST['separate_mobile'] ),
			'purge_home_on_update' => ! empty( $_POST['purge_home_on_update'] ),
			'excluded_uris'        => $this->lines( $_POST['excluded_uris'] ?? '' ),
			'excluded_user_agents' => $this->lines( $_POST['excluded_user_agents'] ?? '' ),
		] );

		// Settings shape what is cacheable, so stale entries must go.
		$this->engine->purge_all();

		$this->redirect_back( 'saved' );
	}

	public function handle_dev_mode() {
		$this->authorize( 'reallycache_dev_mode' );

		$duration = sanitize_key( $_POST['duration'] ?? 'off' );
		$until    = 0;
		if ( '1h' === $duration ) {
			$until = time() + HOUR_IN_SECONDS;
		} elseif ( '24h' === $duration ) {
			$until = time() + DAY_IN_SECONDS;
		}

		Config::save( [ 'dev_mode_until' => $until ] );

		$this->redirect_back( $until ? 'dev_on' : 'dev_off' );
	}

	public function render() {
		$config   = Config::load();
		$stats    = $this->engine->driver()->stats();
		$dev_on   = $config['dev_mode_until'] > time();
		$notice   = sanitize_key( $_GET['rc_notice'] ?? '' );
		$messages = [
			'cleared' => __( 'Cache cleared.', 'reallycache' ),
			'saved'   => __( 'Settings saved and cache cleared.', 'reallycache' ),
			'dev_on'  => __( 'Development mode enabled — caching is paused.', 'reallycache' ),
			'dev_off' => __( 'Development mode disabled.', 'reallycache' ),
		];
		?>
		<div class="wrap">
			<h1>ReallyCache</h1>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $dev_on ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: time remaining */
						esc_html__( 'Development mode is on — nothing is being cached. Expires in %s.', 'reallycache' ),
						esc_html( human_time_diff( time(), $config['dev_mode_until'] ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'reallycache' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Page cache', 'reallycache' ); ?></td>
						<td><?php echo $config['cache_enabled'] && ! $dev_on ? '<strong style="color:#00a32a">' . esc_html__( 'Active', 'reallycache' ) . '</strong>' : '<strong style="color:#d63638">' . esc_html__( 'Inactive', 'reallycache' ) . '</strong>'; ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Web server', 'reallycache' ); ?></td>
						<td><?php echo esc_html( ServerDetector::label() ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Cache engine', 'reallycache' ); ?></td>
						<td><?php echo esc_html( ServerDetector::best_available_tier() ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Drop-in installed', 'reallycache' ); ?></td>
						<td><?php echo file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? esc_html__( 'Yes', 'reallycache' ) : '<strong style="color:#d63638">' . esc_html__( 'No — deactivate and reactivate the plugin', 'reallycache' ) . '</strong>'; ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Cached pages', 'reallycache' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $stats['pages'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Cache size', 'reallycache' ); ?></td>
						<td><?php echo esc_html( size_format( $stats['size'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_clear' ), 'reallycache_clear' ) ); ?>">
					<?php esc_html_e( 'Clear Cache', 'reallycache' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Development Mode', 'reallycache' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pause caching while working on the site. It turns itself back on.', 'reallycache' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'reallycache_dev_mode' ); ?>
				<input type="hidden" name="action" value="reallycache_dev_mode">
				<?php if ( $dev_on ) : ?>
					<button class="button" name="duration" value="off"><?php esc_html_e( 'Disable now', 'reallycache' ); ?></button>
				<?php else : ?>
					<button class="button" name="duration" value="1h"><?php esc_html_e( 'Pause for 1 hour', 'reallycache' ); ?></button>
					<button class="button" name="duration" value="24h"><?php esc_html_e( 'Pause for 24 hours', 'reallycache' ); ?></button>
				<?php endif; ?>
			</form>

			<h2><?php esc_html_e( 'Settings', 'reallycache' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'reallycache_save' ); ?>
				<input type="hidden" name="action" value="reallycache_save">
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page cache', 'reallycache' ); ?></th>
						<td><label><input type="checkbox" name="cache_enabled" <?php checked( $config['cache_enabled'] ); ?>> <?php esc_html_e( 'Enable page caching', 'reallycache' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cache lifetime', 'reallycache' ); ?></th>
						<td>
							<select name="ttl">
								<?php
								foreach ( [
									3600   => __( '1 hour', 'reallycache' ),
									21600  => __( '6 hours', 'reallycache' ),
									43200  => __( '12 hours', 'reallycache' ),
									86400  => __( '24 hours', 'reallycache' ),
									604800 => __( '7 days', 'reallycache' ),
								] as $seconds => $label ) :
									?>
									<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( (int) $config['ttl'], $seconds ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile cache', 'reallycache' ); ?></th>
						<td>
							<label><input type="checkbox" name="separate_mobile" <?php checked( $config['separate_mobile'] ); ?>> <?php esc_html_e( 'Keep a separate cache for mobile visitors', 'reallycache' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only needed when the site serves different HTML to mobile (not just responsive CSS).', 'reallycache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge on update', 'reallycache' ); ?></th>
						<td><label><input type="checkbox" name="purge_home_on_update" <?php checked( $config['purge_home_on_update'] ); ?>> <?php esc_html_e( 'Also purge the home page when content changes', 'reallycache' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="rc-excluded-uris"><?php esc_html_e( 'Never cache these URLs', 'reallycache' ); ?></label></th>
						<td>
							<textarea id="rc-excluded-uris" name="excluded_uris" rows="4" class="large-text code" placeholder="/members/*&#10;/landing-page/"><?php echo esc_textarea( implode( "\n", $config['excluded_uris'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One path per line, * matches anything. Cart, checkout and account pages are excluded automatically.', 'reallycache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rc-excluded-uas"><?php esc_html_e( 'Never cache for these user agents', 'reallycache' ); ?></label></th>
						<td><textarea id="rc-excluded-uas" name="excluded_user_agents" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $config['excluded_user_agents'] ) ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'reallycache' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function authorize( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'reallycache' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function lines( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $raw ) ) );

		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	private function redirect_back( $notice ) {
		wp_safe_redirect( add_query_arg( 'rc_notice', $notice, admin_url( 'admin.php?page=reallycache' ) ) );
		exit;
	}
}
