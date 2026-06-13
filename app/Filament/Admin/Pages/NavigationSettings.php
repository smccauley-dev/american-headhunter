<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use App\Services\Platform\TenantService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class NavigationSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Navigation Settings', 'heroicon-o-bars-3');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';
    protected static ?string $title = 'Navigation Settings';
    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManagePlatformContent();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.navigation-settings';
    }

    public ?array $data = [];

    private array $defaultLinks = [
        ['label' => 'Find Land',    'href' => '/properties',  'enabled' => true],
        ['label' => 'Auctions',     'href' => '/auctions',     'enabled' => true],
        ['label' => 'Outfitters',   'href' => '/outfitters',   'enabled' => true],
        ['label' => 'How It Works', 'href' => '/how-it-works', 'enabled' => true],
    ];

    public function mount(): void
    {
        $t = app(TenantService::class);

        $this->form->fill([
            'nav_links'     => $t->getSetting('nav.links', $this->defaultLinks) ?? $this->defaultLinks,
            'cta_label'     => $t->getSetting('nav.cta_label',    'List Your Land →'),
            'cta_href'      => $t->getSetting('nav.cta_href',     '/get-started?type=landowner'),
            'signin_label'  => $t->getSetting('nav.signin_label', 'Sign In'),
            'signin_href'   => $t->getSetting('nav.signin_href',  '/login'),
            'login_redirect' => $t->getSetting('nav.login_redirect', '/member/profile'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Main Navigation Links')
                    ->description('Add, remove, reorder, or disable links in the main nav bar. Disabled items are hidden without being deleted.')
                    ->headerActions([
                        Action::make('add_nav_item')
                            ->label('Add Nav Item')
                            ->icon('heroicon-o-plus')
                            ->color('gray')
                            ->action(function (): void {
                                $this->data['nav_links'][(string) Str::uuid()] = [
                                    'label'   => '',
                                    'href'    => '',
                                    'enabled' => true,
                                ];
                            }),
                    ])
                    ->schema([
                        Repeater::make('nav_links')
                            ->label('')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->maxLength(60)
                                    ->placeholder('Find Land'),
                                TextInput::make('href')
                                    ->label('URL / Path')
                                    ->required()
                                    ->maxLength(500)
                                    ->placeholder('/properties')
                                    ->regex('/^(\/|https?:\/\/).+/')
                                    ->validationMessages(['regex' => 'Must be a relative path starting with / or a full https:// URL.']),
                                Toggle::make('enabled')
                                    ->label('Visible')
                                    ->default(true)
                                    ->inline(false),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn(array $state): string => $state['label'] ?? 'New Item'),
                    ]),

                Section::make('CTA Button')
                    ->description('The primary call-to-action button on the right side of the nav bar.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cta_label')
                            ->label('Button Label')
                            ->maxLength(60)
                            ->placeholder('List Your Land →'),
                        TextInput::make('cta_href')
                            ->label('Button URL')
                            ->maxLength(500)
                            ->placeholder('/get-started?type=landowner')
                            ->regex('/^(\/|https?:\/\/).+/')
                            ->validationMessages(['regex' => 'Must be a relative path starting with / or a full https:// URL.']),
                    ]),

                Section::make('Sign In Link')
                    ->description('The secondary text link beside the CTA button.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('signin_label')
                            ->label('Link Label')
                            ->maxLength(60)
                            ->placeholder('Sign In'),
                        TextInput::make('signin_href')
                            ->label('Link URL')
                            ->maxLength(500)
                            ->placeholder('/login')
                            ->regex('/^(\/|https?:\/\/).+/')
                            ->validationMessages(['regex' => 'Must be a relative path starting with / or a full https:// URL.']),
                    ]),

                Section::make('Post-Login Redirect')
                    ->description('Where users land after signing in. If they were heading to a protected page, they return there instead.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('login_redirect')
                            ->label('Redirect Path')
                            ->required()
                            ->maxLength(500)
                            ->placeholder('/member/profile')
                            ->regex('/^\/.+/')
                            ->validationMessages(['regex' => 'Must be a relative path starting with /.']),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $t    = app(TenantService::class);

        $t->setSetting('nav.links',        array_values($data['nav_links'] ?? []));
        $t->setSetting('nav.cta_label',    $data['cta_label']);
        $t->setSetting('nav.cta_href',     $data['cta_href']);
        $t->setSetting('nav.signin_label', $data['signin_label']);
        $t->setSetting('nav.signin_href',  $data['signin_href']);
        $t->setSetting('nav.login_redirect', $data['login_redirect']);

        app(AuditService::class)->log(
            eventType:     'update',
            sourceDatabase: 'platform',
            tableName:     'tenant_settings',
            recordId:      'nav.*',
            userId:        Auth::id(),
            ipAddress:     request()->ip(),
            userAgent:     request()->userAgent(),
            actionSummary: 'Navigation settings updated via admin CMS',
            changedFields: ['nav.links', 'nav.cta_label', 'nav.cta_href', 'nav.signin_label', 'nav.signin_href', 'nav.login_redirect'],
        );

        Notification::make()
            ->title('Navigation settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
