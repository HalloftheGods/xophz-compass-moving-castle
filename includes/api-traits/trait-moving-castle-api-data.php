<?php

trait Trait_Moving_Castle_API_Data {

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
}
