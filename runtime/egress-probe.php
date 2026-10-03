<?php

/**
 * Runtime probe: call the real Egress service over HTTP with a real LiveKit token
 * and report exactly what came back. Verification only.
 *
 * Usage: php runtime/egress-probe.php [start|stop|list]
 */

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('livekit.api_key');
$secret = config('livekit.api_secret');
$host = rtrim((string) config('meeting-recordings.egress_api_url'), '/');

$options = (new AccessTokenOptions)->setIdentity('runtime-probe')->setName('Runtime Probe')->setTtl(600);
$grant = (new VideoGrant)->setRoomJoin(true)->setRoomAdmin(true)->setRoomRecord(true)->setRoomName('probe-room');
$token = (new AccessToken($key, $secret))->init($options)->setGrant($grant)->toJwt();

$action = $argv[1] ?? 'start';

$request = match ($action) {
    'start' => (new Livekit\RoomCompositeEgressRequest([
        'room_name' => 'probe-room',
        'layout' => 'screen-share',
        'file_outputs' => [new Livekit\EncodedFileOutput([
            'file_type' => Livekit\EncodedFileType::MP4,
            'filepath' => 'probe/probe.mp4',
            'disable_manifest' => true,
        ])],
    ])),
    'stop' => new Livekit\StopEgressRequest(['egress_id' => $argv[2] ?? '']),
    'list' => new Livekit\ListEgressRequest(['egress_id' => $argv[2] ?? '']),
};

$twirp = ['start' => 'StartRoomCompositeEgress', 'stop' => 'StopEgress', 'list' => 'ListEgress'][$action];

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/protobuf\r\n",
    'content' => $request->serializeToString(),
    'ignore_errors' => true,
    'timeout' => 10,
]]);

$raw = file_get_contents("{$host}/twirp/livekit.Egress/{$twirp}", false, $context);

echo 'action:  ', $action, PHP_EOL;
echo 'status:  ', $http_response_header[0] ?? '?', PHP_EOL;
echo 'bytes:   ', strlen((string) $raw), PHP_EOL;
echo 'hex:     ', substr(bin2hex((string) $raw), 0, 120), PHP_EOL;
echo 'raw:     ', substr((string) $raw, 0, 100), PHP_EOL;

$out = match ($action) {
    'list' => new Livekit\ListEgressResponse(),
    default => new Livekit\EgressInfo(),
};
try {
    $out->mergeFromString((string) $raw);
    echo 'parsed:  ok', PHP_EOL;
    if ($out instanceof Livekit\ListEgressResponse) {
        foreach ($out->getItems() as $item) {
            echo '  item egress=', $item->getEgressId(), ' status=', $item->getStatus(), ' files=', count(iterator_to_array($item->getFileResults())), PHP_EOL;
        }
    } else {
        echo '  egress=', $out->getEgressId(), ' status=', $out->getStatus(), ' files=', count(iterator_to_array($out->getFileResults())), PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'parsed:  ', $e->getMessage(), PHP_EOL;
}
