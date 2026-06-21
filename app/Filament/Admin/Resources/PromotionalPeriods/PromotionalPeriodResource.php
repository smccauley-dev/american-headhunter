<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods;

use App\Filament\Admin\Resources\PromotionalPeriods\Pages\CreatePromotionalPeriod;
use App\Filament\Admin\Resources\PromotionalPeriods\Pages\EditPromotionalPeriod;
use App\Filament\Admin\Resources\PromotionalPeriods\Pages\ListPromotionalPeriods;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PromotionalPeriodResource extends Resource
{
    protected static ?string $model = PromotionalPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Promotions';

    protected static ?string $slug = 'promotions';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Pricing & Promotions';
    }

    protected static ?string $recordTitleAttribute = 'display_name';

    public const PROMOTION_TYPES = [
        'tier_grant'          => 'Tier Grant',
        'percentage_discount' => 'Percentage Discount',
        'dollar_discount'     => 'Dollar Discount',
        'free_period'         => 'Free Period',
        'referral_program'    => 'Referral Program',
        'promo_code_campaign' => 'Promo Code Campaign',
    ];

    public const STATUSES = [
        'draft'     => 'Draft',
        'scheduled' => 'Scheduled',
        'active'    => 'Active',
        'paused'    => 'Paused',
        'exhausted' => 'Exhausted',
        'expired'   => 'Expired',
        'ended'     => 'Ended',
    ];

    public const ON_EXPIRATION = [
        'auto_charge'     => 'Auto-charge',
        'downgrade_free'  => 'Downgrade to free',
        'pause_account'   => 'Pause account',
    ];

    public const ACCOUNT_TYPES = [
        'hunter'     => 'Hunter',
        'landowner'  => 'Landowner',
        'club'       => 'Club',
        'outfitter'  => 'Outfitter',
        'consultant' => 'Consultant',
        'seller'     => 'Seller',
    ];

    public static function canAccess(): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAuth::canManagePricing();
    }

    // Promotions are referenced by claims and codes — retire via status, never delete.
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextInput::make('promo_key')
                        ->label('Promo Key')
                        ->required()
                        ->maxLength(80)
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Permanent once created, e.g. founding_landowner.'),
                    TextInput::make('display_name')
                        ->label('Display Name')
                        ->required()
                        ->maxLength(200),
                    Select::make('promotion_type')
                        ->label('Type')
                        ->options(self::PROMOTION_TYPES)
                        ->required()
                        ->live(),
                    Select::make('status')
                        ->label('Status')
                        ->options(self::STATUSES)
                        ->default('draft')
                        ->required(),
                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Reward')
                ->description('Fill the fields that match the promotion type.')
                ->columns(2)
                ->schema([
                    Select::make('grants_plan_id')
                        ->label('Grants Plan')
                        ->options(fn (): array => MembershipPlan::on('platform')
                            ->orderBy('display_name')
                            ->pluck('display_name', 'id')
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => in_array($get('promotion_type'), ['tier_grant', 'free_period'], true)),
                    TextInput::make('duration_days')
                        ->label('Duration (days)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave blank for permanent.')
                        ->visible(fn (Get $get): bool => in_array($get('promotion_type'), ['tier_grant', 'free_period'], true)),
                    TextInput::make('discount_percentage')
                        ->label('Discount %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->visible(fn (Get $get): bool => $get('promotion_type') === 'percentage_discount'),
                    TextInput::make('discount_amount_cents')
                        ->label('Discount Amount (cents)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('In cents — 1000 = $10.00.')
                        ->visible(fn (Get $get): bool => $get('promotion_type') === 'dollar_discount'),
                    TextInput::make('referral_reward_type')
                        ->label('Referral Reward Type')
                        ->maxLength(30)
                        ->helperText('e.g. account_credit, free_days.')
                        ->visible(fn (Get $get): bool => $get('promotion_type') === 'referral_program'),
                    TextInput::make('referral_reward_value')
                        ->label('Referral Reward Value')
                        ->numeric()
                        ->visible(fn (Get $get): bool => $get('promotion_type') === 'referral_program'),
                    Select::make('on_expiration')
                        ->label('On Expiration')
                        ->options(self::ON_EXPIRATION)
                        ->default('downgrade_free')
                        ->required(),
                ]),

            Section::make('Targeting')
                ->columns(2)
                ->schema([
                    Select::make('target_account_types')
                        ->label('Account Types')
                        ->options(self::ACCOUNT_TYPES)
                        ->multiple()
                        ->helperText('Leave empty to target all account types.'),
                    TagsInput::make('target_states')
                        ->label('States')
                        ->placeholder('e.g. TX')
                        ->helperText('Two-letter codes. Empty = all states.'),
                ]),

            Section::make('Window & Limits')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Starts At')
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label('Ends At')
                        ->seconds(false),
                    TextInput::make('claim_limit')
                        ->label('Total Claim Limit')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave blank for unlimited.'),
                    TextInput::make('per_user_limit')
                        ->label('Per-User Limit')
                        ->numeric()
                        ->minValue(1)
                        ->default(1),
                ]),

            Section::make('Behavior')
                ->columns(2)
                ->schema([
                    Toggle::make('requires_promo_code')
                        ->label('Requires Promo Code'),
                    Toggle::make('stackable_with_other_promos')
                        ->label('Stackable With Other Promos'),
                    Toggle::make('auto_apply_on_signup')
                        ->label('Auto-apply on Signup')
                        ->helperText('Grants this plan automatically to matching new signups. Requires a Grants Plan; discounts apply at checkout instead.')
                        ->visible(fn (Get $get): bool => in_array($get('promotion_type'), ['tier_grant', 'free_period'], true)),
                    Toggle::make('auto_apply_on_first_listing')
                        ->label('Auto-apply on First Listing')
                        ->helperText("Grants this plan when an owner's first listing goes live. Requires a Grants Plan.")
                        ->visible(fn (Get $get): bool => in_array($get('promotion_type'), ['tier_grant', 'free_period'], true)),
                ]),

            Section::make('Display')
                ->collapsed()
                ->columns(2)
                ->schema([
                    Toggle::make('show_on_landing')
                        ->label('Show on Landing'),
                    Toggle::make('show_on_pricing')
                        ->label('Show on Pricing'),
                    Toggle::make('show_claim_counter')
                        ->label('Show Claim Counter'),
                    TextInput::make('pricing_badge_text')
                        ->label('Pricing Badge Text')
                        ->maxLength(100),
                    Textarea::make('landing_banner_text')
                        ->label('Landing Banner Text')
                        ->rows(2)
                        ->columnSpanFull(),
                    Textarea::make('dashboard_callout_text')
                        ->label('Dashboard Callout Text')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('promo_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('promotion_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::PROMOTION_TYPES[$state] ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'              => 'success',
                        'scheduled', 'draft'  => 'warning',
                        'paused'              => 'info',
                        default               => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('claim_count')
                    ->label('Claims')
                    ->formatStateUsing(fn (int $state, PromotionalPeriod $record): string => $record->claim_limit
                        ? "{$state} / {$record->claim_limit}"
                        : (string) $state)
                    ->alignCenter(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('requires_promo_code')
                    ->label('Code')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Promotion'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPromotionalPeriods::route('/'),
            'create' => CreatePromotionalPeriod::route('/create'),
            'edit'   => EditPromotionalPeriod::route('/{record}/edit'),
        ];
    }
}
