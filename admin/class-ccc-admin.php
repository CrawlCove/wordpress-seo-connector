<?php
/**
 * Admin page (Tools → Crawl Cove): connection status, setup instructions,
 * and the change log with one-click revert.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller: Tools → Crawl Cove.
 */
class CCC_Admin {

	/**
	 * Where the "get the desktop app" step on the Tools page sends people.
	 *
	 * The utm_* parameters are plain, user-visible query parameters on a
	 * link the site owner chooses to click — not a request this plugin makes,
	 * not a beacon, no identifier of the site or user is carried. They only
	 * let crawlcove.com count how many of its visitors arrived from an
	 * installed copy of this plugin rather than from a search engine.
	 */
	const APP_URL = 'https://crawlcove.com/download?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=connector-admin';

	/**
	 * Change-log rows per Tools page. Each row costs a post (or term) lookup
	 * for its link and title; with CCC_Change_Log::MAX_ENTRIES at 1,000 an
	 * unpaginated table would be a thousand of them on one admin request.
	 */
	const PER_PAGE = 100;

	/**
	 * Hook the admin menu and the revert form handler.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ccc_revert', array( __CLASS__, 'handle_revert' ) );
	}

	/**
	 * Register the Tools → Crawl Cove submenu page.
	 */
	public static function menu() {
		add_management_page(
			__( 'Crawl Cove Connector', 'crawl-cove-connector' ),
			__( 'Crawl Cove', 'crawl-cove-connector' ),
			'manage_options',
			'crawl-cove-connector',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin-post.php handler for the per-row "Revert" button.
	 */
	public static function handle_revert() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'crawl-cove-connector' ) );
		}
		$change_id = isset( $_POST['change_id'] ) ? (int) $_POST['change_id'] : 0;
		check_admin_referer( 'ccc_revert_' . $change_id );
		$paged = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		$force = ! empty( $_POST['force'] );

		$adapter = CCC_Adapter::detect();
		$notice  = 'error';
		$entry   = CCC_Change_Log::find( $change_id );
		$done    = ( $adapter || ( $entry && isset( $entry['kind'] ) ) ) ? CCC_Change_Log::revert( $change_id, $adapter, $force ) : null;
		if ( true === $done ) {
			$notice = 'reverted';
		} elseif ( is_wp_error( $done ) && 'ccc_value_changed' === $done->get_error_code() ) {
			$notice = 'changed';
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'crawl-cove-connector',
					'ccc_notice' => $notice,
					'change_id'  => $change_id,
					'paged'      => $paged,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Tools → Crawl Cove page: status, setup steps, change log.
	 */
	public static function render() {
		$adapter = CCC_Adapter::detect();
		$log     = CCC_Change_Log::all();
		$conn    = CCC_Connection::last();
		// Read-only display flags from our own redirect / pagination links
		// (handle_revert() already nonce-checked the action that set the
		// notice); nothing here changes state.
		$notice = isset( $_GET['ccc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ccc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed = isset( $_GET['change_id'] ) ? CCC_Change_Log::find( (int) $_GET['change_id'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$superseded = CCC_Change_Log::superseded( $log );

		$total = count( $log );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array_slice( $log, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		// One query for every post on this page instead of one per row.
		$post_ids = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['post_id'] > 0 && ! isset( $row['kind'] ) ) {
				$post_ids[] = (int) $row['post_id'];
			}
		}
		if ( $post_ids ) {
			_prime_post_caches( array_unique( $post_ids ), false, false );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crawl Cove Connector', 'crawl-cove-connector' ); ?></h1>

			<?php if ( 'reverted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Change reverted.', 'crawl-cove-connector' ); ?></p></div>
			<?php elseif ( 'changed' === $notice && $failed && isset( $failed['kind'] ) && 'replace_urls' === $failed['kind'] && empty( $failed['reverted'] ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Not reverted: the content holding these links has been edited since that change. Reverting anyway changes the new URL back to the old one wherever it now appears.', 'crawl-cove-connector' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px">
						<input type="hidden" name="action" value="ccc_revert" />
						<input type="hidden" name="change_id" value="<?php echo (int) $failed['id']; ?>" />
						<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
						<input type="hidden" name="force" value="1" />
						<?php wp_nonce_field( 'ccc_revert_' . (int) $failed['id'] ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Revert anyway', 'crawl-cove-connector' ); ?></button>
					</form>
				</div>
			<?php elseif ( 'changed' === $notice && $failed && $adapter && ! isset( $failed['kind'] ) && empty( $failed['reverted'] ) ) : ?>
				<?php $now = CCC_Change_Log::current_value( $failed, $adapter ); ?>
				<div class="notice notice-warning">
					<p>
					<?php
					if ( 'title' === $failed['field'] ) {
						/* translators: %s: the title currently stored in the SEO plugin */
						$changed_msg = __( 'Not reverted: this title has changed since that push and now reads "%s". Reverting would overwrite it.', 'crawl-cove-connector' );
					} else {
						/* translators: %s: the description currently stored in the SEO plugin */
						$changed_msg = __( 'Not reverted: this description has changed since that push and now reads "%s". Reverting would overwrite it.', 'crawl-cove-connector' );
					}
					echo esc_html( sprintf( $changed_msg, '' === $now ? '—' : $now ) );
					?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px">
						<input type="hidden" name="action" value="ccc_revert" />
						<input type="hidden" name="change_id" value="<?php echo (int) $failed['id']; ?>" />
						<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
						<input type="hidden" name="force" value="1" />
						<?php wp_nonce_field( 'ccc_revert_' . (int) $failed['id'] ); ?>
						<button type="submit" class="button button-small">
						<?php
						/* translators: %s: the value the revert would restore */
						echo esc_html( sprintf( __( 'Revert anyway to "%s"', 'crawl-cove-connector' ), '' === $failed['from'] ? '—' : $failed['from'] ) );
						?>
						</button>
					</form>
				</div>
			<?php elseif ( 'error' === $notice || 'changed' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not revert that change.', 'crawl-cove-connector' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'crawl-cove-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'SEO plugin detected', 'crawl-cove-connector' ); ?></td>
						<td>
							<?php if ( $adapter ) : ?>
								<strong><?php echo esc_html( $adapter->label() ); ?></strong>
								<?php echo esc_html( $adapter->plugin_version ? ' v' . $adapter->plugin_version : '' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'None', 'crawl-cove-connector' ); ?></strong>
								<?php esc_html_e( '(install Yoast SEO, Rank Math, SEOPress or AIOSEO; fixes cannot be applied without one)', 'crawl-cove-connector' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Application Passwords', 'crawl-cove-connector' ); ?></td>
						<td>
							<?php if ( CCC_App_Passwords::available() ) : ?>
								<strong><?php esc_html_e( 'On', 'crawl-cove-connector' ); ?></strong>
							<?php else : ?>
								<strong><?php esc_html_e( 'Off', 'crawl-cove-connector' ); ?></strong>
								<?php if ( CCC_App_Passwords::off_by_filter() ) : ?>
									<?php esc_html_e( 'The desktop app cannot log in until they are on. WordPress itself allows them on this site, so a plugin or theme has switched them off.', 'crawl-cove-connector' ); ?>
									<br>
									<?php esc_html_e( 'Wordfence does this by default: untick Firewall, All Firewall Options, Disable WordPress application passwords, then save. On another security plugin, look for a setting with a similar name.', 'crawl-cove-connector' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'The desktop app cannot log in until they are on. WordPress only enables Application Passwords when the site is served over HTTPS (is_ssl()) or WP_ENVIRONMENT_TYPE is local.', 'crawl-cove-connector' ); ?>
									<br>
									<?php esc_html_e( 'If a proxy or load balancer terminates HTTPS in front of WordPress, tell it so in wp-config.php:', 'crawl-cove-connector' ); ?>
									<code>if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &amp;&amp; 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; }</code>
									<br>
									<?php esc_html_e( 'Last resort for a trusted local or staging site:', 'crawl-cove-connector' ); ?>
									<code>add_filter( 'wp_is_application_passwords_available', '__return_true' );</code>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'REST endpoint', 'crawl-cove-connector' ); ?></td>
						<td><code><?php echo esc_html( rest_url( CCC_Rest::NS ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Desktop app', 'crawl-cove-connector' ); ?></td>
						<td>
							<?php if ( $conn ) : ?>
								<?php
								printf(
									/* translators: 1: date and time, 2: WordPress username */
									esc_html__( 'Last connected %1$s by %2$s', 'crawl-cove-connector' ),
									'<strong>' . esc_html( wp_date( 'j M Y H:i', $conn['time'] ) ) . '</strong>',
									'<strong>' . esc_html( $conn['user'] ) . '</strong>'
								);
								?>
							<?php else : ?>
								<strong><?php esc_html_e( 'Not connected yet.', 'crawl-cove-connector' ); ?></strong>
								<?php esc_html_e( 'Nothing has called the REST API. Follow the steps below.', 'crawl-cove-connector' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Connect the Crawl Cove desktop app', 'crawl-cove-connector' ); ?></h2>
			<ol style="max-width:700px">
				<li>
				<?php
					printf(
						/* translators: %s: link to the Crawl Cove download page */
						wp_kses( __( 'Install the <a href="%s">Crawl Cove desktop app</a> on your computer, if you have not already. It is the crawler that finds the title and description problems; this plugin only applies the fixes you approve there. New accounts start on a free trial.', 'crawl-cove-connector' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( self::APP_URL )
					);
				?>
				</li>
				<li>
				<?php
					printf(
						/* translators: %s: link to the current user's profile page */
						wp_kses( __( 'Create an <a href="%s">Application Password</a> for your user (Users → Profile → Application Passwords). Name it "Crawl Cove".', 'crawl-cove-connector' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'profile.php#application-passwords-section' ) )
					);
				?>
				</li>
				<li><?php esc_html_e( 'In Crawl Cove, open the site profile → WordPress → paste the site URL, your username and the application password.', 'crawl-cove-connector' ); ?></li>
				<li><?php esc_html_e( 'Crawl, review the suggested title/description fixes, and push the approved ones. Every push lands in the log below and can be reverted.', 'crawl-cove-connector' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Change log', 'crawl-cove-connector' ); ?></h2>
			<?php if ( count( $log ) >= CCC_Change_Log::MAX_ENTRIES ) : ?>
				<p>
				<?php
					printf(
						/* translators: %s: number of entries the change log keeps */
						esc_html__( 'The log keeps the most recent %s changes. It is full, so each new push drops the oldest entries (already-reverted ones first); dropped entries can no longer be reverted from here.', 'crawl-cove-connector' ),
						esc_html( number_format_i18n( CCC_Change_Log::MAX_ENTRIES ) )
					);
				?>
				</p>
			<?php endif; ?>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'No changes pushed yet.', 'crawl-cove-connector' ); ?></p>
			<?php else : ?>
				<?php if ( $pages > 1 ) : ?>
					<p class="tablenav-pages" style="margin:0 0 8px">
						<?php
						printf(
							/* translators: 1: first row number shown, 2: last row number shown, 3: total number of logged changes */
							esc_html__( 'Showing changes %1$s to %2$s of %3$s.', 'crawl-cove-connector' ),
							esc_html( number_format_i18n( ( $paged - 1 ) * self::PER_PAGE + 1 ) ),
							esc_html( number_format_i18n( ( $paged - 1 ) * self::PER_PAGE + count( $rows ) ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
						<span class="pagination-links" style="margin-left:8px">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%', admin_url( 'tools.php?page=crawl-cove-connector' ) ),
									'format'  => '',
									'current' => $paged,
									'total'   => $pages,
									'type'    => 'plain',
								)
							)
						);
						?>
						</span>
					</p>
				<?php endif; ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Post', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Field', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Before', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'After', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'By', 'crawl-cove-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $e['time'] ) ); ?></td>
							<td>
								<?php if ( isset( $e['kind'] ) && 'site_setting' === $e['kind'] ) : ?>
									<?php esc_html_e( 'Site setting', 'crawl-cove-connector' ); ?>
								<?php elseif ( isset( $e['kind'] ) && 'image_alt' === $e['kind'] ) : ?>
									<?php echo esc_html( isset( $e['src'] ) ? wp_basename( $e['src'] ) : '' ); ?>
								<?php elseif ( isset( $e['kind'] ) ) : ?>
									<?php
									$ccc_places = isset( $e['targets'] ) && is_array( $e['targets'] ) ? count( $e['targets'] ) : 0;
									/* translators: %d: number of posts, Elementor layouts and menu items the change edited */
									echo esc_html( sprintf( _n( 'Links in %d place', 'Links in %d places', $ccc_places, 'crawl-cove-connector' ), $ccc_places ) );
									?>
								<?php elseif ( CCC_Service::HOME_ID === (int) $e['post_id'] ) : ?>
									<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Homepage', 'crawl-cove-connector' ); ?></a>
								<?php elseif ( (int) $e['post_id'] < 0 ) : ?>
									<?php
									$term      = get_term( - (int) $e['post_id'] );
									$term_edit = ( $term && ! is_wp_error( $term ) ) ? get_edit_term_link( $term ) : '';
									?>
									<?php if ( $term && ! is_wp_error( $term ) && $term_edit ) : ?>
										<a href="<?php echo esc_url( $term_edit ); ?>"><?php echo esc_html( $term->name ); ?></a>
									<?php elseif ( $term && ! is_wp_error( $term ) ) : ?>
										<?php echo esc_html( $term->name ); ?>
									<?php else : ?>
										#<?php echo (int) $e['post_id']; ?>
									<?php endif; ?>
								<?php else : ?>
									<?php $edit = get_edit_post_link( $e['post_id'] ); ?>
									<?php if ( $edit ) : ?>
										<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title( $e['post_id'] ) ); ?></a>
									<?php else : ?>
										#<?php echo (int) $e['post_id']; ?>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $e['field'] ); ?></td>
							<td><?php echo esc_html( '' === $e['from'] ? '—' : $e['from'] ); ?></td>
							<td><?php echo esc_html( ! empty( $e['unlink'] ) ? __( '(link removed)', 'crawl-cove-connector' ) : ( '' === $e['to'] ? '—' : $e['to'] ) ); ?></td>
							<td><?php echo esc_html( $e['source'] ); ?></td>
							<td>
								<?php if ( isset( $superseded[ (int) $e['id'] ] ) ) : ?>
									<em><?php esc_html_e( 'replaced by a later change', 'crawl-cove-connector' ); ?></em>
								<?php elseif ( empty( $e['reverted'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="ccc_revert" />
										<input type="hidden" name="change_id" value="<?php echo (int) $e['id']; ?>" />
										<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
										<?php wp_nonce_field( 'ccc_revert_' . (int) $e['id'] ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Revert', 'crawl-cove-connector' ); ?></button>
									</form>
								<?php else : ?>
									<em><?php esc_html_e( 'reverted', 'crawl-cove-connector' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
