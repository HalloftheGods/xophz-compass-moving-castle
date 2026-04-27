<?php

trait Trait_Moving_Castle_API_Environment {

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
}
