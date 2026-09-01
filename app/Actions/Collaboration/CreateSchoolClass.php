<?php

namespace App\Actions\Collaboration;

use App\Enums\SchoolClassStatus;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use App\Services\ClassJoinCode;
use Illuminate\Support\Facades\DB;

class CreateSchoolClass
{
    public function __construct(private ProvisionDefaultChannels $provision, private AuditLogger $audit, private ClassJoinCode $joinCodes) {}

    public function handle(array $data): SchoolClass
    {
        return DB::transaction(function () use ($data) {
            $data['join_code'] = $this->joinCodes->next();
            $data['join_code_enabled'] = $data['status'] === SchoolClassStatus::Active->value;
            $class = SchoolClass::query()->create($data);
            $this->provision->handle($class, auth()->id());
            $this->audit->log('school-class.created', $class, [], $class->toArray());

            return $class;
        });
    }
}
