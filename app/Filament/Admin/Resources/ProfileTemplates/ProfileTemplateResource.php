<?php

namespace App\Filament\Admin\Resources\ProfileTemplates;

use App\Filament\Admin\Resources\ProfileTemplates\Pages\EditProfileTemplate;
use App\Filament\Admin\Resources\ProfileTemplates\Pages\ListProfileTemplates;
use App\Models\Platform\ProfileTemplate;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProfileTemplateResource extends Resource
{
    protected static ?string $model = ProfileTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $navigationLabel = 'Profile Templates';

    protected static ?string $slug = 'profile-templates';

    protected static ?int $navigationSort = 16;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return AdminAuth::canManagePlatformContent();
    }

    // Fixed system rows — one per profile type. No create/delete.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // SEC-006: explicit edit gate — profile-template theming is platform content.
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManagePlatformContent();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    // Left column: Decorations stacked above Theme, so Theme fills the
                    // space beside the taller Modules section instead of wrapping below it.
                    Group::make([
                        Section::make('Decorations')
                            ->description('Decorative elements drawn on the profile page. Changes apply to every profile of this type once published.')
                            ->schema([
                                Toggle::make('draft_config.decorations.coffee_stain.enabled')
                                    ->label('Coffee-ring stain'),
                                TextInput::make('draft_config.decorations.coffee_stain.opacity')
                                    ->label('Coffee-stain opacity')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(1)
                                    ->step(0.05)
                                    ->helperText('0 = invisible, 1 = full strength. Default 0.45.'),
                                Toggle::make('draft_config.decorations.registration_marks.enabled')
                                    ->label('Registration marks (corner crop marks)'),
                                Toggle::make('draft_config.decorations.topo_background.enabled')
                                    ->label('Topographic background'),
                            ])
                            ->columns(2),

                        Section::make('Theme')
                            ->description('Accent, paper, and ink colors for this profile type. Defaults: accent #C84C21, paper #F8F4EB, ink #0A1512.')
                            ->schema([
                                ColorPicker::make('draft_config.theme.accent')
                                    ->label('Accent')
                                    ->helperText('Highlights — active tabs, pills, links.'),
                                ColorPicker::make('draft_config.theme.paper')
                                    ->label('Paper')
                                    ->helperText('Card background.'),
                                ColorPicker::make('draft_config.theme.ink')
                                    ->label('Ink')
                                    ->helperText('Primary text, borders, drop shadows.'),
                            ])
                            ->columns(3),
                    ])
                        ->columnSpan(1),

                    Section::make('Modules')
                        ->description('Which content sections appear on the profile and in what order. About is always shown. Enable is separate from a member\'s public/private visibility choice. Lower order numbers appear first; Security is always last.')
                        ->columnSpan(1)
                        ->schema([
                            Toggle::make('draft_config.modules.about.enabled')
                                ->label('About')
                                ->disabled()
                                ->helperText('Always shown.'),
                            TextInput::make('draft_config.modules.about.order')
                                ->label('About order')
                                ->numeric()->minValue(1)->step(1),

                            Toggle::make('draft_config.modules.contact.enabled')
                                ->label('Contact'),
                            TextInput::make('draft_config.modules.contact.order')
                                ->label('Contact order')
                                ->numeric()->minValue(1)->step(1),

                            Toggle::make('draft_config.modules.social.enabled')
                                ->label('Social links'),
                            TextInput::make('draft_config.modules.social.order')
                                ->label('Social order')
                                ->numeric()->minValue(1)->step(1),

                            Toggle::make('draft_config.modules.photos.enabled')
                                ->label('Photos'),
                            TextInput::make('draft_config.modules.photos.order')
                                ->label('Photos order')
                                ->numeric()->minValue(1)->step(1),

                            Toggle::make('draft_config.modules.gear.enabled')
                                ->label('Gear'),
                            TextInput::make('draft_config.modules.gear.order')
                                ->label('Gear order')
                                ->numeric()->minValue(1)->step(1),

                            Toggle::make('draft_config.modules.activity.enabled')
                                ->label('Activity'),
                            TextInput::make('draft_config.modules.activity.order')
                                ->label('Activity order')
                                ->numeric()->minValue(1)->step(1),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('profile_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('published_at')
                    ->label('Last Published')
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Draft Updated')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->label('Edit'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfileTemplates::route('/'),
            'edit'  => EditProfileTemplate::route('/{record}/edit'),
        ];
    }
}
