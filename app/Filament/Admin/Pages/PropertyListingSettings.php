<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Services\Platform\TenantService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
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

class PropertyListingSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Property Listings', 'heroicon-o-map');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';
    protected static ?string $title = 'Property Listings';
    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.property-listing-settings';
    }

    public ?array $data = [];

    /** Toggle keys stored as '1'/'0' strings. */
    private const BOOL_KEYS = [
        'filter_state_enabled', 'filter_type_enabled', 'filter_price_enabled',
        'filter_acres_enabled', 'filter_hunters_enabled', 'filter_species_enabled',
        'card_show_acres', 'card_show_species', 'card_show_price', 'card_show_max_hunters',
    ];

    public function mount(): void
    {
        $t = app(TenantService::class);
        $p = fn (string $k, mixed $d) => $t->getSetting("properties.{$k}", $d);

        $this->form->fill([
            // Hero
            'hero_eyebrow'         => $p('hero_eyebrow',         'Find Land'),
            'hero_headline'        => $p('hero_headline',        'Hunting Land for Lease'),
            'hero_subhead_suffix'  => $p('hero_subhead_suffix',  'across the United States'),
            // CTA buttons
            'cta_guest_label'      => $p('cta_guest_label',      'Join Now'),
            'cta_guest_url'        => $p('cta_guest_url',        '/get-started'),
            'cta_apply_label'      => $p('cta_apply_label',      'Apply'),
            'cta_details_label'    => $p('cta_details_label',    'Details'),
            // Filters
            'filter_state_enabled'   => (bool) (int) $p('filter_state_enabled',   '1'),
            'filter_type_enabled'    => (bool) (int) $p('filter_type_enabled',    '1'),
            'filter_price_enabled'   => (bool) (int) $p('filter_price_enabled',   '1'),
            'filter_acres_enabled'   => (bool) (int) $p('filter_acres_enabled',   '1'),
            'filter_hunters_enabled' => (bool) (int) $p('filter_hunters_enabled', '1'),
            'filter_species_enabled' => (bool) (int) $p('filter_species_enabled', '1'),
            'filter_state_label'     => $p('filter_state_label',   'State'),
            'filter_type_label'      => $p('filter_type_label',    'Lease Type'),
            'filter_price_label'     => $p('filter_price_label',   'Price Range'),
            'filter_acres_label'     => $p('filter_acres_label',   'Acres'),
            'filter_hunters_label'   => $p('filter_hunters_label', 'Party Size'),
            'filter_species_label'   => $p('filter_species_label', 'Game Species'),
            // Cards
            'card_columns'           => $p('card_columns', '2'),
            'card_show_acres'        => (bool) (int) $p('card_show_acres',       '1'),
            'card_show_species'      => (bool) (int) $p('card_show_species',     '1'),
            'card_show_price'        => (bool) (int) $p('card_show_price',       '1'),
            'card_show_max_hunters'  => (bool) (int) $p('card_show_max_hunters', '1'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hero')
                    ->description('The dark banner at the top of the Find Land page. The listing count is generated automatically and shown before the subhead text.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('hero_eyebrow')
                            ->label('Eyebrow')
                            ->maxLength(60)
                            ->helperText('Small label above the headline — e.g. "Find Land".'),
                        TextInput::make('hero_headline')
                            ->label('Headline')
                            ->maxLength(80),
                        TextInput::make('hero_subhead_suffix')
                            ->label('Subhead Suffix')
                            ->maxLength(120)
                            ->columnSpanFull()
                            ->helperText('Rendered after the live count — e.g. "1,240 listings <suffix>".'),
                    ]),

                Section::make('CTA Buttons')
                    ->description('Labels for the per-card buttons, and where the guest "Join Now" button links. URLs must be internal paths beginning with "/".')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cta_guest_label')
                            ->label('Guest Button Label')
                            ->maxLength(30)
                            ->required()
                            ->helperText('Shown to logged-out visitors — e.g. "Join Now".'),
                        TextInput::make('cta_guest_url')
                            ->label('Guest Button Link')
                            ->maxLength(200)
                            ->required()
                            ->rule('regex:#^/#')
                            ->helperText('Internal path only, e.g. /get-started or /pricing.'),
                        TextInput::make('cta_apply_label')
                            ->label('Apply Button Label')
                            ->maxLength(30)
                            ->required()
                            ->helperText('Shown to logged-in members.'),
                        TextInput::make('cta_details_label')
                            ->label('Details Button Label')
                            ->maxLength(30)
                            ->required(),
                    ]),

                Section::make('Filters')
                    ->description('Show or hide each filter in the sidebar, and rename its label.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('filter_state_enabled')->label('Show State filter'),
                        TextInput::make('filter_state_label')->label('State label')->maxLength(40),
                        Toggle::make('filter_type_enabled')->label('Show Lease Type filter'),
                        TextInput::make('filter_type_label')->label('Lease Type label')->maxLength(40),
                        Toggle::make('filter_price_enabled')->label('Show Price Range filter'),
                        TextInput::make('filter_price_label')->label('Price Range label')->maxLength(40),
                        Toggle::make('filter_acres_enabled')->label('Show Acres filter'),
                        TextInput::make('filter_acres_label')->label('Acres label')->maxLength(40),
                        Toggle::make('filter_hunters_enabled')->label('Show Party Size filter'),
                        TextInput::make('filter_hunters_label')->label('Party Size label')->maxLength(40),
                        Toggle::make('filter_species_enabled')->label('Show Game Species filter'),
                        TextInput::make('filter_species_label')->label('Game Species label')->maxLength(40),
                    ]),

                Section::make('Card Display & Layout')
                    ->description('How listing cards are arranged and which details they show.')
                    ->columns(2)
                    ->schema([
                        Select::make('card_columns')
                            ->label('Cards per row')
                            ->options(['1' => '1 column', '2' => '2 columns', '3' => '3 columns'])
                            ->default('2')
                            ->selectablePlaceholder(false)
                            ->columnSpanFull(),
                        Toggle::make('card_show_acres')->label('Show acreage'),
                        Toggle::make('card_show_species')->label('Show species tags'),
                        Toggle::make('card_show_price')->label('Show price'),
                        Toggle::make('card_show_max_hunters')->label('Show max hunters'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $t    = app(TenantService::class);

        foreach ($data as $key => $value) {
            if (in_array($key, self::BOOL_KEYS, true)) {
                $stored = $value ? '1' : '0';
            } elseif ($value === null) {
                $stored = '';
            } else {
                $stored = (string) $value;
            }

            $t->setSetting("properties.{$key}", $stored);
        }

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'platform',
            tableName:      'tenant_settings',
            recordId:       'properties.*',
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  'Property listings page settings updated via admin',
            changedFields:  array_keys($data),
        );

        Notification::make()
            ->title('Property listings settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
