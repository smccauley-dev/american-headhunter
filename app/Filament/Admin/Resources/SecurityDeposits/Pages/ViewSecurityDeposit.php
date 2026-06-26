<?php

namespace App\Filament\Admin\Resources\SecurityDeposits\Pages;

use App\Filament\Admin\Resources\SecurityDeposits\SecurityDepositResource;
use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\SecurityDepositService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSecurityDeposit extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = SecurityDepositResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Security Deposit', 'heroicon-o-banknotes');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Return the held deposit to the lessee (full remaining refund).
            Action::make('release')
                ->label('Release to Lessee')
                ->color('success')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (SecurityDeposit $record): bool => $record->status === 'held')
                ->requiresConfirmation()
                ->modalHeading('Release Security Deposit')
                ->modalDescription('Refunds the full remaining balance to the lessee through Stripe. This cannot be undone.')
                ->modalSubmitActionLabel('Release Deposit')
                ->form([
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->maxLength(200)
                        ->helperText('Recorded with the Stripe refund. Not shown to the lessee.'),
                ])
                ->action(function (SecurityDeposit $record, array $data): void {
                    try {
                        app(SecurityDepositService::class)->release($record->id, auth()->id(), $data['note'] ?? null);
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Release failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Deposit released to lessee')->success()->send();
                    $this->redirect(SecurityDepositResource::getUrl('view', ['record' => $record]));
                }),

            // Forfeit some/all to the landowner. Any remainder is returned to the
            // lessee now; the forfeited amount stays captured until the Connect
            // payout ships (see SecurityDepositService::forfeit).
            Action::make('forfeit')
                ->label('Forfeit')
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->visible(fn (SecurityDeposit $record): bool => $record->status === 'held')
                ->requiresConfirmation()
                ->modalHeading('Forfeit Security Deposit')
                ->modalDescription('Records a forfeiture to the landowner and refunds any un-forfeited remainder to the lessee. The forfeited amount is disbursed to the landowner once payouts are enabled.')
                ->modalSubmitActionLabel('Forfeit Deposit')
                ->fillForm(fn (SecurityDeposit $record): array => [
                    'amount' => number_format($record->remainingCents() / 100, 2, '.', ''),
                ])
                ->form([
                    TextInput::make('amount')
                        ->label('Amount to Forfeit')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(fn (SecurityDeposit $record): float => $record->remainingCents() / 100)
                        ->helperText(fn (SecurityDeposit $record): string => 'Maximum: $' . number_format($record->remainingCents() / 100, 2)),
                    Select::make('fault')
                        ->label('Responsible Party')
                        ->required()
                        ->default(SecurityDepositService::FAULT_LESSEE)
                        ->options([
                            SecurityDepositService::FAULT_LESSEE              => 'Hunter at fault (damage / violation)',
                            SecurityDepositService::FAULT_CONTESTED           => 'Contested — hunter disputes fault',
                            SecurityDepositService::FAULT_LANDOWNER_INITIATED => 'Landowner-initiated, no hunter fault',
                        ])
                        ->helperText("Hunter-fault and contested forfeitures park a Trust Score penalty you'll confirm or waive. Landowner-initiated forfeitures never penalize the hunter."),
                    Select::make('category')
                        ->label('Category')
                        ->options([
                            'property_damage'  => 'Property damage',
                            'equipment_damage' => 'Equipment damage',
                            'rule_violation'   => 'Rule violation',
                            'no_show'          => 'No-show',
                            'unpaid_fees'      => 'Unpaid fees',
                            'cleaning'         => 'Cleaning',
                            'other'            => 'Other',
                        ])
                        ->native(false)
                        ->placeholder('—'),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(200)
                        ->helperText('Required — recorded permanently on the deposit.'),
                ])
                ->action(function (SecurityDeposit $record, array $data): void {
                    $amountCents = (int) round(((float) $data['amount']) * 100);
                    try {
                        app(SecurityDepositService::class)->forfeit(
                            $record->id,
                            $amountCents,
                            $data['reason'],
                            auth()->id(),
                            $data['fault'],
                            $data['category'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Forfeit failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Deposit forfeited')->warning()->send();
                    $this->redirect(SecurityDepositResource::getUrl('view', ['record' => $record]));
                }),

            // A forfeiture attributed to the hunter parks a PROVISIONAL Trust Score
            // penalty (forfeit_trust_status = pending). An admin must affirmatively
            // confirm it — protecting hunters from a scam landowner's unfair claim.
            Action::make('confirmFault')
                ->label('Confirm Fault (apply Trust Score)')
                ->color('danger')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->visible(fn (SecurityDeposit $record): bool => $record->hasPendingTrustDecision())
                ->requiresConfirmation()
                ->modalHeading('Confirm Forfeiture Fault')
                ->modalDescription("Applies the hunter's Trust Score penalty for this forfeiture. Do this only when the hunter is genuinely at fault.")
                ->modalSubmitActionLabel('Confirm & Apply Penalty')
                ->action(function (SecurityDeposit $record): void {
                    try {
                        app(SecurityDepositService::class)->confirmForfeitFault($record->id, auth()->id());
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Confirm failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Fault confirmed — Trust Score penalty applied')->warning()->send();
                    $this->redirect(SecurityDepositResource::getUrl('view', ['record' => $record]));
                }),

            // Exonerate the hunter — no Trust Score penalty (the cash outcome stands).
            Action::make('waiveFault')
                ->label('Waive (no Trust Score hit)')
                ->color('gray')
                ->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn (SecurityDeposit $record): bool => $record->hasPendingTrustDecision())
                ->modalHeading('Waive Forfeiture Penalty')
                ->modalDescription("Clears the pending Trust Score penalty — the hunter is not held at fault. The forfeiture itself is unchanged.")
                ->modalSubmitActionLabel('Waive Penalty')
                ->form([
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->maxLength(200)
                        ->helperText('Recorded with the waiver for the audit trail.'),
                ])
                ->action(function (SecurityDeposit $record, array $data): void {
                    try {
                        app(SecurityDepositService::class)->waiveForfeitFault($record->id, auth()->id(), $data['note'] ?? null);
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Waive failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Penalty waived')->success()->send();
                    $this->redirect(SecurityDepositResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
