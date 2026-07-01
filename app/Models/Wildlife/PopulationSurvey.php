<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

class PopulationSurvey extends BaseModel
{
    protected $connection = 'wildlife';

    protected $table = 'population_surveys';

    protected $fillable = [
        'property_id',
        'species_code',
        'survey_year',
        'method',
        'estimated_count',
        'buck_doe_ratio',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'survey_year' => 'integer',
            'estimated_count' => 'integer',
            'buck_doe_ratio' => 'decimal:2',
        ]);
    }
}
