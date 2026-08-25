<?php

namespace App\Actions\Academics;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SaveAcademicResource
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Model $model, array $data, string $resource): Model
    {
        return DB::transaction(function () use ($model, $data, $resource) {
            $before = $model->exists ? $model->toArray() : [];
            $model->fill($data)->save();
            $this->audit->log($resource.($before ? '.updated' : '.created'), $model, $before, $model->fresh()->toArray());

            return $model;
        });
    }
}
