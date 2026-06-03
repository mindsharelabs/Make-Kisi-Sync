<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Make_Kisi_Admin {

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_config_notice' ] );
	}

	public function add_menu(): void {
		add_options_page(
			'Kisi Access Sync',
			'Kisi Access Sync',
			'manage_options',
			'kisi-sync',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'kisi_sync', 'kisi_sync_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'kisi_sync', 'kisi_sync_group_id', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		] );
		register_setting( 'kisi_sync', 'kisi_sync_plan_ids', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_plan_ids' ],
		] );
		register_setting( 'kisi_sync', 'kisi_sync_log_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
	}

	public function sanitize_plan_ids( $value ): array {
		if ( empty( $value ) ) {
			return [];
		}

		return array_filter( array_map( 'absint', (array) $value ) );
	}

	public function maybe_show_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key  = get_option( 'kisi_sync_api_key', '' );
		$group_id = get_option( 'kisi_sync_group_id', 0 );

		if ( ! $api_key || ! $group_id ) {
			$url = admin_url( 'options-general.php?page=kisi-sync' );
			printf(
				'<div class="notice notice-warning"><p><strong>Kisi Access Sync</strong> is active but not fully configured. <a href="%s">Configure now &rarr;</a></p></div>',
				esc_url( $url )
			);
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plans = $this->get_membership_plans();
		?>
		<div class="wrap">
			<h1>Kisi Access Sync</h1>
			<p>Automatically grant and revoke Kisi door access when WooCommerce memberships become active or inactive.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'kisi_sync' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kisi_sync_api_key">Kisi API Key</label></th>
						<td>
							<input
								type="password"
								id="kisi_sync_api_key"
								name="kisi_sync_api_key"
								value="<?php echo esc_attr( get_option( 'kisi_sync_api_key', '' ) ); ?>"
								class="regular-text"
								autocomplete="off"
							>
							<p class="description">Generate an API key in your Kisi dashboard under <em>Account &rarr; API Keys</em>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kisi_sync_group_id">Kisi Group ID</label></th>
						<td>
							<input
								type="number"
								id="kisi_sync_group_id"
								name="kisi_sync_group_id"
								value="<?php echo esc_attr( get_option( 'kisi_sync_group_id', '' ) ); ?>"
								class="small-text"
								min="1"
							>
							<p class="description">The numeric ID of the Kisi group that members should be added to. Find it in the Kisi dashboard URL when viewing the group.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Membership Plans to Sync</th>
						<td>
							<?php if ( empty( $plans ) ) : ?>
								<p class="description">No membership plans found. <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wc_membership_plan' ) ); ?>">Create plans first.</a></p>
							<?php else : ?>
								<?php
								$saved_ids = array_map( 'intval', (array) get_option( 'kisi_sync_plan_ids', [] ) );
								foreach ( $plans as $plan ) :
									$checked = in_array( $plan->get_id(), $saved_ids, true );
								?>
									<label style="display:block; margin-bottom:6px;">
										<input
											type="checkbox"
											name="kisi_sync_plan_ids[]"
											value="<?php echo esc_attr( $plan->get_id() ); ?>"
											<?php checked( $checked ); ?>
										>
										<?php echo esc_html( $plan->get_name() ); ?>
										<span class="description">(ID: <?php echo esc_html( $plan->get_id() ); ?>)</span>
									</label>
								<?php endforeach; ?>
								<p class="description">Leave all unchecked to sync <strong>all</strong> membership plans.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Debug Logging</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="kisi_sync_log_enabled"
									value="1"
									<?php checked( get_option( 'kisi_sync_log_enabled', false ) ); ?>
								>
								Write sync events to the PHP error log
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_test_connection(); ?>
		</div>
		<?php
	}

	private function render_test_connection(): void {
		$api_key = get_option( 'kisi_sync_api_key', '' );

		if ( ! $api_key ) {
			return;
		}

		echo '<hr>';
		echo '<h2>Connection Test</h2>';

		if ( isset( $_GET['kisi_test'] ) && check_admin_referer( 'kisi_test_connection' ) ) {
			$api    = new Make_Kisi_API( $api_key );
			$result = $api->find_user_by_email( wp_get_current_user()->user_email );

			if ( is_wp_error( $result ) ) {
				printf(
					'<div class="notice notice-error inline"><p>Connection failed: %s</p></div>',
					esc_html( $result->get_error_message() )
				);
			} else {
				echo '<div class="notice notice-success inline"><p>Connection successful. API key is valid.</p></div>';
			}
		}

		$test_url = wp_nonce_url( admin_url( 'options-general.php?page=kisi-sync&kisi_test=1' ), 'kisi_test_connection' );
		printf( '<a href="%s" class="button">Test API Connection</a>', esc_url( $test_url ) );
	}

	private function get_membership_plans(): array {
		if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return [];
		}

		return wc_memberships_get_membership_plans() ?: [];
	}
}
