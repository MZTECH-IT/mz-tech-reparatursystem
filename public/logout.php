<?php
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';

start_secure_session();
do_logout();
header('Location: ' . url('index.php'));
exit;
