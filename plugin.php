<?php
/**
 * Plugin Name: ElasticPress Verify Index
 * Description: WP-CLI command that checks a random sample of posts against the ElasticPress index.
 * Version: 0.1.0
 * Author: Human Made Limited
 * Author URI: https://humanmade.com/
 * License: GPL-2.0-or-later
 * Requires PHP: 8.1
 *
 * @package HM\EP_Verify_Index
 */

namespace HM\EP_Verify_Index;

require_once __DIR__ . '/inc/namespace.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/class-command.php';
}

bootstrap();
