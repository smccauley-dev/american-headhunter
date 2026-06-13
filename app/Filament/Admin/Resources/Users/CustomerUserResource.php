<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\EditCustomerUser;
use App\Filament\Admin\Resources\Users\Pages\ListCustomerUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewCustomerUser;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $slug = 'platform-users';

    protected static ?string $navigationLabel = 'Platform Users';

    protected static ?string $modelLabel = 'Platform User';

    protected static ?int $navigationSort = 2;

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

    private static array $customerAccountTypes = [
        'hunter', 'landowner', 'club', 'outfitter', 'consultant', 'seller',
    ];

    private static array $nonAdminRoles = [
        'hunter', 'landowner', 'club_admin', 'outfitter', 'consultant', 'seller',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('profile.first_name')
                    ->label('Name')
                    ->formatStateUsing(fn ($record) =>
                        trim(($record->profile?->first_name ?? '') . ' ' . ($record->profile?->last_name ?? '')) ?: '—'
                    )
                    ->description(fn ($record) => $record->email)
                    ->searchable(query: fn (Builder $query, string $search) =>
                        $query->where('email', 'ilike', "%{$search}%")
                              ->orWhereHas('profile', fn ($q) =>
                                  $q->where('first_name', 'ilike', "%{$search}%")
                                    ->orWhere('last_name',  'ilike', "%{$search}%")
                              )
                    )
                    ->sortable(query: fn (Builder $query, string $direction) =>
                        $query->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
                              ->orderBy('user_profiles.last_name', $direction)
                    ),
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'hunter'     => 'info',
                        'landowner'  => 'success',
                        'club'       => 'warning',
                        'outfitter'  => 'primary',
                        'consultant' => 'secondary',
                        'seller'     => 'gray',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'hunter'     => 'Hunter',
                        'landowner'  => 'Landowner',
                        'club'       => 'Club',
                        'outfitter'  => 'Outfitter',
                        'consultant' => 'Consultant',
                        'seller'     => 'Seller',
                        default      => ucfirst($state),
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'active'               => 'success',
                        'suspended'            => 'warning',
                        'banned'               => 'danger',
                        'pending_verification' => 'gray',
                        default                => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'active'               => 'Active',
                        'suspended'            => 'Suspended',
                        'banned'               => 'Banned',
                        'pending_verification' => 'Pending',
                        default                => ucfirst($state),
                    }),
                TextColumn::make('trust_score')
                    ->label('Trust')
                    ->color(fn ($state) => match (true) {
                        $state < 40  => 'danger',
                        $state < 70  => 'warning',
                        default      => 'success',
                    })
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_type')
                    ->label('Account Type')
                    ->options([
                        'hunter'     => 'Hunter',
                        'landowner'  => 'Landowner',
                        'club'       => 'Club',
                        'outfitter'  => 'Outfitter',
                        'consultant' => 'Consultant',
                        'seller'     => 'Seller',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'active'               => 'Active',
                        'suspended'            => 'Suspended',
                        'banned'               => 'Banned',
                        'pending_verification' => 'Pending Verification',
                    ]),
                SelectFilter::make('state_code')
                    ->label('State')
                    ->options(\App\Support\UsStates::names())
                    ->searchable()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('profile', fn ($q) => $q->where('state_code', $data['value']))
                        : $query),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereIn('account_type', static::$customerAccountTypes)
            ->with(['profile', 'roles']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerUsers::route('/'),
            'edit'  => EditCustomerUser::route('/{record}/edit'),
            'view'  => ViewCustomerUser::route('/{record}'),
        ];
    }

    public static function getNonAdminRoles(): array
    {
        return static::$nonAdminRoles;
    }
}
