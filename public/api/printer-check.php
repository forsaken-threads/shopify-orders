<?php
declare(strict_types=1);

/**
 * Printer connectivity check and test label for the Brother QL-820NWB.
 *
 * GET /api/printer-check.php
 *   Runs the checks in order — target, print host, print service, printer —
 *   and returns {steps: [{key, label, status, seconds, summary, output}]}.
 *   status is pass | fail | skipped | info | warn.  Once a step fails the rest
 *   come back skipped rather than each waiting out its own timeout.  Nothing
 *   is printed: the printer step opens a TCP connection and closes it unsent.
 *
 * POST /api/printer-check.php
 *   Prints one 1ml label titled "Test label", with the time in the display
 *   timezone as its brand line so the tape can be matched to the click.
 *   Returns {ok, exit, seconds, printed_at, output}.
 *   Header: X-CSRF-Token: <token>
 *
 * Every ssh call goes through app/print-host.php, the same prefix and paths
 * print-order.php prints with, so a pass here means printing connects the same
 * way.  The printer's address is read from the print-label.py deployed on the
 * print host, never from the repo's copy, which nothing keeps in step with it.
 *
 * Requires the 'tools' permission (root).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/print-host.php';

requireApiPermission($config, 'tools');

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if ($method === 'POST') {
    $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken  = $_SESSION['csrf_token']        ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

// Each check below blocks on ssh for up to ConnectTimeout, and PHP holds the
// session file locked for the whole request — release it, as print-order.php does.
session_write_close();

$tz        = new DateTimeZone($config['display_timezone']);
$sshPrefix = printSshPrefix($config);

/**
 * Run one command on the print host.  Returns [exit code, output, seconds].
 */
function runOnPrintHost(string $sshPrefix, string $remoteCmd): array
{
    $output = [];
    $exit   = 0;
    $t0     = microtime(true);
    exec($sshPrefix . escapeshellarg($remoteCmd) . ' 2>&1', $output, $exit);
    return [$exit, implode("\n", $output), round(microtime(true) - $t0, 2)];
}

// ── POST: test label ─────────────────────────────────────────────────────────

