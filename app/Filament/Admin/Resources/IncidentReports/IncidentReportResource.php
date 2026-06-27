<?php

namespace App\Filament\Admin\Resources\IncidentReports;

use App\Filament\Admin\Resources\IncidentReports\Pages\ListIncidentReports;
use App\Filament\Admin\Resources\IncidentReports\Pages\ViewIncidentReport;
use App\Models\Incidents\IncidentReport;
use App\Services\Incidents\IncidentService;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
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

    /** Allowed incident types (mirrors the DB CHECK) as value => label, for the edit form. */
    public static function typeOptions(): array
    {
        return collect(IncidentService::TYPES)
            ->mapWithKeys(fn (string $t): array => [$t => self::label($t)])->all();
    }

    /** The report's line-item types, combined into one label, e.g. "Fire · Medical". */
    public static function typesSummary(IncidentReport $record): string
    {
        $labels = collect($record->incident_items ?? [])
            ->pluck('type')
            ->filter()
            ->map(fn (string $t): string => self::label($t))
            ->unique()
            ->values();

        return $labels->isNotEmpty()
            ? $labels->implode(' · ')
            : ($record->incident_type ? self::label($record->incident_type) : '—');
    }

    /** A per-line-item breakdown (type — severity — when) for the infolist. */
    public static function itemsList(IncidentReport $record): HtmlString
    {
        $items = (array) ($record->incident_items ?? []);
        if (! $items) {
            return new HtmlString('<span style="color:#9ca3af;">—</span>');
        }

        $rows = '';
        foreach ($items as $item) {
            $type     = isset($item['type']) ? self::label((string) $item['type']) : '—';
            $severity = isset($item['severity']) ? self::label((string) $item['severity']) : '—';
            $when     = isset($item['occurred_at'])
                ? \Illuminate\Support\Carbon::parse($item['occurred_at'])->format('M j, Y H:i')
                : '—';
            $rows .= '<li style="font-size:13px;color:#374151;margin:2px 0;"><strong>'.e($type).'</strong>'
                .' <span style="font-size:11px;color:#6b7280;">'.e($severity).' · '.e($when).'</span></li>';
        }

        return new HtmlString('<ul style="margin:0;padding-left:16px;list-style:disc;">'.$rows.'</ul>');
    }

    /** The people involved in the incident (name + an "under 18" marker) for the infolist. */
    public static function partiesList(IncidentReport $record): HtmlString
    {
        $parties = (array) ($record->parties_involved ?? []);
        if (! $parties) {
            return new HtmlString('<span style="color:#9ca3af;">No parties recorded.</span>');
        }

        $rows = '';
        foreach ($parties as $party) {
            $name  = trim((string) ($party['full_name'] ?? '')) ?: 'Unnamed';
            $minor = ! empty($party['is_minor'])
                ? ' <span style="font-size:10px;font-weight:600;color:#c84c21;border:1px solid #c84c21;border-radius:4px;padding:1px 5px;letter-spacing:0.04em;">UNDER 18</span>'
                : '';
            $rows .= '<li style="font-size:13px;color:#374151;margin:2px 0;"><strong>'.e($name).'</strong>'.$minor.'</li>';
        }

        return new HtmlString('<ul style="margin:0;padding-left:16px;list-style:disc;">'.$rows.'</ul>');
    }

    /** Allowed severities (mirrors the DB CHECK) as value => label, for the edit form. */
    public static function severityOptions(): array
    {
        return collect(['minor', 'moderate', 'serious', 'critical'])
            ->mapWithKeys(fn (string $s): array => [$s => self::label($s)])->all();
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

        $docs    = \App\Models\Documents\Document::whereIn('id', $ids)->get()->keyBy('id');
        $items   = [];
        $missing = [];
        foreach ($ids as $id) {
            $doc = $docs[$id] ?? null;
            if (! $doc) {
                $missing[] = $id;
                continue;
            }

            $items[] = [
                'url'     => route('admin.documents.view', ['documentId' => $doc->id]),
                'name'    => $doc->original_filename ?? 'Document',
                'isImage' => str_starts_with((string) ($doc->mime_type ?? ''), 'image/'),
            ];
        }

        return new HtmlString(view('filament.admin.incidents.evidence-gallery', ['items' => $items, 'missing' => $missing])->render());
    }

    /**
     * The admin-only investigation notes for this incident, newest first. Note authors
     * are resolved to names cross-DB (no SQL join). Never surfaced to the reporter.
     */
    public static function adminNotesList(IncidentReport $record): HtmlString
    {
        $records = app(IncidentService::class)->adminNotes($record->id);

        $authorIds = $records->pluck('author_user_id')->filter()->unique()->all();
        $authors   = $authorIds
            ? \App\Models\Identity\User::whereIn('id', $authorIds)->get()->keyBy('id')
            : collect();

        $notes = $records->map(fn ($n): array => [
            'time'   => $n->created_at,
            'author' => $n->author_user_id ? ($authors[$n->author_user_id]?->getFilamentName() ?? 'Unknown user') : 'System',
            'body'   => (string) $n->note,
        ])->all();

        return new HtmlString(view('filament.admin.incidents.admin-notes', ['notes' => $notes])->render());
    }

    /**
     * The full change history for this incident — who changed what, and when —
     * read from the immutable audit log (DB 9) and rendered as a timeline. Actor
     * ids are resolved to names cross-DB (no SQL join) for readability.
     */
    public static function changeLog(IncidentReport $record): HtmlString
    {
        $events = \App\Models\Audit\AuditLog::query()
            ->where('table_name', 'incident_reports')
            ->where('record_id', $record->id)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        if ($events->isEmpty()) {
            return new HtmlString('<span style="color:#9ca3af;">No changes recorded yet.</span>');
        }

        $actorIds = $events->pluck('user_id')->filter()->unique()->all();
        $actors   = $actorIds
            ? \App\Models\Identity\User::whereIn('id', $actorIds)->get()->keyBy('id')
            : collect();

        $eventLabels = [
            'incident_report.filed'          => 'Filed',
            'incident_report.status_changed' => 'Status changed',
            'incident_report.updated'        => 'Details edited',
            'incident_report.evidence_added' => 'Photos added',
            'incident_report.parties_updated' => 'Parties updated',
        ];

        $rows = $events->map(fn ($e): array => [
            'time'    => $e->occurred_at,
            'actor'   => $e->user_id ? ($actors[$e->user_id]?->getFilamentName() ?? 'Unknown user') : 'System',
            'event'   => $eventLabels[$e->event_type] ?? self::label((string) str_replace('.', ' ', $e->event_type)),
            'summary' => $e->action_summary,
            'old'     => (array) ($e->old_values ?? []),
            'new'     => (array) ($e->new_values ?? []),
        ])->all();

        return new HtmlString(view('filament.admin.incidents.audit-log', ['rows' => $rows])->render());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('incident_number')
                    ->label('Case #')
                    ->searchable()
                    ->placeholder('—')
                    ->weight('bold'),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (string $state): string => self::label($state))
                    ->sortable(),
                TextColumn::make('incident_type')
                    ->label('Type')
                    ->state(fn (IncidentReport $record): string => self::typesSummary($record)),
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

    /**
     * Correct the incident's line items and descriptive fields. Lives in the Incident
     * section header; every change is diff-audited via IncidentService::updateDetails.
     */
    public static function editDetailsAction(): Action
    {
        return Action::make('edit_details')
            ->label('Edit Details')
            ->color('gray')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Edit Incident Details')
            ->modalSubmitActionLabel('Save Changes')
            ->fillForm(fn (IncidentReport $record): array => [
                'items'                   => collect($record->incident_items ?? [])->map(fn ($it): array => [
                    'type'        => $it['type'] ?? null,
                    'severity'    => $it['severity'] ?? null,
                    'occurred_at' => isset($it['occurred_at']) ? \Illuminate\Support\Carbon::parse($it['occurred_at'])->format('Y-m-d\TH:i') : null,
                ])->all(),
                'parties'                 => collect($record->parties_involved ?? [])->map(fn ($p): array => [
                    'full_name' => $p['full_name'] ?? null,
                    'is_minor'  => (bool) ($p['is_minor'] ?? false),
                ])->all(),
                'location_description'    => $record->location_description,
                'description'             => $record->description,
                'injuries_reported'       => $record->injuries_reported,
                'authorities_notified'    => $record->authorities_notified,
                'authority_report_number' => $record->authority_report_number,
            ])
            ->form([
                Repeater::make('items')
                    ->label('Incident types')
                    ->helperText('One event can be several things at once — add a row for each (e.g. a fire and a medical injury).')
                    ->schema([
                        Select::make('type')->options(self::typeOptions())->required(),
                        Select::make('severity')->options(self::severityOptions())->required(),
                        DateTimePicker::make('occurred_at')->label('When')->seconds(false)->required(),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->addActionLabel('Add another type')
                    ->required(),
                Repeater::make('parties')
                    ->label('Parties involved')
                    ->helperText('The people involved in this incident. Tick "Under 18" for any minor.')
                    ->schema([
                        TextInput::make('full_name')->label('Full name')->required()->maxLength(200),
                        Toggle::make('is_minor')->label('Under 18')->inline(false),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add a person')
                    ->default([]),
                TextInput::make('location_description')->label('Location on the property')->maxLength(500),
                Textarea::make('description')->label('What happened')->rows(4)->required()->maxLength(2000),
                Toggle::make('injuries_reported')->label('Injuries reported'),
                Toggle::make('authorities_notified')->label('Authorities notified'),
                TextInput::make('authority_report_number')->label('Authority report #')->maxLength(100),
            ])
            ->action(function (IncidentReport $record, array $data, $livewire): void {
                try {
                    app(IncidentService::class)->updateDetails($record->id, $data, auth()->id());
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Update failed')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Incident updated')->success()->send();
                $livewire->redirect(self::getUrl('view', ['record' => $record]));
            });
    }

    /**
     * Append an admin-only investigation note. Lives in the Investigation Notes section
     * header; staff-authored only — never shown to the reporter.
     */
    public static function addNoteAction(): Action
    {
        return Action::make('add_note')
            ->label('Add Note')
            ->color('gray')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->modalHeading('Add Investigation Note')
            ->modalDescription('Visible to admins only — not shown to the reporter. Notes are timestamped and cannot be edited or deleted.')
            ->modalSubmitActionLabel('Add Note')
            ->form([
                Textarea::make('note')->label('Note')->required()->rows(4)->maxLength(2000),
            ])
            ->action(function (IncidentReport $record, array $data, $livewire): void {
                try {
                    app(IncidentService::class)->addAdminNote($record->id, (string) $data['note'], auth()->id());
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Could not add note')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Note added')->success()->send();
                $livewire->redirect(self::getUrl('view', ['record' => $record]));
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Incident')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->schema([
                            Section::make('Incident')
                                ->key('incident-section')
                                ->icon('heroicon-o-exclamation-triangle')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Case summary, severity, and what happened.')
                                ->columns(3)
                                ->headerActions([
                                    self::editDetailsAction(),
                                ])
                                ->schema([
                                    TextEntry::make('incident_number')
                                        ->label('Case #')
                                        ->weight('bold')
                                        ->placeholder('—'),
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
                                        ->state(fn (IncidentReport $record): string => self::typesSummary($record))
                                        ->placeholder('—'),
                                    TextEntry::make('occurred_at')->label('Earliest occurred')->dateTime('M j, Y H:i')->placeholder('—'),
                                    TextEntry::make('incident_items')
                                        ->label('Incident types')
                                        ->state(fn (IncidentReport $record): HtmlString => self::itemsList($record))
                                        ->columnSpanFull(),
                                    TextEntry::make('location_description')->label('Location')->placeholder('—')->columnSpan(2),
                                    TextEntry::make('description')->label('What Happened')->placeholder('—')->columnSpanFull(),
                                ]),

                            Section::make('Parties Involved')
                                ->icon('heroicon-o-user-group')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('The people involved, and whether any were minors.')
                                ->schema([
                                    TextEntry::make('parties_involved')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->state(fn (IncidentReport $record): HtmlString => self::partiesList($record)),
                                ]),

                            Section::make('Safety & Authorities')
                                ->icon('heroicon-o-shield-check')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Injuries and whether authorities were notified.')
                                ->columns(3)
                                ->schema([
                                    IconEntry::make('injuries_reported')->label('Injuries Reported')->boolean(),
                                    IconEntry::make('authorities_notified')->label('Authorities Notified')->boolean(),
                                    TextEntry::make('authority_report_number')->label('Authority Report #')->placeholder('—'),
                                ]),

                            Section::make('Parties & References')
                                ->icon('heroicon-o-users')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Who reported it, and the property and lease involved.')
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
                                ->icon('heroicon-o-check-circle')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Outcome and resolution notes.')
                                ->columns(3)
                                ->visible(fn (IncidentReport $record): bool => in_array($record->status, ['resolved', 'closed'], true))
                                ->schema([
                                    TextEntry::make('resolved_at')->label('Resolved')->dateTime('M j, Y H:i')->placeholder('—'),
                                    TextEntry::make('resolution_notes')->label('Resolution Notes')->placeholder('—')->columnSpan(2),
                                ]),
                        ]),

                    Tab::make('Photo Evidence')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            Section::make('Photo Evidence')
                                ->icon('heroicon-o-photo')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Photos submitted with this report.')
                                ->schema([
                                    TextEntry::make('evidence')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->state(fn (IncidentReport $record): HtmlString => self::evidenceList($record)),
                                ]),
                        ]),

                    Tab::make('Investigation Notes')
                        ->icon('heroicon-o-chat-bubble-left-ellipsis')
                        ->schema([
                            Section::make('Investigation Notes')
                                ->key('investigation-notes-section')
                                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Admin-only — not visible to the reporter.')
                                ->headerActions([
                                    self::addNoteAction(),
                                ])
                                ->schema([
                                    TextEntry::make('admin_notes')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->state(fn (IncidentReport $record): HtmlString => self::adminNotesList($record)),
                                ]),
                        ]),

                    Tab::make('Change History')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Section::make('Change History')
                                ->icon('heroicon-o-clock')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Every edit and status change, who made it, and when.')
                                ->schema([
                                    TextEntry::make('change_log')
                                        ->hiddenLabel()
                                        ->columnSpanFull()
                                        ->state(fn (IncidentReport $record): HtmlString => self::changeLog($record)),
                                ]),
                        ]),
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
