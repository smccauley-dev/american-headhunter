<?php

namespace App\Filament\Admin\Pages;

use App\Services\Audit\AuditService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use App\Services\Platform\TenantService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class HomepageSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Homepage Settings', 'heroicon-o-home');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';
    protected static ?string $title = 'Homepage Settings';
    protected static ?int $navigationSort = 10;

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
        return 'filament.admin.pages.homepage-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $t = app(TenantService::class);

        $this->form->fill([
            'site_logo_path' => $t->getSetting('site.logo_path', null) ?: null,
            'topbar_tagline' => $t->getSetting('topbar.tagline', 'Hunting Lease Marketplace'),
            'topbar_phone'   => $t->getSetting('topbar.phone',   '(800) 555-0124'),
            'topbar_link1'   => $t->getSetting('topbar.link1',   'Hunters'),
            'topbar_link2'   => $t->getSetting('topbar.link2',   'Landowners'),
            'topbar_link3'   => $t->getSetting('topbar.link3',   'Clubs'),
            'topbar_link4'   => $t->getSetting('topbar.link4',   'Outfitters'),
            'hero_card_count' => $t->getSetting('home.hero_card_count', '1'),
            'hero_eyebrow'    => $t->getSetting('home.hero_eyebrow',    'The Premier Hunting Lease Marketplace'),
            'hero_line1'      => $t->getSetting('home.hero_line1',       'Land'),
            'hero_line2'      => $t->getSetting('home.hero_line2',       'worth &'),
            'hero_line3'      => $t->getSetting('home.hero_line3',       'hunting.'),
            'hero_stat1_label' => $t->getSetting('home.hero_stat1_label', 'Properties Listed'),
            'hero_stat1_value' => $t->getSetting('home.hero_stat1_value', '12,400+'),
            'hero_stat2_label' => $t->getSetting('home.hero_stat2_label', 'States Covered'),
            'hero_stat2_value' => $t->getSetting('home.hero_stat2_value', '48'),
            'hero_stat3_label' => $t->getSetting('home.hero_stat3_label', 'Leases Signed'),
            'hero_stat3_value' => $t->getSetting('home.hero_stat3_value', '38,000+'),
            'stat1_label'     => $t->getSetting('home.stat1_label', 'Active Properties'),
            'stat1_num'       => $t->getSetting('home.stat1_num',   '12,400+'),
            'stat1_sub'       => $t->getSetting('home.stat1_sub',   'Across 48 states'),
            'stat2_label'     => $t->getSetting('home.stat2_label', 'Total Acres Listed'),
            'stat2_num'       => $t->getSetting('home.stat2_num',   '4.2M'),
            'stat2_sub'       => $t->getSetting('home.stat2_sub',   'And growing every week'),
            'stat3_label'     => $t->getSetting('home.stat3_label', 'Leases Completed'),
            'stat3_num'       => $t->getSetting('home.stat3_num',   '38,000+'),
            'stat3_sub'       => $t->getSetting('home.stat3_sub',   'Every one e-signed'),
            'stat4_label'     => $t->getSetting('home.stat4_label', 'Landowner Payouts'),
            'stat4_num'       => $t->getSetting('home.stat4_num',   '$47M'),
            'stat4_sub'       => $t->getSetting('home.stat4_sub',   'Paid out to date'),
            'section_almanac_enabled'      => (bool)(int) $t->getSetting('home.section_almanac_enabled',      '1'),
            'section_stats_enabled'        => (bool)(int) $t->getSetting('home.section_stats_enabled',         '1'),
            'section_expedition_enabled'   => (bool)(int) $t->getSetting('home.section_expedition_enabled',    '1'),
            'section_testimonials_enabled' => (bool)(int) $t->getSetting('home.section_testimonials_enabled',  '1'),
            'section_cta_enabled'          => (bool)(int) $t->getSetting('home.section_cta_enabled',           '1'),
            'cta_headline'    => $t->getSetting('home.cta_headline', 'Your next season starts here.'),
            'cta_sub'         => $t->getSetting('home.cta_sub',      "Join thousands of landowners and hunters who've moved the entire leasing process — from search to signature — into one platform."),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Site Logo')
                    ->description('Upload a branded logo to display in the navigation bar. Leave blank to use the default AH text mark.')
                    ->schema([
                        FileUpload::make('site_logo_path')
                            ->label('Logo Image')
                            ->disk('public')
                            ->directory('site')
                            ->image()
                            ->imagePreviewHeight('72')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/webp'])
                            ->helperText('PNG or WebP only · Max 2 MB · Leave blank to keep the AH text mark')
                            ->visibility('public'),
                    ]),

                Section::make('Top Bar')
                    ->description('The thin info strip at the very top of the page, above the main navigation.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('topbar_tagline')
                            ->label('Tagline')
                            ->maxLength(100)
                            ->helperText('Left side — e.g. "Hunting Lease Marketplace"'),
                        TextInput::make('topbar_phone')
                            ->label('Phone Number')
                            ->maxLength(30)
                            ->helperText('Left side — leave blank to hide'),
                        TextInput::make('topbar_link1')->label('Link Label 1')->maxLength(40),
                        TextInput::make('topbar_link2')->label('Link Label 2')->maxLength(40),
                        TextInput::make('topbar_link3')->label('Link Label 3')->maxLength(40),
                        TextInput::make('topbar_link4')->label('Link Label 4')
                            ->maxLength(40)
                            ->helperText('Leave any label blank to hide that item'),
                    ]),

                Section::make('Hero Layout')
                    ->description('Control how many field cards appear in the hero section.')
                    ->schema([
                        Select::make('hero_card_count')
                            ->label('Field Cards in Hero')
                            ->options(['1' => '1 Field Card', '2' => '2 Field Cards'])
                            ->default('1')
                            ->helperText('2 cards promotes two properties side by side. The second card uses a compact layout showing Coordinates, Acreage, Primary Game, and Season.'),
                    ]),

                Section::make('Hero Copy')
                    ->description('Main headline and eyebrow text displayed at the top of the homepage.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('hero_eyebrow')
                            ->label('Eyebrow')
                            ->maxLength(100)
                            ->columnSpan(3),
                        TextInput::make('hero_line1')->label('Headline — Line 1')->maxLength(60),
                        TextInput::make('hero_line2')->label('Headline — Line 2')->maxLength(60),
                        TextInput::make('hero_line3')->label('Headline — Line 3')->maxLength(60),
                    ]),

                Section::make('Hero Stats')
                    ->description('Three short stats displayed beneath the headline in the hero.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('hero_stat1_label')->label('Stat 1 — Label')->maxLength(40),
                        TextInput::make('hero_stat1_value')->label('Stat 1 — Value')->maxLength(20),
                        TextInput::make('hero_stat2_label')->label('Stat 2 — Label')->maxLength(40),
                        TextInput::make('hero_stat2_value')->label('Stat 2 — Value')->maxLength(20),
                        TextInput::make('hero_stat3_label')->label('Stat 3 — Label')->maxLength(40),
                        TextInput::make('hero_stat3_value')->label('Stat 3 — Value')->maxLength(20),
                    ]),

                Section::make('Platform Stats Block')
                    ->description('Four-column statistics row displayed between sections.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('stat1_label')->label('Stat 1 — Label')->maxLength(60),
                        TextInput::make('stat1_num')->label('Stat 1 — Number')->maxLength(20),
                        TextInput::make('stat1_sub')->label('Stat 1 — Sub')->maxLength(60),
                        TextInput::make('stat2_label')->label('Stat 2 — Label')->maxLength(60),
                        TextInput::make('stat2_num')->label('Stat 2 — Number')->maxLength(20),
                        TextInput::make('stat2_sub')->label('Stat 2 — Sub')->maxLength(60),
                        TextInput::make('stat3_label')->label('Stat 3 — Label')->maxLength(60),
                        TextInput::make('stat3_num')->label('Stat 3 — Number')->maxLength(20),
                        TextInput::make('stat3_sub')->label('Stat 3 — Sub')->maxLength(60),
                        TextInput::make('stat4_label')->label('Stat 4 — Label')->maxLength(60),
                        TextInput::make('stat4_num')->label('Stat 4 — Number')->maxLength(20),
                        TextInput::make('stat4_sub')->label('Stat 4 — Sub')->maxLength(60),
                    ]),

                Section::make('Section Visibility')
                    ->description('Toggle entire sections on or off. Changes take effect immediately.')
                    ->schema([
                        Toggle::make('section_almanac_enabled')
                            ->label('§ 02 — Species Almanac'),
                        Toggle::make('section_stats_enabled')
                            ->label('Platform Stats Block'),
                        Toggle::make('section_expedition_enabled')
                            ->label('§ 03 — The Expedition (How It Works)'),
                        Toggle::make('section_testimonials_enabled')
                            ->label('Testimonials — Field Reports'),
                        Toggle::make('section_cta_enabled')
                            ->label('Bottom Call-to-Action'),
                    ]),

                Section::make('Call to Action')
                    ->description('Content for the bottom CTA section.')
                    ->schema([
                        TextInput::make('cta_headline')
                            ->label('Headline')
                            ->maxLength(120),
                        Textarea::make('cta_sub')
                            ->label('Supporting Text')
                            ->rows(3)
                            ->maxLength(400),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $t    = app(TenantService::class);

        // Delete the old logo file when it is replaced or cleared
        $oldLogoPath = $t->getSetting('site.logo_path', null);
        $newLogoPath = $data['site_logo_path'] ?? null;
        if ($oldLogoPath && $oldLogoPath !== '' && $oldLogoPath !== $newLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        $boolKeys = [
            'section_almanac_enabled', 'section_stats_enabled', 'section_expedition_enabled',
            'section_testimonials_enabled', 'section_cta_enabled',
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $boolKeys)) {
                $stored = $value ? '1' : '0';
            } elseif ($value === null) {
                $stored = '';
            } else {
                $stored = (string) $value;
            }

            if (str_starts_with($key, 'topbar_')) {
                $settingKey = 'topbar.' . substr($key, 7);
            } elseif (str_starts_with($key, 'site_')) {
                $settingKey = 'site.' . substr($key, 5);
            } else {
                $settingKey = "home.{$key}";
            }

            $t->setSetting($settingKey, $stored);
        }

        app(AuditService::class)->log(
            eventType:     'update',
            sourceDatabase: 'platform',
            tableName:     'tenant_settings',
            recordId:      'home.*',
            userId:        Auth::id(),
            ipAddress:     request()->ip(),
            userAgent:     request()->userAgent(),
            actionSummary: 'Homepage settings updated via admin CMS',
            changedFields: array_keys($data),
        );

        Notification::make()
            ->title('Homepage settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
