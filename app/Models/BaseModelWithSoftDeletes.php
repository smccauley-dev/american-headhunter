<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseModelWithSoftDeletes extends BaseModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'deleted_at' => 'datetime',
        ]);
    }
}
