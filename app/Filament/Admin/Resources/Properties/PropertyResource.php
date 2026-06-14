<?php

namespace App\Filament\Admin\Resources\Properties;

use App\Filament\Admin\Resources\Properties\Pages\EditPropertyV2;
use App\Filament\Admin\Resources\Properties\Pages\ListProperties;
use App\Filament\Admin\Resources\Properties\Pages\ViewPropertyV2;
use App\Filament\Admin\Resources\Properties\RelationManagers\ListingsRelationManager;
use App\Filament\Admin\Resources\Properties\RelationManagers\RulesRelationManager;
use App\Filament\Admin\Resources\Properties\RelationManagers\SpeciesRelationManager;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyForm;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyInfolist;
use App\Filament\Admin\Resources\Properties\Tables\PropertiesTable;
use App\Models\Property\Property;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PropertyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PropertyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertiesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManageProperties();
    }

    // SEC-006: explicit mutation gates — edit/delete/restore require property
    // management; force-delete (permanent) is super_admin only, matching the
    // single-record ForceDeleteAction gate (SEC-019) so the bulk path can't bypass it.
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

    public static function canRestore(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canRestoreAny(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canForceDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::isSuperAdmin();
    }

    public static function canForceDeleteAny(): bool
    {
        return AdminAuth::isSuperAdmin();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug', 'state_code', 'county'];
    }

    public static function getRelations(): array
    {
        return [
            SpeciesRelationManager::class,
            RulesRelationManager::class,
            ListingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
            'view'  => ViewPropertyV2::route('/{record}'),
            'edit'  => EditPropertyV2::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
