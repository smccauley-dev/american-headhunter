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

    private static array $speciesLabels = [
        'whitetail_deer' => 'Whitetail Deer',
        'mule_deer'      => 'Mule Deer',
        'turkey'         => 'Turkey',
        'waterfowl'      => 'Waterfowl',
        'dove'           => 'Dove',
        'hog'            => 'Hog',
        'elk'            => 'Elk',
        'bear'           => 'Bear',
        'antelope'       => 'Antelope',
        'pheasant'       => 'Pheasant',
        'quail'          => 'Quail',
        'rabbit'         => 'Rabbit',
        'squirrel'       => 'Squirrel',
        'coyote'         => 'Coyote',
        'other'          => 'Other',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('species_code')
                    ->label('Species')
                    ->required()
                    ->options(self::$speciesLabels)
                    ->helperText('Each species can only be added once per property.'),
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
                    ->formatStateUsing(fn (string $state): string => self::$speciesLabels[$state] ?? $state),
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
