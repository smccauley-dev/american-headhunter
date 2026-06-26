<?php

namespace App\Filament\Admin\Resources\DamageClaims;

use App\Filament\Admin\Resources\DamageClaims\Pages\ListDamageClaims;
use App\Filament\Admin\Resources\DamageClaims\Pages\ViewDamageClaim;
use App\Models\Incidents\DamageClaim;
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

class DamageClaimResource extends Resource
{
    protected static ?string $model = DamageClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Damage Claims';

    protected static ?string $slug = 'damage-claims';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Incidents';
    }

    protected static ?string $recordTitleAttribute = 'id';

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
            'submitted', 'under_review' => 'warning',
            'approved' => 'info',
            'paid'     => 'success',
            'covered'  => 'success',
            'denied'   => 'gray',
            default    => 'gray',
        };
    }

    public static function statusLabel(string $state): string
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

    public static function evidenceList(DamageClaim $record): HtmlString
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
                    ->label('Claim')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (DamageClaim $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('claim_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(str_replace('_', ' ', $state)) : '—'),
                TextColumn::make('claimant_user_id')
                    ->label('Landowner')
                    ->state(fn (DamageClaim $record): ?string => $record->getClaimant()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('amount_claimed_cents')
                    ->label('Claimed')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('amount_approved_cents')
                    ->label('Approved')
                    ->money('USD', divideBy: 100)
                    ->placeholder('—'),
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
            Section::make('Claim')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('claim_type')
                        ->label('Type')
                        ->formatStateUsing(fn (?string $state): ?string => $state ? ucwords(str_replace('_', ' ', $state)) : null)
                        ->placeholder('—'),
                    TextEntry::make('amount_claimed_cents')->label('Claimed')->money('USD', divideBy: 100),
                    TextEntry::make('amount_approved_cents')->label('Approved')->money('USD', divideBy: 100)->placeholder('—'),
                    TextEntry::make('description')->label('Description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('review_notes')->label('Review Notes')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('resolved_at')->label('Resolved')->dateTime('M j, Y H:i')->placeholder('—'),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    TextEntry::make('claimant_user_id')
                        ->label('Landowner (claimant)')
                        ->state(fn (DamageClaim $record): ?string => $record->getClaimant()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (DamageClaim $record): ?HtmlString => self::rawIdHint($record->claimant_user_id)),
                    TextEntry::make('lease_id')
                        ->label('Lease')
                        ->state(fn (DamageClaim $record): ?string => $record->leaseLabel())
                        ->placeholder('—')
                        ->helperText(fn (DamageClaim $record): ?HtmlString => self::rawIdHint($record->lease_id)),
                    TextEntry::make('security_deposit_id')
                        ->label('Settled From Deposit')
                        ->placeholder('—')
                        ->helperText(fn (DamageClaim $record): ?HtmlString => self::rawIdHint($record->security_deposit_id)),
                ]),

            Section::make('Insurance')
                ->columns(3)
                ->visible(fn (DamageClaim $record): bool => $record->insurance_covered_party !== null && $record->insurance_covered_party !== 'none')
                ->schema([
                    TextEntry::make('insurance_covered_party')
                        ->label('Covered Party')
                        ->formatStateUsing(fn (?string $state): ?string => $state ? ucfirst($state) : null)
                        ->placeholder('—'),
                    TextEntry::make('insurer_name')->label('Insurer')->placeholder('—'),
                    TextEntry::make('policy_number')->label('Policy #')->placeholder('—'),
                    TextEntry::make('coverage_status')
                        ->label('Coverage Status')
                        ->formatStateUsing(fn (?string $state): ?string => $state ? ucfirst($state) : null)
                        ->placeholder('—'),
                ]),

            Section::make('Photo Evidence')
                ->schema([
                    TextEntry::make('evidence')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (DamageClaim $record): HtmlString => self::evidenceList($record)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDamageClaims::route('/'),
            'view'  => ViewDamageClaim::route('/{record}'),
        ];
    }
}
