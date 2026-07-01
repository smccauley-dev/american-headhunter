<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

class Season extends BaseModel
{
    protected $connection = 'wildlife';

    protected $table = 'seasons';

    protected $fillable = [
        'state_code',
        'species_code',
        'season_name',
        'season_type',
        'start_date',
        'end_date',
        'year',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'start_date' => 'date',
            'end_date' => 'date',
            'year' => 'integer',
        ]);
    }
}
