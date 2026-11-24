<?php

class Xophz_Compass_Moving_Castle_Magic_Door {

	const OPTION_KEY = 'mc_magic_doors';

	public function register_routes() {
		register_rest_route( 'moving-castle/v1', '/magic-door/themes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_themes' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/magic-door/doors', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_doors' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_door' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/magic-door/doors/(?P<id>[a-zA-Z0-9_-]+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_door' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_door' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/magic-door/doors/(?P<id>[a-zA-Z0-9_-]+)/toggle', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_door' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/magic-door/sunrise-status', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sunrise_status' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_network' ) || current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/magic-door/import-legacy-domains', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_legacy_domains' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_network' );
				}
			)
		));
	}

	public function get_themes( $request ) {
		$all_themes = wp_get_themes();
		$active_stylesheet = get_stylesheet();
		$themes = array();

		foreach ( $all_themes as $slug => $theme ) {
			$screenshot = $theme->get_screenshot();
			$themes[] = array(
				'slug'       => $slug,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'author'     => $theme->get( 'Author' ),
				'screenshot' => $screenshot ? $screenshot : '',
				'active'     => ( $slug === $active_stylesheet ),
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'themes'  => $themes
		));
	}

	private function get_doors_data() {
		$raw = get_option( self::OPTION_KEY, '[]' );
		$doors = json_decode( $raw, true );
		return is_array( $doors ) ? $doors : array();
	}

	private function save_doors_data( $doors ) {
		update_option( self::OPTION_KEY, wp_json_encode( $doors ), false );
	}

	public function get_doors( $request ) {
		return rest_ensure_response( array(
			'success' => true,
			'doors'   => $this->get_doors_data()
		));
	}

	public function save_door( $request ) {
		$params = $request->get_json_params();
		$doors  = $this->get_doors_data();

		$new_door = array(
			'id'          => sanitize_key( $params['id'] ?? wp_generate_password( 12, false ) ),
			'label'       => sanitize_text_field( $params['label'] ?? '' ),
			'triggerType' => sanitize_text_field( $params['triggerType'] ?? 'domain' ),
			'trigger'     => sanitize_text_field( $params['trigger'] ?? '' ),
			'themeSlug'   => sanitize_text_field( $params['themeSlug'] ?? '' ),
			'themeName'   => sanitize_text_field( $params['themeName'] ?? '' ),
			'color'       => sanitize_text_field( $params['color'] ?? 'purple' ),
			'active'      => false,
		);

		$is_valid = ! empty( $new_door['label'] ) && ! empty( $new_door['trigger'] ) && ! empty( $new_door['themeSlug'] );
		if ( ! $is_valid ) {
			return new WP_Error( 'invalid_door', 'Label, trigger, and theme are required.', array( 'status' => 400 ) );
		}

		$theme_exists = wp_get_theme( $new_door['themeSlug'] )->exists();
		if ( ! $theme_exists ) {
			return new WP_Error( 'invalid_theme', 'Theme does not exist on this installation.', array( 'status' => 400 ) );
		}

		$doors[] = $new_door;
		$this->save_doors_data( $doors );

		return rest_ensure_response( array(
			'success' => true,
			'door'    => $new_door,
			'doors'   => $doors
		));
	}

	public function update_door( $request ) {
		$door_id = sanitize_key( $request->get_param( 'id' ) );
		$params  = $request->get_json_params();
		$doors   = $this->get_doors_data();

		$idx   = array_search( $door_id, array_column( $doors, 'id' ) );
		$found = $idx !== false;

		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Door not found.', array( 'status' => 404 ) );
		}

		$allowed_fields = array(
			'label',
			'triggerType',
			'trigger',
			'themeSlug',
			'themeName',
			'color',
			'siteTitle',
			'siteTagline',
			'seoMode',
			'experimentId',
			'targetBlogId',
			'frontPageId',
		);
		foreach ( $allowed_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$doors[ $idx ][ $field ] = sanitize_text_field( $params[ $field ] );
			}
		}

		if ( isset( $params['themeSlug'] ) ) {
			$theme_exists = wp_get_theme( $params['themeSlug'] )->exists();
			if ( ! $theme_exists ) {
				return new WP_Error( 'invalid_theme', 'Theme does not exist on this installation.', array( 'status' => 400 ) );
			}
		}

		$this->save_doors_data( $doors );

		return rest_ensure_response( array(
			'success' => true,
			'door'    => $doors[ $idx ],
			'doors'   => $doors
		));
	}

	public function delete_door( $request ) {
		$door_id = sanitize_key( $request->get_param( 'id' ) );
		$doors   = $this->get_doors_data();

		$filtered = array_values( array_filter( $doors, function( $d ) use ( $door_id ) {
			return $d['id'] !== $door_id;
		}));

		$was_deleted = count( $filtered ) < count( $doors );
		if ( ! $was_deleted ) {
			return new WP_Error( 'not_found', 'Door not found.', array( 'status' => 404 ) );
		}

		$this->save_doors_data( $filtered );

		return rest_ensure_response( array(
			'success' => true,
			'doors'   => $filtered
		));
	}

	public function toggle_door( $request ) {
		$door_id = sanitize_key( $request->get_param( 'id' ) );
		$doors   = $this->get_doors_data();

		$idx   = array_search( $door_id, array_column( $doors, 'id' ) );
		$found = $idx !== false;

		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Door not found.', array( 'status' => 404 ) );
		}

		$doors[ $idx ]['active'] = ! $doors[ $idx ]['active'];
		$this->save_doors_data( $doors );

		return rest_ensure_response( array(
			'success' => true,
			'door'    => $doors[ $idx ],
			'doors'   => $doors
		));
	}

	public function apply_theme_override() {
		$doors = $this->get_doors_data();
		if ( empty( $doors ) ) return;

		$active_doors = array_filter( $doors, function( $d ) {
			return ! empty( $d['active'] );
		});

		if ( empty( $active_doors ) ) return;

		$matched_door = null;

		foreach ( $active_doors as $door ) {
			if ( $this->evaluate_trigger( $door ) ) {
				$matched_door = $door;
				break;
			}
		}

		if ( ! $matched_door ) return;

		if ( ! defined( 'MC_ACTIVE_DOOR_ID' ) ) {
			define( 'MC_ACTIVE_DOOR_ID', (string) ( $matched_door['id'] ?? '' ) );
		}
		do_action( 'moving_castle_door_matched', $matched_door );

		$matched_theme = (string) ( $matched_door['themeSlug'] ?? '' );
		if ( '' !== $matched_theme ) {
			$theme = wp_get_theme( $matched_theme );
			if ( $theme->exists() ) {
				$override_template = function() use ( $matched_theme ) {
					return $matched_theme;
				};
				add_filter( 'template', $override_template );
				add_filter( 'stylesheet', $override_template );
			}
		}

		// Brand identity overrides per Magic Door
		if ( ! empty( $matched_door['siteTitle'] ) ) {
			$door_title = (string) $matched_door['siteTitle'];
			add_filter( 'option_blogname', function() use ( $door_title ) { return $door_title; } );
		}
		if ( ! empty( $matched_door['siteTagline'] ) ) {
			$door_tagline = (string) $matched_door['siteTagline'];
			add_filter( 'option_blogdescription', function() use ( $door_tagline ) { return $door_tagline; } );
		}
		if ( ! empty( $matched_door['frontPageId'] ) ) {
			$front_id = (int) $matched_door['frontPageId'];
			add_filter( 'option_show_on_front', function() { return 'page'; } );
			add_filter( 'option_page_on_front', function() use ( $front_id ) { return $front_id; } );
		}

		// Overrides for domain mapping & SEO canonical safety
		if ( in_array( $matched_door['triggerType'], array( 'domain', 'subdomain' ), true ) ) {
			$primary_home = get_option( 'home' );
			$protocol     = is_ssl() ? 'https://' : 'http://';
			$mapped_url   = $protocol . $_SERVER['HTTP_HOST'];

			$override_url = function() use ( $mapped_url ) {
				return $mapped_url;
			};

			add_filter( 'option_home', $override_url );
			add_filter( 'option_siteurl', $override_url );

			// Prevent WP from redirecting back to the original domain
			remove_action( 'template_redirect', 'redirect_canonical' );

			$seo_mode = (string) ( $matched_door['seoMode'] ?? 'canonical_primary' );
			if ( 'noindex' === $seo_mode ) {
				add_action( 'wp_head', function() {
					echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
				}, 1 );
			} elseif ( 'canonical_primary' === $seo_mode && ! empty( $primary_home ) ) {
				$req_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
				$canon   = rtrim( $primary_home, '/' ) . $req_uri;
				remove_action( 'wp_head', 'rel_canonical' );
				add_action( 'wp_head', function() use ( $canon ) {
					echo '<link rel="canonical" href="' . esc_url( $canon ) . '" />' . "\n";
				}, 1 );
			}
		}
	}

	/**
	 * Report sunrise.php status and count of legacy wp_domain_mapping rows.
	 */
	public function get_sunrise_status() {
		global $wpdb;
		$sunrise_path = WP_CONTENT_DIR . '/sunrise.php';
		$status       = 'missing';
		if ( file_exists( $sunrise_path ) ) {
			$contents = (string) file_get_contents( $sunrise_path );
			$status   = false !== strpos( $contents, 'MOVING_CASTLE_SUNRISE' ) ? 'managed' : 'third_party';
		}

		$legacy_table = $wpdb->base_prefix . 'domain_mapping';
		$legacy_count = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) ) === $legacy_table ) {
			$legacy_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy_table}" );
		}

		return rest_ensure_response( array(
			'success'        => true,
			'sunrise_status' => $status,
			'sunrise_active' => defined( 'SUNRISE' ) && SUNRISE,
			'legacy_domains' => $legacy_count,
		));
	}

	/**
	 * Import existing rows from wp_domain_mapping into Moving Castle Magic Doors.
	 */
	public function import_legacy_domains() {
		global $wpdb;
		$legacy_table = $wpdb->base_prefix . 'domain_mapping';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) ) !== $legacy_table ) {
			return rest_ensure_response( array( 'success' => true, 'imported' => 0, 'doors' => $this->get_doors_data() ) );
		}

		$rows     = $wpdb->get_results( "SELECT blog_id, domain, active FROM {$legacy_table} ORDER BY blog_id ASC", ARRAY_A );
		$doors    = $this->get_doors_data();
		$existing = array();
		foreach ( $doors as $d ) {
			$existing[ strtolower( (string) ( $d['trigger'] ?? '' ) ) ] = true;
		}

		$imported = 0;
		foreach ( (array) $rows as $row ) {
			$domain = strtolower( trim( (string) ( $row['domain'] ?? '' ) ) );
			if ( '' === $domain || isset( $existing[ $domain ] ) ) {
				continue;
			}
			$doors[] = array(
				'id'           => 'door_' . substr( md5( $domain ), 0, 10 ),
				'label'        => $domain,
				'triggerType'  => 'domain',
				'trigger'      => $domain,
				'themeSlug'    => '',
				'themeName'    => 'Site Default',
				'color'        => 'cyan',
				'seoMode'      => 'canonical_primary',
				'targetBlogId' => (int) ( $row['blog_id'] ?? 1 ),
				'active'       => ! empty( $row['active'] ),
			);
			$existing[ $domain ] = true;
			$imported++;
		}

		if ( $imported > 0 ) {
			$this->save_doors_data( $doors );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'imported' => $imported,
			'doors'    => $doors,
		));
	}

	private function evaluate_trigger( $door ) {
		$trigger_type = $door['triggerType'] ?? '';
		$trigger      = $door['trigger'] ?? '';
		$host         = $_SERVER['HTTP_HOST'] ?? '';
		$request_uri  = $_SERVER['REQUEST_URI'] ?? '';

		if ( $trigger_type === 'domain' ) {
			$clean_trigger = preg_replace( '#^https?://#', '', rtrim( $trigger, '/' ) );
			$clean_host    = rtrim( $host, '/' );
			return strcasecmp( $clean_host, $clean_trigger ) === 0;
		}

		if ( $trigger_type === 'subdomain' ) {
			$clean_trigger = preg_replace( '#^https?://#', '', rtrim( $trigger, '/' ) );
			$clean_host    = rtrim( $host, '/' );
			return strcasecmp( $clean_host, $clean_trigger ) === 0;
		}

		if ( $trigger_type === 'role' ) {
			$user = wp_get_current_user();
			if ( ! $user || ! $user->exists() ) return false;
			$role = strtolower( sanitize_text_field( $trigger ) );
			return in_array( $role, array_map( 'strtolower', $user->roles ), true );
		}

		if ( $trigger_type === 'param' ) {
			$clean = ltrim( $trigger, '?' );
			parse_str( $clean, $parsed );
			foreach ( $parsed as $key => $val ) {
				$param_value = $_GET[ $key ] ?? null;
				$has_match = ( $param_value !== null && $param_value === $val );
				if ( ! $has_match ) return false;
			}
			return ! empty( $parsed );
		}

		return false;
	}
}
