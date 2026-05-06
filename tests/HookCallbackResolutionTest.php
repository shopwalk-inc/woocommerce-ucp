<?php
/**
 * Static check: every string callback passed to add_action()/add_filter() in
 * the plugin source must resolve to a function actually defined in the plugin
 * source. Catches regressions like the 3.1.0 typo where a brand-rename pass
 * left `add_action( 'woocommerce_init', 'shopwalk_define_payment_gateway_class' )`
 * pointing at a function name that no longer existed — fatal at activation.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

final class HookCallbackResolutionTest extends TestCase {

	public function test_string_callbacks_resolve_to_defined_functions(): void {
		$root  = realpath( __DIR__ . '/..' );
		$files = $this->scan_plugin_php_files( $root );

		$defined = $this->collect_defined_function_names( $files );
		$callbacks = $this->collect_string_callbacks( $files );

		$missing = array();
		foreach ( $callbacks as $cb ) {
			if ( isset( $defined[ $cb['fn'] ] ) ) {
				continue;
			}
			// Callable might be a built-in or a WP core function; skip those.
			if ( function_exists( $cb['fn'] ) ) {
				continue;
			}
			$missing[] = sprintf(
				'%s (referenced from %s:%d as a %s callback for "%s")',
				$cb['fn'],
				str_replace( $root . '/', '', $cb['file'] ),
				$cb['line'],
				$cb['kind'],
				$cb['hook']
			);
		}

		$this->assertSame(
			array(),
			$missing,
			"add_action/add_filter string callbacks pointing at undefined functions:\n - "
			. implode( "\n - ", $missing )
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function scan_plugin_php_files( string $root ): array {
		$skip = array( 'vendor', 'tests', 'node_modules', '.git', 'languages' );
		$out  = array();
		$it   = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
				static function ( $current ) use ( $skip ) {
					return ! in_array( $current->getFilename(), $skip, true );
				}
			)
		);
		foreach ( $it as $entry ) {
			if ( $entry->isFile() && 'php' === $entry->getExtension() ) {
				$out[] = $entry->getPathname();
			}
		}
		return $out;
	}

	/**
	 * Returns a set keyed by function name (value `true`) of every top-level
	 * `function foo()` definition across the plugin source.
	 *
	 * @param array<int, string> $files
	 * @return array<string, bool>
	 */
	private function collect_defined_function_names( array $files ): array {
		$defined = array();
		foreach ( $files as $f ) {
			$src = file_get_contents( $f );
			if ( false === $src ) {
				continue;
			}
			if ( preg_match_all( '/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $src, $m ) ) {
				foreach ( $m[1] as $name ) {
					$defined[ $name ] = true;
				}
			}
		}
		return $defined;
	}

	/**
	 * Returns every `add_action('hook', 'fn_name', …)` / `add_filter('hook', 'fn_name', …)`
	 * call where the callback is a single string — i.e. a function name. Array
	 * callbacks (`array($this, 'method')`) and Closures are intentionally not
	 * checked here; only the string-callback pattern that produced the 3.1.0 fatal.
	 *
	 * @param array<int, string> $files
	 * @return array<int, array{file:string, line:int, kind:string, hook:string, fn:string}>
	 */
	private function collect_string_callbacks( array $files ): array {
		$out = array();
		$re  = '/(add_action|add_filter)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/';
		foreach ( $files as $f ) {
			$src = file_get_contents( $f );
			if ( false === $src ) {
				continue;
			}
			if ( ! preg_match_all( $re, $src, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $i => $whole ) {
				$line  = substr_count( substr( $src, 0, $whole[1] ), "\n" ) + 1;
				$out[] = array(
					'file' => $f,
					'line' => $line,
					'kind' => $m[1][ $i ][0],
					'hook' => $m[2][ $i ][0],
					'fn'   => $m[3][ $i ][0],
				);
			}
		}
		return $out;
	}
}
