<?php

namespace App\Filament\Admin\Resources\IncidentReports;

use App\Filament\Admin\Resources\IncidentReports\Pages\ListIncidentReports;
use App\Filament\Admin\Resources\IncidentReports\Pages\ViewIncidentReport;
use App\Models\Incidents\IncidentReport;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class IncidentReportResource extends Resource
{
    protected static ?string $model = IncidentReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Incident Reports';

    protected static ?string $slug = 'incident-reports';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Incidents';
    }

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSecurity();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function severityColor(string $state): string
    {
        return match ($state) {
            'critical' => 'danger',
            'serious'  => 'warning',
            'moderate' => 'info',
            'minor'    => 'gray',
            default    => 'gray',
        };
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'open'          => 'warning',
            'investigating' => 'info',
            'resolved'      => 'success',
            'closed'        => 'gray',
            default         => 'gray',
        };
    }

    public static function label(string $state): string
    {
        return ucwords(str_replace('_', ' ', $state));
    }

    public static function rawIdHint(?string $id): ?HtmlString
    {
        if (! $id) {
            return null;
        }

        return new HtmlString(
            '<span style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">REF UUID: '.e($id).'</span>'
        );
    }

    public static function evidenceList(IncidentReport $record): HtmlString
    {
        $ids = (array) ($record->evidence_document_ids ?? []);
        if (! $ids) {
            return new HtmlString('<span style="color:#9ca3af;">No photo evidence submitted.</span>');
        }

        $docs = \App\Models\Documents\Document::whereIn('id', $ids)->get()->keyBy('id');
        $rows = '';
        foreach ($ids as $id) {
            $doc   = $docs[$id] ?? null;
            $name  = $doc?->original_filename ?? 'Document';
            $state = $doc?->status ?? 'missing';
            $rows .= '<li style="font-size:13px;color:#374151;margin:2px 0;">'
                .e($name).' <span style="font-size:10px;color:#9ca3af;font-family:ui-monospace,monospace;">('.e($state).')</span></li>';
        }

        return new HtmlString('<ul style="margin:0;padding-left:16px;list-style:disc;">'.$rows.'</ul>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (string $state): string => self::label($state))
                    ->sortable(),
                TextColumn::make('incident_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? self::label($state) : '—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::label($state)),
                TextColumn::make('property_id')
                    ->label('Property')
                    ->state(fn (IncidentReport $record): ?string => $record->getProperty()?->title)
                    ->placeholder('—'),
                TextColumn::make('reporter_user_id')
                    ->label('Reporter')
                    ->state(fn (IncidentReport $record): ?string => $record->getReporter()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('occurred_at')
                    ->label('Occurred')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incident')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => self::label($state)),
                    TextEntry::make('severity')
                        ->badge()
                        ->color(fn (string $state): string => self::severityColor($state))
                        ->formatStateUsing(fn (string $state): string => self::label($state)),
                    TextEntry::make('incident_type')
                        ->label('Type')
                        ->formatStateUsing(fn (?string $state): ?string => $state ? self::label($state) : null)
                        ->placeholder('—'),
                    TextEntry::make('occurred_at')->label('Occurred')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('location_description')->label('Location')->placeholder('—')->columnSpan(2),
                    TextEntry::make('description')->label('What Happened')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Safety & Authorities')
                ->columns(3)
                ->schema([
                    IconEntry::make('injuries_reported')->label('Injuries Reported')->boolean(),
                    IconEntry::make('authorities_notified')->label('Authorities Notified')->boolean(),
                    TextEntry::make('authority_report_number')->label('Authority Report #')->placeholder('—'),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    TextEntry::make('reporter_user_id')
                        ->label('Reporter')
                        ->state(fn (IncidentReport $record): ?string => $record->getReporter()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (IncidentReport $record): ?HtmlString => self::rawIdHint($record->reporter_user_id)),
                    TextEntry::make('property_id')
                        ->label('Property')
                        ->state(fn (IncidentReport $record): ?string => $record->getProperty()?->title)
                        ->placeholder('—')
                        ->helperText(fn (IncidentReport $record): ?HtmlString => self::rawIdHint($record->property_id)),
                    TextEntry::make('lease_id')
                        ->label('Lease')
                        ->state(fn (IncidentReport $record): ?string => $record->leaseLabel())
                        ->placeholder('—')
                        ->helperText(fn (IncidentReport $record): ?HtmlString => self::rawIdHint($record->lease_id)),
                ]),

            Section::make('Resolution')
                ->columns(3)
                ->visible(fn (IncidentReport $record): bool => in_array($record->status, ['resolved', 'closed'], true))
                ->schema([
                    TextEntry::make('resolved_at')->label('Resolved')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('resolution_notes')->label('Resolution Notes')->placeholder('—')->columnSpan(2),
                ]),

            Section::make('Photo Evidence')
                ->schema([
                    TextEntry::make('evidence')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (IncidentReport $record): HtmlString => self::evidenceList($record)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidentReports::route('/'),
            'view'  => ViewIncidentReport::route('/{record}'),
        ];
    }
}
