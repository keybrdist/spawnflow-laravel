<?php

namespace Spawnflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

interface Presentable
{
    /**
     * Transform a model instance into an API response.
     */
    public function toResponse(Model $instance, ?FieldContext $context = null): JsonResponse;
}
