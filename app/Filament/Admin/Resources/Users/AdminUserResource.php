<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\CreateAdminUser;
use App\Filament\Admin\Resources\Users\Pages\EditAdminUser;
use App\Filament\Admin\Resources\Users\Pages\ListAdminUsers;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AdminUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'admin-users';

    protected static ?string $navigationLabel = 'Admin Users';

    protected static ?string $modelLabel = 'Admin User';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Users & Access';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSecurity();
    }

    private static array $adminRoles = [
        'super_admin', 'global_admin', 'property_admin',
        'security_admin', 'article_admin', 'staff',
    ];

    public static function form(Schema $schema): Schema
    {
        $adminRoleOptions = Role::whereIn('name', static::$adminRoles)
            ->orderBy('display_name')
            ->pluck('display_name', 'id')
            ->toArray();

        return $schema->components([
            \Filament\Schemas\Components\Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('first_name')
                        ->label('First Name')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('last_name')
                        ->label('Last Name')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('email')
                        ->label('Email Address')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(table: 'users', column: 'email', ignoreRecord: true)
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Password')
                ->schema([
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->minLength(10)
                        ->maxLength(128)
                        ->helperText('Leave blank on edit to keep the existing password.')
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create'),
                ]),

            \Filament\Schemas\Components\Section::make('Roles & Status')
                ->columns(2)
                ->schema([
                    CheckboxList::make('roles')
                        ->relationship(
                            'roles',
                            'display_name',
                            fn ($query) => $query->whereIn('name', static::$adminRoles)->orderBy('display_name')
                        )
                        ->columns(2)
                        ->columnSpanFull()
                        ->required()
                        ->helperText('Users may hold multiple roles. Access is the union of all assigned roles.'),
                    Select::make('status')
                        ->options([
                            'active'    => 'Active',
                            'suspended' => 'Suspended',
                        ])
                        ->default('active')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('profile.first_name')
                    ->label('Name')
                    ->formatStateUsing(fn ($record) =>
                        trim(($record->profile?->first_name ?? '') . ' ' . ($record->profile?->last_name ?? '')) ?: '—'
                    )
                    ->searchable(query: fn (Builder $query, string $search) =>
                        $query->whereHas('profile', fn ($q) =>
                            $q->where('first_name', 'ilike', "%{$search}%")
                              ->orWhere('last_name',  'ilike', "%{$search}%")
                        )
                    )
                    ->sortable(query: fn (Builder $query, string $direction) =>
                        $query->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
                              ->orderBy('user_profiles.last_name', $direction)
                    ),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->formatStateUsing(fn ($record) =>
                        $record->roles->whereIn('name', static::$adminRoles)
                            ->pluck('display_name')
                            ->join(', ') ?: '—'
                    )
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'active'    => 'success',
                        'suspended' => 'danger',
                        default     => 'gray',
                    }),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Admin User'),
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => AdminAuth::isSuperAdmin()),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', static::$adminRoles))
            ->with(['profile', 'roles']);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAdminUsers::route('/'),
            'create' => CreateAdminUser::route('/create'),
            'edit'   => EditAdminUser::route('/{record}/edit'),
        ];
    }
}
