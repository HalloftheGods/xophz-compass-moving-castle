<?php

trait Trait_Moving_Castle_API_Files {

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
}
