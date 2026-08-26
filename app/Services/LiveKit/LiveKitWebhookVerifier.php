<?php

namespace App\Services\LiveKit;

interface LiveKitWebhookVerifier
{
    public function verify(string $body, string $authorization): VerifiedWebhook;
}
