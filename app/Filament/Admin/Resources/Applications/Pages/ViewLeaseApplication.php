<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Enums\LeaseDocumentTag;
use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Applications\LeaseApplicationResource;
use App\Filament\Admin\Resources\Users\CustomerUserResource;
use App\Models\Documents\Document;
use App\Models\Identity\UserProfile;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Models\Lease\LeaseApplicationMessage;
use App\Models\Lease\LeaseApplicationReviewHistory;
use App\Models\Property\PropertyListing;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\LeaseFinanceSummaryService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Property\PropertyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ViewLeaseApplication extends ViewRecord
{
    use HasViewPageScaffold;

    protected static string $resource = LeaseApplicationResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([

                // ── Application Details — 2/3 wide ────────────────────────────
                Section::make('Application Details')
                    ->columnSpan(2)
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('id')
                                ->label('Application ID')
                                ->fontFamily('mono')
                                ->formatStateUsing(fn (string $state): string => 'AH-' . strtoupper(substr($state, 0, 8)))
                                ->copyable(),

                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'pending'      => 'warning',
                                    'under_review' => 'info',
                                    'approved'     => 'success',
                                    'rejected'     => 'danger',
                                    'withdrawn'    => 'gray',
                                    'expired'      => 'gray',
                                    default        => 'gray',
                                }),

                            TextEntry::make('application_type')
                                ->label('Type')
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'individual' => 'Individual',
                                    'club'       => 'Club',
                                    default      => $state,
                                }),

                            TextEntry::make('proposed_start')
                                ->label('Proposed Start')
                                ->date('F j, Y'),

                            TextEntry::make('proposed_end')
                                ->label('Proposed End')
                                ->date('F j, Y'),

                            TextEntry::make('desired_hunters')
                                ->label('Hunters Named')
                                ->numeric(),

                            TextEntry::make('created_at')
                                ->label('Submitted')
                                ->dateTime('F j, Y H:i')
                                ->columnSpanFull(),

                            TextEntry::make('message')
                                ->label('Message to Landowner')
                                ->columnSpanFull()
                                ->placeholder('No message provided.')
                                ->prose(),
                        ]),
                    ]),

                // ── Sidebar — 1/3 wide ────────────────────────────────────────
                Section::make('Listing & Applicant')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('listing_id')
                            ->label('Listing ID')
                            ->fontFamily('mono')
                            ->state(fn (LeaseApplication $record): string =>
                                strtoupper(substr($record->listing_id, 0, 8)) . ($record->property_slug_snapshot ? ' ↗' : '')
                            )
                            ->url(fn (LeaseApplication $record): string => $this->listingUrl($record))
                            ->openUrlInNewTab()
                            ->copyable(),

                        TextEntry::make('property_title')
                            ->label('Property')
                            ->state(function (LeaseApplication $record): string {
                                $title = $record->property_title_snapshot
                                    ?? $this->resolveListing($record)?->property?->title;
                                return $title ? $title . ' ↗' : '—';
                            })
                            ->url(fn (LeaseApplication $record): string => $this->listingUrl($record))
                            ->openUrlInNewTab(),

                        TextEntry::make('property_location')
                            ->label('Location')
                            ->state(function (LeaseApplication $record): string {
                                if ($record->property_location_snapshot) {
                                    return $record->property_location_snapshot;
                                }
                                $prop = $this->resolveListing($record)?->property;
                                return $prop ? "{$prop->county} County, {$prop->state_code}" : '—';
                            }),

                        TextEntry::make('applicant_name')
                            ->label('Applicant Name')
                            ->state(fn (LeaseApplication $record): string => rescue(function () use ($record) {
                                $profile = UserProfile::on('identity')
                                    ->where('user_id', $record->applicant_user_id)
                                    ->first();
                                return $profile
                                    ? trim("{$profile->first_name} {$profile->last_name}") ?: '—'
                                    : '—';
                            }, '—')),

                        TextEntry::make('applicant_user_id')
                            ->label('Applicant ID')
                            ->fontFamily('mono')
                            ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8)) . ' ↗')
                            ->url(fn (LeaseApplication $record): string =>
                                CustomerUserResource::getUrl('edit', ['record' => $record->applicant_user_id])
                            )
                            ->openUrlInNewTab()
                            ->copyable(),
                    ]),

                // ── Signing Status — full width (only when lease exists) ───────
                Section::make('Lease & Signing Status')
                    ->columnSpan(3)
                    ->visible(fn (LeaseApplication $record): bool => $record->lease()->exists())
                    ->schema([
                        TextEntry::make('signing_status')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildSigningStatusHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Payment Status — full width (only when lease exists) ──────
                Section::make('Payment Status')
                    ->columnSpan(3)
                    ->description('What the hunter has paid and the landowner\'s net after fees.')
                    ->visible(fn (LeaseApplication $record): bool => $record->lease()->exists())
                    ->schema([
                        TextEntry::make('payment_status')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildPaymentStatusHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Lease Documents — full width (visible whenever a lease exists) ──
                Section::make('Lease Documents')
                    ->columnSpan(3)
                    ->description('Contract documents attached to or generated by this lease.')
                    ->visible(fn (LeaseApplication $record): bool => $record->lease()->exists())
                    ->headerActions([
                        Action::make('upload_document')
                            ->label('Upload Document')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->form([
                                FileUpload::make('document_file')
                                    ->label('Document (PDF)')
                                    ->disk('local')
                                    ->directory('pending-lease-docs')
                                    ->acceptedFileTypes(['application/pdf'])
                                    ->maxSize(20480)
                                    ->required(),
                                Select::make('tag')
                                    ->label('Document Type')
                                    ->required()
                                    ->options(LeaseDocumentTag::options())
                                    ->native(false),
                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->maxLength(500)
                                    ->nullable(),
                            ])
                            ->action(function (LeaseApplication $record, array $data): void {
                                $lease = $record->lease;
                                if (! $lease) {
                                    Notification::make()->title('No lease found')->danger()->send();
                                    return;
                                }

                                $storedPath   = \Illuminate\Support\Facades\Storage::disk('local')->path($data['document_file']);
                                $uploadedFile = new \Illuminate\Http\UploadedFile(
                                    $storedPath,
                                    basename($data['document_file']),
                                    'application/pdf',
                                    null,
                                    true,
                                );

                                try {
                                    app(LeaseDocumentService::class)->upload(
                                        $lease->id,
                                        $uploadedFile,
                                        $data['tag'],
                                        auth()->id(),
                                        $data['notes'] ?? null,
                                    );
                                    \Illuminate\Support\Facades\Storage::disk('local')->delete($data['document_file']);
                                } catch (\Throwable $e) {
                                    \Illuminate\Support\Facades\Log::warning('Admin lease document upload failed', ['error' => $e->getMessage()]);
                                    Notification::make()->title('Upload failed')->body($e->getMessage())->danger()->send();
                                    return;
                                }

                                Notification::make()->title('Document uploaded')->success()->send();
                                $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                            }),
                    ])
                    ->schema([
                        TextEntry::make('lease_documents')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildLeaseDocumentsHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Hunter Roster — full width ────────────────────────────────
                Section::make('Hunter Roster')
                    ->columnSpan(3)
                    ->schema([
                        TextEntry::make('hunter_roster')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildHunterRosterHtml($record->id))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Notes — full width ────────────────────────────────────────
                Section::make('Notes')
                    ->columnSpan(3)
                    ->description('Visible to staff and landowner only — not shown to the applicant.')
                    ->headerActions([
                        Action::make('edit_notes')
                            ->label('Edit Notes')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->fillForm(fn (LeaseApplication $record): array => [
                                'admin_notes' => $record->admin_notes ?? '',
                            ])
                            ->form([
                                Textarea::make('admin_notes')
                                    ->label('Application Notes')
                                    ->helperText('Private — visible to staff and landowner only. Not shown to the applicant.')
                                    ->maxLength(5000)
                                    ->rows(8),
                            ])
                            ->action(function (LeaseApplication $record, array $data): void {
                                app(ApplicationMessageService::class)->saveNotes($record->id, $data['admin_notes'] ?? '');
                                Notification::make()->title('Notes saved')->success()->send();
                                $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                            }),
                    ])
                    ->schema([
                        TextEntry::make('admin_notes')
                            ->label('')
                            ->placeholder('No notes added yet. Click "Edit Notes" to add.')
                            ->columnSpanFull()
                            ->prose(),
                    ]),

                // ── Communications — full width ───────────────────────────────
                Section::make('Communications')
                    ->columnSpan(3)
                    ->description('Messages between staff, landowner, and applicant regarding this application.')
                    ->headerActions([
                        Action::make('send_message')
                            ->label('Send Message')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedChatBubbleLeft)
                            ->form([
                                Textarea::make('message')
                                    ->label('Message to Applicant')
                                    ->required()
                                    ->maxLength(2000)
                                    ->rows(5)
                                    ->helperText('The applicant will receive an email notification with this message.'),
                            ])
                            ->action(function (LeaseApplication $record, array $data): void {
                                app(ApplicationMessageService::class)->send(
                                    $record->id,
                                    auth()->id(),
                                    'admin',
                                    $data['message'],
                                );
                                Notification::make()->title('Message sent to applicant')->success()->send();
                                $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                            }),
                    ])
                    ->schema([
                        TextEntry::make('communications_thread')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildMessagesHtml($record->id))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Review History — full width ───────────────────────────────
                Section::make('Review History')
                    ->columnSpan(3)
                    ->description('Every approval, rejection, and override recorded in order.')
                    ->schema([
                        TextEntry::make('review_history')
                            ->label('')
                            ->state(fn (LeaseApplication $record): HtmlString => $this->buildReviewHistoryHtml($record->id))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── Review — full width ───────────────────────────────────────
                Section::make('Review')
                    ->columnSpan(3)
                    ->collapsed(fn (LeaseApplication $record): bool => $record->reviewed_at === null)
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('reviewed_at')
                                ->label('Reviewed At')
                                ->dateTime('F j, Y H:i')
                                ->placeholder('Not yet reviewed'),

                            TextEntry::make('reviewed_by_user_id')
                                ->label('Reviewed By')
                                ->fontFamily('mono')
                                ->formatStateUsing(fn (?string $state): string => $state ? strtoupper(substr($state, 0, 8)) : '—')
                                ->placeholder('—'),

                            TextEntry::make('rejection_reason')
                                ->label('Rejection Reason')
                                ->placeholder('—')
                                ->visible(fn (LeaseApplication $record): bool => $record->status === 'rejected'),
                        ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print')
                ->color('gray')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (LeaseApplication $record): string => route('admin.applications.print', $record->id))
                ->openUrlInNewTab(),

            // Sign as Lessor — visible after approval, while lessor signature is pending
            Action::make('sign_as_lessor')
                ->label('Sign as Lessor')
                ->color('warning')
                ->icon(Heroicon::OutlinedPencil)
                ->visible(fn (LeaseApplication $record): bool => $this->lessorSignaturePending($record))
                ->requiresConfirmation()
                ->modalHeading('Sign on Behalf of Lessor')
                ->modalDescription('This records the landowner\'s in-platform signature using your current session. The action is permanently logged.')
                ->modalSubmitActionLabel('Sign as Lessor')
                ->action(function (LeaseApplication $record): void {
                    $lease = $record->lease;
                    if (! $lease) {
                        Notification::make()->title('No lease found')->danger()->send();
                        return;
                    }

                    $esigRequest = app(EsignatureService::class)->getRequestForLease($lease->id);
                    if (! $esigRequest) {
                        Notification::make()->title('No signing request found')->danger()->send();
                        return;
                    }

                    $activated = app(EsignatureService::class)->recordSignature(
                        $esigRequest->id,
                        $lease->lessor_user_id,
                        request()->ip(),
                        request()->userAgent(),
                        recordedByUserId: auth()->id(),
                    );

                    $message = $activated
                        ? 'Lessor signed — lease is now ACTIVE'
                        : 'Lessor signed — waiting for lessee signature';

                    Notification::make()->title($message)->success()->send();
                    $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                }),

            Action::make('override')
                ->label('Override Decision')
                ->color('warning')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (LeaseApplication $record): bool => in_array($record->status, ['approved', 'rejected']))
                ->form([
                    Select::make('new_status')
                        ->label('New Decision')
                        ->required()
                        ->options(['approved' => 'Approve', 'rejected' => 'Reject'])
                        ->native(false),
                    Textarea::make('reason')
                        ->label('Override Reason')
                        ->required()
                        ->maxLength(1000)
                        ->helperText('Required — this is recorded permanently in the review history.'),
                    Checkbox::make('notify_applicant')
                        ->label('Notify applicant of status change')
                        ->helperText('Posts a message in Communications and emails the applicant.'),
                ])
                ->action(function (LeaseApplication $record, array $data): void {
                    try {
                        app(ApplicationService::class)->override(
                            $record->id,
                            auth()->id(),
                            $data['new_status'],
                            $data['reason'],
                        );
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Override blocked')->body($e->getMessage())->danger()->send();
                        return;
                    }
                    if (! empty($data['notify_applicant'])) {
                        $statusLabel = $data['new_status'] === 'approved' ? 'approved' : 'rejected';
                        app(ApplicationMessageService::class)->send(
                            $record->id,
                            auth()->id(),
                            'admin',
                            "Your application status has been updated to {$statusLabel}. Reason: {$data['reason']}",
                        );
                    }
                    Notification::make()->title('Decision overridden')->warning()->send();
                    $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                }),

            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modalHeading('Approve Application')
                ->modalDescription('Vet-first: approval opens a 24-hour window for the applicant to pay the booking fee and claim the spot. No lease is created until they pay — the first approved applicant to pay wins. Lease terms come from the listing.')
                ->visible(fn (LeaseApplication $record): bool => in_array($record->status, ['pending', 'under_review'], true))
                ->form([
                    Checkbox::make('notify_applicant')
                        ->label('Notify applicant to pay the booking fee')
                        ->helperText('Posts a message with the payment link so the applicant knows they have 24 hours to claim the spot.')
                        ->default(true),
                ])
                ->action(function (LeaseApplication $record, array $data): void {
                    try {
                        app(ApplicationService::class)->approve($record->id, auth()->id());
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()
                            ->title('Approval failed — application left unchanged')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }

                    if (! empty($data['notify_applicant'])) {
                        $payUrl = route('apply.status', $record->id);
                        app(ApplicationMessageService::class)->send(
                            $record->id,
                            auth()->id(),
                            'admin',
                            "Your lease application has been approved! You have 24 hours to pay the booking fee and claim your spot. Pay here: {$payUrl}",
                        );
                    }

                    Notification::make()
                        ->title('Application approved — applicant has 24 hours to pay the booking fee')
                        ->success()
                        ->send();
                    $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                }),

            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->icon(Heroicon::OutlinedXCircle)
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Reason for Rejection')
                        ->required()
                        ->maxLength(500)
                        ->helperText('This will be shown to the applicant.'),
                    Checkbox::make('notify_applicant')
                        ->label('Notify applicant of rejection')
                        ->helperText('Posts a message in Communications and emails the applicant.')
                        ->default(true),
                ])
                ->visible(fn (LeaseApplication $record): bool => $record->status === 'pending')
                ->action(function (LeaseApplication $record, array $data): void {
                    app(ApplicationService::class)->reject($record->id, auth()->id(), $data['rejection_reason']);
                    if (! empty($data['notify_applicant'])) {
                        app(ApplicationMessageService::class)->send(
                            $record->id,
                            auth()->id(),
                            'admin',
                            "Your lease application has been rejected. Reason: {$data['rejection_reason']}",
                        );
                    }
                    Notification::make()->title('Application rejected')->warning()->send();
                    $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }

    // ── Private HTML builders ─────────────────────────────────────────────────

    private function lessorSignaturePending(LeaseApplication $record): bool
    {
        if ($record->status !== 'approved') {
            return false;
        }

        $lease = $record->lease;
        if (! $lease || $lease->status !== 'pending_signatures') {
            return false;
        }

        $esigRequest = app(EsignatureService::class)->getRequestForLease($lease->id);
        if (! $esigRequest) {
            return false;
        }

        $signer = app(EsignatureService::class)->signerForUser($esigRequest->id, $lease->lessor_user_id);

        return $signer && $signer->status !== 'signed';
    }

    // ── Listing lookup (memoized — the sidebar resolves it up to 5× per render) ──

    private ?PropertyListing $resolvedListing = null;

    private bool $listingResolved = false;

    private function resolveListing(LeaseApplication $record): ?PropertyListing
    {
        if (! $this->listingResolved) {
            $this->resolvedListing = rescue(
                fn () => app(PropertyService::class)->findListing($record->listing_id),
                null,
            );
            $this->listingResolved = true;
        }

        return $this->resolvedListing;
    }

    private function listingUrl(LeaseApplication $record): string
    {
        $slug = $record->property_slug_snapshot
            ?? $this->resolveListing($record)?->property?->slug;

        return $slug ? route('property.show', $slug) : '#';
    }

    // ── Lease document actions (mounted from the lease-documents partial) ──────

    public function deleteLeaseDocumentAction(): Action
    {
        return Action::make('deleteLeaseDocument')
            ->requiresConfirmation()
            ->modalHeading('Remove document from lease?')
            ->modalDescription('The document will be detached from the lease and permanently deleted after 30 days. It can be restored until then from the deleted documents list.')
            ->modalSubmitActionLabel('Delete')
            ->color('danger')
            ->action(function (array $arguments): void {
                app(LeaseDocumentService::class)->remove($arguments['documentId'], auth()->id());
                Notification::make()->title('Document removed')->success()->send();
                $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $this->getRecord()]));
            });
    }

    public function restoreLeaseDocumentAction(): Action
    {
        return Action::make('restoreLeaseDocument')
            ->requiresConfirmation()
            ->modalHeading('Restore document?')
            ->modalDescription('The document will be re-attached to the lease.')
            ->modalSubmitActionLabel('Restore')
            ->color('success')
            ->action(function (array $arguments): void {
                app(LeaseDocumentService::class)->restore($arguments['documentId'], auth()->id());
                Notification::make()->title('Document restored')->success()->send();
                $this->redirect(LeaseApplicationResource::getUrl('view', ['record' => $this->getRecord()]));
            });
    }

    private function buildLeaseDocumentsHtml(LeaseApplication $record): HtmlString
    {
        $lease = $record->lease;
        if (! $lease) {
            return new HtmlString('<p style="color:#888;font-size:13px">No documents.</p>');
        }

        $documents = [];

        // E-signature template and signed copy
        $esigRequest = app(EsignatureService::class)->getRequestForLease($lease->id);
        $esigDocIds  = array_filter([
            'mla'            => $esigRequest?->template_document_id,
            'fully_executed' => $esigRequest?->signed_document_id,
        ]);

        if (! empty($esigDocIds)) {
            $esigDocs = Document::on('documents')
                ->whereIn('id', array_values($esigDocIds))
                ->get(['id', 'original_filename', 'size_bytes', 'created_at'])
                ->keyBy('id');

            foreach ($esigDocIds as $tagKey => $docId) {
                $doc = $esigDocs->get($docId);
                if (! $doc) {
                    continue;
                }

                $tag         = LeaseDocumentTag::from($tagKey);
                $documents[] = [
                    'label'       => $tag->label(),
                    'badge'       => strtoupper(str_replace('_', ' ', $tagKey)),
                    'badgeStyle'  => $tag->badgeStyle(),
                    'subtitle'    => $tagKey === 'mla'
                        ? 'Contract sent for e-signature'
                        : 'Fully executed — all parties have signed',
                    'filename'    => $doc->original_filename ?? 'document.pdf',
                    'size'        => $doc->size_bytes ? number_format($doc->size_bytes / 1024, 0) . ' KB' : '',
                    'date'        => $doc->created_at?->format('M j, Y') ?? '',
                    'downloadUrl' => route('admin.documents.download', $docId),
                    'deletableId' => null,
                ];
            }
        }

        // General lease_documents attachments
        foreach (app(LeaseDocumentService::class)->getForLease($lease->id) as $ld) {
            $documents[] = [
                'label'       => $ld->tag->label(),
                'badge'       => strtoupper(str_replace('_', ' ', $ld->tag->value)),
                'badgeStyle'  => $ld->tag->badgeStyle(),
                'subtitle'    => $ld->notes ?? '',
                'filename'    => $ld->original_filename ?? 'document.pdf',
                'size'        => $ld->size_bytes ? number_format($ld->size_bytes / 1024, 0) . ' KB' : '',
                'date'        => $ld->created_at?->format('M j, Y') ?? '',
                'downloadUrl' => route('admin.lease-documents.download', $ld->id),
                'deletableId' => $ld->id,
            ];
        }

        // Soft-deleted documents (recovery section)
        $deletedDocuments = app(LeaseDocumentService::class)->getDeletedForLease($lease->id)
            ->map(fn ($ld) => [
                'label'       => $ld->tag->label(),
                'badge'       => strtoupper(str_replace('_', ' ', $ld->tag->value)),
                'badgeStyle'  => $ld->tag->badgeStyle(),
                'filename'    => $ld->original_filename ?? 'document.pdf',
                'size'        => $ld->size_bytes ? number_format($ld->size_bytes / 1024, 0) . ' KB' : '',
                'deletedDate' => $ld->deleted_at?->format('M j, Y') ?? '',
                'prunedOn'    => $ld->deleted_at ? $ld->deleted_at->addDays(30)->format('M j, Y') : '',
                'restoreId'   => $ld->id,
            ])->all();

        return new HtmlString(view('filament.admin.applications.lease-documents', [
            'documents'        => $documents,
            'deletedDocuments' => $deletedDocuments,
        ])->render());
    }

    private function buildSigningStatusHtml(LeaseApplication $record): HtmlString
    {
        $lease = $record->lease;
        if (! $lease) {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">No lease record created yet.</p>');
        }

        $esigRequest = app(EsignatureService::class)->getRequestForLease($lease->id);

        // Deposit status (pay-then-sign): the hunter's signature is gated on a held
        // deposit, so surface it alongside the signers. null = no deposit configured.
        $depositService = app(SecurityDepositService::class);
        $dueCents = rescue(fn () => $depositService->amountDueCents($lease), 0) ?: 0;
        $existing = rescue(fn () => $depositService->forLease($lease->id), null);
        $deposit  = ($dueCents > 0 || $existing) ? [
            'status' => $existing?->status,
            'amount' => number_format(($existing ? (int) $existing->amount_cents : $dueCents) / 100, 2),
        ] : null;

        // Non-refundable booking deposit — also gates the hunter's signature.
        $bookingService = app(BookingDepositService::class);
        $bookingDue     = rescue(fn () => $bookingService->amountDueCents($lease), 0) ?: 0;
        $bookingRow     = rescue(fn () => $bookingService->forLease($lease->id), null);
        $bookingDeposit = ($bookingDue > 0 || $bookingRow) ? [
            'status' => $bookingRow?->status,
            'amount' => number_format(($bookingRow ? (int) $bookingRow->amount_cents : $bookingDue) / 100, 2),
        ] : null;

        return new HtmlString(view('filament.admin.applications.signing-status', [
            'lease'          => $lease,
            'signers'        => $esigRequest?->signers()->orderBy('order_num')->get(),
            'signingUrl'     => route('member.leases.sign', $lease->id),
            'deposit'        => $deposit,
            'bookingDeposit' => $bookingDeposit,
        ])->render());
    }

    private function buildPaymentStatusHtml(LeaseApplication $record): HtmlString
    {
        $lease = $record->lease;
        if (! $lease) {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">No lease record created yet.</p>');
        }

        $summary = rescue(
            fn () => app(LeaseFinanceSummaryService::class)->landownerSummary($lease),
            null,
        );

        if ($summary === null) {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">Payment summary unavailable.</p>');
        }

        return new HtmlString(view('filament.admin.applications.payment-status', [
            's' => $summary,
        ])->render());
    }

    private function buildReviewHistoryHtml(string $applicationId): HtmlString
    {
        $records = LeaseApplicationReviewHistory::where('application_id', $applicationId)
            ->orderBy('created_at')
            ->get();

        if ($records->isEmpty()) {
            return new HtmlString(
                '<p style="color:#888;font-style:italic;font-size:13px;padding:8px 0">No review decisions recorded yet.</p>'
            );
        }

        return new HtmlString(view('filament.admin.applications.review-history', [
            'records' => $records,
        ])->render());
    }

    private function buildMessagesHtml(string $applicationId): HtmlString
    {
        $messages = LeaseApplicationMessage::where('application_id', $applicationId)
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            return new HtmlString(
                '<p style="color:#888;font-style:italic;font-size:13px;padding:8px 0">No messages yet. Use "Send Message" to contact the applicant.</p>'
            );
        }

        $senderNames = UserProfile::on('identity')
            ->whereIn('user_id', $messages->pluck('sender_user_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (UserProfile $p) => [
                $p->user_id => trim("{$p->first_name} {$p->last_name}"),
            ])
            ->all();

        return new HtmlString(view('filament.admin.applications.communications', [
            'messages'    => $messages,
            'senderNames' => $senderNames,
        ])->render());
    }

    private function buildHunterRosterHtml(string $applicationId): HtmlString
    {
        $hunters = LeaseApplicationHunter::where('application_id', $applicationId)
            ->orderByRaw("hunter_type = 'primary' DESC")
            ->orderBy('created_at')
            ->get();

        if ($hunters->isEmpty()) {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">No hunter details captured (submitted before hunter roster was required).</p>');
        }

        return new HtmlString(view('filament.admin.applications.hunter-roster', [
            'hunters' => $hunters,
        ])->render());
    }
}
