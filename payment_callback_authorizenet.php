<?php

// Authorize.net's webhook management API rejects callback URLs containing query
// string parameters. XenForo's standard callback URL format is:
//   /payment_callback.php?_xfProvider=authorizenet
// which fails webhook creation. This shim provides a clean URL that Authorize.net
// accepts, then injects the _xfProvider parameter and delegates to XenForo's
// standard payment callback handler.

$dir = __DIR__;
$requirePath = '/payment_callback.php';

$path = $dir . $requirePath;
if (!file_exists($path) && isset($_SERVER['SCRIPT_FILENAME'])) {
    $path = dirname($_SERVER['SCRIPT_FILENAME']) . $requirePath;
}

$_GET['_xfProvider'] = 'authorizenet';

/** @noinspection PhpIncludeInspection */
require($path);
