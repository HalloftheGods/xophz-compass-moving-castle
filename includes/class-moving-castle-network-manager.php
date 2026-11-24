<?php
/**
 * Core network and site lifecycle operations for Moving Castle.
 *
 * Network creation/deletion logic follows the WP Multi-Network approach (GPLv2+)
 * without requiring WP Multi-Network to be installed.
 */
class Moving_Castle_Network_Manager {

	/**
	 * Sitemeta keys that must never be copied from an existing network.
	 *
	 * @var string[]
	 */
	const SKIP_META_KEYS = array(
		'site_admins',
		'siteurl',
		'site_name',
		'admin_email',
		'main_site',
		'blog_count',
		'user_count',
	);

	/**
	 * Normalize a domain string (lowercase, strip scheme/port/trailing dot).
	 */
	public static function normalize_domain( string $raw ): string {
		$clean = strtolower( trim( $raw ) );
		$clean = preg_replace( '#^https?://#i', '', $clean );
		$clean = preg_replace( '#/.*$#', '', (string) $clean );
		$clean = preg_replace( '/:\d+$/', '', (string) $clean );
		return rtrim( (string) $clean, '.' );
	}

	/**
	 * Normalize a network or site path (leading and trailing slash).
	 */
	public static function normalize_path( string $raw ): string {
		$clean = trim( $raw );
		if ( '' === $clean || '/' === $clean ) {
			return '/';
		}
		return '/' . trim( $clean, '/' ) . '/';
	}

