<?php

namespace App\Filament\Admin\Resources\ServiceVerifications\Concerns;

use App\Services\Identity\ServiceVerificationService;
use App\Support\AdminAuth;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared review-queue table for the two parallel service-verification resources
 * (veteran / first responder). Both tables live in DB 1, share one lifecycle,
 * and are reviewed identically — only the model and labels differ — so the
 * column set, status filter, proof link, and approve/reject actions are built
 * once here and parameterised by the concrete resource's verificationType().
 */
trait BuildsVerificationQueue
{
    public static function configureQueueTable(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc') // oldest pending first — FIFO queue
            ->columns([
                TextColumn::make('user.email')
                    ->label('Applicant')
                    ->formatStateUsing(fn (Model $record): string =>
                        trim(($record->user?->profile?->first_name ?? '') . ' ' . ($record->user?->profile?->last_name ?? ''))
                            ?: ($record->user?->email ?? '—')
                    )
                    ->description(fn (Model $record): ?string => $record->user?->email)
                    ->searchable(query: fn ($query, string $search) =>
                        $query->whereHas('user', fn ($q) =>
                            $q->where('email', 'ilike', "%{$search}%")
                              ->orWhereHas('profile', fn ($p) =>
                                  $p->where('first_name', 'ilike', "%{$search}%")
                                    ->orWhere('last_name',  'ilike', "%{$search}%")
                              )
                        )
                    ),
                TextColumn::make('method')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'id_me' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === 'id_me' ? 'ID.me' : 'Document'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->label('Reviewed')
                    ->since()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),
            ])
            ->recordActions([
                Action::make('view_proof')
                    ->label('View Proof')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Model $record): ?string =>
                        filled($record->document_id) ? route('admin.documents.view', $record->document_id) : null
                    )
                    ->openUrlInNewTab()
                    ->visible(fn (Model $record): bool => filled($record->document_id)),

                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approve this verification, flip the member\'s status, and grant the configured benefit.')
                    ->visible(fn (Model $record): bool => $record->status === 'pending' && AdminAuth::canManageUsers())
                    ->action(function (Model $record): void {
                        app(ServiceVerificationService::class)->approve($record, AdminAuth::user()?->id);
                        Notification::make()->title('Verification approved')->success()->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Reject this verification. The member\'s status is left unchanged.')
                    ->visible(fn (Model $record): bool => $record->status === 'pending' && AdminAuth::canManageUsers())
                    ->action(function (Model $record): void {
                        app(ServiceVerificationService::class)->reject($record, AdminAuth::user()?->id);
                        Notification::make()->title('Verification rejected')->warning()->send();
                    }),
            ]);
    }
}
