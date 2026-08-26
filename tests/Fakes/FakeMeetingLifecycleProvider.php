<?php

namespace Tests\Fakes;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Models\Meeting;
use Closure;
use RuntimeException;

class FakeMeetingLifecycleProvider implements MeetingLifecycleProvider
{
    public MeetingProviderState $startState = MeetingProviderState::Active;

    public MeetingProviderState $endState = MeetingProviderState::Ended;

    public MeetingProviderState $inspectState = MeetingProviderState::Unknown;

    public int $startCalls = 0;

    public int $endCalls = 0;

    public bool $failStart = false;

    public bool $failEnd = false;

    public ?Closure $onStart = null;

    public function start(Meeting $meeting, string $attemptUuid): MeetingProviderState
    {
        $this->startCalls++;
        if ($this->onStart) {
            ($this->onStart)($meeting, $attemptUuid);
        }
        if ($this->failStart) {
            throw new RuntimeException('secret-provider-detail-should-not-persist');
        }

        return $this->startState;
    }

    public function end(Meeting $meeting): MeetingProviderState
    {
        $this->endCalls++;
        if ($this->failEnd) {
            throw new RuntimeException('secret-provider-detail-should-not-persist');
        }

        return $this->endState;
    }

    public function inspect(Meeting $meeting): MeetingProviderState
    {
        return $this->inspectState;
    }
}
