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

		$allowed_fields = array( 'label', 'triggerType', 'trigger', 'themeSlug', 'themeName', 'color' );
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

		$matched_theme = $matched_door['themeSlug'];
		$theme = wp_get_theme( $matched_theme );
		if ( ! $theme->exists() ) return;

		$override_template = function() use ( $matched_theme ) {
			return $matched_theme;
		};

		add_filter( 'template', $override_template );
		add_filter( 'stylesheet', $override_template );

		// Overrides for domain mapping
		if ( in_array( $matched_door['triggerType'], array( 'domain', 'subdomain' ), true ) ) {
			$protocol = is_ssl() ? 'https://' : 'http://';
			$mapped_url = $protocol . $_SERVER['HTTP_HOST'];
			
			$override_url = function( $url ) use ( $mapped_url ) {
				return $mapped_url;
			};

			add_filter( 'option_home', $override_url );
			add_filter( 'option_siteurl', $override_url );

			// Prevent WP from redirecting back to the original domain
			remove_action( 'template_redirect', 'redirect_canonical' );
		}
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
