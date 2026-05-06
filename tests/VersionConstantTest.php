<?php
/**
 * Static check: the runtime VERSION constant must match the plugin's
 * Version: header and the readme.txt's Stable tag. Catches the drift bug
 * shipped from 3.1.1 through 3.1.3 where `WOOCOMMERCE_SHOPWALK_VERSION`
 * was hardcoded to "3.1.1" while the file header bumped through three
 * releases — so every UA string, Discovery doc, and dashboard "Plugin v…"
 * rendering reported a stale version.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

final class VersionConstantTest extends TestCase {

	public function test_main_file_header_matches_readme_stable_tag(): void {
		$root = realpath( __DIR__ . '/..' );

		$main_src  = file_get_contents( $root . '/shopwalk-for-woocommerce.php' );
		$readme_src = file_get_contents( $root . '/readme.txt' );

		preg_match( '/^\s*\*\s*Version:\s*([0-9][^\s]*)/m', $main_src, $hm );
		preg_match( '/^Stable tag:\s*([0-9][^\s]*)/m', $readme_src, $rm );

		$this->assertNotEmpty( $hm[1] ?? null, 'No Version: header found in main plugin file' );
		$this->assertNotEmpty( $rm[1] ?? null, 'No Stable tag: line found in readme.txt' );
		$this->assertSame(
			$hm[1],
			$rm[1],
			'Version: header and readme.txt Stable tag must match — same-commit rule'
		);
	}

	public function test_version_constant_is_derived_from_header_not_hardcoded(): void {
		$root = realpath( __DIR__ . '/..' );
		$main_src = file_get_contents( $root . '/shopwalk-for-woocommerce.php' );

		// The constant must NOT be defined with a literal version string.
		// Anything matching `define( 'WOOCOMMERCE_SHOPWALK_VERSION', '<digits>...' )`
		// would be a regression — the value belongs in the Version: header.
		$this->assertSame(
			0,
			preg_match(
				"/define\(\s*['\"]WOOCOMMERCE_SHOPWALK_VERSION['\"]\s*,\s*['\"][0-9]/",
				$main_src
			),
			'WOOCOMMERCE_SHOPWALK_VERSION must be derived from the plugin header (get_file_data), not hardcoded — drift caused 3.1.1 to ship in every UA string through 3.1.3'
		);
	}
}
