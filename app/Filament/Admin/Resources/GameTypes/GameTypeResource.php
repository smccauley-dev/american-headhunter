<?php

namespace App\Filament\Admin\Resources\GameTypes;

use App\Filament\Admin\Resources\GameTypes\Pages\CreateGameType;
use App\Filament\Admin\Resources\GameTypes\Pages\EditGameType;
use App\Filament\Admin\Resources\GameTypes\Pages\ListGameTypes;
use App\Models\Property\GameType;
use App\Services\Property\PropertyService;
use App\Support\AdminAuth;
use App\Support\SvgSanitizer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The admin-managed registry of huntable game types behind property_species.
 * Each row owns a display label, a default availability, a sort position, an
 * active flag (deactivate rather than delete a type already in use), and an
 * inline SVG icon rendered on public listings. `code` is the FK slug referenced
 * by property_species — locked once a type exists.
 */
class GameTypeResource extends Resource
{
    protected static ?string $model = GameType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Game Types';

    protected static ?string $slug = 'game-types';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    protected static ?string $recordTitleAttribute = 'label';

    public static function canAccess(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAuth::canManageProperties();
    }

    public static function canDelete(Model $record): bool
    {
        return AdminAuth::canManageProperties();
    }

    /**
     * Fold a pasted full <svg> down to inner markup + an extracted viewBox, and
     * guarantee a viewBox is always stored. Shared by the Create/Edit pages.
     */
    public static function applyIconNormalization(array $data): array
    {
        $norm = SvgSanitizer::normalizeIcon($data['icon_svg'] ?? null);

        $data['icon_svg'] = $norm['icon_svg'];

        if ($norm['icon_viewbox'] !== null) {
            $data['icon_viewbox'] = $norm['icon_viewbox'];
        }

        if (empty($data['icon_viewbox'])) {
            $data['icon_viewbox'] = '0 0 512 512';
        }

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Game Type')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(50)
                        ->rule('regex:/^[a-z][a-z0-9_]*$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->helperText('Lowercase slug, e.g. "whitetail_deer". Stored as the species code and locked once the type is in use.'),
                    TextInput::make('label')
                        ->label('Display Label')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Shown to members and on public listings, e.g. "Whitetail Deer".'),
                    Select::make('default_availability')
                        ->label('Default Availability')
                        ->required()
                        ->default('seasonal')
                        ->options(PropertyService::AVAILABILITY_OPTIONS)
                        ->helperText('Pre-selected when this type is added to a property. Year-round suits hogs, coyotes, etc.'),
                    TextInput::make('sort_order')
                        ->label('Sort Order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower numbers appear first. Also set by dragging rows in the list.'),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive types are hidden from the species picker but keep labelling properties that already use them.'),
                ]),

            Section::make('Icon')
                ->description('Paste a complete <svg>…</svg> or just the inner markup (paths). The outer wrapper and its viewBox are extracted automatically. Monochrome icons inherit the surrounding text color; multi-color icons keep their own fills.')
                ->columns(2)
                ->schema([
                    Textarea::make('icon_svg')
                        ->label('SVG Markup')
                        ->rows(8)
                        ->live(onBlur: true)
                        ->columnSpan(1)
                        ->helperText('Leave blank for no icon (the species shows as a plain label).'),
                    TextInput::make('icon_viewbox')
                        ->label('viewBox')
                        ->default('0 0 512 512')
                        ->maxLength(40)
                        ->helperText('Auto-filled when a full <svg> is pasted. Defaults to 0 0 512 512.'),
                    Placeholder::make('icon_preview')
                        ->label('Preview')
                        ->columnSpanFull()
                        ->content(function (Get $get): HtmlString {
                            $norm  = SvgSanitizer::normalizeIcon($get('icon_svg'));
                            $inner = $norm['icon_svg'];

                            if (! $inner) {
                                return new HtmlString('<span style="opacity:.5;font-size:.85rem">No icon set</span>');
                            }

                            $vb = $norm['icon_viewbox'] ?: ($get('icon_viewbox') ?: '0 0 512 512');

                            return new HtmlString(
                                '<svg viewBox="' . e($vb) . '" width="64" height="64" '
                                . 'fill="currentColor" style="display:block">' . $inner . '</svg>'
                            );
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('icon_svg')
                    ->label('Icon')
                    ->alignCenter()
                    ->html()
                    ->formatStateUsing(function (GameType $record): HtmlString {
                        if (! $record->icon_svg) {
                            return new HtmlString('<span style="opacity:.4">—</span>');
                        }

                        return new HtmlString(
                            '<svg viewBox="' . e($record->icon_viewbox) . '" width="24" height="24" '
                            . 'fill="currentColor" style="display:inline-block">' . $record->icon_svg . '</svg>'
                        );
                    }),
                TextColumn::make('label')
                    ->label('Label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('default_availability')
                    ->label('Availability')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PropertyService::AVAILABILITY_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'year_round' ? 'success' : 'gray'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete Game Type')
                    ->before(function (GameType $record, DeleteAction $action): void {
                        if (app(PropertyService::class)->gameTypeInUse($record->code)) {
                            Notification::make()
                                ->danger()
                                ->title('Game type in use')
                                ->body('One or more properties still list this game type. Deactivate it instead of deleting.')
                                ->send();

                            $action->halt();
                        }
                    })
                    ->after(fn () => app(PropertyService::class)->forgetGameTypesCache()),
            ])
            ->toolbarActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add Game Type'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListGameTypes::route('/'),
            'create' => CreateGameType::route('/create'),
            'edit'   => EditGameType::route('/{record}/edit'),
        ];
    }
}