if ($method === 'POST') {
    $printedAt = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    $remoteCmd = PRINT_SERVICE_PYTHON . ' ' . PRINT_SERVICE_SCRIPT . ' '
               . escapeshellarg('1ml') . ' ' . escapeshellarg('Test label') . ' ' . escapeshellarg($printedAt);
    [$exit, $output, $seconds] = runOnPrintHost($sshPrefix, $remoteCmd);

    echo json_encode([
        'ok'         => $exit === 0,
        'exit'       => $exit,
        'seconds'    => $seconds,
        'printed_at' => $printedAt,
        'output'     => $output,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── GET: checks ──────────────────────────────────────────────────────────────

$steps = [];

// 1. Target — configuration only, so it cannot fail; every later result is read
// against it.
$target = $config['print_ssh_target'];
$route  = strtolower($config['print_route']);
switch ($config['print_target_source']) {
    case 'pinned':
        $why = 'Pinned by PRINT_SSH_TARGET in env.ini'
             . ($route !== '' ? ", which overrides route.ini's route = {$route}." : '.');
        break;
    case 'route':
        $why = "Picked by route.ini's route = {$route}, through PRINT_SSH_TARGET_" . strtoupper($route) . ' in env.ini.';
        break;
    case 'route_unset':
        $why = "route.ini says route = {$route}, but env.ini has no PRINT_SSH_TARGET_" . strtoupper($route)
             . ', so the built-in default is used.  That is probably not what was meant.';
        break;
    default:
        $why = (is_file($config['print_route_ini_path']) ? 'route.ini names no route' : 'There is no route.ini')
             . ' and PRINT_SSH_TARGET is not set, so the built-in default is used.';
}
$routeIniMtime = @filemtime($config['print_route_ini_path']);
if ($routeIniMtime !== false) {
    // sync-print-route.sh rewrites the file only when the route changes, so its
    // age is when the route last changed, not when it was last checked.
    $why .= '  route.ini last changed '
          . (new DateTimeImmutable('@' . $routeIniMtime))->setTimezone($tz)->format('M j, Y g:i A') . '.';
}
$steps[] = [
    'key'     => 'target',
    'label'   => 'Target',
    'status'  => $config['print_target_source'] === 'route_unset' ? 'warn' : 'info',
    'seconds' => null,
    'summary' => $target . ' — ' . $why,
    'output'  => null,
];

// 2. Print host — the same probe print-order.php runs before the first label.
[$exit, $output, $seconds] = runOnPrintHost($sshPrefix, 'true');
if ($exit === 0) {
    $summary = "ssh to {$target} succeeded.";
} else {
    $summary = "ssh exited {$exit}.  "
             . (printHostUnreachable($output)
                 ? 'It never reached the host, so printing would stop before sending anything.'
                 : 'It reached the host but failed there, so printing would retry.');
}
$steps[] = [
    'key'     => 'host',
    'label'   => 'Print host',
    'status'  => $exit === 0 ? 'pass' : 'fail',
    'seconds' => $seconds,
    'summary' => $summary,
    'output'  => $exit === 0 ? null : $output,
];

// 3. Print service — the two paths every label runs.  Unquoted assignments, so
// the host's shell expands their tildes.
$failed = $exit !== 0;
if (!$failed) {
    $remoteCmd = 'py=' . PRINT_SERVICE_PYTHON . '; script=' . PRINT_SERVICE_SCRIPT . '; rc=0; '
               . 'if [ -x "$py" ]; then echo "found $py"; else echo "missing or not executable: $py"; rc=1; fi; '
               . 'if [ -f "$script" ]; then echo "found $script"; else echo "missing: $script"; rc=1; fi; '
               . 'exit $rc';
    [$exit, $output, $seconds] = runOnPrintHost($sshPrefix, $remoteCmd);
    $failed  = $exit !== 0;
    $steps[] = [
        'key'     => 'service',
        'label'   => 'Print service',
        'status'  => $failed ? 'fail' : 'pass',
        'seconds' => $seconds,
        'summary' => $failed ? 'The print service is not where printing looks for it.'
                             : 'The venv Python and print-label.py are both in place.',
        'output'  => $failed ? $output : null,
    ];
}

// 4. Printer — PRINTER as the deployed print-label.py has it, parsed the way
// brother_ql's network backend parses it (tcp:// stripped, port 9100 unless
// given), then connected to and closed without a byte sent.  importlib because
// the hyphen rules out import; loading it is safe because the script only calls
// main() under __main__.
$printerPy = <<<'PY'
import importlib.util, json, socket, sys, time
spec = importlib.util.spec_from_file_location('print_label', sys.argv[1])
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
out = {'printer': mod.PRINTER}
if not mod.PRINTER.startswith('tcp://'):
    out['error'] = 'not a network printer'
    print(json.dumps(out))
    sys.exit(1)
host, _, port = mod.PRINTER[6:].partition(':')
out['host'] = host
out['port'] = int(port) if port else 9100
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.settimeout(5)
try:
    s.connect((host, out['port']))
except OSError as e:
    out['error'] = str(e) or type(e).__name__
finally:
    s.close()
print(json.dumps(out))
sys.exit(1 if 'error' in out else 0)
PY;
if (!$failed) {
    $remoteCmd = PRINT_SERVICE_PYTHON . ' -c ' . escapeshellarg($printerPy) . ' ' . PRINT_SERVICE_SCRIPT;
    [$exit, $output, $seconds] = runOnPrintHost($sshPrefix, $remoteCmd);
    $lines  = array_values(array_filter(explode("\n", $output), static fn(string $l): bool => trim($l) !== ''));
    $result = $lines ? json_decode((string) end($lines), true) : null;

    if (!is_array($result)) {
        $summary = 'Could not read PRINTER from the deployed print-label.py.';
    } elseif (!isset($result['host'])) {
        $summary = "PRINTER is {$result['printer']}, which is not a network printer.";
    } else {
        $address = $result['host'] . ':' . $result['port'];
        $summary = $exit === 0
            ? "Connected to {$address}, from PRINTER = {$result['printer']}."
            : "Could not connect to {$address}, from PRINTER = {$result['printer']}: {$result['error']}.";
    }
    $steps[] = [
        'key'     => 'printer',
        'label'   => 'Printer',
        'status'  => $exit === 0 ? 'pass' : 'fail',
        'seconds' => $seconds,
        'summary' => $summary,
        'output'  => $exit === 0 ? null : $output,
    ];
}

foreach (['service' => 'Print service', 'printer' => 'Printer'] as $key => $label) {
    if (!in_array($key, array_column($steps, 'key'), true)) {
        $steps[] = [
            'key'     => $key,
            'label'   => $label,
            'status'  => 'skipped',
            'seconds' => null,
            'summary' => 'Skipped: an earlier check failed.',
            'output'  => null,
        ];
    }
}

echo json_encode(['target' => $target, 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
