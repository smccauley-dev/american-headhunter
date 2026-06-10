<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use App\Services\Platform\TenantService;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class LoginPageSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Login Page Settings', 'heroicon-o-lock-closed');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $title = 'Login Page Settings';
    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSecurity();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.login-page-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $t = app(TenantService::class);

        $this->form->fill([
            'unauthorized_message'    => $t->getSetting('login.unauthorized_message',    'This system is for authorized users only. Unauthorized access or use is prohibited and may result in criminal or civil penalties.'),
            'policy_label'            => $t->getSetting('login.policy_label',            'Authorized Use Policy'),
            'policy_url'              => $t->getSetting('login.policy_url',              '/authorized-use-policy'),
            'security_policy_label'   => $t->getSetting('login.security_policy_label',   'Security Policy'),
            'security_policy_url'     => $t->getSetting('login.security_policy_url',     '/security-policy'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unauthorized Use Notice')
                    ->description('Displayed below the Sign In button on the admin login page.')
                    ->schema([
                        Textarea::make('unauthorized_message')
                            ->label('Message')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Leave blank to hide the notice entirely.'),
                    ]),

                Section::make('Policy Links')
                    ->description('Two configurable links displayed below the notice. Leave URL blank to hide a link.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('policy_label')
                            ->label('Link 1 — Label')
                            ->maxLength(80)
                            ->placeholder('Authorized Use Policy'),
                        TextInput::make('policy_url')
                            ->label('Link 1 — URL')
                            ->maxLength(500)
                            ->placeholder('/authorized-use-policy')
                            ->regex('/^(\/|https?:\/\/).+/')
                            ->validationMessages(['regex' => 'Must be a relative path (e.g. /policy) or full https:// URL.']),
                        TextInput::make('security_policy_label')
                            ->label('Link 2 — Label')
                            ->maxLength(80)
                            ->placeholder('Security Policy'),
                        TextInput::make('security_policy_url')
                            ->label('Link 2 — URL')
                            ->maxLength(500)
                            ->placeholder('/security-policy')
                            ->regex('/^(\/|https?:\/\/).+/')
                            ->validationMessages(['regex' => 'Must be a relative path (e.g. /policy) or full https:// URL.']),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $t    = app(TenantService::class);

        $t->setSetting('login.unauthorized_message',  $data['unauthorized_message']  ?? '');
        $t->setSetting('login.policy_label',           $data['policy_label']           ?? '');
        $t->setSetting('login.policy_url',             $data['policy_url']             ?? '');
        $t->setSetting('login.security_policy_label',  $data['security_policy_label']  ?? '');
        $t->setSetting('login.security_policy_url',    $data['security_policy_url']    ?? '');

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'platform',
            tableName:      'tenant_settings',
            recordId:       'login.*',
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  'Login page settings updated via admin',
            changedFields:  array_keys($data),
        );

        Notification::make()
            ->title('Login page settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
