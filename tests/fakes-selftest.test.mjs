import assert from 'node:assert/strict';
import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { fakeWp } from './helpers/wp-fakes.mjs';

const seed = {
  multisite: true,
  currentNetworkId: 1,
  networks: [
    { id: 1, domain: 'ms.localhost', path: '/' },
    { id: 2, domain: 'two.localhost', path: '/' },
  ],
  sites: [
    { id: 1, networkId: 1, domain: 'ms.localhost', path: '/' },
    { id: 2, networkId: 1, domain: 'ms.localhost', path: '/blog2/' },
    { id: 3, networkId: 2, domain: 'two.localhost', path: '/' },
  ],
};

describe( 'Moving Castle WordPress fakes', () => {
  it( 'fake get_sites filters by network_id and counts', () => {
    const out = runPhpJson( fakeWp( seed ) + `
      echo json_encode( array(
        'count' => get_sites( array( 'network_id' => 1, 'count' => true ) ),
        'ids'   => array_map( function ( $s ) { return (int) $s->blog_id; }, get_sites( array( 'network_id' => 2 ) ) ),
        'page'  => count( get_sites( array( 'network_id' => 1, 'number' => 1, 'offset' => 1 ) ) ),
      ) );
    ` );
    assert.equal( out.count, 2 );
    assert.deepEqual( out.ids, [ 3 ] );
    assert.equal( out.page, 1 );
  } );

  it( 'fake wp_insert_site assigns increasing ids and records the call', () => {
    const out = runPhpJson( fakeWp( seed ) + `
      $a = wp_insert_site( array( 'domain' => 'ms.localhost', 'path' => '/blog3/', 'network_id' => 1, 'title' => 'Three', 'user_id' => 1 ) );
      $b = wp_insert_site( array( 'domain' => 'ms.localhost', 'path' => '/blog4/', 'network_id' => 1 ) );
      $dup = wp_insert_site( array( 'domain' => 'ms.localhost', 'path' => '/blog3/', 'network_id' => 1 ) );
      $site = get_site( $a );
      echo json_encode( array(
        'a' => $a, 'b' => $b,
        'dup' => is_wp_error( $dup ) ? $dup->get_error_code() : null,
        'site_id' => $site->site_id, 'network_id' => $site->network_id, 'blog_id' => $site->blog_id,
        'first_call' => $GLOBALS['fake']['calls'][0],
      ) );
    ` );
    assert.equal( out.a, 4 );
    assert.equal( out.b, 5 );
    assert.equal( out.dup, 'site_taken' );
    assert.equal( out.site_id, '1' );
    assert.equal( out.blog_id, '4' );
    assert.equal( out.network_id, 1 );
    assert.equal( out.first_call[ 0 ], 'wp_insert_site' );
    assert.equal( out.first_call[ 1 ][ 0 ].title, 'Three' );
  } );

  it( 'fake do_action records hook name and args', () => {
    const out = runPhpJson( fakeWp( seed ) + `
      do_action( 'moving_castle_site_moved', 3, 2, 1 );
      echo json_encode( $GLOBALS['fake']['actions'] );
    ` );
    assert.deepEqual( out, [ [ 'moving_castle_site_moved', [ 3, 2, 1 ] ] ] );
  } );
} );