	/**
	 * Create a new network and its main site.
	 *
	 * @param string   $domain
	 * @param string   $path
	 * @param string   $site_name
	 * @param int|null $copy_from Network ID to copy sitemeta from, or null for core defaults.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_network( string $domain, string $path, string $site_name, ?int $copy_from = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled on this installation.', array( 'status' => 400 ) );
		}

		global $wpdb;
		$clean_domain = self::normalize_domain( $domain );
		$clean_path   = self::normalize_path( $path );
		$clean_name   = sanitize_text_field( $site_name );

		if ( '' === $clean_domain ) {
			return new WP_Error( 'castle_invalid_domain', 'A valid domain is required.', array( 'status' => 400 ) );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->site} WHERE domain = %s AND path = %s LIMIT 1", $clean_domain, $clean_path )
		);
		if ( $existing ) {
			return new WP_Error( 'castle_network_exists', 'A network with that domain and path already exists.', array( 'status' => 409 ) );
		}

		$inserted = $wpdb->insert(
			$wpdb->site,
			array(
				'domain' => $clean_domain,
				'path'   => $clean_path,
			),
			array( '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'castle_db_error', 'Failed to insert network row.', array( 'status' => 500 ) );
		}

		$network_id = (int) $wpdb->insert_id;
		$current_user = wp_get_current_user();
		$admin_email  = $current_user && ! empty( $current_user->user_email ) ? $current_user->user_email : get_site_option( 'admin_email', '' );
		$admin_login  = $current_user && ! empty( $current_user->user_login ) ? $current_user->user_login : 'admin';

		try {
			if ( null !== $copy_from && $copy_from > 0 ) {
				self::copy_network_meta( $copy_from, $network_id );
			} else {
				if ( ! function_exists( 'populate_network_meta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/schema.php';
				}
				if ( function_exists( 'populate_network_meta' ) ) {
					populate_network_meta( $network_id );
				}
			}

			$scheme = is_ssl() ? 'https://' : 'http://';
			update_network_option( $network_id, 'site_name', $clean_name ? $clean_name : $clean_domain );
			update_network_option( $network_id, 'siteurl', $scheme . $clean_domain . $clean_path );
			update_network_option( $network_id, 'admin_email', $admin_email );
			update_network_option( $network_id, 'site_admins', array( $admin_login ) );

			$main_site_id = wp_insert_site(
				array(
					'domain'     => $clean_domain,
					'path'       => $clean_path,
					'network_id' => $network_id,
					'title'      => $clean_name ? $clean_name : $clean_domain,
					'user_id'    => get_current_user_id(),
				)
			);

			if ( is_wp_error( $main_site_id ) ) {
				self::rollback_network( $network_id, 0 );
				return $main_site_id;
			}

			update_network_option( $network_id, 'main_site', (int) $main_site_id );
			clean_network_cache( $network_id );

			return array(
				'id'           => $network_id,
				'name'         => $clean_name ? $clean_name : $clean_domain,
				'domain'       => $clean_domain,
				'path'         => $clean_path,
				'main_site_id' => (int) $main_site_id,
				'site_count'   => 1,
				'admin_email'  => $admin_email,
				'status'       => 'Active',
			);
		} catch ( Exception $e ) {
			self::rollback_network( $network_id, 0 );
			return new WP_Error( 'castle_network_create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update network display name or admin email.
	 *
	 * @param int                  $network_id
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_network( int $network_id, array $data ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}

		$network = get_network( $network_id );
		if ( ! $network ) {
			return new WP_Error( 'castle_not_found', 'Network not found.', array( 'status' => 404 ) );
		}

		if ( isset( $data['site_name'] ) && '' !== trim( (string) $data['site_name'] ) ) {
			update_network_option( $network_id, 'site_name', sanitize_text_field( (string) $data['site_name'] ) );
		}
		if ( isset( $data['admin_email'] ) && is_email( (string) $data['admin_email'] ) ) {
			update_network_option( $network_id, 'admin_email', sanitize_email( (string) $data['admin_email'] ) );
		}

		clean_network_cache( $network_id );

		return array(
			'id'          => $network_id,
			'name'        => (string) get_network_option( $network_id, 'site_name', $network->domain ),
			'domain'      => $network->domain,
			'path'        => $network->path,
			'admin_email' => (string) get_network_option( $network_id, 'admin_email', '' ),
		);
	}

	/**
	 * Move all sites from a network to a destination network, then delete the empty network.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_network( int $network_id, int $move_to ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}
		if ( get_current_network_id() === $network_id ) {
			return new WP_Error( 'castle_network_in_use', 'Cannot delete the currently active network.', array( 'status' => 409 ) );
		}
		if ( $move_to <= 0 || $move_to === $network_id || ! get_network( $move_to ) ) {
			return new WP_Error( 'castle_invalid_destination', 'A valid destination network is required.', array( 'status' => 400 ) );
		}
		if ( ! get_network( $network_id ) ) {
			return new WP_Error( 'castle_not_found', 'Network not found.', array( 'status' => 404 ) );
		}

		$sites = get_sites( array( 'network_id' => $network_id, 'number' => 0 ) );
		$moved = 0;

		foreach ( $sites as $site ) {
			$res = self::move_site_internal( (int) $site->blog_id, $move_to );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$moved++;
		}

		global $wpdb;
		$wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $network_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->site, array( 'id' => $network_id ), array( '%d' ) );

		wp_update_network_site_counts( $move_to );
		clean_network_cache( array( $network_id, $move_to ) );

		return array(
			'deleted_network_id' => $network_id,
			'moved_sites'        => $moved,
			'destination_id'     => $move_to,
		);
	}

	/**
	 * Create a new site inside a network.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_site( int $network_id, string $domain, string $path, string $title, int $admin_user_id = 0 ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}
		if ( ! get_network( $network_id ) ) {
			return new WP_Error( 'castle_not_found', 'Target network not found.', array( 'status' => 404 ) );
		}

		$clean_domain = self::normalize_domain( $domain );
		$clean_path   = self::normalize_path( $path );
		$clean_title  = sanitize_text_field( $title );
		$owner_id     = $admin_user_id > 0 ? $admin_user_id : get_current_user_id();

		if ( '' === $clean_domain ) {
			return new WP_Error( 'castle_invalid_domain', 'A valid domain is required.', array( 'status' => 400 ) );
		}

		$collision = get_sites(
			array(
				'network_id' => $network_id,
				'domain'     => $clean_domain,
				'path'       => $clean_path,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( ! empty( $collision ) ) {
			return new WP_Error( 'castle_site_collision', 'A site with that domain and path already exists on this network.', array( 'status' => 409 ) );
		}

		$site_id = wp_insert_site(
			array(
				'domain'     => $clean_domain,
				'path'       => $clean_path,
				'network_id' => $network_id,
				'title'      => $clean_title ? $clean_title : $clean_domain,
				'user_id'    => $owner_id,
			)
		);

		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		if ( $owner_id > 0 ) {
			add_user_to_blog( (int) $site_id, $owner_id, 'administrator' );
		}

		wp_update_network_site_counts( $network_id );

		return array(
			'id'         => (int) $site_id,
			'network_id' => $network_id,
			'name'       => $clean_title ? $clean_title : $clean_domain,
			'domain'     => $clean_domain . $clean_path,
			'status'     => 'active',
		);
	}

	/**
	 * Update site attributes (archived, title, public).
	 *
	 * @param int                  $site_id
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_site( int $site_id, array $data ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}

		$site = get_site( $site_id );
		if ( ! $site ) {
			return new WP_Error( 'castle_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		$updates = array();
		if ( array_key_exists( 'archived', $data ) ) {
			$updates['archived'] = $data['archived'] ? 1 : 0;
		}
		if ( array_key_exists( 'public', $data ) ) {
			$updates['public'] = $data['public'] ? 1 : 0;
		}
		if ( ! empty( $updates ) ) {
			$res = wp_update_site( $site_id, $updates );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $data['title'] ) && '' !== trim( (string) $data['title'] ) ) {
			update_blog_option( $site_id, 'blogname', sanitize_text_field( (string) $data['title'] ) );
		}

		clean_blog_cache( $site_id );
		$fresh = get_site( $site_id );

		return array(
			'id'         => $site_id,
			'network_id' => (int) $fresh->site_id,
			'name'       => (string) get_blog_option( $site_id, 'blogname', $fresh->domain ),
			'domain'     => $fresh->domain . $fresh->path,
			'archived'   => (bool) $fresh->archived,
			'public'     => (bool) $fresh->public,
			'status'     => self::resolve_site_status( $fresh ),
		);
	}

	/**
	 * Move a non-main site to another network.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move_site( int $site_id, int $target_network_id ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}

		$site = get_site( $site_id );
		if ( ! $site ) {
			return new WP_Error( 'castle_not_found', 'Site not found.', array( 'status' => 404 ) );
		}
		if ( is_main_site( $site_id, (int) $site->site_id ) ) {
			return new WP_Error( 'castle_main_site', 'Cannot move a network main site on its own.', array( 'status' => 409 ) );
		}

		return self::move_site_internal( $site_id, $target_network_id );
	}

	/**
	 * Permanently delete a non-main site after domain confirmation.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_site( int $site_id, string $confirm_domain ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'castle_not_multisite', 'Multisite is not enabled.', array( 'status' => 400 ) );
		}

		$site = get_site( $site_id );
		if ( ! $site ) {
			return new WP_Error( 'castle_not_found', 'Site not found.', array( 'status' => 404 ) );
		}
		if ( is_main_site( $site_id, (int) $site->site_id ) ) {
			return new WP_Error( 'castle_main_site', 'Cannot delete a network main site.', array( 'status' => 409 ) );
		}

		$expected = strtolower( trim( $site->domain ) );
		$provided = self::normalize_domain( $confirm_domain );
		if ( $provided !== $expected ) {
			return new WP_Error( 'castle_confirm_mismatch', 'Confirmation domain does not match site domain.', array( 'status' => 400 ) );
		}

		$network_id = (int) $site->site_id;
		$deleted    = wp_delete_site( $site_id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		wp_update_network_site_counts( $network_id );
		return array(
			'deleted_site_id' => $site_id,
			'domain'          => $site->domain,
		);
	}

	/**
	 * Resolve human-readable status from a WP_Site object.
	 */
	public static function resolve_site_status( $site ): string {
		if ( ! empty( $site->deleted ) ) {
			return 'deleted';
		}
		if ( ! empty( $site->spam ) ) {
			return 'spam';
		}
		if ( ! empty( $site->archived ) ) {
			return 'archived';
		}
		return 'active';
	}

