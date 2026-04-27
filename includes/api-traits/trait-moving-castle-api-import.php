<?php

trait Trait_Moving_Castle_API_Import {

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

		if ( $task === 'pull_database' ) {
			$dest_url = isset( $params['dest_url'] ) ? sanitize_text_field( $params['dest_url'] ) : get_site_url();
			return $this->pull_database( $base_url, $token, $is_dry, $dest_url );
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

	private function pull_database( $base_url, $token, $is_dry, $dest_url ) {
		set_time_limit( 0 );
		$dest_dir = sys_get_temp_dir() . '/mc-db-extract-' . md5( $token );
		if ( ! is_dir( $dest_dir ) ) wp_mkdir_p( $dest_dir );

		$result = $this->pull_files( $base_url, $token, 'database', $dest_dir, 'Database', $is_dry, array( 'dest_url' => urlencode( $dest_url ) ) );
		if ( is_wp_error( $result ) || $is_dry ) {
			return $result;
		}

		$sql_file = $dest_dir . '/database.sql';
		if ( ! file_exists( $sql_file ) ) {
			return new WP_Error( 'sql_missing', 'Database SQL file not found in archive.', array( 'status' => 500 ) );
		}

		global $wpdb;
		$current_user_id   = get_current_user_id();
		$current_user_meta = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'", $current_user_id ) );

		$handle = fopen( $sql_file, 'r' );
		$query = '';
		while ( ( $line = fgets( $handle ) ) !== false ) {
			if ( trim( $line ) === '' || strpos( ltrim( $line ), '--' ) === 0 ) continue;
			$query .= $line;
			if ( substr( rtrim( $query ), -1 ) === ';' ) {
				$wpdb->query( $query );
				$query = '';
			}
		}
		fclose( $handle );
		unlink( $sql_file );
		rmdir( $dest_dir );

		if ( $current_user_id && $current_user_meta ) {
			// Restore the user's session token to ensure they aren't logged out
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'", $current_user_id ) );
			if ( $exists ) {
				$wpdb->update( $wpdb->usermeta, array( 'meta_value' => $current_user_meta->meta_value ), array( 'user_id' => $current_user_id, 'meta_key' => 'session_tokens' ) );
			} else {
				$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $current_user_id, 'meta_key' => 'session_tokens', 'meta_value' => $current_user_meta->meta_value ) );
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Database imported successfully.'
		));
	}

	private function pull_files( $base_url, $token, $type, $dest_dir, $label, $is_dry, $extra_params = array() ) {
		$offset = 0;
		$done   = false;
		$prep_body = array();

		$extra_query = '';
		if ( ! empty( $extra_params ) ) {
			foreach ( $extra_params as $k => $v ) {
				$extra_query .= '&' . $k . '=' . $v;
			}
		}

		while ( ! $done ) {
			$fresh_param = ( $offset === 0 ) ? '&fresh=1' : '';
			$prepare_url   = $base_url . '/wp-json/moving-castle/v1/files/prepare?token=' . $token . '&type=' . $type . '&offset=' . $offset . $fresh_param . $extra_query;
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

		require_once ABSPATH . 'wp-admin/includes/file.php';
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
