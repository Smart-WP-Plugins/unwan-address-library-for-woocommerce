#!/usr/bin/env node
/**
 * Compile script-translation JSON for the block bundles from the locale .po files.
 *
 * WordPress resolves these via load_script_textdomain(), which — because
 * BlocksIntegration passes an explicit $path — checks
 *   languages/{domain}-{locale}-{md5(<script path relative to the plugin>)}.json
 * before falling back to WP_LANG_DIR. The md5 is taken over the *built* script
 * path (for example "build/blocks/frontend.js"), not the src path the strings
 * were extracted from, so each entry below maps one to the other.
 *
 * Only the strings a given bundle actually uses are emitted, matching what
 * `wp i18n make-json` produces.
 *
 * Usage (from the plugin root): npm run i18n:json
 */

const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const DOMAIN = 'unwan-for-woocommerce';
const LANG_DIR = path.join( ROOT, 'languages' );

// src file the strings live in -> built file WordPress actually registers.
const BUNDLES = [
	{ src: 'src/blocks/frontend.js', build: 'build/blocks/frontend.js' },
	{ src: 'src/blocks/editor.js', build: 'build/blocks/editor.js' },
];

/**
 * Minimal PO reader; assumes --no-wrap (one physical line per string).
 *
 * @param {string} file Absolute path to a .po file.
 * @return {{header: string, entries: Map}} Parsed header block and translated entries.
 */
function parsePo( file ) {
	const unescape = ( s ) =>
		s.replace(
			/\\(["\\ntr])/g,
			( _, c ) =>
				( { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' } )[ c ]
		);
	const entries = new Map();
	let header = '';
	let id = null;
	let isPlural = false;
	let forms = [];

	const flush = () => {
		if ( id === null ) {
			return;
		}
		if ( id === '' ) {
			header = forms[ 0 ] || '';
		} else if ( forms.some( ( f ) => f !== '' ) ) {
			entries.set( id, { isPlural, forms } );
		}
		id = null;
		isPlural = false;
		forms = [];
	};

	for ( const line of fs.readFileSync( file, 'utf8' ).split( '\n' ) ) {
		let m;
		if ( ( m = line.match( /^msgid "(.*)"$/ ) ) ) {
			flush();
			id = unescape( m[ 1 ] );
		} else if ( line.startsWith( 'msgid_plural "' ) ) {
			isPlural = true;
		} else if ( ( m = line.match( /^msgstr(?:\[(\d+)\])? "(.*)"$/ ) ) ) {
			forms[ m[ 1 ] === undefined ? 0 : Number( m[ 1 ] ) ] = unescape(
				m[ 2 ]
			);
		} else if ( ( m = line.match( /^"(.*)"$/ ) ) && id === '' ) {
			forms[ 0 ] = ( forms[ 0 ] || '' ) + unescape( m[ 1 ] );
		}
	}
	flush();
	return { header, entries };
}

/**
 * Collect the msgids referenced by a single JS source file.
 *
 * @param {string} srcFile Plugin-relative path to a JS source file.
 * @return {Set<string>} Every msgid xgettext finds in that file.
 */
function msgidsIn( srcFile ) {
	const tmp = path.join( LANG_DIR, `_tmp-${ path.basename( srcFile ) }.pot` );
	execFileSync(
		'xgettext',
		[
			'--language=JavaScript',
			'--from-code=UTF-8',
			'--no-wrap',
			'--keyword=__',
			'--keyword=_x:1,2c',
			'--keyword=_n:1,2',
			'--keyword=_nx:1,2,4c',
			'-o',
			tmp,
			srcFile,
		],
		{ cwd: ROOT }
	);
	const ids = new Set( parsePo( tmp ).entries.keys() );
	// parsePo drops untranslated entries; re-read raw for the msgid list.
	const raw = fs.readFileSync( tmp, 'utf8' );
	for ( const m of raw.matchAll( /^msgid "(.+)"$/gm ) ) {
		ids.add( m[ 1 ].replace( /\\(["\\])/g, '$1' ) );
	}
	fs.unlinkSync( tmp );
	return ids;
}

const locales = fs
	.readdirSync( LANG_DIR )
	.filter( ( f ) => f.startsWith( `${ DOMAIN }-` ) && f.endsWith( '.po' ) )
	.map( ( f ) => f.slice( DOMAIN.length + 1, -3 ) );

if ( ! locales.length ) {
	process.stderr.write( 'No .po files found in languages/.\n' );
	process.exit( 1 );
}

let written = 0;

for ( const bundle of BUNDLES ) {
	const ids = msgidsIn( bundle.src );
	const hash = crypto
		.createHash( 'md5' )
		.update( bundle.build )
		.digest( 'hex' );

	for ( const locale of locales ) {
		const { header, entries } = parsePo(
			path.join( LANG_DIR, `${ DOMAIN }-${ locale }.po` )
		);
		const pluralForms =
			( header.match( /Plural-Forms:\s*([^\\]+)\\n/ ) || [] )[ 1 ] ||
			'nplurals=2; plural=(n != 1);';

		const messages = {
			'': {
				domain: 'messages',
				lang: locale,
				'plural-forms': pluralForms.trim(),
			},
		};

		let hits = 0;
		for ( const id of ids ) {
			const entry = entries.get( id );
			if ( entry ) {
				messages[ id ] = entry.forms.slice();
				hits++;
			}
		}

		if ( ! hits ) {
			continue;
		}

		const out = {
			'translation-revision-date': new Date().toISOString(),
			generator: 'unwan/scripts/make-json.js',
			source: bundle.build,
			domain: 'messages',
			locale_data: { messages },
		};

		fs.writeFileSync(
			path.join( LANG_DIR, `${ DOMAIN }-${ locale }-${ hash }.json` ),
			JSON.stringify( out )
		);
		written++;
	}

	process.stdout.write(
		`${ bundle.build } -> md5 ${ hash } (${ ids.size } strings, ${ locales.length } locales)\n`
	);
}

process.stdout.write( `Wrote ${ written } script-translation JSON files.\n` );
