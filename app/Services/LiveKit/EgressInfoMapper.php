<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\FileInfo;

/**
 * Translates the SDK's EgressInfo into this application's own types.
 *
 * Both the Egress service client and the webhook receiver hand back the same proto
 * message, so the translation lives here once. Keeping a single mapping is what
 * guarantees that an egress reaching Ready through a webhook is described in
 * exactly the same terms as one that reached it by polling the provider.
 */
final class EgressInfoMapper
{
    public static function status(EgressInfo $info): MeetingRecordingProviderStatus
    {
        return match ((int) $info->getStatus()) {
            EgressStatus::EGRESS_STARTING => MeetingRecordingProviderStatus::Starting,
            EgressStatus::EGRESS_ACTIVE => MeetingRecordingProviderStatus::Active,
            EgressStatus::EGRESS_ENDING => MeetingRecordingProviderStatus::Ending,
            EgressStatus::EGRESS_COMPLETE => MeetingRecordingProviderStatus::Complete,
            // The provider gave up on this egress, so no output will ever arrive.
            // Reporting that as "unknown" would leave a recording waiting forever.
            EgressStatus::EGRESS_FAILED, EgressStatus::EGRESS_ABORTED, EgressStatus::EGRESS_LIMIT_REACHED => MeetingRecordingProviderStatus::Failed,
            default => MeetingRecordingProviderStatus::Unknown,
        };
    }

    /**
     * @return list<LiveKitRecordingFile>
     */
    public static function files(EgressInfo $info): array
    {
        $files = [];
        foreach ($info->getFileResults() as $result) {
            $files[] = self::file($result);
        }

        return $files;
    }

    public static function file(FileInfo $result): LiveKitRecordingFile
    {
        return new LiveKitRecordingFile(
            (string) $result->getFilename(),
            max(0, (int) $result->getDuration()),
            max(0, (int) $result->getSize()),
            (string) $result->getLocation(),
        );
    }
}