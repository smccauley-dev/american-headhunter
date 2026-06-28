<?php

namespace App\Filament\Admin\Resources\Properties\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpeciesRelationManager extends RelationManager
{
    protected static string $relationship = 'species';

    protected static ?string $title = 'Species';

    /** Active types for the picker; all types (incl. deactivated) for display. */
    private static function speciesLabels(bool $activeOnly = true): array
    {
        return app(\App\Services\Property\PropertyService::class)->speciesLabels($activeOnly);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('species_code')
                    ->label('Species')
                    ->required()
                    ->options(fn () => self::speciesLabels())
                    ->helperText('Each species can only be added once per property.'),
                Select::make('availability')
                    ->label('Availability')
                    ->required()
                    ->default('seasonal')
                    ->options(\App\Services\Property\PropertyService::AVAILABILITY_OPTIONS)
                    ->helperText('Huntable in a regulated season, or year-round (e.g. hogs, coyotes).'),
                Toggle::make('is_primary')
                    ->label('Primary Species')
                    ->helperText('Mark as the main huntable species for this property.')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('species_code')
                    ->label('Species')
                    ->formatStateUsing(fn (string $state): string => self::speciesLabels(false)[$state] ?? $state),
                TextColumn::make('availability')
                    ->label('Availability')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => \App\Services\Property\PropertyService::AVAILABILITY_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'year_round' ? 'success' : 'gray'),
                IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Species'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
