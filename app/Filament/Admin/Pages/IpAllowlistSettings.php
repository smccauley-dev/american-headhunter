<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Services\Platform\TenantService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class IpAllowlistSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('IP Allowlist', 'heroicon-o-shield-check');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $title          = 'IP Allowlist';
    protected static ?int    $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Users & Access';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSecurity();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.ip-allowlist-settings';
    }

    public ?array $ipData     = [];
    public ?array $bypassData = [];

    protected function getForms(): array
    {
        return ['ipForm', 'bypassForm'];
    }

    public function mount(): void
    {
        $t   = app(TenantService::class);
        $ips = (array) $t->getSetting('admin.ip_allowlist', []);

        $this->ipForm->fill([
            'entries' => array_map(fn ($ip) => ['ip' => $ip], $ips),
        ]);

        $bypassIps = (array) $t->getSetting('admin.bypass_ips', []);
        $this->bypassForm->fill([
            'bypass_ips' => array_map(fn ($ip) => ['ip' => $ip], $bypassIps),
        ]);
    }

    public function ipForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('IP Allowlist')
                    ->description(new HtmlString(
                        '<strong>Empty list = all IPs allowed.</strong> ' .
                        'Add at least one entry to start restricting access. ' .
                        'Supports individual IPs (e.g. <code>203.0.113.1</code>) and CIDR ranges (e.g. <code>192.168.1.0/24</code>).'
                    ))
                    ->extraAttributes([
                        'style' => 'border:none!important;box-shadow:none!important;background:transparent!important;',
                    ])
                    ->schema([
                        Repeater::make('entries')
                            ->label('Allowed IPs / CIDR Ranges')
                            ->schema([
                                TextInput::make('ip')
                                    ->label('IP Address or CIDR Range')
                                    ->required()
                                    ->placeholder('e.g. 203.0.113.1 or 192.168.1.0/24')
                                    ->rule(function () {
                                        return function (string $attribute, mixed $value, \Closure $fail) {
                                            $parts  = explode('/', $value, 2);
                                            $ip     = $parts[0];
                                            $prefix = $parts[1] ?? null;

                                            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                                $fail('Enter a valid IPv4 address or CIDR range (e.g. 10.0.0.1 or 10.0.0.0/24).');
                                                return;
                                            }

                                            if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32)) {
                                                $fail('CIDR prefix must be between 0 and 32.');
                                            }
                                        };
                                    })
                                    ->maxLength(18),
                            ])
                            ->addable(false)
                            ->columnSpanFull()
                            ->reorderable(false)
                            ->helperText('Your current IP is: ' . request()->ip()),
                    ]),
            ])
            ->statePath('ipData');
    }

    public function bypassForm(Schema $schema): Schema
    {
        $envBypassIp = config('platform.admin_ip_bypass_ip');

        return $schema
            ->components([
                Section::make('Emergency Bypass IP')
                    ->description(new HtmlString(
                        'This IP always passes the allowlist check — even if the list blocks everything. ' .
                        'Use it to recover from a locked-out configuration without server access. ' .
                        'Leave blank to disable. ' .
                        ($envBypassIp
                            ? '<br><strong>Note:</strong> <code>ADMIN_IP_BYPASS_IP=' . e($envBypassIp) . '</code> is also set in the server environment and will always bypass regardless of this field.'
                            : '')
                    ))
                    ->schema([
                        Repeater::make('bypass_ips')
                            ->label('Bypass IPs')
                            ->schema([
                                TextInput::make('ip')
                                    ->label('IP Address')
                                    ->required()
                                    ->placeholder('e.g. 203.0.113.5')
                                    ->rule(function () {
                                        return function (string $attribute, mixed $value, \Closure $fail) {
                                            if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                                $fail('Enter a valid IPv4 address (e.g. 203.0.113.5).');
                                            }
                                        };
                                    })
                                    ->maxLength(15),
                            ])
                            ->addable(false)
                            ->columnSpanFull()
                            ->reorderable(false)
                            ->helperText('Stored in the database. The server-level ADMIN_IP_BYPASS_IP env var is a separate independent escape hatch.'),
                    ]),
            ])
            ->statePath('bypassData');
    }

    public function addEntry(): void
    {
        $state = $this->ipForm->getState();
        $state['entries'][] = ['ip' => ''];
        $this->ipForm->fill($state);
    }

    public function addBypassEntry(): void
    {
        $state = $this->bypassForm->getState();
        $state['bypass_ips'][] = ['ip' => ''];
        $this->bypassForm->fill($state);
    }

    public function save(): void
    {
        $ipState     = $this->ipForm->getState();
        $bypassState = $this->bypassForm->getState();

        $entries    = $ipState['entries'] ?? [];
        $ips        = array_values(array_filter(array_map(fn ($e) => trim($e['ip'] ?? ''), $entries)));
        $bypassIps  = array_values(array_filter(array_map(fn ($e) => trim($e['ip'] ?? ''), $bypassState['bypass_ips'] ?? [])));

        $t = app(TenantService::class);
        $t->setSetting('admin.ip_allowlist', $ips);
        $t->setSetting('admin.bypass_ips', $bypassIps);

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'platform',
            tableName:      'tenant_settings',
            recordId:       'admin.ip_allowlist',
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  'Admin IP allowlist updated (' . count($ips) . ' entries, ' . count($bypassIps) . ' bypass IPs)',
            changedFields:  ['admin.ip_allowlist', 'admin.bypass_ips'],
        );

        $message = empty($ips)
            ? 'IP allowlist cleared — all IPs are now permitted.'
            : 'IP allowlist saved — ' . count($ips) . ' ' . str('entry')->plural(count($ips)) . ' active.';

        Notification::make()->title($message)->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
