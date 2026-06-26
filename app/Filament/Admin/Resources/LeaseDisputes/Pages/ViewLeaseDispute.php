<?php

namespace App\Filament\Admin\Resources\LeaseDisputes\Pages;

use App\Filament\Admin\Resources\LeaseDisputes\LeaseDisputeResource;
use App\Models\Incidents\LeaseDispute;
use App\Services\Incidents\DisputeService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLeaseDispute extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = LeaseDisputeResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Lease Dispute', 'heroicon-o-scale');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Landowner was within their rights — the forfeiture stands and the
            // hunter takes the Trust Score penalty. Money moves at this terminal node.
            Action::make('uphold')
                ->label('Uphold Forfeiture')
                ->color('danger')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->visible(fn (LeaseDispute $record): bool => $record->isOpen())
                ->requiresConfirmation()
                ->modalHeading('Uphold the Forfeiture')
                ->modalDescription('Settles the deposit in the landowner\'s favour and applies the hunter\'s Trust Score penalty. This finalizes the money movement and cannot be undone.')
                ->modalSubmitActionLabel('Uphold & Penalize')
                ->form([
                    Textarea::make('note')->label('Note (optional)')->maxLength(200)
                        ->helperText('Recorded with the resolution for the audit trail.'),
                ])
                ->action(fn (LeaseDispute $record, array $data) => $this->resolve($record, DisputeService::OUTCOME_UPHOLD, $data['note'] ?? null)),

            // Landowner's claim was unjustified — refund the hunter in full, dock the
            // landowner, and optionally restore the vindicated hunter's standing.
            Action::make('overturn')
                ->label('Overturn (refund hunter)')
                ->color('success')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (LeaseDispute $record): bool => $record->isOpen())
                ->modalHeading('Overturn the Forfeiture')
                ->modalDescription('Refunds the full deposit to the hunter and applies a Trust Score penalty to the landowner for an unjustified forfeiture.')
                ->modalSubmitActionLabel('Overturn & Refund')
                ->form([
                    Checkbox::make('credit_initiator')
                        ->label('Also restore the hunter\'s standing (+5)')
                        ->helperText('Credit the vindicated hunter a dispute_resolved_for_user bonus.')
                        ->default(false),
                    Textarea::make('note')->label('Note (optional)')->maxLength(200),
                ])
                ->action(fn (LeaseDispute $record, array $data) => $this->resolve(
                    $record,
                    DisputeService::OUTCOME_OVERTURN,
                    $data['note'] ?? null,
                    ['credit_initiator' => (bool) ($data['credit_initiator'] ?? false)],
                )),

            // Settle via insurance — no fault for either party. Binary disposition:
            // keep (disburse claimed to landowner) or refund (return to hunter).
            Action::make('optOut')
                ->label('Covered by Insurance')
                ->color('gray')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn (LeaseDispute $record): bool => $record->isOpen())
                ->modalHeading('Settle via Insurance')
                ->modalDescription('Closes the dispute with no Trust Score change for either party. Choose how the held deposit is settled.')
                ->modalSubmitActionLabel('Settle via Insurance')
                ->form([
                    Select::make('disposition')
                        ->label('Settlement')
                        ->required()
                        ->default('refund')
                        ->native(false)
                        ->options([
                            'refund' => 'Refund the deposit to the hunter',
                            'keep'   => 'Disburse the claimed amount to the landowner',
                        ]),
                    Textarea::make('note')->label('Note (optional)')->maxLength(200),
                ])
                ->action(fn (LeaseDispute $record, array $data) => $this->resolve(
                    $record,
                    DisputeService::OUTCOME_OPT_OUT,
                    $data['note'] ?? null,
                    ['disposition' => $data['disposition']],
                )),
        ];
    }

    private function resolve(LeaseDispute $record, string $outcome, ?string $note, array $opts = []): void
    {
        try {
            app(DisputeService::class)->resolve($record->id, $outcome, auth()->id(), $note, $opts);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Resolution failed')->body($e->getMessage())->danger()->send();
            return;
        }

        Notification::make()->title('Dispute resolved')->success()->send();
        $this->redirect(LeaseDisputeResource::getUrl('view', ['record' => $record]));
    }
}
