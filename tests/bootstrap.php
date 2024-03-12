<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Glueup
 */

// Give access to tests_add_filter() function.
require_once dirname( dirname( __FILE__ ) ) . '/vendor/wp-phpunit/wp-phpunit/bootstrap.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/glueup.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

