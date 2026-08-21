<?php
/**
 * Runtime environment and release-header consistency.
 *
 * @package Unwan
 */

/**
 * Guards the invariants the WordPress.org release checklist depends on, and
 * records the WordPress/WooCommerce versions the suite actually ran against.
 */
class CompatibilityTest extends WP_UnitTestCase {

	/**
	 * Parsed plugin file header.
	 *
	 * @return array<string,string>
	 */
	private function plugin_header(): array {
		$file = dirname( __DIR__, 2 ) . '/unwan-for-woocommerce.php';

		// get_plugin_data() does not expose the licence or the WooCommerce
		// headers, so read those directly.
		return array_merge(
			get_plugin_data( $file, false, false ),
			get_file_data(
				$file,
				array(
					'License'    => 'License',
					'LicenseURI' => 'License URI',
					'WCRequires' => 'WC requires at least',
					'WCTested'   => 'WC tested up to',
				)
			)
		);
	}

	/**
	 * Parsed readme.txt header block.
	 *
	 * @return array<string,string>
	 */
	private function readme_header(): array {
		$lines  = file( dirname( __DIR__, 2 ) . '/readme.txt', FILE_IGNORE_NEW_LINES );
		$header = array();

		// The header block runs from the line after the === title === down to
		// the first blank line.
		foreach ( array_slice( (array) $lines, 1 ) as $line ) {
			if ( '' === trim( $line ) ) {
				break;
			}

			if ( preg_match( '/^([A-Za-z][A-Za-z ]*):\s*(.+?)\s*$/', $line, $matches ) ) {
				$header[ $matches[1] ] = $matches[2];
			}
		}

		return $header;
	}

	/**
	 * WordPress, WooCommerce, and Unwan are all loaded.
	 */
	public function test_the_plugin_loads_in_this_environment(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( class_exists( \Unwan\AddressLibrary\AddressRepository::class ) );
		$this->assertTrue( class_exists( \Unwan\AddressLibrary\Plugin::class ) );
		$this->assertTrue( defined( 'UNWAN_VERSION' ) );

		fwrite(
			STDERR,
			sprintf(
				PHP_EOL . '[unwan] Verified against WordPress %s / WooCommerce %s / PHP %s' . PHP_EOL,
				get_bloginfo( 'version' ),
				WC()->version,
				PHP_VERSION
			)
		);
	}

	/**
	 * The suite runs at or above every declared minimum.
	 */
	public function test_the_environment_meets_the_declared_minimums(): void {
		$header = $this->plugin_header();

		$this->assertTrue(
			version_compare( get_bloginfo( 'version' ), $header['RequiresWP'], '>=' ),
			'WordPress meets the declared minimum'
		);
		$this->assertTrue(
			version_compare( PHP_VERSION, $header['RequiresPHP'], '>=' ),
			'PHP meets the declared minimum'
		);
		$this->assertTrue(
			version_compare( WC()->version, UNWAN_MINIMUM_WC_VERSION, '>=' ),
			'WooCommerce meets the declared minimum'
		);
	}

	/**
	 * The version constant, plugin header, and readme stable tag agree.
	 */
	public function test_the_version_is_declared_consistently(): void {
		$this->assertSame( UNWAN_VERSION, $this->plugin_header()['Version'] );
		$this->assertSame( UNWAN_VERSION, $this->readme_header()['Stable tag'] );
	}

	/**
	 * Requirement headers match between the plugin file and the readme.
	 */
	public function test_requirement_headers_match_the_readme(): void {
		$plugin = $this->plugin_header();
		$readme = $this->readme_header();

		$this->assertSame( $plugin['RequiresWP'], $readme['Requires at least'] );
		$this->assertSame( $plugin['RequiresPHP'], $readme['Requires PHP'] );
		$this->assertSame( 'woocommerce', $readme['Requires Plugins'] );
		$this->assertSame( UNWAN_MINIMUM_WC_VERSION, $readme['WC requires at least'] );
		$this->assertSame( $plugin['WCRequires'], $readme['WC requires at least'] );
		$this->assertSame( $plugin['WCTested'], $readme['WC tested up to'] );
	}

