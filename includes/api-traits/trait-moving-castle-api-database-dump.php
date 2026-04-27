<?php

trait Trait_Moving_Castle_API_Database_Dump {

	public function dump_database( $request ) {
		$token_data = $this->validate_token( $request );
		if ( ! $token_data ) {
			return new WP_Error( 'invalid_token', 'Invalid or expired token.', array( 'status' => 403 ) );
		}

		$site_id       = $token_data['site_id'];
		$is_standalone = ! empty( $token_data['standalone'] );
		$scope         = isset( $token_data['scope'] ) ? $token_data['scope'] : array( 'database' );
		$prefix        = $this->resolve_prefix( $site_id );
		$source_url    = untrailingslashit( get_site_url() );
		$dest_url      = untrailingslashit( $request->get_param('dest_url') ? esc_url_raw( urldecode( $request->get_param('dest_url') ) ) : $source_url );
		$needs_replace = ( $source_url !== $dest_url );

		$token    = sanitize_text_field( $request->get_param( 'token' ) );
		$zip_path = sys_get_temp_dir() . '/mc-database-' . md5( $token ) . '.zip';
		$sql_path = sys_get_temp_dir() . '/mc-database-' . md5( $token ) . '.sql';

		$offset    = absint( $request->get_param( 'offset' ) ?: 0 );
		$is_resume = $offset > 0;
		$is_fresh  = ! empty( $request->get_param( 'fresh' ) );

		if ( file_exists( $zip_path ) && ! $is_resume ) {
			if ( $is_fresh ) {
				unlink( $zip_path );
				if ( file_exists( $sql_path ) ) unlink( $sql_path );
			} else {
				return rest_ensure_response( $this->encrypt_payload( array(
					'success'  => true,
					'type'     => 'database',
					'zip_size' => filesize( $zip_path ),
					'cached'   => true,
					'done'     => true,
				) ) );
			}
		}

		global $wpdb;
		$tables_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
				DB_NAME,
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_N
		);

		$tables_to_dump = array();
		foreach ( $tables_raw as $row ) {
			$table_name = $row[0];
			if ( ! in_array( 'includeUsers', $scope, true ) ) {
				if ( $table_name === $wpdb->base_prefix . 'users' || $table_name === $wpdb->base_prefix . 'usermeta' ) {
					continue;
				}
			}
			if ( ! in_array( 'includeOptions', $scope, true ) ) {
				if ( $table_name === $prefix . 'options' ) {
					continue;
				}
			}
			$tables_to_dump[] = $table_name;
		}

		$start_time = microtime( true );
		$max_time   = apply_filters( 'moving_castle_max_execution_time', 5 );
		$done       = true;

		$handle = fopen( $sql_path, $is_resume ? 'a' : 'w' );
		if ( ! $handle ) {
			return new WP_Error( 'file_error', 'Cannot write to temp directory.', array( 'status' => 500 ) );
		}

		// Write header if fresh
		if ( ! $is_resume ) {
			fwrite( $handle, "-- Moving Castle Database Dump\n" );
			fwrite( $handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n" );
			fwrite( $handle, "SET NAMES utf8mb4;\n" );
			fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );
		}

		$current_table_idx = isset( $token_data['dump_table_idx'] ) ? absint( $token_data['dump_table_idx'] ) : 0;
		$current_row_idx   = isset( $token_data['dump_row_idx'] ) ? absint( $token_data['dump_row_idx'] ) : 0;

		if ( $is_fresh || ! $is_resume ) {
			$current_table_idx = 0;
			$current_row_idx = 0;
		}

		while ( $current_table_idx < count( $tables_to_dump ) ) {
			$table = $tables_to_dump[ $current_table_idx ];

			if ( $current_row_idx === 0 ) {
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
				if ( isset( $create['Create Table'] ) ) {
					fwrite( $handle, $create['Create Table'] . ";\n\n" );
				}
			}

			$limit  = 1000;
			$offset_db = $current_row_idx;

			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset_db}", ARRAY_A );
			
			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$vals = array();
					foreach ( $row as $val ) {
						if ( $val === null ) {
							$vals[] = 'NULL';
						} else {
							if ( $needs_replace ) {
								$val = $this->recursive_unserialize_replace( $source_url, $dest_url, $val );
							}
							$val = str_replace( array( "\r", "\n" ), array( '\r', '\n' ), esc_sql( $val ) );
							$vals[] = "'" . $val . "'";
						}
					}
					fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $vals ) . ");\n" );
				}
				$current_row_idx += count( $rows );
			} else {
				// Table complete
				fwrite( $handle, "\n\n" );
				$current_table_idx++;
				$current_row_idx = 0;
			}

			if ( microtime( true ) - $start_time >= $max_time ) {
				$done = false;
				break;
			}
		}

		if ( $done ) {
			fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
		}
		fclose( $handle );

		if ( $done ) {
			$zip = new ZipArchive();
			if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
				$zip->addFile( $sql_path, 'database.sql' );
				$zip->close();
			}
			if ( file_exists( $sql_path ) ) unlink( $sql_path );
			
			// Clear state
			unset( $token_data['dump_table_idx'] );
			unset( $token_data['dump_row_idx'] );
			set_transient( 'mc_migration_' . $token, wp_json_encode( $token_data ), 3600 );
		} else {
			// Save state
			$token_data['dump_table_idx'] = $current_table_idx;
			$token_data['dump_row_idx']   = $current_row_idx;
			set_transient( 'mc_migration_' . $token, wp_json_encode( $token_data ), 3600 );
		}

		$new_offset = $offset + 1; // Just use as a counter

		return rest_ensure_response( $this->encrypt_payload( array(
			'success'    => true,
			'type'       => 'database',
			'offset'     => $new_offset,
			'total'      => count( $tables_to_dump ),
			'done'       => $done,
			'zip_size'   => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
		) ) );
	}
}
