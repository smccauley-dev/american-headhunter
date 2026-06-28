<?php

namespace App\Filament\Admin\Resources\LeasePayments\Pages;

use App\Filament\Admin\Resources\LeasePayments\LeasePaymentResource;
use App\Models\Billing\LeasePayment;
use App\Services\Billing\LeasePaymentService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLeasePayment extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = LeasePaymentResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Lease Payment', 'heroicon-o-banknotes');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Refund the lease payment — reverses the destination transfer (claws the
            // net back from the landowner) and returns the platform fee. A blank
            // amount refunds in full; a partial amount marks it partially refunded.
            Action::make('refund')
                ->label('Refund')
                ->color('danger')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (LeasePayment $record): bool => $record->status !== 'refunded')
                ->requiresConfirmation()
                ->modalHeading('Refund Lease Payment')
                ->modalDescription('Refunds the customer through Stripe, reversing the transfer to the landowner and returning the platform fee. This cannot be undone.')
                ->modalSubmitActionLabel('Refund Payment')
                ->fillForm(fn (LeasePayment $record): array => [
                    'amount' => number_format($record->gross_cents / 100, 2, '.', ''),
                ])
                ->form([
                    TextInput::make('amount')
                        ->label('Amount to Refund')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(fn (LeasePayment $record): float => $record->gross_cents / 100)
                        ->helperText(fn (LeasePayment $record): string => 'Maximum: $' . number_format($record->gross_cents / 100, 2) . ' (gross). Leave at the full amount to refund completely.'),
                ])
                ->action(function (LeasePayment $record, array $data): void {
                    $amountCents = (int) round(((float) $data['amount']) * 100);
                    $full        = $amountCents >= $record->gross_cents;
                    try {
                        app(LeasePaymentService::class)->refund(
                            $record,
                            $full ? null : $amountCents,
                            auth()->id(),
                        );
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Refund failed')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Lease payment refunded')->success()->send();
                    $this->redirect(LeasePaymentResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
