<?php

namespace App\Filament\Admin\Resources\DamageClaims\Pages;

use App\Filament\Admin\Resources\DamageClaims\DamageClaimResource;
use App\Models\Incidents\DamageClaim;
use App\Services\Incidents\DamageClaimService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDamageClaim extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = DamageClaimResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Damage Claim', 'heroicon-o-wrench-screwdriver');
    }

    /** A claim still awaiting a review decision. */
    private static function isPending(DamageClaim $record): bool
    {
        return in_array($record->status, ['submitted', 'under_review'], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Approve with an approved amount (≤ claimed). Does not move money on its
            // own — settling against the deposit is a separate, explicit action.
            Action::make('approve')
                ->label('Approve')
                ->color('info')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (DamageClaim $record): bool => self::isPending($record))
                ->modalHeading('Approve Damage Claim')
                ->modalSubmitActionLabel('Approve Claim')
                ->fillForm(fn (DamageClaim $record): array => [
                    'amount' => number_format(((int) $record->amount_claimed_cents) / 100, 2, '.', ''),
                ])
                ->form([
                    TextInput::make('amount')
                        ->label('Approved Amount')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(fn (DamageClaim $record): float => ((int) $record->amount_claimed_cents) / 100)
                        ->helperText(fn (DamageClaim $record): string => 'Maximum: $'.number_format(((int) $record->amount_claimed_cents) / 100, 2)),
                    Textarea::make('note')->label('Note (optional)')->maxLength(200),
                ])
                ->action(function (DamageClaim $record, array $data): void {
                    $cents = (int) round(((float) $data['amount']) * 100);
                    $this->review($record, DamageClaimService::DECISION_APPROVE, $cents, $data['note'] ?? null);
                }),

            // Reject the claim outright.
            Action::make('deny')
                ->label('Deny')
                ->color('gray')
                ->icon(Heroicon::OutlinedXCircle)
                ->visible(fn (DamageClaim $record): bool => self::isPending($record))
                ->requiresConfirmation()
                ->modalHeading('Deny Damage Claim')
                ->modalSubmitActionLabel('Deny Claim')
                ->form([
                    Textarea::make('note')->label('Reason (optional)')->maxLength(200),
                ])
                ->action(fn (DamageClaim $record, array $data) => $this->review($record, DamageClaimService::DECISION_DENY, null, $data['note'] ?? null)),

            // Settle outside the platform — insurer handled the loss, no deposit move.
            Action::make('covered')
                ->label('Covered by Insurance')
                ->color('success')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn (DamageClaim $record): bool => self::isPending($record))
                ->requiresConfirmation()
                ->modalHeading('Mark Covered by Insurance')
                ->modalDescription('Closes the claim as settled by insurance — no deposit forfeiture.')
                ->modalSubmitActionLabel('Mark Covered')
                ->form([
                    Textarea::make('note')->label('Note (optional)')->maxLength(200),
                ])
                ->action(fn (DamageClaim $record, array $data) => $this->review($record, DamageClaimService::DECISION_COVERED, null, $data['note'] ?? null)),

            // Settle an approved claim from the held deposit — records a forfeiture
            // claim that then follows the contest/adjudication loop.
            Action::make('forfeitDeposit')
                ->label('Forfeit Deposit for Approved Amount')
                ->color('danger')
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (DamageClaim $record): bool => $record->status === DamageClaimService::DECISION_APPROVE && (int) $record->amount_approved_cents > 0)
                ->requiresConfirmation()
                ->modalHeading('Forfeit Deposit')
                ->modalDescription('Records a forfeiture claim against the lease\'s held deposit for the approved amount. The hunter can contest it before it finalizes.')
                ->modalSubmitActionLabel('Forfeit Deposit')
                ->action(function (DamageClaim $record): void {
                    try {
                        app(DamageClaimService::class)->forfeitDepositForApproved($record->id, auth()->id());
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Forfeit failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Deposit forfeiture recorded')->warning()->send();
                    $this->redirect(DamageClaimResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }

    private function review(DamageClaim $record, string $decision, ?int $amountCents, ?string $note): void
    {
        try {
            app(DamageClaimService::class)->review($record->id, $decision, $amountCents, auth()->id(), $note);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Review failed')->body($e->getMessage())->danger()->send();
            return;
        }

        Notification::make()->title('Claim updated')->success()->send();
        $this->redirect(DamageClaimResource::getUrl('view', ['record' => $record]));
    }
}
