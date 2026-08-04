/**
 * Create a production-only plugin archive from the explicit package allowlist.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const AdmZip = require( 'adm-zip' );
const fastGlob = require( 'fast-glob' );
const packageData = require( '../package.json' );

const projectRoot = path.resolve( __dirname, '..' );
const archivePath = path.join( projectRoot, `${ packageData.name }.zip` );
const rootDirectory = `${ packageData.name }/`;
const ignoreFile = path.join( projectRoot, '.distignore' );
const ignorePatterns = fs
	.readFileSync( ignoreFile, 'utf8' )
	.split( /\r?\n/ )
	.map( ( line ) => line.trim() )
	.filter( ( line ) => line && ! line.startsWith( '#' ) );
const files = fastGlob
	.sync( packageData.distFiles, {
		cwd: projectRoot,
		dot: true,
		followSymbolicLinks: false,
		ignore: ignorePatterns,
		onlyFiles: true,
	} )
	.sort();

if ( files.length === 0 ) {
	throw new Error(
		'No production files matched the distribution allowlist.'
	);
}

const archive = new AdmZip();

files.forEach( ( file ) => {
	const directory = path.posix.dirname( file );
	archive.addLocalFile(
		path.join( projectRoot, file ),
		rootDirectory + ( directory === '.' ? '' : directory )
	);
} );

archive.writeZip( archivePath );

process.stdout.write(
	`Created ${ path.basename( archivePath ) } with ${
		files.length
	} production files.\n`
);
