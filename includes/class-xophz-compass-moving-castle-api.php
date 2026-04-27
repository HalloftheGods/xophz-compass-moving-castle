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

		register_rest_route( 'moving-castle/v1', '/files/prepare', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'prepare_files' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/files/download', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_files' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/files/cleanup', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cleanup_files' ),
				'permission_callback' => '__return_true',
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

			$theme_obj  = wp_get_theme( get_blog_option( $site->blog_id, 'stylesheet' ) );
			$theme_name = $theme_obj->get( 'Name' );
			if ( $theme_obj->parent() ) {
				$theme_name = 'Parent: ' . $theme_obj->parent()->get( 'Name' ) . ', Child: ' . $theme_name;
			}

			$response[] = array(
				'id'            => $site->blog_id,
				'name'          => $details->blogname,
				'domain'        => $site->domain . $site->path,
				'theme'         => $theme_name,
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
		
		$theme_obj  = wp_get_theme();
		$theme_name = $theme_obj->get( 'Name' );
		if ( $theme_obj->parent() ) {
			$theme_name = 'Parent: ' . $theme_obj->parent()->get( 'Name' ) . ', Child: ' . $theme_name;
		}

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
					'theme'         => $theme_name,
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

				// Check if users tables should be included
				if ( ! in_array( 'includeUsers', $scope, true ) ) {
					if ( $table_name === $wpdb->base_prefix . 'users' || $table_name === $wpdb->base_prefix . 'usermeta' ) {
						continue;
					}
				}

				// Check if options table should be included
				if ( ! in_array( 'includeOptions', $scope, true ) ) {
					if ( $table_name === $prefix . 'options' ) {
						continue;
					}
				}

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

			$media_info = is_dir( $uploads_dir ) ? $this->get_dir_info( $uploads_dir ) : array( 'size' => 0, 'count' => 0 );
			
			$manifest['media'] = array(
				'path'           => $uploads_dir,
				'exists'         => is_dir( $uploads_dir ),
				'size'           => $media_info['size'],
				'file_count'     => $media_info['count'],
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
				'files'   => array_values( $active_plugins ),
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
					$info = $this->get_dir_info( $theme_dir );
					$size += $info['size'];
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

		$has_mu_plugins_scope = in_array( 'mu-plugins', $scope, true );
		if ( $has_mu_plugins_scope ) {
			$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
			if ( is_dir( $mu_dir ) ) {
				$info = $this->get_dir_info( $mu_dir );
				$manifest['mu-plugins'] = array(
					'path'       => $mu_dir,
					'exists'     => true,
					'size'       => $info['size'],
					'file_count' => $info['count']
				);
			}
		}

		$has_languages_scope = in_array( 'languages', $scope, true );
		if ( $has_languages_scope ) {
			$lang_dir = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages';
			if ( is_dir( $lang_dir ) ) {
				$info = $this->get_dir_info( $lang_dir );
				$manifest['languages'] = array(
					'path'       => $lang_dir,
					'exists'     => true,
					'size'       => $info['size'],
					'file_count' => $info['count']
				);
			}
		}

		$has_others_scope = in_array( 'others', $scope, true );
		if ( $has_others_scope ) {
			$others_dir = WP_CONTENT_DIR;
			$excludes = array(
				WP_CONTENT_DIR . '/plugins',
				WP_CONTENT_DIR . '/themes',
				WP_CONTENT_DIR . '/uploads',
				WP_CONTENT_DIR . '/mu-plugins',
				WP_CONTENT_DIR . '/languages',
				WP_CONTENT_DIR . '/upgrade',
				WP_CONTENT_DIR . '/cache',
			);

			$size = 0;
			$count = 0;
			$exists = false;

			if ( is_dir( $others_dir ) ) {
				$iterator = new DirectoryIterator( $others_dir );
				foreach ( $iterator as $fileinfo ) {
					if ( $fileinfo->isDot() ) continue;
					$path = $fileinfo->getPathname();
					
					$is_excluded = false;
					foreach ( $excludes as $exclude ) {
						if ( strpos( $path, $exclude ) === 0 ) {
							$is_excluded = true;
							break;
						}
					}
					if ( $is_excluded ) continue;

					$exists = true;
					if ( $fileinfo->isDir() ) {
						$info = $this->get_dir_info( $path );
						$size += $info['size'];
						$count += $info['count'];
					} else {
						$size += $fileinfo->getSize();
						$count++;
					}
				}

				if ( $exists ) {
					$manifest['others'] = array(
						'path'       => $others_dir,
						'exists'     => true,
						'size'       => $size,
						'file_count' => $count
					);
				}
			}
		}

		return $manifest;
	}

	private function get_dir_info( $dir ) {
		$size = 0;
		$count = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
					$count++;
				}
			}
		} catch ( Exception $e ) {
			// Ignore read errors
		}
		return array( 'size' => $size, 'count' => $count );
	}

	private function resolve_uploads_dir( $site_id, $is_standalone ) {
		$is_subsite = ! $is_standalone && $site_id > 1;
		if ( $is_subsite ) {
			return WP_CONTENT_DIR . '/uploads/sites/' . $site_id;
		}
		return wp_upload_dir()['basedir'];
	}

	private function get_tmp_zip_path( $token, $type ) {
		return sys_get_temp_dir() . '/mc-' . $type . '-' . md5( $token ) . '.zip';
	}

	private function resolve_active_themes( $site_id, $is_standalone ) {
		$is_main_or_standalone = $is_standalone || $site_id < 2;

		$stylesheet = $is_main_or_standalone
			? get_stylesheet()
			: get_blog_option( $site_id, 'stylesheet' );

		$template = $is_main_or_standalone
			? get_template()
			: get_blog_option( $site_id, 'template' );

		$themes = array( $stylesheet );
		if ( $stylesheet !== $template ) {
			$themes[] = $template;
		}

		return array_unique( array_filter( $themes ) );
	}

	private function resolve_active_plugins( $site_id, $is_standalone ) {
		$is_main_or_standalone = $is_standalone || $site_id < 2;

		$active = $is_main_or_standalone
			? get_option( 'active_plugins', array() )
			: get_blog_option( $site_id, 'active_plugins', array() );

		if ( ! $is_standalone ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active = array_merge( $active, array_keys( $network_plugins ) );
			}
		}

		$slugs = array();
		foreach ( array_unique( $active ) as $plugin_file ) {
			$slug    = dirname( $plugin_file );
			$is_real = ( $slug !== '.' );
			if ( $is_real ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	private function filter_media_files( $dir, $token_data ) {
		$time_range = isset( $token_data['mediaTimeRange'] ) ? $token_data['mediaTimeRange'] : 'all';
		if ( $time_range === 'all' ) return null;

		$now = time();
		$cutoff_start = 0;
		$cutoff_end   = $now;

		if ( $time_range === '30d' ) {
			$cutoff_start = $now - ( 30 * DAY_IN_SECONDS );
		} elseif ( $time_range === '1y' ) {
			$cutoff_start = $now - ( 365 * DAY_IN_SECONDS );
		} elseif ( $time_range === 'range' ) {
			$start_date = isset( $token_data['mediaStartDate'] ) ? $token_data['mediaStartDate'] : '';
			$end_date   = isset( $token_data['mediaEndDate'] ) ? $token_data['mediaEndDate'] : '';
			if ( $start_date ) $cutoff_start = strtotime( $start_date );
			if ( $end_date )   $cutoff_end   = strtotime( $end_date ) + DAY_IN_SECONDS;
		}

		return array( 'start' => $cutoff_start, 'end' => $cutoff_end );
	}

	public function prepare_files( $request ) {
		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$type = sanitize_text_field( $request->get_param( 'type' ) );
		$allowed_types = array( 'media', 'themes', 'plugins', 'mu-plugins', 'languages', 'others' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Invalid file type.', array( 'status' => 400 ) );
		}

		$site_id       = $token_data['site_id'];
		$is_standalone = ! empty( $token_data['standalone'] );
		$token         = sanitize_text_field( $request->get_param( 'token' ) );
		$zip_path      = $this->get_tmp_zip_path( $token, $type );
		$manifest_path = $zip_path . '.manifest';

		$offset    = absint( $request->get_param( 'offset' ) ?: 0 );
		$is_resume = $offset > 0;
		$is_fresh  = ! empty( $request->get_param( 'fresh' ) );

		if ( file_exists( $zip_path ) && ! $is_resume ) {
			if ( $is_fresh ) {
				unlink( $zip_path );
				if ( file_exists( $manifest_path ) ) {
					unlink( $manifest_path );
				}
			} else {
				$zip = new ZipArchive();
				if ( $zip->open( $zip_path ) === true ) {
					$cached_count = $zip->numFiles;
					$zip->close();

					return rest_ensure_response( $this->encrypt_payload( array(
						'success'    => true,
						'type'       => $type,
						'file_count' => $cached_count,
						'zip_size'   => filesize( $zip_path ),
						'cached'     => true,
						'done'       => true,
					) ) );
				}
			}
		}

		$start_time = microtime( true );
		$max_execution_time = apply_filters( 'moving_castle_max_execution_time', 5 ); // 5 seconds default chunk limit

		// Generate manifest if it doesn't exist
		if ( ! file_exists( $manifest_path ) ) {
			$manifest_handle = fopen( $manifest_path, 'w' );
			$total_files     = 0;

			if ( $type === 'media' ) {
				$source_dir  = $this->resolve_uploads_dir( $site_id, $is_standalone );
				$time_filter = $this->filter_media_files( $source_dir, $token_data );

				if ( is_dir( $source_dir ) ) {
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file ) {
						if ( ! $file->isFile() ) continue;
						if ( $time_filter ) {
							$mtime = $file->getMTime();
							if ( $mtime < $time_filter['start'] || $mtime > $time_filter['end'] ) continue;
						}
						$relative = substr( $file->getPathname(), strlen( $source_dir ) + 1 );
						fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
						$total_files++;
					}
				}
			} elseif ( $type === 'themes' ) {
				$active_themes = $this->resolve_active_themes( $site_id, $is_standalone );
				foreach ( $active_themes as $theme_slug ) {
					$theme_dir = WP_CONTENT_DIR . '/themes/' . $theme_slug;
					if ( ! is_dir( $theme_dir ) ) continue;

					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file ) {
						if ( ! $file->isFile() ) continue;
						$relative = $theme_slug . '/' . substr( $file->getPathname(), strlen( $theme_dir ) + 1 );
						fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
						$total_files++;
					}
				}
			} elseif ( $type === 'plugins' ) {
				$active_plugins = $this->resolve_active_plugins( $site_id, $is_standalone );
				foreach ( $active_plugins as $plugin_slug ) {
					$plugin_dir = WP_CONTENT_DIR . '/plugins/' . $plugin_slug;
					if ( ! is_dir( $plugin_dir ) ) continue;

					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file ) {
						if ( ! $file->isFile() ) continue;
						$relative = $plugin_slug . '/' . substr( $file->getPathname(), strlen( $plugin_dir ) + 1 );
						fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
						$total_files++;
					}
				}
			} elseif ( $type === 'mu-plugins' ) {
				$source_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
				if ( is_dir( $source_dir ) ) {
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file ) {
						if ( ! $file->isFile() ) continue;
						$relative = substr( $file->getPathname(), strlen( $source_dir ) + 1 );
						fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
						$total_files++;
					}
				}
			} elseif ( $type === 'languages' ) {
				$source_dir = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages';
				if ( is_dir( $source_dir ) ) {
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
					);
					foreach ( $iterator as $file ) {
						if ( ! $file->isFile() ) continue;
						$relative = substr( $file->getPathname(), strlen( $source_dir ) + 1 );
						fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
						$total_files++;
					}
				}
			} elseif ( $type === 'others' ) {
				$others_dir = WP_CONTENT_DIR;
				$excludes = array(
					WP_CONTENT_DIR . '/plugins',
					WP_CONTENT_DIR . '/themes',
					WP_CONTENT_DIR . '/uploads',
					WP_CONTENT_DIR . '/mu-plugins',
					WP_CONTENT_DIR . '/languages',
					WP_CONTENT_DIR . '/upgrade',
					WP_CONTENT_DIR . '/cache',
				);

				if ( is_dir( $others_dir ) ) {
					$dir_iterator = new DirectoryIterator( $others_dir );
					foreach ( $dir_iterator as $fileinfo ) {
						if ( $fileinfo->isDot() ) continue;
						$path = $fileinfo->getPathname();
						
						$is_excluded = false;
						foreach ( $excludes as $exclude ) {
							if ( strpos( $path, $exclude ) === 0 ) {
								$is_excluded = true;
								break;
							}
						}
						if ( $is_excluded ) continue;

						if ( $fileinfo->isDir() ) {
							$iterator = new RecursiveIteratorIterator(
								new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
							);
							foreach ( $iterator as $file ) {
								if ( ! $file->isFile() ) continue;
								$relative = substr( $file->getPathname(), strlen( $others_dir ) + 1 );
								fputcsv( $manifest_handle, array( $file->getPathname(), $relative ) );
								$total_files++;
							}
						} else {
							$relative = substr( $fileinfo->getPathname(), strlen( $others_dir ) + 1 );
							fputcsv( $manifest_handle, array( $fileinfo->getPathname(), $relative ) );
							$total_files++;
						}
					}
				}
			}

			fclose( $manifest_handle );
		}

		// Count total files
		$total_files = 0;
		$manifest_handle = fopen( $manifest_path, 'r' );
		if ( $manifest_handle ) {
			while ( fgets( $manifest_handle ) !== false ) {
				$total_files++;
			}
			rewind( $manifest_handle );
		}

		// Prepare ZIP
		$zip = new ZipArchive();
		$zip_flags = $is_resume ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( $zip->open( $zip_path, $zip_flags ) !== true ) {
			if ( $manifest_handle ) fclose( $manifest_handle );
			return new WP_Error( 'zip_failed', 'Could not create or open ZIP archive.', array( 'status' => 500 ) );
		}

		$processed_in_this_chunk = 0;
		$done = true;
		$current_line = 0;

		if ( $manifest_handle ) {
			// Skip to offset
			while ( $current_line < $offset && ! feof( $manifest_handle ) ) {
				fgets( $manifest_handle );
				$current_line++;
			}

			// Process files
			while ( ( $row = fgetcsv( $manifest_handle ) ) !== false ) {
				if ( count( $row ) === 2 ) {
					$zip->addFile( $row[0], $row[1] );
					$processed_in_this_chunk++;
				}

				if ( microtime( true ) - $start_time >= $max_execution_time ) {
					$done = false;
					break;
				}
			}
			fclose( $manifest_handle );
		}

		$zip->close();
		$new_offset = $offset + $processed_in_this_chunk;

		// Clean up manifest if done
		if ( $done && file_exists( $manifest_path ) ) {
			unlink( $manifest_path );
		}

		return rest_ensure_response( $this->encrypt_payload( array(
			'success'    => true,
			'type'       => $type,
			'offset'     => $new_offset,
			'total'      => $total_files,
			'file_count' => $new_offset,
			'zip_size'   => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
			'done'       => $done,
		) ) );
	}

	public function download_files( $request ) {
		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$type     = sanitize_text_field( $request->get_param( 'type' ) );
		$token    = sanitize_text_field( $request->get_param( 'token' ) );
		$zip_path = $this->get_tmp_zip_path( $token, $type );

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'zip_missing', 'ZIP file not found. Run prepare first.', array( 'status' => 404 ) );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="mc-' . $type . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path );
		exit;
	}

	public function cleanup_files( $request ) {
		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$type          = sanitize_text_field( $request->get_param( 'type' ) );
		$token         = sanitize_text_field( $request->get_param( 'token' ) );
		$zip_path      = $this->get_tmp_zip_path( $token, $type );
		$manifest_path = $zip_path . '.manifest';
		$deleted       = false;

		if ( file_exists( $zip_path ) ) {
			$deleted = unlink( $zip_path );
		}
		if ( file_exists( $manifest_path ) ) {
			unlink( $manifest_path );
		}

		return rest_ensure_response( array(
			'success' => true,
			'deleted' => $deleted,
			'path'    => basename( $zip_path )
		) );
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

	private function recursive_unserialize_replace( $from, $to, $data, $serialised = false ) {
		try {
			if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				$_tmp = clone $data;
				foreach ( $data as $key => $value ) {
					$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_string( $data ) ) {
				$data = str_replace( $from, $to, $data );
			}

			if ( $serialised ) {
				return serialize( $data );
			}

		} catch ( Exception $e ) {
			// Fail gracefully by returning original
		}

		return $data;
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

			$origin_prefix = isset( $params['origin_prefix'] ) ? sanitize_text_field( $params['origin_prefix'] ) : '';
			$local_table   = $table;
			
			if ( $origin_prefix && strpos( $table, $origin_prefix ) === 0 ) {
				$local_table = $wpdb->prefix . substr( $table, strlen( $origin_prefix ) );
			}

			if ( $page === 0 ) {
				if ( ! empty( $body['create_schema'] ) && ! $is_dry ) {
					$create_sql = $body['create_schema'];
					if ( $origin_prefix && $local_table !== $table ) {
						$create_sql = str_replace( "`{$table}`", "`{$local_table}`", $create_sql );
					}
					$wpdb->query( "DROP TABLE IF EXISTS `{$local_table}`" );
					$wpdb->query( $create_sql );
				}
				$msg = $is_dry ? 'Schema parsed (dry run).' : 'Schema synced.';
				return rest_ensure_response( array( 'success' => true, 'message' => $msg, 'row_count' => 0 ) );
			}

			$rows = $body['data'];
			if ( empty( $rows ) ) {
				return rest_ensure_response( array( 'success' => true, 'message' => 'Table complete.', 'done' => true, 'row_count' => 0 ) );
			}

			if ( ! $is_dry ) {
				$source_url = untrailingslashit( $base_url );
				$dest_url   = untrailingslashit( get_site_url() );
				$needs_replace = ( $source_url !== $dest_url );

				foreach ( $rows as $row ) {
					if ( $needs_replace ) {
						foreach ( $row as $col => $val ) {
							$row[ $col ] = $this->recursive_unserialize_replace( $source_url, $dest_url, $val );
						}
					}
					$wpdb->replace( $local_table, $row );
				}
			}

			$row_count = count( $rows );
			return rest_ensure_response( array(
				'success'   => true,
				'message'   => ( $is_dry ? 'Simulated ' : 'Synced ' ) . $row_count . ' rows.',
				'row_count' => $row_count,
				'done'      => $row_count < 1000
			));
		}

		if ( $task === 'activate_extensions' ) {
			if ( $is_dry ) {
				return rest_ensure_response( array( 'success' => true, 'message' => 'Dry run: Theme and plugins would be activated.' ) );
			}
			
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';

			$plugins_to_activate = isset( $params['plugins'] ) ? (array) $params['plugins'] : array();
			$theme_to_activate   = isset( $params['theme'] ) ? sanitize_text_field( $params['theme'] ) : '';
			$site_id             = isset( $params['site_id'] ) ? absint( $params['site_id'] ) : 0;
			$is_network          = is_multisite() && empty( $site_id );

			$messages = array();

			if ( ! empty( $theme_to_activate ) ) {
				if ( $site_id > 1 ) {
					switch_to_blog( $site_id );
					switch_theme( $theme_to_activate );
					restore_current_blog();
				} else {
					switch_theme( $theme_to_activate );
				}
				$messages[] = "Theme '{$theme_to_activate}' activated.";
			}

			if ( ! empty( $plugins_to_activate ) ) {
				$activated_count = 0;
				if ( $site_id > 1 ) {
					switch_to_blog( $site_id );
				}

				$pending_plugins = $plugins_to_activate;
				$passes          = 0;
				$max_passes      = 3;

				while ( count( $pending_plugins ) > 0 && $passes < $max_passes ) {
					$passes++;
					$newly_activated = array();

					foreach ( $pending_plugins as $idx => $plugin ) {
						try {
							$result = activate_plugin( $plugin, '', $is_network, false );
							if ( ! is_wp_error( $result ) ) {
								$activated_count++;
								$newly_activated[] = $idx;
							}
						} catch ( \Throwable $e ) {
							error_log( "[Moving Castle] Pass {$passes}: Failed to activate plugin {$plugin}: " . $e->getMessage() );
						}
					}

					if ( empty( $newly_activated ) ) {
						break;
					}

					foreach ( $newly_activated as $idx ) {
						unset( $pending_plugins[ $idx ] );
					}
				}

				if ( $site_id > 1 ) {
					restore_current_blog();
				}

				$messages[] = "{$activated_count} plugins activated.";
			}

			return rest_ensure_response( array( 'success' => true, 'message' => implode( ' ', $messages ) ) );
		}

		$file_tasks = array(
			'pull_media'      => array( 'type' => 'media',      'dest' => wp_upload_dir()['basedir'],   'label' => 'Media' ),
			'pull_themes'     => array( 'type' => 'themes',     'dest' => WP_CONTENT_DIR . '/themes',   'label' => 'Themes' ),
			'pull_plugins'    => array( 'type' => 'plugins',    'dest' => WP_CONTENT_DIR . '/plugins',  'label' => 'Plugins' ),
			'pull_mu-plugins' => array( 'type' => 'mu-plugins', 'dest' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins', 'label' => 'MU Plugins' ),
			'pull_languages'  => array( 'type' => 'languages',  'dest' => defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages', 'label' => 'Languages' ),
			'pull_others'     => array( 'type' => 'others',     'dest' => WP_CONTENT_DIR, 'label' => 'Others' ),
		);

		$is_file_task = isset( $file_tasks[ $task ] );
		if ( $is_file_task ) {
			$ft = $file_tasks[ $task ];
			return $this->pull_files( $base_url, $token, $ft['type'], $ft['dest'], $ft['label'], $is_dry );
		}

		return new WP_Error( 'invalid_task', 'Invalid task specified.', array( 'status' => 400 ) );
	}

	private function pull_files( $base_url, $token, $type, $dest_dir, $label, $is_dry ) {
		$offset = 0;
		$done   = false;
		$prep_body = array();

		while ( ! $done ) {
			$prepare_url   = $base_url . '/wp-json/moving-castle/v1/files/prepare?token=' . $token . '&type=' . $type . '&offset=' . $offset;
			$prep_response = wp_remote_get( $prepare_url, array( 'timeout' => 300 ) );

			if ( is_wp_error( $prep_response ) ) {
				return new WP_Error( 'prepare_failed', 'Could not prepare ' . $label . ' ZIP: ' . $prep_response->get_error_message(), array( 'status' => 500 ) );
			}

			$prep_raw  = json_decode( wp_remote_retrieve_body( $prep_response ), true );
			$prep_body = $this->decrypt_payload( $prep_raw );

			if ( empty( $prep_body['success'] ) ) {
				return new WP_Error( 'prepare_failed', 'Source failed to create ' . $label . ' archive.', array( 'status' => 500 ) );
			}

			$done   = ! empty( $prep_body['done'] );
			$offset = isset( $prep_body['offset'] ) ? absint( $prep_body['offset'] ) : 0;
		}

		$file_count = $prep_body['total'] ?? ( $prep_body['file_count'] ?? 0 );
		$zip_size   = $prep_body['zip_size'];

		if ( $is_dry ) {
			return rest_ensure_response( array(
				'success'    => true,
				'message'    => 'Dry run: ' . $file_count . ' ' . strtolower( $label ) . ' files (' . size_format( $zip_size ) . ' compressed). ZIP cached for live run.',
				'file_count' => $file_count,
				'zip_size'   => $zip_size,
			));
		}

		$download_url = $base_url . '/wp-json/moving-castle/v1/files/download?token=' . $token . '&type=' . $type;
		$local_zip    = sys_get_temp_dir() . '/mc-import-' . $type . '-' . md5( $token ) . '.zip';

		$download_response = wp_remote_get( $download_url, array(
			'timeout'  => 600,
			'stream'   => true,
			'filename' => $local_zip,
		) );

		if ( is_wp_error( $download_response ) ) {
			$this->remote_cleanup( $base_url, $token, $type );
			return new WP_Error( 'download_failed', 'Could not download ' . $label . ' ZIP: ' . $download_response->get_error_message(), array( 'status' => 500 ) );
		}

		WP_Filesystem();
		$unzip_result = unzip_file( $local_zip, $dest_dir );

		if ( file_exists( $local_zip ) ) {
			unlink( $local_zip );
		}

		$this->remote_cleanup( $base_url, $token, $type );

		if ( is_wp_error( $unzip_result ) ) {
			return new WP_Error( 'extract_failed', 'Could not extract ' . $label . ' ZIP: ' . $unzip_result->get_error_message(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'message'    => $label . ' transfer complete. ' . $file_count . ' files (' . size_format( $zip_size ) . ').',
			'file_count' => $file_count,
			'zip_size'   => $zip_size,
		));
	}

	private function remote_cleanup( $base_url, $token, $type ) {
		wp_remote_post( $base_url . '/wp-json/moving-castle/v1/files/cleanup', array(
			'timeout' => 15,
			'body'    => wp_json_encode( array( 'token' => $token, 'type' => $type ) ),
			'headers' => array( 'Content-Type' => 'application/json' ),
		) );
	}

	public function cleanup_stale_zips() {
		$tmp_dir   = sys_get_temp_dir();
		$max_age   = 3600;
		$pattern   = $tmp_dir . '/mc-*.zip';
		$zip_files = glob( $pattern );

		if ( ! is_array( $zip_files ) ) return;

		$now     = time();
		$deleted = 0;

		foreach ( $zip_files as $file ) {
			$is_stale = ( $now - filemtime( $file ) ) > $max_age;
			if ( $is_stale ) {
				unlink( $file );
				$deleted++;
			}
		}

		if ( $deleted > 0 ) {
			error_log( '[Moving Castle] Cleaned up ' . $deleted . ' stale ZIP file(s) from tmp.' );
		}
	}
}
