<?php
declare(strict_types=1);

/**
 * Ends an in-progress masquerade and returns the session to the original
 * (root) user.  Quietly no-ops when the session isn't masquerading.  No
 * permission gate: anyone who finds themselves masquerading is allowed to
 * stop, which is what makes this the dependable escape hatch from the
 * user menu — even when the masqueraded role can't see /users.php.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

stopMasquerade($config);
header('Location: /index.php');
exit;
