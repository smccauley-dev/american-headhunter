<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Services\Platform\TenantService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Global switches for the game-type icons shown on public listings: turn the
 * icons on or off platform-wide, and toggle/edit the artist credit so a
 * different illustrator or licence can be named (or the credit dropped entirely
 * for original artwork). Stored as `game_icons.*` in DB 12 tenant_settings.
 */
class GameIconSettings extends Page implements HasForms
{
    use HasIconPageHeading;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $title = 'Game Icon Settings';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Game Icon Settings', 'heroicon-o-sparkles');
    }

    public function getView(): string
    {
        return 'filament.admin.pages.game-icon-settings';
    }

    public ?array $data = [];

    /** game_icons.* keys stored as '1'/'0'. */
    private const BOOL_KEYS = ['enabled', 'credit_enabled'];

    /** Defaults mirror PropertyController::show — game-icons.net under CC BY 3.0. */
    private const DEFAULTS = [
        'enabled'              => '1',
        'credit_enabled'       => '1',
        'credit_text'          => 'Game-type icons by Lorc, Delapouite & Caro Asercion',
        'credit_url'           => 'https://game-icons.net',
        'credit_license_label' => 'CC BY 3.0',
        'credit_license_url'   => 'https://creativecommons.org/licenses/by/3.0/',
    ];

    public function mount(): void
    {
        $t = app(TenantService::class);

        $this->form->fill([
            'enabled'              => (bool) (int) $t->getSetting('game_icons.enabled', self::DEFAULTS['enabled']),
            'credit_enabled'       => (bool) (int) $t->getSetting('game_icons.credit_enabled', self::DEFAULTS['credit_enabled']),
            'credit_text'          => $t->getSetting('game_icons.credit_text', self::DEFAULTS['credit_text']),
            'credit_url'           => $t->getSetting('game_icons.credit_url', self::DEFAULTS['credit_url']),
            'credit_license_label' => $t->getSetting('game_icons.credit_license_label', self::DEFAULTS['credit_license_label']),
            'credit_license_url'   => $t->getSetting('game_icons.credit_license_url', self::DEFAULTS['credit_license_url']),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Icons')
                    ->description('Show the per-game-type icons on public property listings. When off, species appear as plain labels everywhere.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Show game-type icons'),
                    ]),

                Section::make('Artist Credit')
                    ->description('The small attribution line under a listing. Required by Creative-Commons artwork like game-icons.net; turn it off (or rewrite it) if you switch to original or differently-licensed icons.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('credit_enabled')
                            ->label('Show the credit line')
                            ->columnSpanFull(),
                        TextInput::make('credit_text')
                            ->label('Credit Text')
                            ->maxLength(200)
                            ->columnSpanFull()
                            ->helperText('e.g. "Game-type icons by Lorc, Delapouite & Caro Asercion". Links to the credit URL when set.'),
                        TextInput::make('credit_url')
                            ->label('Credit Link')
                            ->url()
                            ->maxLength(255)
                            ->helperText('Where the credit text links — usually the artist/source site.'),
                        TextInput::make('credit_license_label')
                            ->label('Licence Label')
                            ->maxLength(60)
                            ->helperText('e.g. "CC BY 3.0". Leave blank to omit the licence note.'),
                        TextInput::make('credit_license_url')
                            ->label('Licence Link')
                            ->url()
                            ->maxLength(255)
                            ->helperText('Where the licence label links — usually the licence deed.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $t    = app(TenantService::class);

        foreach ($data as $key => $value) {
            $stored = in_array($key, self::BOOL_KEYS, true)
                ? ($value ? '1' : '0')
                : (string) ($value ?? '');

            $t->setSetting("game_icons.{$key}", $stored);
        }

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'platform',
            tableName:      'tenant_settings',
            recordId:       'game_icons.*',
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  'Game icon settings updated via admin CMS',
            changedFields:  array_keys($data),
        );

        Notification::make()
            ->title('Game icon settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
