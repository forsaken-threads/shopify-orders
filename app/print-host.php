<?php
declare(strict_types=1);

/**
 * How Cent Notes reaches the label print host.  print-order.php prints through
 * this and tools' printer check tests through it, and the test is only worth
 * anything while the two connect identically — so neither spells it itself.
 */

// Paths on the print host, left for its shell to expand, so they must stay
// outside any quoting in a remote command.
const PRINT_SERVICE_PYTHON = '~/print-service/venv/bin/python3';
const PRINT_SERVICE_SCRIPT = '~/print-service/print-label.py';

/**
 * The ssh command up to and including the target, ready for a remote command.
 *
 * ConnectTimeout: fail fast if the printer host is unreachable.
 * ServerAliveInterval/CountMax: detect a stalled connection within 15s.
 * -4: the print host answers on IPv4 only — its AAAA record resolves but
 * drops inbound SSH, so without this a container with a v6 route would
 * burn ConnectTimeout on v6 before falling back on every single label.
 */
function printSshPrefix(array $config): string
{
    return 'ssh -4 -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=3 '
         . escapeshellarg($config['print_ssh_target']) . ' ';
}

/**
 * Whether ssh gave up before it ever reached the print host.
 *
 * Keyed on ssh's own wording rather than on the errno text after the colon:
 * musl writes "Operation timed out" where glibc writes "Connection timed
 * out", so the container and a dev box word one failure two ways.  The two
 * prefixes cover connect timeout, refused, no route and DNS.  A connection
 * that dropped mid-transfer words itself differently and stays retryable,
 * which is the distinction the retry rule needs.
 */
function printHostUnreachable(string $sshOutput): bool
{
    return str_contains($sshOutput, 'ssh: connect to host ')
        || str_contains($sshOutput, 'ssh: Could not resolve hostname ');
}
