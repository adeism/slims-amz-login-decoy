<?php
/**
 * Plugin Name: AMZ Login Decoy
 * Plugin URI: https://github.com/adeism/slims-amz-login-decoy
 * Description: Protects predictable SLiMS login URLs with a decoy form, security logging, IP blocking, and a secret staff login door.
 * Version: 1.0.0
 * Author: Ade Ismail Siregar
 */

use SLiMS\Plugins;

defined('INDEX_AUTH') OR die('Direct access not allowed');

require_once __DIR__ . '/helper.php';

$plugin = Plugins::getInstance();

$plugin->registerMenu('system', 'AMZ Login Decoy', __DIR__ . '/admin.php');
$plugin->registerMenu('opac', 'ld', __DIR__ . '/opac_menu.php');

$plugin->registerHook(Plugins::CONTENT_BEFORE_LOAD, function () {
    amzldHandleOpacRequest();
});

$plugin->registerHook(Plugins::ADMIN_SESSION_AFTER_START, function () {
    amzldHandleAdminRequest();
});
