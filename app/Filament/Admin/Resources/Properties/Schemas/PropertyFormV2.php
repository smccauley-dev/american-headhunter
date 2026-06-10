<?php

namespace App\Filament\Admin\Resources\Properties\Schemas;

use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyManager;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\HtmlString;

class PropertyFormV2
{
    private static array $speciesOptions = [
        'whitetail_deer' => 'Whitetail Deer',
        'mule_deer'      => 'Mule Deer',
        'turkey'         => 'Turkey',
        'waterfowl'      => 'Waterfowl',
        'dove'           => 'Dove',
        'hog'            => 'Hog',
        'elk'            => 'Elk',
        'bear'           => 'Bear',
        'antelope'       => 'Antelope',
        'pheasant'       => 'Pheasant',
        'quail'          => 'Quail',
        'rabbit'         => 'Rabbit',
        'squirrel'       => 'Squirrel',
        'coyote'         => 'Coyote',
        'other'          => 'Other',
    ];

    private static function renderManagersHtml($record): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to manage access.</p>'
            );
        }

        try {
            $managers = PropertyManager::where('property_id', $record->id)
                ->whereNull('revoked_at')
                ->orderBy('granted_at')
                ->get();
        } catch (\Throwable) {
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        if ($managers->isEmpty()) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">'
                . 'No active managers assigned. Use <strong>Grant Manager Access</strong> in the page header to add one.'
                . '</p>'
            );
        }

        // Bulk-load all referenced users in one query (avoids per-row cache/serialization issues)
        $userIds = $managers->pluck('user_id')
            ->merge($managers->pluck('granted_by_user_id'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $cols = '2.5fr 1fr 1.5fr 1.5fr 0.8fr';
        $hs   = 'font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
              . 'color:#6b7280;padding:0.5rem 0.75rem;border-bottom:2px solid #e5e7eb;';
        $cs   = 'font-size:0.875rem;color:#374151;padding:0.625rem 0.75rem;'
              . 'border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';

        $html  = "<div style=\"display:grid;grid-template-columns:{$cols};\">";
        $html .= "<div style=\"{$hs}\">Name</div>"
               . "<div style=\"{$hs}\">Role</div>"
               . "<div style=\"{$hs}\">Granted</div>"
               . "<div style=\"{$hs}\">Granted By</div>"
               . "<div style=\"{$hs}\">Action</div>";

        foreach ($managers as $m) {
            $user      = $users->get($m->user_id);
            $grantedBy = $users->get($m->granted_by_user_id);

            $name          = htmlspecialchars($user?->profile?->full_name ?: ($user?->email ?? '—'));
            $email         = htmlspecialchars($user?->email ?? '');
            $grantedByName = htmlspecialchars(
                $grantedBy?->profile?->full_name ?: ($grantedBy?->email ?? '—')
            );

            $roleBadge = match ($m->role) {
                'owner'    => '<span style="background:#fce7f3;color:#9d174d;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Owner</span>',
                'co_owner' => '<span style="background:#d1fae5;color:#065f46;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Co-Owner</span>',
                'manager'  => '<span style="background:#dbeafe;color:#1e40af;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Manager</span>',
                'operator' => '<span style="background:#fef3c7;color:#92400e;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Operator</span>',
                default    => htmlspecialchars($m->role),
            };

            $granted = $m->granted_at?->format('M j, Y') ?? '—';
            $mid     = $m->id;

            $html .= "<div style=\"{$cs}\"><div>"
                   . "<div style=\"font-weight:500;\">{$name}</div>"
                   . "<div style=\"font-size:0.75rem;color:#9ca3af;\">{$email}</div>"
                   . "</div></div>";
            $html .= "<div style=\"{$cs}\">{$roleBadge}</div>";
            $html .= "<div style=\"{$cs}\">{$granted}</div>";
            $html .= "<div style=\"{$cs}\">{$grantedByName}</div>";
            $html .= "<div style=\"{$cs}\">"
                   . "<button type=\"button\""
                   . " wire:click=\"revokePropertyManager('{$mid}')\""
                   . " wire:confirm=\"Revoke this manager&apos;s access?\""
                   . " style=\"font-size:0.75rem;color:#dc2626;font-weight:500;cursor:pointer;"
                   . "background:none;border:none;padding:0;text-decoration:underline;\">"
                   . "Revoke"
                   . "</button></div>";
        }

        $html .= '</div>';
        return new HtmlString($html);
    }

    private static function amenitiesTabSchema(): array
    {
        $sections = PropertyAmenity::distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($cat) =>
                Section::make(PropertyAmenity::categoryLabel($cat))
                    ->schema([self::amenityCategoryCheckboxList($cat)])
            )
            ->all();

        return [Grid::make(2)->schema($sections)];
    }

    private static function amenityCategoryCheckboxList(string $category): CheckboxList
    {
        return CheckboxList::make("amenities_{$category}")
            ->hiddenLabel()
            ->options(fn () => PropertyAmenity::where('category', $category)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray())
            ->columns(3)
            ->gridDirection('row')
            ->columnSpanFull()
            ->afterStateHydrated(function (CheckboxList $component) use ($category) {
                $record = $component->getRecord();
                if (! $record) {
                    $component->state([]);
                    return;
                }
                $component->state(
                    $record->amenities()
                        ->where('property_amenities.category', $category)
                        ->pluck('property_amenities.id')
                        ->toArray()
                );
            });
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('General Info')
                            ->schema([
                                Section::make()
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('title')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        TextInput::make('slug')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true)
                                            ->columnSpanFull(),
                                        Textarea::make('description')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                        Select::make('status')
                                            ->required()
                                            ->options([
                                                'draft'     => 'Draft',
                                                'active'    => 'Active',
                                                'suspended' => 'Suspended',
                                                'archived'  => 'Archived',
                                            ])
                                            ->default('draft'),
                                        TextInput::make('state_code')
                                            ->label('State Code')
                                            ->required()
                                            ->maxLength(2)
                                            ->placeholder('TX'),
                                        TextInput::make('county')
                                            ->required()
                                            ->maxLength(100),
                                        TextInput::make('center_lat')
                                            ->label('Latitude')
                                            ->numeric()
                                            ->minValue(-90)
                                            ->maxValue(90)
                                            ->placeholder('30.267153')
                                            ->helperText('WGS84 decimal degrees — map pin only.'),
                                        TextInput::make('center_lng')
                                            ->label('Longitude')
                                            ->numeric()
                                            ->minValue(-180)
                                            ->maxValue(180)
                                            ->placeholder('-97.743057')
                                            ->helperText('Negative values are West.'),
                                        TextInput::make('total_acres')
                                            ->label('Total Acres')
                                            ->required()
                                            ->numeric()
                                            ->minValue(1),
                                        TextInput::make('huntable_acres')
                                            ->label('Huntable Acres')
                                            ->numeric()
                                            ->minValue(0),
                                    ]),
                            ]),

                        Tab::make('Game Type')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('species')
                                            ->relationship()
                                            ->columns(2)
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Game Type')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->schema([
                                                Select::make('species_code')
                                                    ->label('Species')
                                                    ->required()
                                                    ->options(self::$speciesOptions),
                                                Toggle::make('is_primary')
                                                    ->label('Primary Species')
                                                    ->helperText('Main huntable species for this property.')
                                                    ->inline(false),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Property Rules')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('rules')
                                            ->relationship()
                                            ->reorderable('sort_order')
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Rule')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->schema([
                                                Textarea::make('rule_text')
                                                    ->label('Rule')
                                                    ->required()
                                                    ->rows(2)
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                                TextInput::make('sort_order')
                                                    ->label('Order')
                                                    ->numeric()
                                                    ->default(0),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Amenities')
                            ->schema(static::amenitiesTabSchema()),

                        Tab::make('Listings')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('listings')
                                            ->relationship()
                                            ->itemLabel(fn(array $state): string => isset($state['id'])
                                                ? 'ID · ' . strtoupper(substr($state['id'], 0, 8))
                                                : 'New Listing'
                                            )
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Listing')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->columns(2)
                                            ->schema([
                                                Select::make('listing_type')
                                                    ->label('Type')
                                                    ->required()
                                                    ->options([
                                                        'annual_lease'   => 'Annual Lease',
                                                        'seasonal_lease' => 'Seasonal Lease',
                                                        'day_hunt'       => 'Day Hunt',
                                                        'auction'        => 'Auction',
                                                    ]),
                                                Select::make('status')
                                                    ->required()
                                                    ->options([
                                                        'draft'    => 'Draft',
                                                        'active'   => 'Active',
                                                        'sold_out' => 'Sold Out',
                                                        'expired'  => 'Expired',
                                                        'archived' => 'Archived',
                                                    ])
                                                    ->default('draft'),
                                                Select::make('visibility')
                                                    ->required()
                                                    ->options([
                                                        'public'       => 'Public',
                                                        'members_only' => 'Members Only',
                                                        'invite_only'  => 'Invite Only',
                                                    ])
                                                    ->default('public'),
                                                Toggle::make('auto_renew')
                                                    ->label('Auto Renew')
                                                    ->default(false)
                                                    ->inline(false),
                                                DatePicker::make('season_start')
                                                    ->label('Season Start'),
                                                DatePicker::make('season_end')
                                                    ->label('Season End')
                                                    ->afterOrEqual('season_start'),
                                                TextInput::make('max_hunters')
                                                    ->label('Max Hunters')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),
                                                TextInput::make('min_hunters')
                                                    ->label('Min Hunters')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->placeholder('No minimum'),
                                                TextInput::make('price_per_hunter')
                                                    ->label('Price Per Hunter')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('price_total')
                                                    ->label('Total Price')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('deposit_amount')
                                                    ->label('Deposit ($)')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('deposit_percent')
                                                    ->label('Deposit (%)')
                                                    ->numeric()
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Managers')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Active Managers')
                                    ->description('Users who can manage this property on behalf of the owner. Grant access from the page header.')
                                    ->schema([
                                        Placeholder::make('property_managers_display')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component) {
                                                return static::renderManagersHtml($component->getRecord());
                                            }),
                                    ]),
                            ]),

                    ]),
            ]);
    }
}
