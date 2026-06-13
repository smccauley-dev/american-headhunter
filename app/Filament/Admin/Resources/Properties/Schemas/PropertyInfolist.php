<?php

namespace App\Filament\Admin\Resources\Properties\Schemas;

use App\Models\Property\Property;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PropertyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Listing Info')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->fontFamily('mono'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active'    => 'success',
                                'draft'     => 'gray',
                                'suspended' => 'danger',
                                'archived'  => 'warning',
                                default     => 'gray',
                            }),
                        TextEntry::make('title'),
                        TextEntry::make('slug')
                            ->fontFamily('mono'),
                        TextEntry::make('description')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'ah-description-entry']),
                    ]),

                Section::make('Location')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('state_code')
                            ->label('State')
                            ->formatStateUsing(fn ($state) => \App\Support\UsStates::names()[$state] ?? $state),
                        TextEntry::make('county'),
                        TextEntry::make('center_lat')
                            ->label('Latitude')
                            ->placeholder('—')
                            ->fontFamily('mono'),
                        TextEntry::make('center_lng')
                            ->label('Longitude')
                            ->placeholder('—')
                            ->fontFamily('mono'),
                    ]),

                Section::make('Acreage')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('total_acres')
                            ->label('Total Acres')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('huntable_acres')
                            ->label('Huntable Acres')
                            ->numeric(decimalPlaces: 2)
                            ->placeholder('—'),
                    ]),

                Section::make('Internal References')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('owner_user_id')
                            ->label('Owner UUID')
                            ->fontFamily('mono'),
                        TextEntry::make('boundary_geospatial_id')
                            ->label('Boundary UUID')
                            ->fontFamily('mono')
                            ->placeholder('—'),
                        TextEntry::make('primary_photo_document_id')
                            ->label('Primary Photo UUID')
                            ->fontFamily('mono')
                            ->placeholder('—'),
                    ]),

                Section::make('Timestamps')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                        TextEntry::make('deleted_at')
                            ->dateTime()
                            ->visible(fn (Property $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