	/**
	 * The plugin name is identical in both places.
	 *
	 * Plugin Check reports mismatched_plugin_name otherwise.
	 */
	public function test_the_plugin_name_matches_the_readme_title(): void {
		$readme = file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );

		preg_match( '/^===\s*(.+?)\s*===/', $readme, $matches );

		$this->assertSame( $this->plugin_header()['Name'], $matches[1] );
	}

	/**
	 * WordPress "Tested up to" takes a major.minor version only.
	 *
	 * A patch component is Plugin Check's invalid_tested_upto_minor, an error.
	 */
	public function test_the_wordpress_tested_up_to_header_has_no_patch_component(): void {
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+$/',
			$this->readme_header()['Tested up to']
		);
	}

	/**
	 * The readme claims coverage for the WordPress version under test.
	 */
	public function test_the_readme_claims_coverage_for_this_wordpress_version(): void {
		$tested  = $this->readme_header()['Tested up to'];
		$running = get_bloginfo( 'version' );

		$this->assertTrue(
			version_compare( $tested, $running, '>=' ),
			sprintf(
				'readme.txt says "Tested up to: %s" but the suite ran on WordPress %s. '
					. 'Bump the header once the run is green.',
				$tested,
				$running
			)
		);
	}

	/**
	 * The readme claims coverage for the WooCommerce version under test.
	 */
	public function test_the_readme_claims_coverage_for_this_woocommerce_version(): void {
		$tested = $this->readme_header()['WC tested up to'];

		$this->assertTrue(
			version_compare( $tested, WC()->version, '>=' ),
			sprintf(
				'readme.txt says "WC tested up to: %s" but the suite ran on WooCommerce %s.',
				$tested,
				WC()->version
			)
		);
	}

	/**
	 * Readme tags are capped at five; Plugin Check ignores the rest.
	 */
	public function test_the_readme_lists_no_more_than_five_tags(): void {
		$tags = array_filter( array_map( 'trim', explode( ',', $this->readme_header()['Tags'] ) ) );

		$this->assertLessThanOrEqual( 5, count( $tags ) );
	}

	/**
	 * The licence is declared in the plugin header, not only the readme.
	 */
	public function test_the_licence_is_declared_in_both_places(): void {
		$plugin = $this->plugin_header();
		$readme = $this->readme_header();

		$this->assertNotSame( '', $plugin['License'], 'plugin_header_no_license is a blocking error' );
		$this->assertNotSame( '', $plugin['LicenseURI'] );
		$this->assertStringContainsString( 'GPL', $plugin['License'] );
		$this->assertStringContainsString( 'GPL', $readme['License'] );
		$this->assertNotSame( '', $readme['License URI'] );
		$this->assertSame( $plugin['LicenseURI'], $readme['License URI'] );
	}

	/**
	 * The text domain matches the plugin slug everywhere it is declared.
	 */
	public function test_the_text_domain_matches_the_slug_everywhere(): void {
		$this->assertSame( 'unwan-for-woocommerce', $this->plugin_header()['TextDomain'] );

		foreach ( glob( dirname( __DIR__, 2 ) . '/src/blocks/*/block.json' ) as $path ) {
			$block = json_decode( (string) file_get_contents( $path ), true );

			$this->assertSame(
				'unwan-for-woocommerce',
				$block['textdomain'],
				basename( dirname( $path ) ) . '/block.json declares the right text domain'
			);
		}
	}

	/**
	 * Unwan declares compatibility with the WooCommerce features it relies on.
	 */
	public function test_woocommerce_feature_compatibility_is_declared(): void {
		$this->assertTrue(
			class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ),
			'WooCommerce exposes the feature-compatibility API'
		);

		$source = file_get_contents( dirname( __DIR__, 2 ) . '/unwan-for-woocommerce.php' );

		$this->assertStringContainsString( 'custom_order_tables', $source, 'HPOS compatibility is declared' );
		$this->assertStringContainsString( 'cart_checkout_blocks', $source, 'Checkout Blocks compatibility is declared' );
	}
}
