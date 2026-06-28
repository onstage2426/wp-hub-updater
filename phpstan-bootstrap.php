<?php

/**
 * PHPStan bootstrap: define WordPress constants that are always present at
 * runtime but are not provided by the wordpress-stubs package.
 */

defined('ABSPATH')         || define('ABSPATH', '/');
defined('WP_DEBUG')        || define('WP_DEBUG', false);
defined('WP_PLUGIN_DIR')   || define('WP_PLUGIN_DIR', '/');
defined('WPMU_PLUGIN_DIR') || define('WPMU_PLUGIN_DIR', '/');
defined('DOING_CRON')      || define('DOING_CRON', false);
defined('WP_INSTALLING')   || define('WP_INSTALLING', false);