	/**
	 * Internal site move shared by move_site() and delete_network().
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function move_site_internal( int $site_id, int $target_network_id ) {
		$site = get_site( $site_id );
		if ( ! $site ) {
			return new WP_Error( 'castle_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		$source_network_id = (int) $site->site_id;
		if ( $target_network_id <= 0 || $target_network_id === $source_network_id || ! get_network( $target_network_id ) ) {
			return new WP_Error( 'castle_invalid_destination', 'Invalid destination network.', array( 'status' => 400 ) );
		}

		$collision = get_sites(
			array(
				'network_id' => $target_network_id,
				'domain'     => $site->domain,
				'path'       => $site->path,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( ! empty( $collision ) ) {
			return new WP_Error( 'castle_site_collision', 'Destination network already has a site at ' . $site->domain . $site->path, array( 'status' => 409 ) );
		}

		$updated = wp_update_site( $site_id, array( 'network_id' => $target_network_id ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		wp_update_network_site_counts( $source_network_id );
		wp_update_network_site_counts( $target_network_id );
		clean_blog_cache( $site_id );
		clean_network_cache( array( $source_network_id, $target_network_id ) );

		return array(
			'site_id'           => $site_id,
			'from_network_id'   => $source_network_id,
			'target_network_id' => $target_network_id,
		);
	}

	/**
	 * Copy sitemeta rows from an existing network to a new network.
	 */
	private static function copy_network_meta( int $from_network_id, int $to_network_id ): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d", $from_network_id ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( '' === $key || in_array( $key, self::SKIP_META_KEYS, true ) || 0 === strpos( $key, '_site_transient_' ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->sitemeta,
				array(
					'site_id'    => $to_network_id,
					'meta_key'   => $key,
					'meta_value' => $row['meta_value'],
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Clean up partial rows if network creation fails midway.
	 */
	private static function rollback_network( int $network_id, int $main_site_id ): void {
		global $wpdb;
		if ( $main_site_id > 0 ) {
			wp_delete_site( $main_site_id );
		}
		$wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $network_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->site, array( 'id' => $network_id ), array( '%d' ) );
		clean_network_cache( $network_id );
	}
}
