/**
 * In-memory fake of WordPress multisite core, as a PHP prelude string.
 * Usage: runPhpJson( fakeWp( seed ) + '<php under test>' ).
 * State lives in $GLOBALS['fake']; mutating calls are recorded in
 * $GLOBALS['fake']['calls'] as [ function, args ] and hooks in ['actions'].
 * Reads (get_*) are not recorded. multisite defaults to true.
 */

export const PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-moving-castle';

const PRELUDE = `
$GLOBALS['fake'] = fake_build_state( json_decode( base64_decode( '__SEED__' ), true ) );

class WP_Site {}
class WP_Network {}

class Fake_Wpdb {
	public $site = 'wp_site';
	public $sitemeta = 'wp_sitemeta';
	public $insert_id = 0;
	public function insert( $table, $data, $format = null ) {
		fake_record( 'wpdb->insert', array( $table, $data ) );
		$fake = &$GLOBALS['fake'];
		if ( $table === $this->sitemeta ) {
			$fake['options'][ (int) $data['site_id'] ][ $data['meta_key'] ] = $data['meta_value'];
			$this->insert_id = count( $fake['options'][ (int) $data['site_id'] ] );
		} elseif ( $table === $this->site ) {
			$id = isset( $data['id'] ) ? (int) $data['id'] : max( array_merge( array( 0 ), array_keys( $fake['networks'] ) ) ) + 1;
			$fake['networks'][ $id ] = array( 'id' => $id, 'domain' => $data['domain'], 'path' => $data['path'] );
			$this->insert_id = $id;
		}
		return 1;
	}
	public function delete( $table, $where, $format = null ) {
		fake_record( 'wpdb->delete', array( $table, $where ) );
		$fake = &$GLOBALS['fake'];
		if ( $table === $this->sitemeta ) {
			$id = (int) $where['site_id'];
			if ( isset( $where['meta_key'] ) ) {
				$hit = isset( $fake['options'][ $id ][ $where['meta_key'] ] );
				unset( $fake['options'][ $id ][ $where['meta_key'] ] );
				return $hit ? 1 : 0;
			}
			$count = isset( $fake['options'][ $id ] ) ? count( $fake['options'][ $id ] ) : 0;
			unset( $fake['options'][ $id ] );
			return $count;
		}
		if ( $table === $this->site && isset( $fake['networks'][ (int) $where['id'] ] ) ) {
			unset( $fake['networks'][ (int) $where['id'] ] );
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

function fake_build_state( $seed ) {
	$state = array(
		'multisite'  => isset( $seed['multisite'] ) ? (bool) $seed['multisite'] : true,
		'current_network_id' => isset( $seed['currentNetworkId'] ) ? (int) $seed['currentNetworkId'] : 1,
		'current_user_id'    => isset( $seed['currentUserId'] ) ? (int) $seed['currentUserId'] : 0,
		'users'      => isset( $seed['users'] ) ? $seed['users'] : array(),
		'fail'       => isset( $seed['fail'] ) ? $seed['fail'] : array(),
		'networks'   => array(),
		'sites'      => array(),
		'options'    => array(),
		'blog_options' => array(),
		'calls'      => array(),
		'actions'    => array(),
	);
	foreach ( $seed['networks'] as $n ) {
		$state['networks'][ (int) $n['id'] ] = array( 'id' => (int) $n['id'], 'domain' => $n['domain'], 'path' => $n['path'] );
		$state['options'][ (int) $n['id'] ] = isset( $n['options'] ) ? $n['options'] : array();
	}
	foreach ( $seed['sites'] as $s ) {
		$state['sites'][ (int) $s['id'] ] = fake_site_row( $s );
		if ( isset( $s['options'] ) ) {
			$state['blog_options'][ (int) $s['id'] ] = $s['options'];
		}
	}
	return $state;
}

function fake_record( $name, $args ) {
	$GLOBALS['fake']['calls'][] = array( $name, $args );
}

function fake_flag( $row, $key, $default ) {
	return isset( $row[ $key ] ) ? ( $row[ $key ] ? 1 : 0 ) : $default;
}

function fake_site_row( $s ) {
	return array(
		'id'         => (int) $s['id'],
		'network_id' => isset( $s['networkId'] ) ? (int) $s['networkId'] : (int) $s['network_id'],
		'domain'     => $s['domain'],
		'path'       => $s['path'],
		'title'      => isset( $s['title'] ) ? $s['title'] : '',
		'archived'   => fake_flag( $s, 'archived', 0 ),
		'spam'       => fake_flag( $s, 'spam', 0 ),
		'deleted'    => fake_flag( $s, 'deleted', 0 ),
		'public'     => fake_flag( $s, 'public', 1 ),
	);
}

function fake_site_object( $row ) {
	$site = new WP_Site();
	$site->id         = $row['id'];
	$site->blog_id    = (string) $row['id'];
	$site->network_id = $row['network_id'];
	$site->site_id    = (string) $row['network_id'];
	$site->domain     = $row['domain'];
	$site->path       = $row['path'];
	$site->blogname   = $row['title'];
	foreach ( array( 'archived', 'spam', 'deleted', 'public' ) as $flag ) {
		$site->$flag = (string) $row[ $flag ];
	}
	return $site;
}

function fake_network_object( $row ) {
	$network         = new WP_Network();
	$network->id     = $row['id'];
	$network->domain = $row['domain'];
	$network->path   = $row['path'];
	return $network;
}

function fake_page( $rows, $args ) {
	if ( ! empty( $args['count'] ) ) {
		return count( $rows );
	}
	$number = isset( $args['number'] ) ? (int) $args['number'] : 100;
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
	return array_slice( array_values( $rows ), $offset, $number > 0 ? $number : null );
}

function fake_match( $row, $args, $keys ) {
	foreach ( $keys as $key ) {
		if ( isset( $args[ $key ] ) && (string) $row[ $key ] !== (string) $args[ $key ] ) {
			return false;
		}
	}
	return true;
}

function is_multisite() {
	return $GLOBALS['fake']['multisite'];
}

function get_current_network_id() {
	return $GLOBALS['fake']['current_network_id'];
}

function get_current_user_id() {
	return $GLOBALS['fake']['current_user_id'];
}

function get_network( $id ) {
	return isset( $GLOBALS['fake']['networks'][ (int) $id ] ) ? fake_network_object( $GLOBALS['fake']['networks'][ (int) $id ] ) : null;
}

function get_networks( $args = array() ) {
	$rows = array();
	foreach ( $GLOBALS['fake']['networks'] as $row ) {
		if ( fake_match( $row, $args, array( 'domain', 'path' ) ) ) {
			$rows[] = ! empty( $args['count'] ) ? $row : fake_network_object( $row );
		}
	}
	return fake_page( $rows, $args );
}

function get_site( $id = null ) {
	$id = null === $id ? 1 : (int) $id;
	return isset( $GLOBALS['fake']['sites'][ $id ] ) ? fake_site_object( $GLOBALS['fake']['sites'][ $id ] ) : null;
}

function get_sites( $args = array() ) {
	$rows = array();
	foreach ( $GLOBALS['fake']['sites'] as $row ) {
		if ( isset( $args['network_id'] ) && $row['network_id'] !== (int) $args['network_id'] ) {
			continue;
		}
		if ( fake_match( $row, $args, array( 'domain', 'path', 'archived', 'spam', 'deleted', 'public' ) ) ) {
			$rows[] = $row;
		}
	}
	$page = fake_page( $rows, $args );
	if ( ! is_array( $page ) ) {
		return $page;
	}
	if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
		return array_map( function ( $row ) { return $row['id']; }, $page );
	}
	return array_map( 'fake_site_object', $page );
}

function wp_insert_site( $data ) {
	fake_record( 'wp_insert_site', array( $data ) );
	$fake = &$GLOBALS['fake'];
	if ( ! empty( $fake['fail']['wp_insert_site'] ) ) {
		$msg = $fake['fail']['wp_insert_site'];
		return new WP_Error( 'fake_insert_failed', is_string( $msg ) ? $msg : 'Fake wp_insert_site failure.' );
	}
	$data['network_id'] = isset( $data['network_id'] ) ? (int) $data['network_id'] : $fake['current_network_id'];
	foreach ( $fake['sites'] as $row ) {
		if ( $row['network_id'] === $data['network_id'] && $row['domain'] === $data['domain'] && $row['path'] === $data['path'] ) {
			return new WP_Error( 'site_taken', 'Sorry, that site already exists!' );
		}
	}
	$data['id'] = max( array_merge( array( 0 ), array_keys( $fake['sites'] ) ) ) + 1;
	$fake['sites'][ $data['id'] ] = fake_site_row( $data );
	return $data['id'];
}

function wp_update_site( $id, $data ) {
	fake_record( 'wp_update_site', array( $id, $data ) );
	$fake = &$GLOBALS['fake'];
	if ( ! isset( $fake['sites'][ (int) $id ] ) ) {
		return new WP_Error( 'site_not_exist', 'Site does not exist.' );
	}
	$row = &$fake['sites'][ (int) $id ];
	foreach ( array( 'domain', 'path', 'title' ) as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$row[ $key ] = $data[ $key ];
		}
	}
	foreach ( array( 'archived', 'spam', 'deleted', 'public' ) as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$row[ $key ] = $data[ $key ] ? 1 : 0;
		}
	}
	if ( isset( $data['network_id'] ) ) {
		$row['network_id'] = (int) $data['network_id'];
	}
	return (int) $id;
}

function wp_delete_site( $id ) {
	fake_record( 'wp_delete_site', array( $id ) );
	$fake = &$GLOBALS['fake'];
	if ( ! isset( $fake['sites'][ (int) $id ] ) ) {
		return new WP_Error( 'site_not_exist', 'Site does not exist.' );
	}
	$site = fake_site_object( $fake['sites'][ (int) $id ] );
	unset( $fake['sites'][ (int) $id ] );
	unset( $fake['blog_options'][ (int) $id ] );
	return $site;
}

function get_network_option( $network_id, $key, $default = false ) {
	$options = $GLOBALS['fake']['options'];
	return isset( $options[ (int) $network_id ] ) && array_key_exists( $key, $options[ (int) $network_id ] ) ? $options[ (int) $network_id ][ $key ] : $default;
}

function update_network_option( $network_id, $key, $value ) {
	fake_record( 'update_network_option', array( $network_id, $key, $value ) );
	if ( isset( $GLOBALS['fake']['fail']['update_network_option_key'] ) && $GLOBALS['fake']['fail']['update_network_option_key'] === $key ) {
		return false;
	}
	$GLOBALS['fake']['options'][ (int) $network_id ][ $key ] = $value;
	return true;
}

function delete_network_option( $network_id, $key ) {
	fake_record( 'delete_network_option', array( $network_id, $key ) );
	$had = array_key_exists( $key, isset( $GLOBALS['fake']['options'][ (int) $network_id ] ) ? $GLOBALS['fake']['options'][ (int) $network_id ] : array() );
	unset( $GLOBALS['fake']['options'][ (int) $network_id ][ $key ] );
	return $had;
}

function get_blog_option( $site_id, $key, $default = false ) {
	$options = $GLOBALS['fake']['blog_options'];
	return isset( $options[ (int) $site_id ] ) && array_key_exists( $key, $options[ (int) $site_id ] ) ? $options[ (int) $site_id ][ $key ] : $default;
}

function update_blog_option( $site_id, $key, $value ) {
	fake_record( 'update_blog_option', array( $site_id, $key, $value ) );
	$GLOBALS['fake']['blog_options'][ (int) $site_id ][ $key ] = $value;
	return true;
}

function populate_network_meta( $network_id, $meta = array() ) {
	fake_record( 'populate_network_meta', array( $network_id, $meta ) );
	foreach ( $meta as $key => $value ) {
		if ( ! isset( $GLOBALS['fake']['options'][ (int) $network_id ] ) || ! array_key_exists( $key, $GLOBALS['fake']['options'][ (int) $network_id ] ) ) {
			$GLOBALS['fake']['options'][ (int) $network_id ][ $key ] = $value;
		}
	}
}

function clean_network_cache( $ids ) {
	fake_record( 'clean_network_cache', array( $ids ) );
}

function clean_blog_cache( $site ) {
	fake_record( 'clean_blog_cache', array( $site ) );
}

function wp_update_network_site_counts( $network_id = null ) {
	fake_record( 'wp_update_network_site_counts', array( $network_id ) );
	$network_id = null === $network_id ? $GLOBALS['fake']['current_network_id'] : (int) $network_id;
	$GLOBALS['fake']['options'][ $network_id ]['blog_count'] = get_sites( array( 'network_id' => $network_id, 'spam' => 0, 'deleted' => 0, 'archived' => 0, 'count' => true ) );
}

function get_userdata( $id ) {
	$users = $GLOBALS['fake']['users'];
	if ( ! isset( $users[ $id ] ) ) {
		return false;
	}
	$user             = new stdClass();
	$user->ID         = (int) $id;
	$user->user_login = $users[ $id ]['login'];
	$user->user_email = $users[ $id ]['email'];
	return $user;
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function do_action( $hook ) {
	$args = func_get_args();
	array_shift( $args );
	$GLOBALS['fake']['actions'][] = array( $hook, $args );
}
`;

export function fakeWp( seed ) {
  const encoded = Buffer.from( JSON.stringify( seed ) ).toString( 'base64' );
  return PRELUDE.replace( '__SEED__', encoded );
}
