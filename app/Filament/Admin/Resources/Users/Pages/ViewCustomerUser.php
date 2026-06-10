<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Users\CustomerUserResource;
use App\Services\Identity\UserService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ViewCustomerUser extends ViewRecord
{
    use HasIconPageHeading;
    use HasViewPageScaffold;

    protected static string $resource = CustomerUserResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $name = trim(
            ($this->getRecord()->profile?->first_name ?? '')
            . ' '
            . ($this->getRecord()->profile?->last_name ?? '')
        ) ?: $this->getRecord()->email;

        return $this->headingWithIcon($name, 'heroicon-o-user');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(3)
                ->schema([
                    TextEntry::make('email')->label('Email'),
                    TextEntry::make('phone')->label('Phone')->placeholder('-'),
                    TextEntry::make('account_type')
                        ->label('Primary Portal')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'hunter'     => 'info',
                            'landowner'  => 'success',
                            'club'       => 'warning',
                            'outfitter'  => 'primary',
                            'consultant' => 'secondary',
                            'seller'     => 'gray',
                            default      => 'gray',
                        }),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'active'               => 'success',
                            'suspended'            => 'warning',
                            'banned'               => 'danger',
                            'pending_verification' => 'gray',
                            default                => 'gray',
                        }),
                    TextEntry::make('trust_score')->label('Trust Score'),
                    TextEntry::make('is_veteran')
                        ->label('Veteran')
                        ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                    TextEntry::make('last_login_at')
                        ->label('Last Login')
                        ->dateTime('M j, Y H:i')
                        ->placeholder('Never'),
                    TextEntry::make('created_at')
                        ->label('Registered')
                        ->dateTime('M j, Y'),
                ]),

            Section::make('Profile')
                ->columns(3)
                ->schema([
                    TextEntry::make('profile.first_name')->label('First Name')->placeholder('-'),
                    TextEntry::make('profile.last_name')->label('Last Name')->placeholder('-'),
                    TextEntry::make('profile.display_name')->label('Display Name')->placeholder('-'),
                    TextEntry::make('profile.state_code')->label('State')->placeholder('-'),
                    TextEntry::make('profile.zip_code')->label('ZIP')->placeholder('-'),
                    TextEntry::make('profile.date_of_birth')
                        ->label('Date of Birth')
                        ->date('M j, Y')
                        ->placeholder('-'),
                    TextEntry::make('profile.gender')->label('Gender')->placeholder('-'),
                    TextEntry::make('profile.bio')
                        ->label('Bio')
                        ->columnSpanFull()
                        ->placeholder('-'),
                ]),

            Section::make('Platform Roles')
                ->schema([
                    TextEntry::make('assigned_roles')
                        ->label('Assigned Roles')
                        ->state(fn ($record) =>
                            $record->roles
                                ->whereIn('name', CustomerUserResource::getNonAdminRoles())
                                ->pluck('display_name')
                                ->join(', ') ?: 'No roles assigned'
                        ),
                ]),

            Section::make('MFA Status')
                ->description('Use the actions above to disable individual factors or regenerate recovery codes. Use "Reset MFA" to wipe all factors and tokens.')
                ->columns(4)
                ->schema([
                    TextEntry::make('mfa_totp')
                        ->label('TOTP Authenticator')
                        ->state(fn ($record) => self::factorState($record->id, 'totp'))
                        ->badge()
                        ->color(fn ($state) => self::factorColor($state)),

                    TextEntry::make('mfa_sms')
                        ->label('SMS OTP')
                        ->state(fn ($record) => self::factorState($record->id, 'sms'))
                        ->badge()
                        ->color(fn ($state) => self::factorColor($state)),

                    TextEntry::make('mfa_email')
                        ->label('Email OTP')
                        ->state(fn ($record) => self::factorState($record->id, 'email'))
                        ->badge()
                        ->color(fn ($state) => self::factorColor($state)),

                    TextEntry::make('mfa_recovery_codes')
                        ->label('Recovery Codes')
                        ->state(fn ($record) => self::recoveryCodeSummary($record->id))
                        ->color(fn ($state) => str_starts_with($state, 'None') ? 'gray' : 'info'),
                ]),
        ]);
    }

    private static function factorState(string $userId, string $method): string
    {
        $row = DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $userId)
            ->where('method', $method)
            ->first(['is_enabled', 'verified_at']);

        if (! $row) {
            return 'Not enrolled';
        }

        if ($row->verified_at && $row->is_enabled) {
            $date = \Carbon\Carbon::parse($row->verified_at)->format('M j, Y');
            return "Active (verified {$date})";
        }

        if ($row->verified_at && ! $row->is_enabled) {
            return 'Enrolled â disabled';
        }

        return 'Pending confirmation';
    }

    private static function factorColor(string $state): string
    {
        return match (true) {
            str_starts_with($state, 'Active')  => 'success',
            str_contains($state, 'disabled')   => 'warning',
            str_starts_with($state, 'Pending') => 'info',
            default                            => 'gray',
        };
    }

    private static function recoveryCodeSummary(string $userId): string
    {
        $unused = DB::connection('identity')
            ->table('user_recovery_codes')
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->count();

        if ($unused === 0) {
            return 'None â will be generated on first MFA enrollment';
        }

        return "{$unused} of 10 unused";
    }

    private function isFactorActive(string $method): bool
    {
        $row = DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $this->getRecord()->id)
            ->where('method', $method)
            ->first(['is_enabled', 'verified_at']);

        return (bool) ($row && $row->verified_at && $row->is_enabled);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            ...$this->standardViewHeaderActions(),

            // Nuclear reset — all factors, recovery codes, and tokens wiped
            Action::make('reset_mfa')
                ->label('Reset MFA')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reset all MFA for this user?')
                ->modalDescription(
                    'This disables all enrolled factors, invalidates all recovery codes, '
                    . 'and revokes all active API tokens. The user must re-authenticate '
                    . 'and re-enroll MFA from scratch.'
                )
                ->visible(fn () => AdminAuth::isSuperAdmin())
                ->action(function () use ($record) {
                    app(UserService::class)->resetMfa($record, auth()->id());

                    Notification::make()
                        ->title('MFA reset â all factors disabled, recovery codes invalidated, tokens revoked')
                        ->success()
                        ->send();
                }),

            // Per-factor disable — surgical, leaves other factors intact
            Action::make('disable_totp')
                ->label('Disable TOTP')
                ->icon('heroicon-o-qr-code')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Disable TOTP authenticator?')
                ->modalDescription(
                    "The user's TOTP authenticator factor will be disabled. Other factors "
                    . 'and recovery codes remain intact. The user can re-enroll TOTP at any time.'
                )
                ->visible(fn () => AdminAuth::canManageSecurity() && $this->isFactorActive('totp'))
                ->action(function () use ($record) {
                    app(UserService::class)->disableMfaFactor($record, 'totp', auth()->id());

                    Notification::make()
                        ->title('TOTP authenticator disabled')
                        ->success()
                        ->send();
                }),

            Action::make('disable_email_otp')
                ->label('Disable Email OTP')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Disable Email OTP?')
                ->modalDescription(
                    "The user's email OTP factor will be disabled. Other factors "
                    . 'and recovery codes remain intact. The user can re-enroll at any time.'
                )
                ->visible(fn () => AdminAuth::canManageSecurity() && $this->isFactorActive('email'))
                ->action(function () use ($record) {
                    app(UserService::class)->disableMfaFactor($record, 'email', auth()->id());

                    Notification::make()
                        ->title('Email OTP disabled')
                        ->success()
                        ->send();
                }),

            Action::make('disable_sms')
                ->label('Disable SMS OTP')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Disable SMS OTP?')
                ->modalDescription(
                    "The user's SMS OTP factor will be disabled. Other factors "
                    . 'and recovery codes remain intact. The user can re-enroll at any time.'
                )
                ->visible(fn () => AdminAuth::canManageSecurity() && $this->isFactorActive('sms'))
                ->action(function () use ($record) {
                    app(UserService::class)->disableMfaFactor($record, 'sms', auth()->id());

                    Notification::make()
                        ->title('SMS OTP disabled')
                        ->success()
                        ->send();
                }),

            // Recovery code regeneration — codes emailed to user, admin never sees them
            Action::make('regenerate_recovery_codes')
                ->label('Regenerate Recovery Codes')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Regenerate recovery codes?')
                ->modalDescription(
                    'New recovery codes will be generated and emailed directly to the user. '
                    . "Their existing codes will stop working immediately. "
                    . "You will not see the new codes â they go straight to the user's inbox."
                )
                ->visible(fn () => AdminAuth::canManageSecurity())
                ->action(function () use ($record) {
                    app(UserService::class)->adminRegenerateRecoveryCodes($record, auth()->id());

                    Notification::make()
                        ->title("New recovery codes generated and emailed to {$record->email}")
                        ->success()
                        ->send();
                }),

            // Token / account actions
            Action::make('revoke_tokens')
                ->label('Revoke All Tokens')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Revoke all API tokens?')
                ->modalDescription('This immediately logs the user out of all mobile devices. They will need to log in again.')
                ->visible(fn () => AdminAuth::isSuperAdmin())
                ->action(function () use ($record) {
                    app(UserService::class)->revokeAllTokens($record);

                    Notification::make()
                        ->title('All API tokens revoked')
                        ->success()
                        ->send();
                }),

            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $record->status === 'active')
                ->url(fn () => static::getResource()::getUrl('edit', ['record' => $record])),

            Action::make('ban')
                ->label('Ban User')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => AdminAuth::isSuperAdmin() && $record->status !== 'banned')
                ->url(fn () => static::getResource()::getUrl('edit', ['record' => $record])),
        ];
    }
}
