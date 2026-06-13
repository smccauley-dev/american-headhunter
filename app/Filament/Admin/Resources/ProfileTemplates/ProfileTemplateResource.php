<?php

namespace App\Filament\Admin\Resources\ProfileTemplates;

use App\Filament\Admin\Resources\ProfileTemplates\Pages\EditProfileTemplate;
use App\Filament\Admin\Resources\ProfileTemplates\Pages\ListProfileTemplates;
use App\Models\Platform\ProfileTemplate;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
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

            Section::make('Modules')
                ->description('Which content sections appear on the profile. About is always shown. This is separate from a member\'s public/private visibility choice.')
                ->schema([
                    Toggle::make('draft_config.modules.about.enabled')
                        ->label('About')
                        ->disabled()
                        ->helperText('Always shown.'),
                    Toggle::make('draft_config.modules.contact.enabled')
                        ->label('Contact'),
                    Toggle::make('draft_config.modules.social.enabled')
                        ->label('Social links'),
                    Toggle::make('draft_config.modules.photos.enabled')
                        ->label('Photos'),
                    Toggle::make('draft_config.modules.gear.enabled')
                        ->label('Gear'),
                    Toggle::make('draft_config.modules.activity.enabled')
                        ->label('Activity'),
                ])
                ->columns(2),
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
