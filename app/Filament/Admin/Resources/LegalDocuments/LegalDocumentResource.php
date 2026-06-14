<?php

namespace App\Filament\Admin\Resources\LegalDocuments;

use App\Filament\Admin\Resources\LegalDocuments\Pages\ManageLegalDocuments;
use App\Models\Platform\LegalDocument;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LegalDocumentResource extends Resource
{
    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Legal Documents';

    protected static ?string $slug = 'legal-documents';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManageSystem();
    }

    // SEC-006: explicit mutation gates.
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function canDeleteAny(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('document_key')
                ->label('Document Key')
                ->required()
                ->maxLength(100)
                ->helperText('Lowercase slug — e.g. hunter_info_certification, terms_of_service. Immutable after creation.')
                ->regex('/^[a-z][a-z0-9_]*$/')
                ->placeholder('hunter_info_certification')
                ->columnSpanFull(),

            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('version')
                ->label('Version')
                ->required()
                ->numeric()
                ->minValue(1)
                ->helperText('Increment when making substantive changes to the text. Acceptances record which version was active at time of signing.'),

            DatePicker::make('effective_date')
                ->label('Effective Date')
                ->required()
                ->native(false),

            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Only one version per document key should be active at a time. Activating this will not automatically deactivate others — manage manually.')
                ->columnSpanFull(),

            Textarea::make('content')
                ->label('Document Text')
                ->required()
                ->rows(20)
                ->helperText('Plain text content shown to users. Use blank lines for paragraph breaks.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('version')
                    ->label('Ver.')
                    ->sortable()
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('effective_date')
                    ->label('Effective')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('document_key')
            ->recordActions([
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (LegalDocument $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (LegalDocument $record) => $record->is_active ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
                    ->color(fn (LegalDocument $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (LegalDocument $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Legal Document'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLegalDocuments::route('/'),
        ];
    }
}
