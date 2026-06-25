<?php

namespace App\Filament\Admin\Resources\PricingCallouts;

use App\Filament\Admin\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Admin\Resources\PricingCallouts\Pages\CreatePricingCallout;
use App\Filament\Admin\Resources\PricingCallouts\Pages\EditPricingCallout;
use App\Filament\Admin\Resources\PricingCallouts\Pages\ListPricingCallouts;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PricingCallout;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Publishable horizontal banners shown beneath the plan cards on a pricing tab
 * (e.g. the "Veteran or First Responder?" callout). Unlike membership plans these
 * are not purchasable — just copy, optional feature bullets, and a single CTA.
 */
class PricingCalloutResource extends Resource
{
    protected static ?string $model = PricingCallout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Pricing Callouts';

    protected static ?string $slug = 'pricing-callouts';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Pricing & Promotions';
    }

    protected static ?string $recordTitleAttribute = 'eyebrow';

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

    public static function canDelete(Model $record): bool
    {
        return AdminAuth::canManagePricing();
    }

    /**
     * A section-header "Add" action that appends an empty row to a repeater,
     * keyed by a UUID like Filament's own repeater state. Lets each section show
     * its add control top-right (the admin standard) instead of the repeater's
     * default bottom button. Disables itself once an optional max is reached.
     */
    private static function addRowAction(string $field, string $label, ?int $max = null): Action
    {
        return Action::make("add_{$field}")
            ->label($label)
            ->icon(Heroicon::OutlinedPlus)
            ->button()
            ->color('gray')
            ->disabled(fn (Get $get): bool => $max !== null && count($get($field) ?? []) >= $max)
            ->action(function (Get $get, Set $set) use ($field, $max): void {
                $items = $get($field) ?? [];
                if ($max !== null && count($items) >= $max) {
                    return;
                }
                $items[(string) Str::uuid()] = [];
                $set($field, $items);
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Explicit two-column layout: the left column stacks Content then
            // Buttons (the copy + CTA pair), the right column stacks Features then
            // Display — so Buttons sits directly under Content instead of dropping
            // to the bottom-left cell of Filament's default row grid.
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    Group::make()
                        ->schema([
                            Section::make('Content')
                                ->description('The copy shown in the banner. The body is plain text; keep it short.')
                                ->columns(2)
                                ->schema([
                                    Select::make('account_type')
                                        ->label('Pricing Tab')
                                        ->options(MembershipPlanResource::ACCOUNT_TYPES)
                                        ->required()
                                        ->helperText('Which account-type tab the banner appears under.'),
                                    TextInput::make('eyebrow')
                                        ->label('Eyebrow')
                                        ->maxLength(80)
                                        ->helperText('Small uppercase label, e.g. "Veteran or First Responder?"'),
                                    Textarea::make('body')
                                        ->label('Body')
                                        ->required()
                                        ->rows(3)
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Buttons')
                                ->description('One or more CTA buttons. Leave empty for none. Add a "service" flag to a link (e.g. /get-started?type=hunter&service=veteran or &service=first_responder) to preselect the signup form\'s veteran / first-responder document step.')
                                ->headerActions([
                                    self::addRowAction('buttons', 'Add Button', max: 3),
                                ])
                                ->schema([
                                    Repeater::make('buttons')
                                        ->hiddenLabel()
                                        ->addable(false)
                                        ->columns(2)
                                        ->reorderable()
                                        ->maxItems(3)
                                        ->schema([
                                            TextInput::make('label')
                                                ->label('Button Label')
                                                ->required()
                                                ->maxLength(40),
                                            TextInput::make('url')
                                                ->label('Button Link')
                                                ->required()
                                                ->maxLength(255)
                                                ->helperText('e.g. /get-started?type=hunter&service=veteran'),
                                        ]),
                                ]),

                            Section::make('Display')
                                ->columns(2)
                                ->schema([
                                    Select::make('plan_id')
                                        ->label('Linked Plan (optional)')
                                        ->options(fn (): array => MembershipPlan::on('platform')
                                            ->where('is_active', true)
                                            ->whereNull('deleted_at')
                                            ->orderBy('account_type')
                                            ->orderBy('sort_order')
                                            ->get()
                                            ->mapWithKeys(fn (MembershipPlan $p): array => [
                                                $p->id => "{$p->display_name} ({$p->account_type})",
                                            ])
                                            ->all())
                                        ->placeholder('None — no price shown')
                                        ->helperText("Surfaces this plan's live price on the banner. Display only — grants no entitlements."),
                                    ColorPicker::make('accent_color')
                                        ->label('Accent Color')
                                        ->helperText('Hex color for the eyebrow, bullets, border and button. Blank uses the blaze default.'),
                                    TextInput::make('sort_order')
                                        ->label('Sort Order')
                                        ->numeric()
                                        ->default(0),
                                    Toggle::make('is_published')
                                        ->label('Published')
                                        ->helperText('Show this banner on the public pricing page.'),
                                ]),
                        ]),

                    Group::make()
                        ->schema([
                            Section::make('Features')
                                ->description('Optional bullet points, like the perks on a plan card. Leave empty for none.')
                                ->headerActions([
                                    self::addRowAction('features', 'Add Feature'),
                                ])
                                ->schema([
                                    Repeater::make('features')
                                        ->hiddenLabel()
                                        ->addable(false)
                                        ->columns(2)
                                        ->reorderable()
                                        ->schema([
                                            TextInput::make('label')
                                                ->label('Label')
                                                ->required()
                                                ->maxLength(100),
                                            TextInput::make('description')
                                                ->label('Description')
                                                ->maxLength(150),
                                        ]),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_type')
                    ->label('Tab')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => MembershipPlanResource::ACCOUNT_TYPES[$state] ?? $state),
                TextColumn::make('eyebrow')
                    ->label('Eyebrow')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('body')
                    ->label('Body')
                    ->limit(60)
                    ->wrap(),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('account_type')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete Pricing Callout'),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Callout'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPricingCallouts::route('/'),
            'create' => CreatePricingCallout::route('/create'),
            'edit'   => EditPricingCallout::route('/{record}/edit'),
        ];
    }
}
