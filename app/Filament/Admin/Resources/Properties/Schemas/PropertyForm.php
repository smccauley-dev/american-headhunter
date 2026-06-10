<?php

namespace App\Filament\Admin\Resources\Properties\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PropertyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Listing Info')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(4),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft'     => 'Draft',
                                'active'    => 'Active',
                                'suspended' => 'Suspended',
                                'archived'  => 'Archived',
                            ])
                            ->default('draft'),
                    ]),

                Section::make('Location')
                    ->columns(2)
                    ->schema([
                        TextInput::make('state_code')
                            ->label('State Code')
                            ->required()
                            ->maxLength(2)
                            ->placeholder('TX'),
                        TextInput::make('county')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('center_lat')
                            ->label('Latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->placeholder('30.267153')
                            ->helperText('WGS84 decimal degrees — used for map pin display only.'),
                        TextInput::make('center_lng')
                            ->label('Longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->placeholder('-97.743057')
                            ->helperText('Negative values are West. Not used for spatial queries.'),
                    ]),

                Section::make('Acreage')
                    ->columns(2)
                    ->schema([
                        TextInput::make('total_acres')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('huntable_acres')
                            ->numeric()
                            ->minValue(0),
                    ]),

                // address_encrypted is never exposed in admin UI — managed via PropertyService
                // boundary_geospatial_id and primary_photo_document_id are set by services
            ]);
    }
}
