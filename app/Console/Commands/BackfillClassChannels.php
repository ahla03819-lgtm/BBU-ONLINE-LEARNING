<?php

namespace App\Console\Commands;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\Collaboration\ProvisionSubjectChannel;
use App\Enums\ClassSubjectStatus;
use App\Models\Channel;
use App\Models\SchoolClass;
use Illuminate\Console\Command;

class BackfillClassChannels extends Command
{
    protected $signature = 'collaboration:backfill-channels {--dry-run : Report changes without writing} {--chunk=100 : Classes per chunk}';

    protected $description = 'Provision missing default and subject channels for existing classes';

    public function handle(ProvisionDefaultChannels $defaults, ProvisionSubjectChannel $subjects): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $existing = 0;
        $failures = 0;

        SchoolClass::query()->with(['classSubjects' => fn ($query) => $query->where('status', ClassSubjectStatus::Active)->with('subject')])->orderBy('id')->chunkById($chunk, function ($classes) use ($defaults, $subjects, $dryRun, &$created, &$existing, &$failures) {
            foreach ($classes as $class) {
                try {
                    if ($dryRun) {
                        $existingDefaults = Channel::query()->where('school_class_id', $class->id)->whereNotNull('default_slot')->count();
                        $existingSubjects = Channel::query()->whereIn('class_subject_id', $class->classSubjects->pluck('id'))->count();
                        $existing += $existingDefaults + $existingSubjects;
                        $created += (2 - $existingDefaults) + ($class->classSubjects->count() - $existingSubjects);

                        continue;
                    }
                    $result = $defaults->handle($class);
                    $created += $result['created'];
                    $existing += $result['existing'];
                    foreach ($class->classSubjects as $classSubject) {
                        $channel = $subjects->handle($classSubject);
                        $channel->wasRecentlyCreated ? $created++ : $existing++;
                    }
                } catch (\Throwable $exception) {
                    $failures++;
                    $this->error("Class {$class->id}: {$exception->getMessage()}");
                }
            }
        });

        $mode = $dryRun ? 'Dry run' : 'Backfill';
        $this->info("{$mode} complete: {$created} to create/created, {$existing} existing, {$failures} failures.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
