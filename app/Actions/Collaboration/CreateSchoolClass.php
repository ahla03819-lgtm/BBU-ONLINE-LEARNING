<?php

namespace App\Actions\Collaboration;

use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateSchoolClass
{
    public function __construct(private ProvisionDefaultChannels $provision, private AuditLogger $audit) {}

    public function handle(array $data): SchoolClass
    {
        return DB::transaction(function () use ($data) {
            $class = SchoolClass::query()->create($data);
            $this->provision->handle($class, auth()->id());
            $this->audit->log('school-class.created', $class, [], $class->toArray());

            return $class;
        });
    }
}
