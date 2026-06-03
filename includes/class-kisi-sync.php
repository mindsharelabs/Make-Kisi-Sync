<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Make_Kisi_Sync {

	// Meta keys
	const USER_META_KISI_ID           = 'kisi_user_id';
	const MEMBERSHIP_META_ASSIGNMENT  = 'kisi_role_assignment_id';

	// Statuses that mean "has access"
	const ACTIVE_STATUSES = [ 'active', 'complimentary' ];

	private Make_Kisi_API $api;

	public function __construct( Make_Kisi_API $api ) {
		$this->api = $api;
	}

	public function register_hooks(): void {
		// Covers all status transitions (cancel, expire, pause, reactivate, etc.)
		add_action( 'wc_memberships_user_membership_status_changed', [ $this, 'on_status_changed' ], 10, 3 );

		// Also fires when access is granted via purchase (catches the initial grant)
		add_action( 'wc_memberships_grant_membership_access_from_purchase', [ $this, 'on_access_granted_from_purchase' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * @param WC_Memberships_User_Membership $user_membership
	 * @param string $old_status  Status slug without 'wcm-' prefix
	 * @param string $new_status  Status slug without 'wcm-' prefix
	 */
	public function on_status_changed( $user_membership, string $old_status, string $new_status ): void {
		
		if ( ! $this->should_sync_plan( $user_membership->get_plan_id() ) ) {
			return;
		}

		$becoming_active   = in_array( $new_status, self::ACTIVE_STATUSES, true );
		$was_active        = in_array( $old_status, self::ACTIVE_STATUSES, true );

		if ( $becoming_active && ! $was_active ) {
			$this->grant_access( $user_membership );
		} elseif ( ! $becoming_active && $was_active ) {
			$this->revoke_access( $user_membership );
		}
	}

	/**
	 * @param WC_Memberships_Membership_Plan $plan
	 * @param array $args  Keys: user_id, product_id, order_id, user_membership_id
	 */
	public function on_access_granted_from_purchase( $plan, array $args ): void {
		if ( ! $this->should_sync_plan( $plan->get_id() ) ) {
			return;
		}

		$membership = wc_memberships_get_user_membership( $args['user_membership_id'] );

		if ( $membership && in_array( $membership->get_status(), self::ACTIVE_STATUSES, true ) ) {
			$this->grant_access( $membership );
		}
	}

	// -------------------------------------------------------------------------
	// Access management
	// -------------------------------------------------------------------------

	private function grant_access( $user_membership ): void {
		$group_id = (int) get_option( 'kisi_sync_group_id', 0 );

		if ( ! $group_id ) {
			$this->log( 'Grant skipped: Kisi group ID not configured.' );
			return;
		}

		$wp_user_id   = $user_membership->get_user_id();
		$membership_id = $user_membership->get_id();

		// Don't double-grant if we already have an assignment stored
		$existing_assignment = get_post_meta( $membership_id, self::MEMBERSHIP_META_ASSIGNMENT, true );
		if ( $existing_assignment ) {
			$this->log( "Grant skipped: membership #{$membership_id} already has assignment {$existing_assignment}." );
			return;
		}

		$kisi_user_id = $this->get_or_create_kisi_user( $wp_user_id );

		if ( is_wp_error( $kisi_user_id ) ) {
			$this->log( 'Failed to get/create Kisi user: ' . $kisi_user_id->get_error_message() );
			return;
		}

		// Check for an existing assignment in Kisi (idempotency safeguard)
		$existing = $this->api->find_role_assignments( $kisi_user_id, $group_id );
		if ( ! empty( $existing ) ) {
			$assignment_id = $existing[0]['id'];
			update_post_meta( $membership_id, self::MEMBERSHIP_META_ASSIGNMENT, $assignment_id );
			$this->log( "Found existing Kisi assignment {$assignment_id} for user #{$wp_user_id}, stored on membership #{$membership_id}." );
			return;
		}

		$result = $this->api->create_role_assignment( $kisi_user_id, $group_id );

		if ( is_wp_error( $result ) ) {
			$this->log( "Failed to create Kisi role assignment (kisi_user_id={$kisi_user_id}, group_id={$group_id}): " . $result->get_error_message() );
			return;
		}

		$assignment_id = $result['id'] ?? null;

		if ( $assignment_id ) {
			update_post_meta( $membership_id, self::MEMBERSHIP_META_ASSIGNMENT, $assignment_id );
			$this->log( "Granted Kisi access: assignment #{$assignment_id} for WP user #{$wp_user_id}, membership #{$membership_id}." );
		}
	}

	private function revoke_access( $user_membership ): void {
		$membership_id = $user_membership->get_id();
		$assignment_id = (int) get_post_meta( $membership_id, self::MEMBERSHIP_META_ASSIGNMENT, true );

		if ( ! $assignment_id ) {
			$this->log( "Revoke skipped: no Kisi assignment stored for membership #{$membership_id}." );
			return;
		}

		$result = $this->api->delete_role_assignment( $assignment_id );

		if ( is_wp_error( $result ) ) {
			// 404 means already deleted — treat as success
			$code = $result->get_error_data( 'kisi_api_error' )['status'] ?? 0;
			if ( 404 !== $code ) {
				$this->log( 'Failed to delete Kisi role assignment: ' . $result->get_error_message() );
				return;
			}
		}

		delete_post_meta( $membership_id, self::MEMBERSHIP_META_ASSIGNMENT );
		$this->log( "Revoked Kisi access: assignment #{$assignment_id} removed for membership #{$membership_id}." );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the stored Kisi user ID for a WP user, or create one if missing.
	 */
	private function get_or_create_kisi_user( int $wp_user_id ): int|WP_Error {
		$kisi_user_id = (int) get_user_meta( $wp_user_id, self::USER_META_KISI_ID, true );

		if ( $kisi_user_id ) {
			return $kisi_user_id;
		}

		$wp_user = get_userdata( $wp_user_id );

		if ( ! $wp_user ) {
			return new WP_Error( 'kisi_no_wp_user', "WP user #{$wp_user_id} not found." );
		}

		// Check if user already exists in Kisi
		$kisi_user = $this->api->find_user_by_email( $wp_user->user_email );

		if ( ! $kisi_user ) {
			$display_name = trim( $wp_user->display_name ) ?: $wp_user->user_email;
			$kisi_user    = $this->api->create_user( $wp_user->user_email, $display_name );

			if ( is_wp_error( $kisi_user ) ) {
				return $kisi_user;
			}
		}

		$kisi_user_id = (int) ( $kisi_user['id'] ?? 0 );

		if ( ! $kisi_user_id ) {
			return new WP_Error( 'kisi_no_user_id', 'Kisi returned a user with no ID.' );
		}

		update_user_meta( $wp_user_id, self::USER_META_KISI_ID, $kisi_user_id );

		return $kisi_user_id;
	}

	private function should_sync_plan( int $plan_id ): bool {
		$plan_ids = get_option( 'kisi_sync_plan_ids', [] );

		// If no plans configured, sync all plans
		if ( empty( $plan_ids ) ) {
			return true;
		}

		return in_array( $plan_id, array_map( 'intval', (array) $plan_ids ), true );
	}

	private function log( string $message ): void {
		if ( ! get_option( 'kisi_sync_log_enabled', false ) ) {
			return;
		}

		error_log( '[make-kisi-sync] ' . $message );
	}
}
