<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Make_Kisi_API {

	const BASE_URL = 'https://api.kisi.io';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	// -------------------------------------------------------------------------
	// Users
	// -------------------------------------------------------------------------

	/**
	 * Find a Kisi user by email. Returns the user array or null.
	 */
	public function find_user_by_email( string $email ): ?array {
		$response = $this->request( 'GET', '/users', [ 'query' => $email ] );

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return null;
		}

		foreach ( $response as $user ) {
			if ( isset( $user['email'] ) && strtolower( $user['email'] ) === strtolower( $email ) ) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * Create a managed Kisi user. Returns the user array or WP_Error.
	 */
	public function create_user( string $email, string $name ): array|WP_Error {
		return $this->request( 'POST', '/users', [], [
			'user' => [
				'email'       => $email,
				'name'        => $name,
				'send_emails' => false,
				'confirm'     => true,
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Role Assignments (access grants)
	// -------------------------------------------------------------------------

	/**
	 * Grant a user access to a group. Returns the role_assignment array or WP_Error.
	 */
	public function create_role_assignment( int $user_id, int $group_id ): array|WP_Error {
		return $this->request( 'POST', '/role_assignments', [], [
			'role_assignment' => [
				'user_id'  => $user_id,
				'role_id'  => 'group_basic',
				'group_id' => $group_id,
			],
		] );
	}

	/**
	 * Revoke a specific role assignment by ID.
	 */
	public function delete_role_assignment( int $role_assignment_id ): bool|WP_Error {
		$result = $this->request( 'DELETE', "/role_assignments/{$role_assignment_id}" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Find existing role assignments for a user in a group.
	 */
	public function find_role_assignments( int $user_id, int $group_id ): array {
		$response = $this->request( 'GET', '/role_assignments', [
			'user_id'  => $user_id,
			'group_id' => $group_id,
		] );

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return [];
		}

		return $response;
	}

	// -------------------------------------------------------------------------
	// HTTP
	// -------------------------------------------------------------------------

	private function request( string $method, string $path, array $query = [], array $body = [] ): mixed {
		$url = self::BASE_URL . $path;

		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'KISI-LOGIN ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'timeout' => 15,
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		// 204 No Content — success with no body
		if ( 204 === $code ) {
			return [];
		}

		if ( $code === 429 ) {
			return new WP_Error( 'kisi_rate_limit', 'Kisi API rate limit exceeded. Retry later.', [ 'status' => 429 ] );
		}

		if ( $code >= 400 ) {
			$message = $this->parse_error_message( $raw, $code );
			return new WP_Error( 'kisi_api_error', $message, [ 'status' => $code, 'body' => $raw ] );
		}

		if ( empty( $raw ) ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'kisi_json_error', 'Invalid JSON response from Kisi API.' );
		}

		return $decoded;
	}

	private function parse_error_message( string $raw, int $code ): string {
		$data = json_decode( $raw, true );

		if ( ! empty( $data['error'] ) ) {
			return $data['error'];
		}

		if ( ! empty( $data['message'] ) ) {
			return $data['message'];
		}

		return "Kisi API returned HTTP {$code}.";
	}
}
