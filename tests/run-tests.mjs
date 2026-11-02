/**
 * Moving Castle plugin test runner.
 * Runs every *.test.mjs in this directory against the in-memory WordPress fakes.
 */

import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const files = readdirSync( here ).filter( ( name ) => name.endsWith( '.test.mjs' ) ).sort();

for ( const file of files ) {
  await import( pathToFileURL( join( here, file ) ).href );
}

const results = await harness.run();
for ( const failure of results.failures ) {
  console.error( `FAIL [${ failure.suiteName }] ${ failure.testName }` );
  console.error( `  ${ String( failure.error ).split( '\n' ).slice( 0, 4 ).join( '\n  ' ) }` );
}

if ( results.failed > 0 ) {
  console.error( `Moving Castle Tests FAILED: ${ results.failed } failed, ${ results.passed } passed.` );
  process.exit( 1 );
}
console.log( `Moving Castle Tests PASSED: ${ results.passed } passed.` );
process.exit( 0 );
