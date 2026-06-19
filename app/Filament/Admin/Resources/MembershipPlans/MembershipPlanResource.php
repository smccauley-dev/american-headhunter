<?php

namespace App\Filament\Admin\Resources\MembershipPlans;

use App\Filament\Admin\Resources\MembershipPlans\Pages\CreateMembershipPlan;
use App\Filament\Admin\Resources\MembershipPlans\Pages\EditMembershipPlan;
use App\Filament\Admin\Resources\MembershipPlans\Pages\ListMembershipPlans;
use App\Filament\Admin\Resources\MembershipPlans\RelationManagers\EntitlementsRelationManager;
use App\Filament\Admin\Resources\MembershipPlans\RelationManagers\VersionsRelationManager;
use App\Models\Platform\MembershipPlan;
use App\Services\Platform\PlanService;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MembershipPlanResource extends Resource
{
    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Membership Plans';

    protected static ?string $slug = 'membership-plans';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Pricing & Promotions';
    }

    protected static ?string $recordTitleAttribute = 'display_name';

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

    // Deletion is a guarded soft-delete: pricing managers may delete, but the
    // DeleteAction refuses (with a notification) while the plan has live
    // subscribers. Plans are never hard-deleted — versions and subscription
    // history keep referencing them.
    public static function canDelete(Model $record): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Plan')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Identity')
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([
                            Section::make('Identity')
                                ->description('The plan key is locked into published versions and subscriptions — it cannot change once the plan exists.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('plan_key')
                                        ->label('Plan Key')
                                        ->required()
                                        ->maxLength(64)
                                        ->extraInputAttributes(['class' => 'font-mono'])
                                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                        ->helperText('Lowercase, e.g. hunter_scout. Permanent once created.'),
                                    Select::make('account_type')
                                        ->label('Account Type')
                                        ->options(self::ACCOUNT_TYPES)
                                        ->required(),
                                    TextInput::make('display_name')
                                        ->label('Display Name')
                                        ->required()
                                        ->maxLength(100),
                                    TextInput::make('tagline')
                                        ->label('Tagline')
                                        ->maxLength(150),
                                    Textarea::make('description')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Visibility')
                                ->columns(2)
                                ->schema([
                                    Toggle::make('is_public')
                                        ->label('Public')
                                        ->helperText('Shown on the public pricing page.'),
                                    Toggle::make('is_active')
                                        ->label('Active')
                                        ->helperText('Available for new subscriptions.'),
                                    Toggle::make('is_default_free')
                                        ->label('Default Free Tier')
                                        ->helperText('The fallback plan for this account type.'),
                                    TextInput::make('sort_order')
                                        ->label('Sort Order')
                                        ->numeric()
                                        ->default(0),
                                    Textarea::make('admin_notes')
                                        ->label('Admin Notes')
                                        ->rows(2)
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Tab::make('Entitlements')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->schema([
                            Livewire::make(
                                EntitlementsRelationManager::class,
                                fn (MembershipPlan $record): array => [
                                    'ownerRecord' => $record,
                                    'pageClass'   => EditMembershipPlan::class,
                                ],
                            ),
                        ]),

                    Tab::make('Pricing')
                        ->icon(Heroicon::OutlinedCurrencyDollar)
                        ->schema([
                            Section::make('Pricing')
                                ->description('Staged pricing. Publishing a new version is what locks these in for new subscribers; existing subscribers keep their version.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('monthly_price_cents')
                                        ->label('Monthly Price')
                                        ->numeric()
                                        ->prefix('$')
                                        ->minValue(0)
                                        ->step(0.01),
                                    TextInput::make('annual_price_cents')
                                        ->label('Annual Price')
                                        ->numeric()
                                        ->prefix('$')
                                        ->minValue(0)
                                        ->step(0.01),
                                    TextInput::make('platform_fee_pct')
                                        ->label('Platform Fee')
                                        ->numeric()
                                        ->suffix('%')
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->step(0.01),
                                    TextInput::make('commission_pct')
                                        ->label('Commission')
                                        ->numeric()
                                        ->suffix('%')
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->step(0.01),
                                    Toggle::make('monthly_enabled')
                                        ->label('Monthly Billing Enabled'),
                                    Toggle::make('annual_enabled')
                                        ->label('Annual Billing Enabled'),
                                ]),

                            Section::make('Pricing Card')
                                ->description('Controls how this plan is presented on the public pricing page — a header image, an accent color, an optional badge, and whether it is highlighted.')
                                ->columns(2)
                                ->schema([
                                    FileUpload::make('header_image_path')
                                        ->label('Header Image')
                                        ->disk('public')
                                        ->directory('pricing-cards')
                                        ->image()
                                        ->imagePreviewHeight('120')
                                        ->maxSize(2048)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->helperText('JPG, PNG or WebP · Max 2 MB · Used as the card banner')
                                        ->visibility('public')
                                        ->columnSpanFull(),
                                    ColorPicker::make('accent_color')
                                        ->label('Accent Color')
                                        ->helperText('Hex color for the card accent. Leave blank to use the blaze default.'),
                                    TextInput::make('badge_label')
                                        ->label('Badge Label')
                                        ->maxLength(40)
                                        ->helperText('Short ribbon text, e.g. "Most Popular". Leave blank for none.'),
                                    Toggle::make('is_featured')
                                        ->label('Featured')
                                        ->helperText('Visually highlight this plan on the pricing page.'),
                                ]),
                        ]),

                    Tab::make('Stripe')
                        ->icon(Heroicon::OutlinedCreditCard)
                        ->schema([
                            Section::make('Stripe')
                                ->description('Entered manually until Stripe product sync is wired up. Leave blank if not yet created in Stripe.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('stripe_product_id')
                                        ->label('Product ID')
                                        ->extraInputAttributes(['class' => 'font-mono'])
                                        ->maxLength(100),
                                    TextInput::make('stripe_monthly_price_id')
                                        ->label('Monthly Price ID')
                                        ->extraInputAttributes(['class' => 'font-mono'])
                                        ->maxLength(100),
                                    TextInput::make('stripe_annual_price_id')
                                        ->label('Annual Price ID')
                                        ->extraInputAttributes(['class' => 'font-mono'])
                                        ->maxLength(100),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::ACCOUNT_TYPES[$state] ?? $state),
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('monthly_price_cents')
                    ->label('Monthly')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('currentVersion.version_number')
                    ->label('Live Ver.')
                    ->alignCenter()
                    ->placeholder('—'),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete Membership Plan')
                    ->modalDescription('Hides the plan from new signups and the public pricing page. Existing subscribers and version history are unaffected. Refused while the plan has active subscribers.')
                    ->action(function (MembershipPlan $record): void {
                        $deleted = app(PlanService::class)->softDeletePlan($record, auth()->id());

                        if (! $deleted) {
                            Notification::make()
                                ->title('Cannot delete — plan has active subscribers')
                                ->body('Turn off the Active toggle to retire it instead.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Plan deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Plan'),
                BulkActionGroup::make([]),
            ]);
    }

    // EntitlementsRelationManager is embedded as a tab in the form (see form()),
    // so it is intentionally not registered here — only Versions renders below.
    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit'   => EditMembershipPlan::route('/{record}/edit'),
        ];
    }
}
