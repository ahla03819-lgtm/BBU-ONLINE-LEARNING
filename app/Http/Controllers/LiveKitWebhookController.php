<?php

namespace App\Http\Controllers;

use App\Enums\LiveKitWebhookStatus;
use App\Jobs\ProcessLiveKitWebhook;
use App\Models\LiveKitWebhookEvent;
use App\Services\LiveKit\LiveKitWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, LiveKitWebhookVerifier $verifier): JsonResponse
    {
        abort_unless($request->isJson(), 415);
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('livekit.webhook_max_bytes', 262144), 413);

        try {
            $verified = $verifier->verify($body, (string) $request->header('Authorization'));
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid LiveKit webhook.'], 401);
        }

        $event = LiveKitWebhookEvent::query()->firstOrCreate(['event_id' => $verified->eventId], [
            'event_type' => $verified->eventType,
            'livekit_room_name' => $verified->roomName,
            'participant_identity' => $verified->participantIdentity,
            'participant_sid' => $verified->participantSid,
            'track_source' => $verified->trackSource,
            'track_sid' => $verified->trackSid,
            'occurred_at' => $verified->occurredAt,
            'payload_sha256' => $verified->payloadSha256,
            'status' => LiveKitWebhookStatus::Pending,
            'attempts' => 0,
        ]);

        if ($event->wasRecentlyCreated) {
            ProcessLiveKitWebhook::dispatch($event->event_id);
        }

        return response()->json(['accepted' => true]);
    }
}
