<?php

namespace App\Filament\Admin\Resources\SecurityDeposits\Pages;

use App\Filament\Admin\Resources\SecurityDeposits\SecurityDepositResource;
use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\SecurityDepositService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
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
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(200)
                        ->helperText('Required — recorded permanently on the deposit.'),
                ])
                ->action(function (SecurityDeposit $record, array $data): void {
                    $amountCents = (int) round(((float) $data['amount']) * 100);
                    try {
                        app(SecurityDepositService::class)->forfeit($record->id, $amountCents, $data['reason'], auth()->id());
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Forfeit failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Deposit forfeited')->warning()->send();
                    $this->redirect(SecurityDepositResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
