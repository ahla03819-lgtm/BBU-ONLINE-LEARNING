<?php

namespace App\Actions\People;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SaveProfile
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Model $profile, array $data, string $type, string $role): Model
    {
        return DB::transaction(function () use ($profile, $data, $type, $role) {
            $before = $profile->exists ? $profile->toArray() : [];
            $profile->fill($data)->save();
            $profile->user->syncRoles([$role]);
            $this->audit->log($type.($before ? '.updated' : '.created'), $profile, $before, $profile->fresh()->toArray());

            return $profile;
        });
    }
}
