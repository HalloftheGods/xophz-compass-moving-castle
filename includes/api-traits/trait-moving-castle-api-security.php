<?php

trait Trait_Moving_Castle_API_Security {

	private $cipher_algo = 'aes-256-gcm';

	/**
	 * The token the current request authenticated with. Set by validate_token()
	 * so encrypt_payload() can key the response without every caller passing it.
	 */
	private $validated_token = '';

	/**
	 * Keep only the characters wp_generate_password( 32, false ) can emit, so a
	 * token is safe to place in an Authorization header and a transient key.
	 */
	private function normalize_token( $raw ) {
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw );
	}

	/**
	 * Headers the importing site sends with every call to the source site.
	 * The token travels here, never in a URL.
	 */
	private function auth_headers( $token ) {
		return array( 'Authorization' => 'Bearer ' . $this->normalize_token( $token ) );
	}

	/**
	 * Read the migration token from the request's `Authorization: Bearer` header.
	 * The query string is deliberately not consulted.
	 */
	private function get_request_token( $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( ! $header || ! preg_match( '/^Bearer\s+([A-Za-z0-9]+)$/i', trim( $header ), $matches ) ) {
			return '';
		}
		return $matches[1];
	}

	/**
	 * Payload key for one migration connection.
	 *
	 * There is no stored key. The source and destination are two different
	 * WordPress installs with different salts, so wp_salt() cannot be shared
	 * between them, and a connector value would have to be copied to both sites
	 * by hand. The one secret both ends already hold is the one-time migration
	 * token the source generated, so the key is derived from it (HKDF-SHA256).
	 * It exists only for the life of that token (one hour) and is never written
	 * to disk, an option, or git.
	 */
	private function derive_cipher_key( $token ) {
		return hash_hkdf( 'sha256', $this->normalize_token( $token ), 32, 'xophz-moving-castle/payload-key/v2' );
	}

	private function encrypt_payload( $data, $token = '' ) {
		$token = $token ? $token : $this->validated_token;
		if ( ! $token ) {
			return array( 'success' => false, 'message' => 'No migration token to encrypt with.' );
		}

		$iv        = random_bytes( openssl_cipher_iv_length( $this->cipher_algo ) );
		$tag       = '';
		$encrypted = openssl_encrypt( wp_json_encode( $data ), $this->cipher_algo, $this->derive_cipher_key( $token ), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
			return array( 'success' => false, 'message' => 'Payload encryption failed.' );
		}

		return array(
			'encrypted' => true,
			'v'         => 2,
			'payload'   => base64_encode( $encrypted ),
			'iv'        => base64_encode( $iv ),
			'tag'       => base64_encode( $tag ),
		);
	}

	private function decrypt_payload( $data, $token ) {
		if ( empty( $data['encrypted'] ) ) return $data;

		$is_current = is_array( $data ) && isset( $data['v'], $data['payload'], $data['iv'], $data['tag'] ) && (int) $data['v'] === 2;
		if ( ! $is_current ) {
			return array(
				'success' => false,
				'message' => 'The source site uses an older Moving Castle payload format. Update Moving Castle on both sites and generate a new connection link.',
			);
		}

		$decrypted = openssl_decrypt(
			base64_decode( $data['payload'] ),
			$this->cipher_algo,
			$this->derive_cipher_key( $token ),
			OPENSSL_RAW_DATA,
			base64_decode( $data['iv'] ),
			base64_decode( $data['tag'] )
		);
		if ( false === $decrypted ) {
			return array( 'success' => false, 'message' => 'Payload could not be decrypted or was altered in transit.' );
		}

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

		// The token rides in the URL fragment of this copy-and-paste pairing link.
		// A fragment is never sent to a server, so it cannot reach an access log,
		// proxy or Referer header. import_site() reads it back out and every
		// request to this site then carries it in an Authorization header.
		$connection_url = home_url( "/wp-json/moving-castle/v1/#token={$token}" );

		return rest_ensure_response( array(
			'success' => true,
			'token'   => $token,
			'url'     => $connection_url,
			'scope'   => $scope
		));
	}

	private function validate_token( $request ) {
		$token = $this->get_request_token( $request );
		if ( ! $token ) return false;

		$raw = get_transient( 'mc_migration_' . $token );
		if ( ! $raw ) return false;

		$this->validated_token = $token;

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array( 'site_id' => (int) $raw, 'standalone' => false );
		}

		return $data;
	}
}
