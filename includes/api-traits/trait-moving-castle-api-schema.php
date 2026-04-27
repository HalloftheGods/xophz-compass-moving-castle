<?php

trait Trait_Moving_Castle_API_Schema {

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
			'success'     => true,
			'mode'        => $is_standalone ? 'standalone' : 'multisite',
			'prefix'      => $prefix,
			'scope'       => $scope,
			'api_version' => 2,
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
}
