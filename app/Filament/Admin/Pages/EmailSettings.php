<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Services\Communications\MailSettingsService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EmailSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Email Settings', 'heroicon-o-envelope');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $title = 'Email Settings';
    protected static ?int $navigationSort = 13;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.email-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(MailSettingsService::class)->getSettings();

        $this->form->fill([
            'enabled'      => $settings['enabled'],
            'preset'       => $settings['preset'],
            'host'         => $settings['host'],
            'port'         => $settings['port'],
            'encryption'   => $settings['encryption'],
            'username'     => $settings['username'],
            'password'     => '',
            'from_address' => $settings['from_address'],
            'from_name'    => $settings['from_name'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $presets = MailSettingsService::PRESETS;

        return $schema
            ->components([
                Section::make('Outbound Mail')
                    ->description('When enabled, these settings replace the server .env mail configuration. When disabled, the .env configuration is used.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Use these settings for outbound email')
                            ->helperText('Leave off to keep sending through the .env-configured mailer.'),
                        Select::make('preset')
                            ->label('Provider Preset')
                            ->options(array_map(fn (array $p) => $p['label'], $presets))
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set) use ($presets): void {
                                if ($state !== null && $state !== 'custom' && isset($presets[$state])) {
                                    $set('host', $presets[$state]['host']);
                                    $set('port', $presets[$state]['port']);
                                    $set('encryption', $presets[$state]['encryption']);
                                }
                            })
                            ->helperText('Selecting a preset pre-fills host, port and encryption. All fields stay editable.'),
                    ]),

                Section::make('SMTP Connection')
                    ->columns(2)
                    ->schema([
                        TextInput::make('host')
                            ->label('Host')
                            ->placeholder('smtp.example.com')
                            ->maxLength(255),
                        TextInput::make('port')
                            ->label('Port')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535),
                        Select::make('encryption')
                            ->label('Encryption')
                            ->options(['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL/TLS', 'none' => 'None'])
                            ->default('tls'),
                        TextInput::make('username')
                            ->label('Username')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        TextInput::make('password')
                            ->label('Password / API Key')
                            ->password()
                            ->revealable()
                            ->maxLength(1000)
                            ->autocomplete('new-password')
                            ->helperText(app(MailSettingsService::class)->getSettings()['has_password']
                                ? 'A password is stored (encrypted). Leave blank to keep it; enter a new value to replace it.'
                                : 'No password stored yet. Stored encrypted — never displayed again after saving.'),
                    ]),

                Section::make('Sender Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('from_address')
                            ->label('From Address')
                            ->email()
                            ->placeholder('no-reply@americanheadhunter.com')
                            ->maxLength(255),
                        TextInput::make('from_name')
                            ->label('From Name')
                            ->placeholder('American Headhunter')
                            ->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        app(MailSettingsService::class)->saveSettings($data);

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'platform',
            tableName:      'tenant_settings',
            recordId:       'mail.*',
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  'Outbound email settings updated via admin',
            changedFields:  array_values(array_diff(array_keys($data), ['password'])),
        );

        // Never echo the password back into the form state.
        $this->data['password'] = '';

        Notification::make()
            ->title('Email settings saved')
            ->success()
            ->send();
    }

    public function testEmailAction(): Action
    {
        return Action::make('testEmail')
            ->label('Send Test Email')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Send Test Email')
            ->modalDescription('Sends a short test message through the currently saved settings. Save your changes first — unsaved form values are not used.')
            ->form([
                TextInput::make('to')
                    ->label('Send To')
                    ->email()
                    ->required()
                    ->default(fn () => Auth::user()?->email),
            ])
            ->action(function (array $data): void {
                try {
                    app(MailSettingsService::class)->sendTest($data['to']);

                    Notification::make()
                        ->title('Test email sent')
                        ->body("Sent to {$data['to']} — check the inbox (and spam folder).")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Test email failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
