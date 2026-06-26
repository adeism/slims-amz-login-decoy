<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

if (!defined('AMZLD_PLUGIN_DIR')) {
    define('AMZLD_PLUGIN_DIR', __DIR__);
}

// Load core utilities and configurations first
require_once AMZLD_PLUGIN_DIR . '/parts/core_utils.php';

// Load IP utility functions
require_once AMZLD_PLUGIN_DIR . '/parts/ip_utils.php';

// Load logging and alert functions
require_once AMZLD_PLUGIN_DIR . '/parts/log_utils.php';

// Load request handlers and OPAC hooks
require_once AMZLD_PLUGIN_DIR . '/parts/request_handlers.php';
