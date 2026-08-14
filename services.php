<?php
declare(strict_types=1);

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

// Key   = Windows service name exactly as registered with the SCM (the name
//         `sc query` lists, which is not always the display name).
// Value = label shown in the browser.
$service_array = array(
    'Terraria Server'          => 'Terraria Server',
    'Terraria Server 2'        => 'Terraria Server 2',
    'Enshrouded Server'        => 'Enshrouded Server',
    'Minecraft Bedrock Server' => 'Minecraft Bedrock Server',
    'Factorio Server'          => 'Factorio Server',
);

// Game servers frequently ignore the SCM stop request, so give them this long
// to shut down cleanly before the process tree is force killed.
const SM_STOP_GRACE_SECONDS = 15;
// How long to wait for the SCM to report "stopped" after a kill.
const SM_KILL_WAIT_SECONDS = 10;
// How long a service is given to reach "running".
const SM_START_TIMEOUT_SECONDS = 60;
// How often the browser re-reads the service states, in milliseconds.
const SM_POLL_INTERVAL_MS = 2000;

/* SERVICE_STATUS.dwCurrentState values (0 = unknown / not installed). */
const SM_STOPPED          = 1;
const SM_START_PENDING    = 2;
const SM_STOP_PENDING     = 3;
const SM_RUNNING          = 4;
const SM_CONTINUE_PENDING = 5;
const SM_PAUSE_PENDING    = 6;
const SM_PAUSED           = 7;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function sm_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function sm_shell(string $command): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $output = @shell_exec($command . ' 2>&1');
    return is_string($output) ? $output : '';
}

/** State, plus the PID when the win32service extension reports it. */
function sm_query(string $service): array
{
    if (function_exists('win32_query_service_status')) {
        try {
            $status = @win32_query_service_status($service);
            if (is_array($status)) {
                return array(
                    'state' => (int) ($status['CurrentState'] ?? 0),
                    'pid'   => (int) ($status['ProcessId'] ?? 0),
                );
            }
        } catch (\Throwable $e) {
            // Falls through to sc.exe below.
        }
    }
    return sm_sc_query($service);
}

/** Fallback query / PID lookup through sc.exe. */
function sm_sc_query(string $service): array
{
    $output = sm_shell('sc queryex ' . escapeshellarg($service));
    $state  = 0;
    $pid    = 0;
    if (preg_match('/STATE\s*:\s*(\d+)/i', $output, $m)) {
        $state = (int) $m[1];
    }
    if (preg_match('/^\s*PID\s*:\s*(\d+)/mi', $output, $m)) {
        $pid = (int) $m[1];
    }
    return array('state' => $state, 'pid' => $pid);
}

function sm_state(string $service): int
{
    return sm_query($service)['state'];
}

function sm_pid(string $service): int
{
    $pid = sm_query($service)['pid'];
    return $pid > 0 ? $pid : sm_sc_query($service)['pid'];
}

function sm_send_start(string $service): void
{
    if (function_exists('win32_start_service')) {
        try {
            @win32_start_service($service);
            return;
        } catch (\Throwable $e) {
            // Falls through to sc.exe below.
        }
    }
    sm_shell('sc start ' . escapeshellarg($service));
}

function sm_send_stop(string $service): void
{
    if (function_exists('win32_stop_service')) {
        try {
            @win32_stop_service($service);
            return;
        } catch (\Throwable $e) {
            // Falls through to sc.exe below.
        }
    }
    sm_shell('sc stop ' . escapeshellarg($service));
}

function sm_wait_for(string $service, int $target_state, int $timeout_seconds): int
{
    $deadline = time() + $timeout_seconds;
    $state    = sm_state($service);
    while ($state !== $target_state && time() < $deadline) {
        sleep(1);
        $state = sm_state($service);
    }
    return $state;
}

/** /T takes the children with it, which is where the game server actually runs. */
function sm_kill_tree(int $pid): bool
{
    if ($pid <= 4) { // 0 = none, 4 = System
        return false;
    }
    sm_shell('taskkill /PID ' . $pid . ' /T /F');
    return true;
}

function sm_start(string $service): array
{
    sm_send_start($service);
    $state = sm_wait_for($service, SM_RUNNING, SM_START_TIMEOUT_SECONDS);
    return array(
        'state'   => $state,
        'message' => $state === SM_RUNNING
            ? 'Started.'
            : 'Did not reach "running" within ' . SM_START_TIMEOUT_SECONDS . 's.',
    );
}

