<?php

trait Trait_Moving_Castle_API_Networks {

	public function create_network( WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$domain    = (string) ( $params['domain'] ?? '' );
		$path      = (string) ( $params['path'] ?? '/' );
		$site_name = (string) ( $params['site_name'] ?? '' );
		$copy_from = isset( $params['copy_from'] ) && '' !== $params['copy_from'] ? (int) $params['copy_from'] : null;

		$result = Moving_Castle_Network_Manager::create_network( $domain, $path, $site_name, $copy_from );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'network' => $result ) );
	}

	public function update_network( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params();
		$result = Moving_Castle_Network_Manager::update_network( $id, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'network' => $result ) );
	}

	public function delete_network( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$params  = $request->get_json_params();
		$move_to = (int) ( $params['move_sites_to'] ?? 0 );
		$result  = Moving_Castle_Network_Manager::delete_network( $id, $move_to );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function create_site( WP_REST_Request $request ) {
		$params        = $request->get_json_params();
		$network_id    = (int) ( $params['network_id'] ?? get_current_network_id() );
		$domain        = (string) ( $params['domain'] ?? '' );
		$path          = (string) ( $params['path'] ?? '/' );
		$title         = (string) ( $params['title'] ?? '' );
		$admin_user_id = (int) ( $params['admin_user_id'] ?? get_current_user_id() );

		$result = Moving_Castle_Network_Manager::create_site( $network_id, $domain, $path, $title, $admin_user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'site' => $result ) );
	}

	public function update_site( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params();
		$result = Moving_Castle_Network_Manager::update_site( $id, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'site' => $result ) );
	}

	public function move_site( WP_REST_Request $request ) {
		$id         = (int) $request->get_param( 'id' );
		$params     = $request->get_json_params();
		$network_id = (int) ( $params['network_id'] ?? 0 );
		$result     = Moving_Castle_Network_Manager::move_site( $id, $network_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function delete_site( WP_REST_Request $request ) {
		$id             = (int) $request->get_param( 'id' );
		$params         = $request->get_json_params();
		$confirm_domain = (string) ( $params['confirm_domain'] ?? '' );
		$result         = Moving_Castle_Network_Manager::delete_site( $id, $confirm_domain );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}
}
