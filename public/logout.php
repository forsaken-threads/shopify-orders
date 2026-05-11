<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

logOut();
header('Location: /index.php');
exit;
