<?php

namespace App\Filament\Admin\Resources\Amenities;

use App\Filament\Admin\Resources\Amenities\Pages\ManagePropertyAmenities;
use App\Models\Property\PropertyAmenity;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PropertyAmenityResource extends Resource
{
    protected static ?string $model = PropertyAmenity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $slug = 'amenities';

    protected static ?string $navigationLabel = 'Amenities';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageProperties();
    }

    // SEC-006: explicit mutation gates — all amenity writes require property management.
    public static function canCreate(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canDeleteAny(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')
                ->label('Category')
                ->required()
                ->searchable()
                ->options(fn () => PropertyAmenity::distinct()
                    ->orderBy('category')
                    ->pluck('category', 'category')
                    ->map(fn ($cat) => PropertyAmenity::categoryLabel($cat))
                    ->toArray()
                )
                ->createOptionForm([
                    TextInput::make('slug')
                        ->label('New Category Slug')
                        ->required()
                        ->placeholder('e.g. cover_scents')
                        ->helperText('Lowercase letters and underscores only.')
                        ->regex('/^[a-z][a-z0-9_]*$/'),
                ])
                ->createOptionUsing(function (array $data): string {
                    $slug = $data['slug'] ?? '';
                    if (! preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
                        throw new \InvalidArgumentException('Category slug may only contain lowercase letters, numbers, and underscores.');
                    }
                    return $slug;
                }),
            TextInput::make('name')
                ->required()
                ->maxLength(100),
            TextInput::make('icon_name')
                ->label('Icon')
                ->maxLength(50)
                ->placeholder('hero-home')
                ->helperText('Optional Heroicon name for future UI use.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->formatStateUsing(fn ($state) => PropertyAmenity::categoryLabel($state))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('icon_name')
                    ->label('Icon')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('category')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Amenity'),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePropertyAmenities::route('/'),
        ];
    }
}
