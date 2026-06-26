<?php

namespace App\Filament\Admin\Resources\LeaseDisputes;

use App\Filament\Admin\Resources\LeaseDisputes\Pages\ListLeaseDisputes;
use App\Filament\Admin\Resources\LeaseDisputes\Pages\ViewLeaseDispute;
use App\Models\Incidents\LeaseDispute;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class LeaseDisputeResource extends Resource
{
    protected static ?string $model = LeaseDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Lease Disputes';

    protected static ?string $slug = 'lease-disputes';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Incidents';
    }

    protected static ?string $recordTitleAttribute = 'id';

    // Read + adjudicate. Disputes are authored by the contest flow; the resolve
    // actions on the view page mutate through DisputeService.
    public static function canAccess(): bool
    {
        return AdminAuth::canViewBilling();
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

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'open'        => 'warning',
            'mediation', 'arbitration', 'escalated' => 'info',
            'resolved'    => 'success',
            'withdrawn'   => 'gray',
            default       => 'gray',
        };
    }

    public static function statusLabel(string $state): string
    {
        return ucwords(str_replace('_', ' ', $state));
    }

    /** Render a raw cross-DB UUID as small muted mono helper text under a name. */
    public static function rawIdHint(?string $id): ?HtmlString
    {
        if (! $id) {
            return null;
        }

        return new HtmlString(
            '<span style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">REF UUID: '.e($id).'</span>'
        );
    }

    /** Resolve the dispute's evidence document ids to a filename + status list. */
    public static function evidenceList(LeaseDispute $record): HtmlString
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
                TextColumn::make('id')
                    ->label('Dispute')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (LeaseDispute $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('dispute_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(str_replace('_', ' ', $state)) : '—'),
                TextColumn::make('initiator_user_id')
                    ->label('Hunter')
                    ->state(fn (LeaseDispute $record): ?string => $record->getInitiator()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('respondent_user_id')
                    ->label('Landowner')
                    ->state(fn (LeaseDispute $record): ?string => $record->getRespondent()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('amount_disputed_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Filed')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dispute')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('dispute_type')
                        ->label('Type')
                        ->formatStateUsing(fn (?string $state): ?string => $state ? ucwords(str_replace('_', ' ', $state)) : null)
                        ->placeholder('—'),
                    TextEntry::make('amount_disputed_cents')->label('Amount')->money('USD', divideBy: 100),
                    TextEntry::make('description')->label('Hunter\'s Statement')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('resolution')->label('Resolution')->placeholder('Unresolved')->columnSpanFull(),
                    TextEntry::make('resolved_at')->label('Resolved')->dateTime('M j, Y H:i')->placeholder('—'),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    TextEntry::make('initiator_user_id')
                        ->label('Hunter (initiator)')
                        ->state(fn (LeaseDispute $record): ?string => $record->getInitiator()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (LeaseDispute $record): ?HtmlString => self::rawIdHint($record->initiator_user_id)),
                    TextEntry::make('respondent_user_id')
                        ->label('Landowner (respondent)')
                        ->state(fn (LeaseDispute $record): ?string => $record->getRespondent()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (LeaseDispute $record): ?HtmlString => self::rawIdHint($record->respondent_user_id)),
                    TextEntry::make('lease_id')
                        ->label('Lease')
                        ->state(fn (LeaseDispute $record): ?string => $record->leaseLabel())
                        ->placeholder('—')
                        ->helperText(fn (LeaseDispute $record): ?HtmlString => self::rawIdHint($record->lease_id)),
                ]),

            // The contested forfeiture lives on the deposit (DB 4). Show its current
            // financial + Trust Score posture so the adjudicator has full context.
            Section::make('Contested Forfeiture')
                ->columns(3)
                ->visible(fn (LeaseDispute $record): bool => $record->getDeposit() !== null)
                ->schema([
                    TextEntry::make('deposit_amount')
                        ->label('Forfeited Amount')
                        ->state(fn (LeaseDispute $record): ?string => ($d = $record->getDeposit()) ? '$'.number_format($d->forfeited_amount_cents / 100, 2) : null)
                        ->placeholder('—'),
                    TextEntry::make('deposit_category')
                        ->label('Category')
                        ->state(fn (LeaseDispute $record): ?string => ($c = $record->getDeposit()?->forfeit_category) ? ucwords(str_replace('_', ' ', $c)) : null)
                        ->placeholder('—'),
                    TextEntry::make('deposit_trust')
                        ->label('Trust Score')
                        ->state(fn (LeaseDispute $record): ?string => $record->getDeposit()?->forfeit_trust_status)
                        ->formatStateUsing(fn (?string $state): ?string => $state ? ucwords(str_replace('_', ' ', $state)) : null)
                        ->placeholder('—'),
                    TextEntry::make('deposit_reason')
                        ->label('Landowner\'s Reason')
                        ->state(fn (LeaseDispute $record): ?string => $record->getDeposit()?->forfeit_reason)
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Photo Evidence')
                ->schema([
                    TextEntry::make('evidence')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (LeaseDispute $record): HtmlString => self::evidenceList($record)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaseDisputes::route('/'),
            'view'  => ViewLeaseDispute::route('/{record}'),
        ];
    }
}
