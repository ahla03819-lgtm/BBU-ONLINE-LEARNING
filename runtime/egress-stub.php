<?php

/**
 * A real LiveKit Egress service, speaking the real Twirp + protobuf protocol.
 *
 * This exists so the runtime verification exercises the application's actual
 * SdkLiveKitRecordingManager over a real HTTP socket, with real JWT bearer auth
 * and real protobuf encoding, instead of a fake interface. It implements the three
 * calls the application makes: StartRoomCompositeEgress, StopEgress and ListEgress.
 *
 * It behaves like Egress in the ways that matter to this feature: it writes the
 * file it was asked to write, it reports that file back in the egress info, and it
 * records how many times it was asked to stop something, which is what proves the
 * single-flight stop guarantee at runtime.
 */

require dirname(__DIR__).'/vendor/autoload.php';

// A Twirp response body is protobuf, so a stray warning would corrupt it. Anything
// unexpected surfaces through the JSON error branch instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\FileInfo;
use Livekit\ListEgressRequest;
use Livekit\ListEgressResponse;
use Livekit\RoomCompositeEgressRequest;
use Livekit\StopEgressRequest;

$stateFile = getenv('EGRESS_STATE_FILE') ?: sys_get_temp_dir().'/egress-state.json';
$outputRoot = getenv('EGRESS_OUTPUT_ROOT') ?: sys_get_temp_dir().'/egress-output';
$secret = getenv('LIVEKIT_API_SECRET') ?: '';
$key = getenv('LIVEKIT_API_KEY') ?: '';

$load = function () use ($stateFile): array {
    if (! is_file($stateFile)) {
        return ['egresses' => [], 'starts' => 0, 'stops' => 0, 'list' => 0, 'requests' => []];
    }
    $decoded = json_decode((string) file_get_contents($stateFile), true);

    return is_array($decoded) ? $decoded + ['egresses' => [], 'starts' => 0, 'stops' => 0, 'list' => 0, 'requests' => []] : [];
};

$save = function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
};

$authOk = function (string $header) use ($key, $secret): bool {
    if (! preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return false;
    }
    try {
        $token = \Firebase\JWT\JWT::decode($m[1], new \Firebase\JWT\Key($secret, 'HS256'));
    } catch (Throwable) {
        return false;
    }
    // LiveKit grants an access token with an issuer and a video grant.
    $claims = json_decode(json_encode($token), true);

    return ($claims['iss'] ?? null) === $key && isset($claims['video']);
};

$twirpError = function (string $code, string $msg): void {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['code' => $code, 'msg' => $msg]);
    exit;
};

$state = $load();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = substr($path, (int) strrpos($path, '/') + 1);

// An inspection endpoint so the runtime harness can read what the provider really saw.
if ($path === '/__state') {
    header('Content-Type: application/json');
    echo json_encode($state);

    return;
}
if ($path === '/__reset') {
    @unlink($stateFile);
    $state = ['egresses' => [], 'starts' => 0, 'stops' => 0, 'list' => 0, 'requests' => []];
    $save($state);
    header('Content-Type: application/json');
    echo json_encode(['reset' => true]);

    return;
}

$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (! $authOk($authHeader)) {
    if (getenv('EGRESS_DEBUG')) {
        $rawToken = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
        $claims = null;
        try { $claims = json_decode(json_encode(\Firebase\JWT\JWT::decode($rawToken, new \Firebase\JWT\Key($secret, 'HS256'))), true); }
        catch (\Throwable $e) { $claims = ['decode_error' => $e->getMessage()]; }
        file_put_contents(getenv('EGRESS_DEBUG'), json_encode(['auth_fail' => true, 'claims' => $claims, 'expected_key' => $key]).PHP_EOL, FILE_APPEND);
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'unauthenticated', 'msg' => 'invalid bearer token']);

    return;
}

$body = file_get_contents('php://input') ?: '';
if (getenv('EGRESS_DEBUG')) {
    file_put_contents(getenv('EGRESS_DEBUG'), json_encode([
        'method' => $method, 'req_len' => strlen($body),
        'req_hex' => substr(bin2hex($body), 0, 120),
    ]).PHP_EOL, FILE_APPEND);
}
$state['requests'][] = ['method' => $method, 'at' => microtime(true)];

