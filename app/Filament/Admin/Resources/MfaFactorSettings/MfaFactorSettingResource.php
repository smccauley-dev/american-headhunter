<?php

namespace App\Filament\Admin\Resources\MfaFactorSettings;

use App\Filament\Admin\Resources\MfaFactorSettings\Pages\ManageMfaFactorSettings;
use App\Models\Platform\MfaFactorSetting;
use App\Services\Platform\MfaFactorService;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MfaFactorSettingResource extends Resource
{
    protected static ?string $model = MfaFactorSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'MFA Factors';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSystem();
    }

    // Rows are seeded — no creation from admin UI.
    public static function canCreate(): bool
    {
        return false;
    }

    // Rows are permanent — no deletion.
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('is_enabled')
                ->label('Enrollment open')
                ->helperText(
                    'Disabling prevents new enrollments for this method but does not affect '
                    . 'users who are already enrolled — they can still use it to verify. '
                    . 'SMS must remain disabled until a real SMS provider replaces StubSmsDriver '
                    . '(see DEPLOYMENT.md §1a).'
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('factor')
                    ->label('Factor')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'totp'  => 'TOTP Authenticator',
                        'sms'   => 'SMS OTP',
                        'email' => 'Email OTP',
                        default => $state,
                    })
                    ->sortable(),

                IconColumn::make('is_enabled')
                    ->label('Enrollment Open')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('updated_at')
                    ->label('Last Changed')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('factor')
            ->filters([])
            ->recordActions([
                EditAction::make()
                    ->after(fn (MfaFactorSetting $record) =>
                        app(MfaFactorService::class)->invalidateFactor($record->factor)
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMfaFactorSettings::route('/'),
        ];
    }
}
