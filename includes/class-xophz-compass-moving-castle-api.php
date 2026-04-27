<?php

require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-security.php';
require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-environment.php';
require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-data.php';
require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-files.php';
require_once plugin_dir_path( __FILE__ ) . 'api-traits/trait-moving-castle-api-import.php';

class Xophz_Compass_Moving_Castle_API {

	use Trait_Moving_Castle_API_Security;
	use Trait_Moving_Castle_API_Environment;
	use Trait_Moving_Castle_API_Schema;
	use Trait_Moving_Castle_API_Data;
	use Trait_Moving_Castle_API_Files;
	use Trait_Moving_Castle_API_Import;

	public function register_routes() {
		register_rest_route( 'moving-castle/v1', '/sites', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sites' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/connection', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_connection' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/schema', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/data', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_data' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/import', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_site' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/import/process', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_import_task' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				}
			)
		));

		register_rest_route( 'moving-castle/v1', '/files/prepare', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'prepare_files' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/files/download', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_files' ),
				'permission_callback' => '__return_true',
			)
		));

		register_rest_route( 'moving-castle/v1', '/files/cleanup', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cleanup_files' ),
				'permission_callback' => '__return_true',
			)
		));
	}
}
