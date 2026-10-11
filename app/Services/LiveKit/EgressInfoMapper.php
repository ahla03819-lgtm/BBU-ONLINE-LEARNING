<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;
use Carbon\CarbonImmutable;
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
    /** Existing meeting_recordings.duration_seconds is an unsigned MySQL integer. */
    private const MAX_STORABLE_DURATION_SECONDS = '4294967295';

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
            self::durationSeconds($result->getDuration()),
            max(0, (int) $result->getSize()),
            (string) $result->getLocation(),
        );
    }

    /**
     * LiveKit reports FileInfo.duration as an int64 count of nanoseconds.
     *
     * Protobuf may expose int64 values as integers or decimal strings depending
     * on the platform. Dividing the decimal representation avoids converting a
     * large value through float and preserves the existing whole-second
     * truncation convention. Values outside the provider's signed-int64 contract
     * are invalid rather than durations to clamp into a plausible recording.
     */
    public static function durationSeconds(int|string $durationNanoseconds): int
    {
        $duration = self::positiveInt64($durationNanoseconds);
        if ($duration === null) {
            return 0;
        }

        $seconds = strlen($duration) > 9 ? substr($duration, 0, -9) : '0';
        if (strlen($seconds) > strlen(self::MAX_STORABLE_DURATION_SECONDS)
            || (strlen($seconds) === strlen(self::MAX_STORABLE_DURATION_SECONDS)
                && strcmp($seconds, self::MAX_STORABLE_DURATION_SECONDS) > 0)) {
            return 0;
        }

        return (int) $seconds;
    }

    /**
     * Converts LiveKit's positive nanosecond Unix timestamp to an immutable UTC
     * value. A protobuf int64 is surfaced as an integer or a decimal string.
     */
    public static function timestamp(int|string $timestampNanoseconds): ?CarbonImmutable
    {
        $timestamp = self::positiveInt64($timestampNanoseconds);
        if ($timestamp === null) {
            return null;
        }

        $seconds = strlen($timestamp) > 9 ? substr($timestamp, 0, -9) : '0';
        if ($seconds === '0' || self::greaterThan($seconds, (string) PHP_INT_MAX)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampUTC((int) $seconds);
        } catch (\Throwable) {
            // Do not let malformed provider data block reconciliation or webhook
            // ingestion by constructing a date Carbon cannot represent.
            return null;
        }
    }

    /**
     * @return non-empty-string|null
     */
    private static function positiveInt64(int|string $value): ?string
    {
        $value = is_int($value) ? (string) $value : trim($value);
        if (! preg_match('/^\d+$/', $value)) {
            return null;
        }

        $value = ltrim($value, '0');
        if ($value === '') {
            return null;
        }

        return self::greaterThan($value, '9223372036854775807') ? null : $value;
    }

    private static function greaterThan(string $left, string $right): bool
    {
        return strlen($left) > strlen($right)
            || (strlen($left) === strlen($right) && strcmp($left, $right) > 0);
    }
}
