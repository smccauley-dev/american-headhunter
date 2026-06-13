<?php

namespace App\Filament\Admin\Resources\EmailTemplates;

use App\Filament\Admin\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Filament\Admin\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Admin\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Filament\Admin\Resources\EmailTemplates\RelationManagers\VersionsRelationManager;
use App\Models\Communications\EmailTemplate;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $navigationLabel = 'Email Templates';

    protected static ?string $slug = 'email-templates';

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return AdminAuth::canManagePlatformContent();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManagePlatformContent();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('deleted_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('template_key')
                ->label('Template Key')
                ->required()
                ->maxLength(100)
                ->regex('/^[a-z][a-z0-9_.]*$/')
                ->placeholder('marketing.welcome_series_1')
                ->helperText('Lowercase slug used by application code to look up the template — e.g. auth.password_reset. Immutable after creation.')
                ->unique(
                    table: EmailTemplate::class,
                    column: 'template_key',
                    modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'),
                )
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->columnSpanFull(),

            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(150)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('template_key')
                    ->label('Key')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'system' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('activeVersion.version_number')
                    ->label('Active Ver.')
                    ->alignCenter()
                    ->placeholder('None'),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions')
                    ->alignCenter(),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->label('Manage'),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Email Template'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListEmailTemplates::route('/'),
            'create' => CreateEmailTemplate::route('/create'),
            'edit'   => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