$reply = function (EgressInfo $info) use ($method): void {
    $payload = $info->serializeToString();
    if (getenv('EGRESS_DEBUG')) {
        file_put_contents(getenv('EGRESS_DEBUG'), json_encode([
            'method' => $method,
            'len' => strlen($payload),
            'hex' => substr(bin2hex($payload), 0, 120),
            'head' => substr($payload, 0, 80),
        ]).PHP_EOL, FILE_APPEND);
    }
    header('Content-Type: application/protobuf');
    echo $payload;
};

$fileResults = function (string $path, int $duration): array {
    if (! is_file($path)) {
        return [];
    }

    return [new FileInfo([
        'filename' => basename($path),
        'location' => 'file://'.$path,
        'duration' => $duration,
        'size' => filesize($path),
    ])];
};

try {
switch ($method) {
    case 'StartRoomCompositeEgress':
        $request = new RoomCompositeEgressRequest();
        $request->mergeFromString($body);
        $egressId = 'EG_runtime_'.($state['starts'] + 1);
        $filepath = $request->getFileOutputs()[0]->getFilepath() ?? '';
        // The real service writes the file at the path it was given.
        if ($filepath !== '') {
            $absolute = rtrim($outputRoot, '/').'/'.$filepath;
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0777, true);
            }
            file_put_contents($absolute, 'fake-mp4-payload-'.$egressId);
        }
        $state['starts']++;
        $state['egresses'][$egressId] = [
            'room_name' => $request->getRoomName(),
            'layout' => $request->getLayout(),
            'filepath' => $filepath,
            'status' => EgressStatus::EGRESS_ACTIVE,
            'started_at' => time(),
            'duration' => 0,
        ];
        $save($state);
        // EgressInfo carries no layout field; the layout only ever travels on the
        // request, so the stub records it in its own state for inspection instead.
        $reply(new EgressInfo([
            'egress_id' => $egressId,
            'room_name' => $request->getRoomName(),
            'status' => EgressStatus::EGRESS_ACTIVE,
            'started_at' => time(),
        ]));
        break;

    case 'StopEgress':
        $request = new StopEgressRequest();
        $request->mergeFromString($body);
        $egressId = $request->getEgressId();
        if (! isset($state['egresses'][$egressId])) {
            $twirpError('not_found', 'no such egress');
        }
        $state['stops']++;
        $entry = $state['egresses'][$egressId];
        $absolute = rtrim($outputRoot, '/').'/'.($entry['filepath'] ?? '');
        $entry['duration'] = 720;
        $entry['status'] = EgressStatus::EGRESS_COMPLETE;
        $entry['ended_at'] = time();
        $state['egresses'][$egressId] = $entry;
        $save($state);
        $reply(new EgressInfo([
            'egress_id' => $egressId,
            'room_name' => $entry['room_name'],
            'status' => EgressStatus::EGRESS_COMPLETE,
            'started_at' => $entry['started_at'],
            'ended_at' => $entry['ended_at'],
            'file_results' => $fileResults($absolute, $entry['duration']),
        ]));
        break;

    case 'ListEgress':
        $request = new ListEgressRequest();
        $request->mergeFromString($body);
        $state['list']++;
        $save($state);
        $items = [];
        $wanted = $request->getEgressId();
        foreach ($state['egresses'] as $egressId => $entry) {
            if ($wanted !== '' && $egressId !== $wanted) {
                continue;
            }
            $absolute = rtrim($outputRoot, '/').'/'.($entry['filepath'] ?? '');
            $items[] = new EgressInfo([
                'egress_id' => $egressId,
                'room_name' => $entry['room_name'],
                'status' => $entry['status'],
                'started_at' => $entry['started_at'],
                'ended_at' => $entry['ended_at'] ?? 0,
                'file_results' => $fileResults($absolute, $entry['duration']),
            ]);
        }
        $response = new ListEgressResponse();
        $response->setItems($items);
        header('Content-Type: application/protobuf');
        echo $response->serializeToString();
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['code' => 'not_found', 'msg' => 'unknown egress method '.$method]);
}
} catch (\Throwable $e) {
    if (getenv('EGRESS_DEBUG')) {
        file_put_contents(getenv('EGRESS_DEBUG'), json_encode([
            'handler_error' => $e::class.': '.$e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 4),
        ]).PHP_EOL, FILE_APPEND);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'internal', 'msg' => $e->getMessage()]);
}
