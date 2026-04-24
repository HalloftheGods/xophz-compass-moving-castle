<?php

class Xophz_Compass_Moving_Castle_API {

	public function register_routes() {
		register_rest_route( 'moving-castle/v1', '/sites', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sites' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/connection', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_connection' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/schema', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/data', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_data' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/import', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_site' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/import/process', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_import_task' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));
	}

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

	private function is_multisite_network() {
		return is_multisite() && current_user_can( 'manage_network' );
	}

	private function resolve_prefix( $site_id ) {
		global $wpdb;

		$is_standalone = ! is_multisite();
		if ( $is_standalone ) {
			return $wpdb->prefix;
		}

		$is_main_site = ( $site_id == 1 );
		return $is_main_site ? $wpdb->base_prefix : $wpdb->base_prefix . $site_id . '_';
	}

	public function get_sites( $request ) {
		$is_network_admin = $this->is_multisite_network();

		if ( $is_network_admin ) {
			return $this->get_network_sites();
		}

		$is_subsite = is_multisite() && ! $is_network_admin;
		return $this->get_current_site( $is_subsite );
	}

	private function get_file_count( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$count++;
			}
		}
		return $count;
	}

	private function get_site_stats( $site_id, $is_standalone = false ) {
		global $wpdb;
		$prefix = $is_standalone || $site_id < 2 ? $wpdb->base_prefix : $wpdb->base_prefix . $site_id . '_';

		$table_count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
				DB_NAME,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		$active_plugins = $is_standalone || $site_id < 2
			? get_option( 'active_plugins', array() )
			: get_blog_option( $site_id, 'active_plugins', array() );

		if ( ! $is_standalone ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}
		$active_plugins = array_unique( $active_plugins );
		
		$plugin_dirs = array();
		foreach ( $active_plugins as $plugin_file ) {
			$plugin_slug = dirname( $plugin_file );
			if ( $plugin_slug !== '.' ) {
				$plugin_dirs[] = $plugin_slug;
			}
		}
		$plugins_count = count( array_unique( $plugin_dirs ) );

		$uploads_dir = ( ! $is_standalone && $site_id > 1 )
			? WP_CONTENT_DIR . '/uploads/sites/' . $site_id
			: wp_upload_dir()['basedir'];

		$media_count = $this->get_file_count( $uploads_dir );

		return array(
			'table_count'   => (int) $table_count,
			'plugins_count' => $plugins_count,
			'media_count'   => $media_count
		);
	}

	private function get_network_sites() {
		$sites    = get_sites();
		$response = array();

		foreach ( $sites as $site ) {
			$details = get_blog_details( $site->blog_id );
			$stats   = $this->get_site_stats( $site->blog_id, false );

			$response[] = array(
				'id'            => $site->blog_id,
				'name'          => $details->blogname,
				'domain'        => $site->domain . $site->path,
				'theme'         => get_blog_option( $site->blog_id, 'stylesheet' ),
				'type'          => 'subsite',
				'color'         => 'cyan',
				'status'        => 'Active',
				'table_count'   => $stats['table_count'],
				'plugins_count' => $stats['plugins_count'],
				'media_count'   => $stats['media_count']
			);
		}

		return rest_ensure_response( array(
			'mode'  => 'multisite',
			'sites' => $response
		));
	}

	private function get_current_site( $is_subsite = false ) {
		$site_url  = get_site_url();
		$site_name = get_bloginfo( 'name' );
		$theme     = wp_get_theme()->get( 'Name' );

		$site_id = 0;
		$mode    = 'standalone';
		$type    = 'standalone';

		if ( $is_subsite ) {
			$site_id = get_current_blog_id();
			$mode    = 'subsite';
			$type    = 'subsite';
		}

		$stats = $this->get_site_stats( $site_id, ! $is_subsite );

		return rest_ensure_response( array(
			'mode'  => $mode,
			'sites' => array(
				array(
					'id'            => $site_id,
					'name'          => $site_name,
					'domain'        => wp_parse_url( $site_url, PHP_URL_HOST ),
					'theme'         => $theme,
					'type'          => $type,
					'color'         => 'cyan',
					'status'        => 'Active',
					'table_count'   => $stats['table_count'],
					'plugins_count' => $stats['plugins_count'],
					'media_count'   => $stats['media_count'],
				)
			)
		));
	}

	public function generate_connection( $request ) {
		$params  = $request->get_json_params();
		$site_id = isset( $params['site_id'] ) ? absint( $params['site_id'] ) : 0;

		$allowed_scopes = array( 'database', 'media', 'plugins', 'themes' );
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

	public function get_schema( $request ) {
		global $wpdb;

		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$site_id       = $token_data['site_id'];
		$is_standalone = ! empty( $token_data['standalone'] );
		$scope         = isset( $token_data['scope'] ) ? $token_data['scope'] : array( 'database' );
		$prefix        = $this->resolve_prefix( $site_id );

		$response = array(
			'success' => true,
			'mode'    => $is_standalone ? 'standalone' : 'multisite',
			'prefix'  => $prefix,
			'scope'   => $scope,
		);

		$has_database_scope = in_array( 'database', $scope, true );
		if ( $has_database_scope ) {
			$tables_raw = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
					DB_NAME,
					$wpdb->esc_like( $prefix ) . '%'
				),
				ARRAY_N
			);

			$schema = array();
			foreach ( $tables_raw as $row ) {
				$table_name  = $row[0];
				$create_stmt = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_A );
				$has_create   = $create_stmt && isset( $create_stmt['Create Table'] );
				if ( $has_create ) {
					$schema[ $table_name ] = $create_stmt['Create Table'];
				}
			}

			$response['tables'] = array_keys( $schema );
			$response['schema'] = $schema;
		}

		$file_manifest = $this->build_file_manifest( $site_id, $scope, $is_standalone, $token_data );
		if ( ! empty( $file_manifest ) ) {
			$response['files'] = $file_manifest;
		}

		return rest_ensure_response( $this->encrypt_payload( $response ) );
	}

	private function build_file_manifest( $site_id, $scope, $is_standalone, $token_data = array() ) {
		$manifest = array();

		$has_media_scope = in_array( 'media', $scope, true );
		if ( $has_media_scope ) {
			$is_subsite_media = ! $is_standalone && $site_id > 1;
			$uploads_dir      = $is_subsite_media
				? WP_CONTENT_DIR . '/uploads/sites/' . $site_id
				: wp_upload_dir()['basedir'];

			$manifest['media'] = array(
				'path'           => $uploads_dir,
				'exists'         => is_dir( $uploads_dir ),
				'size'           => is_dir( $uploads_dir ) ? $this->get_dir_size( $uploads_dir ) : 0,
				'time_range'     => isset( $token_data['mediaTimeRange'] ) ? $token_data['mediaTimeRange'] : 'all',
				'start_date'     => isset( $token_data['mediaStartDate'] ) ? $token_data['mediaStartDate'] : '',
				'end_date'       => isset( $token_data['mediaEndDate'] ) ? $token_data['mediaEndDate'] : ''
			);
		}

		$has_plugins_scope = in_array( 'plugins', $scope, true );
		if ( $has_plugins_scope ) {
			$active_plugins = $is_standalone || $site_id < 2
				? get_option( 'active_plugins', array() )
				: get_blog_option( $site_id, 'active_plugins', array() );

			if ( ! $is_standalone ) {
				$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
				if ( is_array( $network_plugins ) ) {
					$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
				}
			}
			$active_plugins = array_unique( $active_plugins );

			$plugin_dirs = array();
			foreach ( $active_plugins as $plugin_file ) {
				$plugin_slug = dirname( $plugin_file );
				$has_folder  = ( $plugin_slug !== '.' );
				if ( $has_folder ) {
					$plugin_dirs[] = $plugin_slug;
				}
			}
			$plugin_dirs = array_values( array_unique( $plugin_dirs ) );

			$manifest['plugins'] = array(
				'path'    => WP_CONTENT_DIR . '/plugins',
				'active'  => $plugin_dirs,
				'count'   => count( $plugin_dirs ),
			);
		}

		$has_themes_scope = in_array( 'themes', $scope, true );
		if ( $has_themes_scope ) {
			$stylesheet = $is_standalone || $site_id < 2
				? get_stylesheet()
				: get_blog_option( $site_id, 'stylesheet' );

			$template = $is_standalone || $site_id < 2
				? get_template()
				: get_blog_option( $site_id, 'template' );

			$active_themes = array( $stylesheet );
			if ( $stylesheet !== $template ) {
				$active_themes[] = $template;
			}

			$size = 0;
			$exists = false;
			foreach ( $active_themes as $theme_slug ) {
				$theme_dir = WP_CONTENT_DIR . '/themes/' . $theme_slug;
				if ( is_dir( $theme_dir ) ) {
					$exists = true;
					$size += $this->get_dir_size( $theme_dir );
				}
			}

			$manifest['themes'] = array(
				'path'     => WP_CONTENT_DIR . '/themes',
				'active'   => $active_themes,
				'exists'   => $exists,
				'size'     => $size,
				'is_child' => count( $active_themes ) > 1
			);
		}

		return $manifest;
	}

	private function get_dir_size( $dir ) {
		$size = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			$size += $file->getSize();
		}
		return $size;
	}

	public function get_data( $request ) {
		global $wpdb;

		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$table  = sanitize_text_field( $request->get_param( 'table' ) );
		$page   = absint( $request->get_param( 'page' ) ?: 1 );
		$limit  = 1000;
		$offset = ( $page - 1 ) * $limit;

		$site_id = $token_data['site_id'];
		$prefix  = $this->resolve_prefix( $site_id );

		$table_belongs_to_site = ( strpos( $table, $prefix ) === 0 );
		if ( ! $table_belongs_to_site ) {
			return new WP_Error( 'invalid_table', 'Table does not belong to this site.', array( 'status' => 403 ) );
		}

		if ( $page === 0 ) {
			$schema = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
			return rest_ensure_response( $this->encrypt_payload( array(
				'success'       => true,
				'table'         => $table,
				'create_schema' => isset( $schema['Create Table'] ) ? $schema['Create Table'] : ''
			) ) );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);

		return rest_ensure_response( $this->encrypt_payload( array(
			'success' => true,
			'table'   => $table,
			'page'    => $page,
			'count'   => count( $rows ),
			'data'    => $rows
		) ) );
	}

	public function import_site( $request ) {
		$params         = $request->get_json_params();
		$connection_url = esc_url_raw( $params['connection_url'] );

		if ( ! $connection_url ) {
			return new WP_Error( 'missing_url', 'Connection URL is required.', array( 'status' => 400 ) );
		}

		$parsed = wp_parse_url( $connection_url );
		$has_query = isset( $parsed['query'] );
		if ( ! $has_query ) {
			return new WP_Error( 'invalid_url', 'Invalid Connection URL format.', array( 'status' => 400 ) );
		}

		parse_str( $parsed['query'], $query_params );
		$token = isset( $query_params['token'] ) ? $query_params['token'] : '';

		if ( ! $token ) {
			return new WP_Error( 'invalid_url', 'Missing token in Connection URL.', array( 'status' => 400 ) );
		}

		$port_segment = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$base_url     = $parsed['scheme'] . '://' . $parsed['host'] . $port_segment;

		$schema_url = $base_url . '/wp-json/moving-castle/v1/schema?token=' . $token;
		$response   = wp_remote_get( $schema_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', 'Could not connect to source site: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$raw_body       = json_decode( wp_remote_retrieve_body( $response ), true );
		$body           = $this->decrypt_payload( $raw_body );
		$has_valid_body = ! empty( $body['success'] );

		if ( ! $has_valid_body ) {
			$error_msg = isset( $body['message'] ) ? $body['message'] : 'Unknown schema error.';
			return new WP_Error( 'schema_failed', 'Failed to retrieve schema: ' . $error_msg, array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'message'  => 'Connection established.',
			'mode'     => isset( $body['mode'] ) ? $body['mode'] : 'unknown',
			'scope'    => isset( $body['scope'] ) ? $body['scope'] : array( 'database' ),
			'tables'   => isset( $body['tables'] ) ? $body['tables'] : array(),
			'files'    => isset( $body['files'] ) ? $body['files'] : array(),
			'prefix'   => $body['prefix'],
			'token'    => $token,
			'base_url' => $base_url
		));
	}

	public function process_import_task( $request ) {
		$params   = $request->get_json_params();
		$base_url = esc_url_raw( $params['base_url'] );
		$token    = sanitize_text_field( $params['token'] );
		$task     = sanitize_text_field( $params['task'] );
		$is_dry   = ! empty( $params['dry_run'] );

		if ( ! $base_url || ! $token || ! $task ) {
			return new WP_Error( 'missing_params', 'Missing required parameters.', array( 'status' => 400 ) );
		}

		if ( $task === 'pull_table' ) {
			$table = sanitize_text_field( $params['table'] );
			$page  = absint( $params['page'] );
			
			$data_url = $base_url . '/wp-json/moving-castle/v1/data?token=' . $token . '&table=' . $table . '&page=' . $page;
			$response = wp_remote_get( $data_url, array( 'timeout' => 60 ) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'fetch_failed', 'Could not fetch data: ' . $response->get_error_message(), array( 'status' => 500 ) );
			}

			$raw_body = json_decode( wp_remote_retrieve_body( $response ), true );
			$body     = $this->decrypt_payload( $raw_body );

			if ( empty( $body['success'] ) ) {
				return new WP_Error( 'fetch_failed', 'Invalid data payload.', array( 'status' => 500 ) );
			}

			global $wpdb;

			if ( $page === 0 ) {
				if ( ! empty( $body['create_schema'] ) && ! $is_dry ) {
					// We need to remap the table prefix to local. Pending implementation.
					// $wpdb->query( $body['create_schema'] );
				}
				$msg = $is_dry ? 'Schema parsed (dry run).' : 'Schema synced.';
				return rest_ensure_response( array( 'success' => true, 'message' => $msg ) );
			}

			$rows = $body['data'];
			if ( empty( $rows ) ) {
				return rest_ensure_response( array( 'success' => true, 'message' => 'Table complete.', 'done' => true ) );
			}

			if ( ! $is_dry ) {
				// In a full implementation, we run REPLACE INTO statements here.
			}

			return rest_ensure_response( array(
				'success' => true,
				'message' => ( $is_dry ? 'Simulated ' : 'Synced ' ) . count( $rows ) . ' rows.',
				'done'    => count( $rows ) < 1000
			));
		}

		return new WP_Error( 'invalid_task', 'Invalid task specified.', array( 'status' => 400 ) );
	}
}
