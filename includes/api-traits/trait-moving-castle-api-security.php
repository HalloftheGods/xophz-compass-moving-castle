<?php

trait Trait_Moving_Castle_API_Security {

	private $cipher_key  = 'XophzCompass_MC_SecureKey_Omega26';
	private $cipher_algo = 'AES-256-CBC';

	private function encrypt_payload( $data ) {
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $this->cipher_algo ) );
		$encrypted = openssl_encrypt( wp_json_encode( $data ), $this->cipher_algo, $this->cipher_key, 0, $iv );
		return array(
			'encrypted' => true,
			'payload'   => base64_encode( $encrypted ),
			'iv'        => base64_encode( $iv )
		);
	}

	private function decrypt_payload( $data ) {
		if ( empty( $data['encrypted'] ) ) return $data;
		$iv        = base64_decode( $data['iv'] );
		$encrypted = base64_decode( $data['payload'] );
		$decrypted = openssl_decrypt( $encrypted, $this->cipher_algo, $this->cipher_key, 0, $iv );
		return json_decode( $decrypted, true );
	}

	public function generate_connection( $request ) {
		$params  = $request->get_json_params();
		$site_id = isset( $params['site_id'] ) ? absint( $params['site_id'] ) : 0;

		$allowed_scopes = array( 'database', 'media', 'plugins', 'themes', 'mu-plugins', 'languages', 'others', 'includeOptions', 'includeUsers' );
		$raw_scope      = isset( $params['scope'] ) && is_array( $params['scope'] ) ? $params['scope'] : array( 'database' );
		$scope          = array_values( array_intersect( $raw_scope, $allowed_scopes ) );

		$is_standalone = ! is_multisite();
		$is_subsite    = is_multisite() && ! current_user_can( 'manage_network' );

		if ( $is_standalone ) {
			$site_id = 0;
		} elseif ( $is_subsite ) {
			$site_id = get_current_blog_id();
		}

		$token = wp_generate_password( 32, false );

		$transient_data = array(
			'site_id'          => $site_id,
			'standalone'       => $is_standalone,
			'scope'            => $scope,
			'mediaTimeRange'   => isset( $params['mediaTimeRange'] ) ? sanitize_text_field( $params['mediaTimeRange'] ) : 'all',
			'mediaStartDate'   => isset( $params['mediaStartDate'] ) ? sanitize_text_field( $params['mediaStartDate'] ) : '',
			'mediaEndDate'     => isset( $params['mediaEndDate'] ) ? sanitize_text_field( $params['mediaEndDate'] ) : '',
		);
		set_transient( 'mc_migration_' . $token, wp_json_encode( $transient_data ), 3600 );

		$connection_url = home_url( "/wp-json/moving-castle/v1/?token={$token}" );

		return rest_ensure_response( array(
			'success' => true,
			'token'   => $token,
			'url'     => $connection_url,
			'scope'   => $scope
		));
	}

	private function validate_token( $request ) {
		$token = $request->get_param( 'token' );
		if ( ! $token ) return false;

		$raw = get_transient( 'mc_migration_' . $token );
		if ( ! $raw ) return false;

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array( 'site_id' => (int) $raw, 'standalone' => false );
		}

		return $data;
	}
}