function sm_stop(string $service): array
{
    // Read the PID first: once the SCM tears the service down it is gone.
    $pid = sm_pid($service);
    sm_send_stop($service);

    $state = sm_wait_for($service, SM_STOPPED, SM_STOP_GRACE_SECONDS);
    if ($state === SM_STOPPED) {
        return array('state' => $state, 'message' => 'Stopped cleanly.');
    }

    if ($pid <= 0) {
        $pid = sm_pid($service);
    }
    if (!sm_kill_tree($pid)) {
        return array(
            'state'   => $state,
            'message' => 'Did not stop within ' . SM_STOP_GRACE_SECONDS . 's and no process was found to kill.',
        );
    }

    $state = sm_wait_for($service, SM_STOPPED, SM_KILL_WAIT_SECONDS);
    return array(
        'state'   => $state,
        'message' => 'Did not stop within ' . SM_STOP_GRACE_SECONDS . 's, '
            . ($state === SM_STOPPED
                ? 'killed process ' . $pid . '.'
                : 'kill sent to process ' . $pid . ' but the service is still not stopped.'),
    );
}

function sm_kill(string $service): array
{
    $pid = sm_pid($service);
    if (!sm_kill_tree($pid)) {
        return array('state' => sm_state($service), 'message' => 'No running process found.');
    }
    $state = sm_wait_for($service, SM_STOPPED, SM_KILL_WAIT_SECONDS);
    return array(
        'state'   => $state,
        'message' => $state === SM_STOPPED
            ? 'Killed process ' . $pid . '.'
            : 'Kill sent to process ' . $pid . ' but the service is still not stopped.',
    );
}

function sm_restart(string $service): array
{
    $stop = sm_stop($service);
    if ($stop['state'] !== SM_STOPPED) {
        return array('state' => $stop['state'], 'message' => 'Restart aborted: ' . $stop['message']);
    }
    $start = sm_start($service);
    return array('state' => $start['state'], 'message' => $stop['message'] . ' ' . $start['message']);
}

/** State changing calls must be POSTs carrying the session CSRF token. */
function sm_require_authorised_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sm_json(array('error' => 'POST required.'), 405);
    }
    session_start();
    $expected = (string) ($_SESSION['sm_csrf'] ?? '');
    $supplied = (string) ($_POST['csrf'] ?? '');
    session_write_close(); // release the lock, these requests block for a while

    if ($expected === '' || !hash_equals($expected, $supplied)) {
        sm_json(array('error' => 'Invalid or expired token, reload the page.'), 403);
    }
}

function sm_requested_service(array $service_array): string
{
    $service = (string) ($_POST['service'] ?? '');
    if (!array_key_exists($service, $service_array)) {
        sm_json(array('error' => 'Unknown service.'), 400);
    }
    return $service;
}

/* -------------------------------------------------------------------------
 * Routing
 * ---------------------------------------------------------------------- */

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'services') {
    $services = array();
    foreach ($service_array as $name => $label) {
        $status     = sm_query($name);
        $services[] = array(
            'name'  => $name,
            'label' => $label,
            'state' => $status['state'],
            'pid'   => $status['pid'],
        );
    }
    sm_json($services);
}

if ($action === 'start' || $action === 'stop' || $action === 'restart' || $action === 'kill') {
    sm_require_authorised_post();
    $service = sm_requested_service($service_array);

    ignore_user_abort(true); // a promised kill still has to happen if the tab closes
    @set_time_limit(SM_START_TIMEOUT_SECONDS + SM_STOP_GRACE_SECONDS + SM_KILL_WAIT_SECONDS + 30);

    switch ($action) {
        case 'start':   $result = sm_start($service);   break;
        case 'stop':    $result = sm_stop($service);    break;
        case 'restart': $result = sm_restart($service); break;
        default:        $result = sm_kill($service);    break;
    }

    sm_json(array(
        'name'    => $service,
        'label'   => $service_array[$service],
        'state'   => $result['state'],
        'pid'     => sm_query($service)['pid'],
        'message' => $result['message'],
    ));
}

