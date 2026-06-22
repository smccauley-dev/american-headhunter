<?php

namespace App\Filament\Admin\Resources\ServiceVerifications;

use App\Filament\Admin\Resources\ServiceVerifications\Concerns\BuildsVerificationQueue;
use App\Filament\Admin\Resources\ServiceVerifications\Pages\ListVeteranVerifications;
use App\Models\Identity\VeteranVerification;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VeteranVerificationResource extends Resource
{
    use BuildsVerificationQueue;

    protected static ?string $model = VeteranVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'veteran-verifications';

    protected static ?string $navigationLabel = 'Veteran Verifications';

    protected static ?string $modelLabel = 'Veteran Verification';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Users & Access';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageUsers();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return static::configureQueueTable($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user.profile');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVeteranVerifications::route('/'),
        ];
    }
}
