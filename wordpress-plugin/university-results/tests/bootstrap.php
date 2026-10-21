<?php
/**
 * Unit tests load only the plugin's plain-PHP classes, without WordPress.
 * ABSPATH is defined so the classes' direct-access guard lets them load.
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-urp-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-urp-csv-importer.php';
require_once dirname( __DIR__ ) . '/includes/class-urp-access-codes.php';
