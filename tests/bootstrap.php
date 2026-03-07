<?php
/**
 * PHPUnit bootstrap for Shopwalk AI UCP plugin.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/../vendor/autoload.php';

\Brain\Monkey\setUp();