if ($action === 'js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
?>
var servicesApp = angular.module('servicesApp', []);

servicesApp.controller('ServiceController', ['$scope', '$http', '$interval', '$httpParamSerializer',
function ($scope, $http, $interval, $httpParamSerializer) {

    var POLL_MS = <?php echo SM_POLL_INTERVAL_MS; ?>;
    var FORM_POST = { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
    var polling = false;

    $scope.services = [];
    $scope.lastUpdated = null;
    $scope.error = null;
    $scope.graceSeconds = <?php echo SM_STOP_GRACE_SECONDS; ?>;

    $scope.stateMsg = function (state) {
        switch (state) {
            case 1: return 'Stopped';
            case 2: return 'Starting';
            case 3: return 'Stopping';
            case 4: return 'Running';
            case 5: return 'Resuming';
            case 6: return 'Pausing';
            case 7: return 'Paused';
            default: return 'Unknown / not installed';
        }
    };

    $scope.stateClass = function (state) {
        switch (state) {
            case 1: return 'state-stopped';
            case 4: return 'state-running';
            case 2: case 3: case 5: case 6: return 'state-pending';
            case 7: return 'state-paused';
            default: return 'state-unknown';
        }
    };

    $scope.canStart = function (s) { return !s.busy && (s.state === 1 || s.state === 7 || s.state === 0); };
    $scope.canStop = function (s) { return !s.busy && s.state !== 1 && s.state !== 0; };
    $scope.canRestart = function (s) { return !s.busy && s.state === 4; };
    $scope.canKill = function (s) { return !s.busy && (s.state === 2 || s.state === 3 || s.state === 4 || s.state === 7); };

    function merge(incoming) {
        var known = {};
        angular.forEach($scope.services, function (s) { known[s.name] = s; });
        $scope.services = incoming.map(function (fresh) {
            var existing = known[fresh.name];
            if (!existing) {
                fresh.busy = false;
                fresh.message = null;
                return fresh;
            }
            existing.label = fresh.label;
            existing.pid = fresh.pid;
            existing.state = fresh.state;
            return existing;
        });
        $scope.lastUpdated = new Date();
    }

    function refresh() {
        if (polling) { return; }
        polling = true;
        $http.get('?action=services').then(function (response) {
            $scope.error = null;
            merge(response.data);
        }, function () {
            $scope.error = 'Cannot reach the service manager.';
        })['finally'](function () { polling = false; });
    }

    function command(service, action) {
        if (service.busy) { return; }
        service.busy = true;
        service.message = null;
        $http.post(location.pathname, $httpParamSerializer({
            action: action,
            service: service.name,
            csrf: window.SM_CSRF
        }), FORM_POST).then(function (response) {
            service.state = response.data.state;
            service.pid = response.data.pid;
            service.message = response.data.message;
        }, function (response) {
            service.message = (response.data && response.data.error) || 'Request failed.';
        })['finally'](function () {
            service.busy = false;
            refresh();
        });
    }

    $scope.start = function (service) { command(service, 'start'); };
    $scope.stop = function (service) { command(service, 'stop'); };
    $scope.restart = function (service) { command(service, 'restart'); };
    $scope.kill = function (service) {
        if (!window.confirm('Force kill ' + service.label + '? Unsaved world data may be lost.')) { return; }
        command(service, 'kill');
    };

    refresh();
    var poller = $interval(refresh, POLL_MS);
    $scope.$on('$destroy', function () { $interval.cancel(poller); });
}]);
<?php
    exit;
}

/* -------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------- */

session_start();
if (empty($_SESSION['sm_csrf'])) {
    $_SESSION['sm_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['sm_csrf'];
session_write_close();
?>
<!DOCTYPE html>
<html lang="en" ng-app="servicesApp">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Game Server Services</title>
<style>
    body { font-family: "Segoe UI", Arial, sans-serif; margin: 2em; color: #222; }
    table { border-collapse: collapse; }
    th, td { padding: .4em .7em; text-align: left; border-bottom: 1px solid #ddd; }
    button { min-width: 5.5em; padding: .3em .6em; }
    .state { font-weight: 600; }
    .state-running { color: #1a7f37; }
    .state-stopped { color: #767676; }
    .state-pending { color: #a35200; }
    .state-paused  { color: #a35200; }
    .state-unknown { color: #b42318; }
    .pid, .meta, .message { color: #555; font-size: .85em; }
    .error { color: #b42318; }
</style>
</head>
<body ng-controller="ServiceController">
<h1>Game Server Services</h1>

<p class="error" ng-if="error">{{error}}</p>

<table>
    <tr>
        <th>Service</th>
        <th>State</th>
        <th>PID</th>
        <th colspan="4">Actions</th>
        <th>Last action</th>
    </tr>
    <tr ng-repeat="service in services">
        <td>{{service.label}}</td>
        <td class="state {{stateClass(service.state)}}">{{stateMsg(service.state)}}</td>
        <td class="pid">{{service.pid > 0 ? service.pid : '-'}}</td>
        <td><button ng-click="start(service)" ng-disabled="!canStart(service)">Start</button></td>
        <td><button ng-click="stop(service)" ng-disabled="!canStop(service)">Stop</button></td>
        <td><button ng-click="restart(service)" ng-disabled="!canRestart(service)">Restart</button></td>
        <td><button ng-click="kill(service)" ng-disabled="!canKill(service)">Kill</button></td>
        <td class="message">
            <span ng-if="service.busy">Working&hellip;</span>
            <span ng-if="!service.busy">{{service.message}}</span>
        </td>
    </tr>
</table>

<p class="meta">
    Stop waits {{graceSeconds}}s for a clean shutdown, then force kills the process tree.
    State refreshes automatically<span ng-if="lastUpdated"> &mdash; last updated {{lastUpdated | date:'HH:mm:ss'}}</span>.
</p>

<script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.8.3/angular.min.js"></script>
<script>window.SM_CSRF = <?php echo json_encode($csrf); ?>;</script>
<script src="?action=js"></script>
</body>
</html>
