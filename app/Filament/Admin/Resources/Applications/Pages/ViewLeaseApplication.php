<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Enums\LeaseDocumentTag;
use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Applications\LeaseApplicationResource;
use App\Models\Documents\Document;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Models\Lease\LeaseApplicationMessage;
use App\Models\Lease\LeaseApplicationReviewHistory;
use App\Models\Lease\LeaseHunter;
use App\Models\Property\Property;
use App\Services\Documents\DocumentService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Lease\LeaseService;
use App\Services\Platform\EntitlementService;
use App\Services\Property\PropertyService;
use App\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                            ->url(fn (LeaseApplication $record): string => rescue(function () use ($record) {
                                $slug = $record->property_slug_snapshot
                                    ?? app(PropertyService::class)->findListing($record->listing_id)?->property?->slug;
                                return $slug ? route('property.show', $slug) : '#';
                            }, '#'))
                            ->openUrlInNewTab()
                            ->copyable(),

                        TextEntry::make('property_title')
                            ->label('Property')
                            ->state(fn (LeaseApplication $record): string => rescue(function () use ($record) {
                                $title = $record->property_title_snapshot
                                    ?? app(PropertyService::class)->findListing($record->listing_id)?->property?->title;
                                return $title ? $title . ' ↗' : '—';
                            }, '—'))
                            ->url(fn (LeaseApplication $record): string => rescue(function () use ($record) {
                                $slug = $record->property_slug_snapshot
                                    ?? app(PropertyService::class)->findListing($record->listing_id)?->property?->slug;
                                return $slug ? route('property.show', $slug) : '#';
                            }, '#'))
                            ->openUrlInNewTab(),

                        TextEntry::make('property_location')
                            ->label('Location')
                            ->state(fn (LeaseApplication $record): string => rescue(function () use ($record) {
                                if ($record->property_location_snapshot) {
                                    return $record->property_location_snapshot;
                                }
                                $prop = app(PropertyService::class)->findListing($record->listing_id)?->property;
                                return $prop ? "{$prop->county} County, {$prop->state_code}" : '—';
                            }, '—')),

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
                                url("/admin/admin-users/{$record->applicant_user_id}/edit")
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
                    app(ApplicationService::class)->override(
                        $record->id,
                        auth()->id(),
                        $data['new_status'],
                        $data['reason'],
                    );
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
                ->modalHeading('Approve Application & Create Lease')
                ->visible(fn (LeaseApplication $record): bool => $record->status === 'pending')
                ->fillForm(fn (LeaseApplication $record): array => [
                    'start_date'      => $record->proposed_start ?? $record->listing_season_start_snap,
                    'end_date'        => $record->proposed_end ?? $record->listing_season_end_snap,
                    'total_price'     => rescue(fn () => app(PropertyService::class)->findListing($record->listing_id)?->price_total, null),
                    'sign_as_lessor'  => true,
                    'notify_applicant' => true,
                ])
                ->form([
                    DatePicker::make('start_date')
                        ->label('Lease Start Date')
                        ->required()
                        ->native(false),
                    DatePicker::make('end_date')
                        ->label('Lease End Date')
                        ->required()
                        ->native(false),
                    TextInput::make('total_price')
                        ->label('Total Lease Price')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0),
                    Checkbox::make('sign_as_lessor')
                        ->label('Sign immediately as lessor (landowner)')
                        ->helperText('Records the landowner\'s in-platform signature now. Lessee will be notified to sign next.')
                        ->default(true),
                    Checkbox::make('notify_applicant')
                        ->label('Send signing link to applicant')
                        ->helperText('Posts a message with the signing URL so the lessee knows to review and sign.')
                        ->default(true),
                    FileUpload::make('custom_contract_pdf')
                        ->label('Custom Contract PDF (Ranch+ / Estate only)')
                        ->disk('local')
                        ->directory('pending-contracts')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->nullable()
                        ->helperText('Override the listing\'s MLA. If the listing already has an MLA attached, it will be used automatically — only upload here to override it. Requires Ranch+/Estate entitlement to trigger Dropbox Sign; otherwise falls back to in-platform signing.'),
                ])
                ->action(function (LeaseApplication $record, array $data): void {
                    // 1. Approve the application
                    app(ApplicationService::class)->approve($record->id, auth()->id());

                    // 2. Resolve property and owner (lessor)
                    // property_id_snapshot may be null for older applications — fall back via listing
                    $propertyId = $record->property_id_snapshot
                        ?? DB::connection('property')
                            ->table('property_listings')
                            ->where('id', $record->listing_id)
                            ->value('property_id');

                    $property = $propertyId ? Property::on('property')->find($propertyId) : null;

                    if (! $property) {
                        Notification::make()
                            ->title('Property record not found — lease not created')
                            ->body('Could not resolve a property for this application. Check the listing exists and has a valid property in the property database.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // 3. Create the lease record
                    $lease = app(LeaseService::class)->createFromApplication($record->id, [
                        'property_id'    => $property->id,
                        'listing_id'     => $record->listing_id,
                        'lessee_user_id' => $record->applicant_user_id,
                        'lessor_user_id' => $property->owner_user_id,
                        'start_date'     => $data['start_date'],
                        'end_date'       => $data['end_date'],
                        'total_price'    => $data['total_price'],
                        'deposit_paid'   => 0.00,
                    ]);

                    // 4. Create the primary lessee's LeaseHunter record
                    LeaseHunter::create([
                        'lease_id'    => $lease->id,
                        'user_id'     => $record->applicant_user_id,
                        'role'        => 'primary',
                        'is_approved' => false,
                    ]);

                    // 5. Resolve names and emails for both signers (cross-DB)
                    $lessorUser    = User::on('identity')->find($property->owner_user_id);
                    $lesseeUser    = User::on('identity')->find($record->applicant_user_id);
                    $lessorProfile = UserProfile::on('identity')->where('user_id', $property->owner_user_id)->first();
                    $lesseeProfile = UserProfile::on('identity')->where('user_id', $record->applicant_user_id)->first();

                    $lessorName = $lessorProfile
                        ? trim("{$lessorProfile->first_name} {$lessorProfile->last_name}") ?: 'Landowner'
                        : 'Landowner';
                    $lesseeName = $lesseeProfile
                        ? trim("{$lesseeProfile->first_name} {$lesseeProfile->last_name}") ?: 'Hunter'
                        : 'Hunter';

                    // 6. Resolve the custom contract PDF for signing.
                    // Priority order: (a) admin override upload, (b) MLA already attached to the listing.
                    $customPdf = null;

                    // (a) Admin override: file uploaded in this action's form
                    $pdfStoredName = $data['custom_contract_pdf'] ?? null;
                    if (! empty($pdfStoredName)) {
                        try {
                            $storedPath = \Illuminate\Support\Facades\Storage::disk('local')
                                ->path($pdfStoredName);
                            $uploadedFile = new \Illuminate\Http\UploadedFile(
                                $storedPath,
                                basename($pdfStoredName),
                                'application/pdf',
                                null,
                                true,
                            );
                            $customPdf = app(DocumentService::class)->storeUploadedFile(
                                $uploadedFile,
                                $property->owner_user_id,
                                'contract',
                            );
                            \Illuminate\Support\Facades\Storage::disk('local')
                                ->delete($pdfStoredName);
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('ViewLeaseApplication: custom PDF upload failed', [
                                'application_id' => $record->id,
                                'stored_name'    => $pdfStoredName,
                                'error'          => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Could not store uploaded PDF — using in-platform signing')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        }
                    }

                    // (b) Fall back to MLA already set on the listing by the landowner
                    if ($customPdf === null) {
                        $listingContractDocId = DB::connection('property')
                            ->table('property_listings')
                            ->where('id', $record->listing_id)
                            ->value('custom_contract_document_id');

                        if ($listingContractDocId) {
                            $customPdf = Document::on('documents')->find($listingContractDocId);
                        }
                    }

                    // 7. Create the signing request (routes to Dropbox Sign if customPdf + entitlement present)
                    $esigRequest = app(EsignatureService::class)->createRequest(
                        $lease,
                        auth()->id(),
                        ['user_id' => $property->owner_user_id, 'name' => $lessorName, 'email' => $lessorUser?->email ?? ''],
                        ['user_id' => $record->applicant_user_id, 'name' => $lesseeName, 'email' => $lesseeUser?->email ?? ''],
                        $customPdf,
                    );

                    // 8. Optionally sign as lessor immediately (admin acting on landowner's behalf)
                    $activated = false;
                    if (! empty($data['sign_as_lessor'])) {
                        $activated = app(EsignatureService::class)->recordSignature(
                            $esigRequest->id,
                            $property->owner_user_id,
                            request()->ip(),
                            request()->userAgent(),
                        );
                    }

                    // 9. Notify the applicant with their signing link
                    if (! empty($data['notify_applicant']) && ! $activated) {
                        $signingUrl = route('member.leases.sign', $lease->id);
                        app(ApplicationMessageService::class)->send(
                            $record->id,
                            auth()->id(),
                            'admin',
                            "Your lease application has been approved! Please review and sign your lease agreement here: {$signingUrl}",
                        );
                    }

                    $title = $activated
                        ? 'Application approved — lease is ACTIVE (both parties signed)'
                        : 'Application approved — lease created, awaiting lessee signature';

                    Notification::make()->title($title)->success()->send();
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

    private function hasLeaseDocuments(LeaseApplication $record): bool
    {
        $lease = $record->lease;
        if (! $lease) {
            return false;
        }

        $hasEsigDocs = DB::connection('documents')
            ->table('esignature_requests')
            ->where('lease_id', $lease->id)
            ->where(function ($q) {
                $q->whereNotNull('template_document_id')
                  ->orWhereNotNull('signed_document_id');
            })
            ->exists();

        if ($hasEsigDocs) {
            return true;
        }

        return DB::connection('lease')
            ->table('lease_documents')
            ->where('lease_id', $lease->id)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function buildLeaseDocumentsHtml(LeaseApplication $record): HtmlString
    {
        $lease = $record->lease;
        if (! $lease) {
            return new HtmlString('<p style="color:#888;font-size:13px">No documents.</p>');
        }

        $rows = '';

        // ── Part 1: e-signature template and signed copy ──────────────────────

        $esigRequest = DB::connection('documents')
            ->table('esignature_requests')
            ->where('lease_id', $lease->id)
            ->first(['template_document_id', 'signed_document_id']);

        $esigDocIds = array_filter([
            'mla'          => $esigRequest?->template_document_id,
            'fully_executed' => $esigRequest?->signed_document_id,
        ]);

        if (! empty($esigDocIds)) {
            $esigDocs = DB::connection('documents')
                ->table('documents')
                ->whereIn('id', array_values($esigDocIds))
                ->get(['id', 'original_filename', 'size_bytes', 'created_at'])
                ->keyBy('id');

            foreach ($esigDocIds as $tagKey => $docId) {
                $doc = $esigDocs->get($docId);
                if (! $doc) {
                    continue;
                }

                $tag      = LeaseDocumentTag::from($tagKey);
                $rows    .= $this->documentRow(
                    label:      $tag->label(),
                    badge:      strtoupper(str_replace('_', ' ', $tagKey)),
                    badgeStyle: $tag->badgeStyle(),
                    subtitle:   $tagKey === 'mla'
                        ? 'Contract sent for e-signature'
                        : 'Fully executed — all parties have signed',
                    filename:   $doc->original_filename ?? 'document.pdf',
                    sizeBytes:  $doc->size_bytes,
                    date:       $doc->created_at ? \Carbon\Carbon::parse($doc->created_at)->format('M j, Y') : '',
                    downloadUrl: route('admin.documents.download', $docId),
                );
            }
        }

        // ── Part 2: general lease_documents attachments ───────────────────────

        $leaseDocs = app(LeaseDocumentService::class)->getForLease($lease->id);

        foreach ($leaseDocs as $ld) {
            $tag   = $ld->tag;
            $url   = route('admin.lease-documents.download', $ld->id);
            $rows .= $this->documentRow(
                label:      $tag->label(),
                badge:      strtoupper(str_replace('_', ' ', $tag->value)),
                badgeStyle: $tag->badgeStyle(),
                subtitle:   $ld->notes ?? '',
                filename:   $ld->original_filename ?? 'document.pdf',
                sizeBytes:  $ld->size_bytes,
                date:       $ld->created_at?->format('M j, Y') ?? '',
                downloadUrl: $url,
            );
        }

        if ($rows === '') {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">No documents attached yet.</p>');
        }

        return new HtmlString("<div style=\"display:flex;flex-direction:column;gap:8px;\">{$rows}</div>");
    }

    private function documentRow(
        string $label,
        string $badge,
        string $badgeStyle,
        string $subtitle,
        string $filename,
        ?int   $sizeBytes,
        string $date,
        string $downloadUrl,
    ): string {
        $filename    = e($filename);
        $subtitle    = e($subtitle);
        $size        = $sizeBytes ? number_format($sizeBytes / 1024, 0) . ' KB' : '';
        $meta        = implode(' · ', array_filter([$filename, $size, $date]));
        $subtitleRow = $subtitle !== ''
            ? "<p style=\"font-size:12px;color:#6b7280;margin:0;\">{$subtitle}</p>"
            : '';

        return <<<HTML
            <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;background:#fff;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <svg style="width:28px;height:28px;flex-shrink:0;color:#C84C21;" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/>
                    </svg>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:14px;font-weight:600;color:#111827;">{$label}</span>
                            <span style="font-size:11px;font-weight:700;padding:1px 6px;border-radius:4px;{$badgeStyle}">{$badge}</span>
                        </div>
                        {$subtitleRow}
                        <p style="font-size:11px;color:#9ca3af;margin:0;">{$meta}</p>
                    </div>
                </div>
                <a href="{$downloadUrl}" target="_blank"
                   style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;background:#f3f4f6;font-size:13px;font-weight:500;color:#374151;text-decoration:none;border:1px solid #e5e7eb;white-space:nowrap;flex-shrink:0;">
                    <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Download
                </a>
            </div>
        HTML;
    }

    private function buildSigningStatusHtml(LeaseApplication $record): HtmlString
    {
        $lease = $record->lease;
        if (! $lease) {
            return new HtmlString('<p style="color:#888;font-style:italic;font-size:13px">No lease record created yet.</p>');
        }

        $leaseStatusColor = match ($lease->status) {
            'active'             => '#15803d',
            'pending_signatures' => '#b05a00',
            'expired'            => '#888',
            'terminated'         => '#b91c1c',
            default              => '#555',
        };
        $leaseStatusBg = match ($lease->status) {
            'active'             => '#f0fdf4',
            'pending_signatures' => '#fff7ed',
            'expired'            => '#f5f5f5',
            'terminated'         => '#fef2f2',
            default              => '#f5f5f5',
        };
        $leaseStatusLabel = match ($lease->status) {
            'active'             => 'ACTIVE',
            'pending_signatures' => 'PENDING SIGNATURES',
            'expired'            => 'EXPIRED',
            'terminated'         => 'TERMINATED',
            'cancelled'          => 'CANCELLED',
            default              => strtoupper($lease->status),
        };

        $leaseId     = strtoupper(substr($lease->id, 0, 8));
        $startDate   = $lease->start_date?->format('M j, Y') ?? '—';
        $endDate     = $lease->end_date?->format('M j, Y') ?? '—';
        $totalPrice  = '$' . number_format((float)$lease->total_price, 2);

        $esigRequest = app(EsignatureService::class)->getRequestForLease($lease->id);

        if (! $esigRequest) {
            $signingHtml = '<p style="color:#888;font-style:italic;font-size:13px">No signing request found.</p>';
        } else {
            $signers = $esigRequest->signers()->orderBy('order_num')->get();
            $signerRows = $signers->map(function ($signer) use ($lease): string {
                $isSigned   = $signer->status === 'signed';
                $role       = $signer->order_num === 1 ? 'Lessor (Landowner)' : 'Lessee (Hunter)';
                $statusIcon = $isSigned ? '✓' : '○';
                $statusClr  = $isSigned ? '#15803d' : '#b05a00';
                $statusBg   = $isSigned ? '#f0fdf4' : '#fff7ed';
                $signedAt   = $isSigned && $signer->signed_at
                    ? '<span style="font-size:11px;color:#888;margin-left:8px">Signed ' . $signer->signed_at->format('M j, Y g:i A') . '</span>'
                    : '';

                return <<<HTML
                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:{$statusBg};border-radius:4px;margin-bottom:6px">
                    <span style="font-size:16px;font-weight:700;color:{$statusClr}">{$statusIcon}</span>
                    <div style="flex:1">
                        <div style="font-size:13px;font-weight:600;color:#1a1a1a">{$signer->name}</div>
                        <div style="font-size:11px;color:#888;font-family:monospace">{$role} &nbsp;·&nbsp; {$signer->email}</div>
                    </div>
                    <div style="text-align:right">
                        <span style="font-family:monospace;font-size:10px;font-weight:700;color:{$statusClr};text-transform:uppercase;letter-spacing:.08em">{$signer->status}</span>
                        {$signedAt}
                    </div>
                </div>
                HTML;
            })->join('');

            $signingHtml = "<div style='margin-top:8px'>{$signerRows}</div>";
        }

        $signingUrl = route('member.leases.sign', $lease->id);

        return new HtmlString(<<<HTML
        <div style="font-family:system-ui,sans-serif">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;padding:16px;background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px;margin-bottom:16px">
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Lease ID</div>
                    <div style="font-family:monospace;font-size:13px;color:#1a1a1a">AH-{$leaseId}</div>
                </div>
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Status</div>
                    <div style="display:inline-block;background:{$leaseStatusBg};color:{$leaseStatusColor};font-family:monospace;font-size:10px;font-weight:700;padding:3px 10px;border-radius:2px;text-transform:uppercase;letter-spacing:.08em">{$leaseStatusLabel}</div>
                </div>
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Lease Term</div>
                    <div style="font-size:13px;color:#1a1a1a">{$startDate} – {$endDate}</div>
                </div>
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Total Price</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a1a">{$totalPrice}</div>
                </div>
            </div>
            <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:8px">Signature Status</div>
            {$signingHtml}
            <div style="margin-top:12px;font-size:12px;color:#888">
                Lessee signing URL: <span style="font-family:monospace;font-size:11px;color:#555">{$signingUrl}</span>
            </div>
        </div>
        HTML);
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

        $rows = $records->map(function (LeaseApplicationReviewHistory $h, int $i) use ($records): string {
            $isOverride = $h->isOverride();
            $label      = $h->label();
            $date       = $h->created_at?->format('F j, Y \a\t g:i A') ?? '—';
            $deciderId  = strtoupper(substr($h->decided_by_user_id, 0, 8));

            $badgeColor = match ($h->to_status) {
                'approved' => '#15803d',
                'rejected' => '#b91c1c',
                default    => '#555',
            };
            $badgeBg = match ($h->to_status) {
                'approved' => '#f0fdf4',
                'rejected' => '#fef2f2',
                default    => '#f5f5f5',
            };

            $overridePill = $isOverride
                ? '<span style="background:#fef9c3;color:#854d0e;font-family:monospace;font-size:9px;padding:2px 7px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em;margin-left:8px">Override</span>'
                : '';

            $fromTo = $isOverride
                ? '<span style="font-family:monospace;font-size:11px;color:#888">' . strtoupper($h->from_status) . ' → ' . strtoupper($h->to_status) . '</span>'
                : '';

            $reasonRow = $h->reason
                ? '<div style="margin-top:8px;padding:8px 12px;background:#fafaf9;border-left:2px solid #d1d5db;font-size:13px;color:#444;font-style:italic">"' . htmlspecialchars($h->reason) . '"</div>'
                : '';

            $connector = $i < $records->count() - 1
                ? '<div style="width:2px;height:16px;background:#e5e7eb;margin:4px 0 4px 12px"></div>'
                : '';

            return <<<HTML
            <div>
                <div style="display:flex;align-items:flex-start;gap:12px">
                    <div style="flex-shrink:0;margin-top:3px">
                        <div style="width:26px;height:26px;border-radius:50%;background:{$badgeBg};border:2px solid {$badgeColor};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:{$badgeColor}">
                            {$i}
                        </div>
                    </div>
                    <div style="flex:1;padding-bottom:4px">
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:4px">
                            <span style="font-size:13px;font-weight:600;color:#1a1a1a">{$label}</span>
                            {$overridePill}
                            {$fromTo}
                        </div>
                        <div style="font-family:monospace;font-size:10px;color:#888;margin-bottom:4px">
                            {$date} &nbsp;·&nbsp; User {$deciderId}
                        </div>
                        {$reasonRow}
                    </div>
                </div>
                {$connector}
            </div>
            HTML;
        })->join('');

        return new HtmlString("<div style='font-family:system-ui,sans-serif;padding:4px 0'>{$rows}</div>");
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

        $cards = $messages->map(function (LeaseApplicationMessage $m): string {
            $roleLabel = match ($m->sender_role) {
                'admin'     => 'Admin',
                'landowner' => 'Landowner',
                'applicant' => 'Applicant',
                default     => 'Unknown',
            };

            $roleColor = match ($m->sender_role) {
                'admin'     => '#1d4ed8',
                'landowner' => '#15803d',
                'applicant' => '#b05a00',
                default     => '#888',
            };

            $isApplicant   = $m->sender_role === 'applicant';
            $align         = $isApplicant ? 'flex-start' : 'flex-end';
            $headerJustify = $isApplicant ? 'flex-start' : 'flex-end';
            $bubbleBg      = $isApplicant ? '#f5f1eb' : '#eef2ff';
            $border        = $isApplicant ? '#e5e0d8' : '#c7d2fe';

            $date     = $m->created_at?->format('M j, Y g:i A') ?? '';
            $body     = nl2br(htmlspecialchars($m->message));
            $readMark = (! $isApplicant && $m->is_read)
                ? '<span style="color:#888;font-size:10px;margin-top:4px;display:block">Read ' . ($m->read_at?->format('M j g:i A') ?? '') . '</span>'
                : '';

            return <<<HTML
            <div style="display:flex;flex-direction:column;align-items:{$align};margin-bottom:16px">
                <div style="max-width:70%">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;justify-content:{$headerJustify}">
                        <span style="font-family:monospace;font-size:10px;font-weight:700;color:{$roleColor};text-transform:uppercase;letter-spacing:.1em">{$roleLabel}</span>
                        <span style="font-family:monospace;font-size:10px;color:#aaa">{$date}</span>
                    </div>
                    <div style="background:{$bubbleBg};border:1px solid {$border};border-radius:4px;padding:12px 16px;font-size:14px;line-height:1.6;color:#1a1a1a">
                        {$body}
                    </div>
                    {$readMark}
                </div>
            </div>
            HTML;
        })->join('');

        return new HtmlString("<div style='font-family:system-ui,sans-serif;padding:4px 0'>{$cards}</div>");
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

        $cards = $hunters->map(function (LeaseApplicationHunter $h, int $i): string {
            $name  = htmlspecialchars("{$h->first_name} {$h->last_name}");
            $role  = $h->hunter_type === 'primary' ? 'Primary Hunter' : 'Guest Hunter';
            $minor = $h->is_minor
                ? ' <span style="background:#fff0d6;color:#b05a00;font-size:10px;padding:2px 8px;font-family:monospace;text-transform:uppercase;letter-spacing:.08em;border-radius:2px">Minor</span>'
                : '';
            $num   = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);

            $dob      = $h->date_of_birth ? $h->date_of_birth->format('M j, Y') : '—';
            $email    = $h->email ? htmlspecialchars($h->email) : '—';
            $cellHome = collect([$h->cell_phone, $h->home_phone])->filter()->implode(' / ') ?: '—';
            $address  = collect([$h->address_line1, $h->address_line2, $h->city, $h->state_code, $h->zip_code])->filter()->implode(', ') ?: '—';
            $emergency = $h->emergency_contact_name
                ? htmlspecialchars($h->emergency_contact_name) . ($h->emergency_contact_relationship ? ' (' . htmlspecialchars($h->emergency_contact_relationship) . ')' : '') . ' — ' . htmlspecialchars($h->emergency_contact_phone ?? '')
                : '—';
            $medical  = $h->medical_conditions ? '<span style="color:#b05a00">' . htmlspecialchars($h->medical_conditions) . '</span>' : '<span style="color:#aaa">None reported</span>';

            if ($h->dl_number) {
                $dlText  = htmlspecialchars($h->dl_number) . ' · ' . ($h->dl_state ?? '') . ($h->dl_expiry ? ' · Exp ' . $h->dl_expiry->format('m/Y') : '');
                $dlBadge = $h->dl_confirmed_current
                    ? '<span style="color:#2d7a3a;font-weight:600">✓ Confirmed current</span>'
                    : '<span style="color:#b05a00;font-weight:600">⚠ Not confirmed</span>';
                $dlRow   = "{$dlText} &nbsp; {$dlBadge}";
            } else {
                $dlRow = '<span style="color:#aaa">—</span>';
            }

            if ($h->hunting_license_number) {
                $licText  = htmlspecialchars($h->hunting_license_number) . ' · ' . ($h->hunting_license_state ?? '') . ($h->hunting_license_expiry ? ' · Exp ' . $h->hunting_license_expiry->format('m/Y') : '');
                $licBadge = $h->hunting_license_confirmed_current
                    ? '<span style="color:#2d7a3a;font-weight:600">✓ Confirmed current</span>'
                    : '<span style="color:#b05a00;font-weight:600">⚠ Not confirmed</span>';
                $licRow   = "{$licText} &nbsp; {$licBadge}";
            } else {
                $licRow = '<span style="color:#aaa">—</span>';
            }

            return <<<HTML
            <div style="border:1px solid #e5e0d8;border-radius:4px;overflow:hidden;margin-bottom:16px">
                <div style="background:#f5f1eb;padding:12px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e5e0d8">
                    <span style="font-family:monospace;font-size:11px;color:#888;letter-spacing:.1em">{$num}</span>
                    <span style="font-size:16px;font-weight:600;color:#1a1a1a">{$name}</span>
                    {$minor}
                    <span style="font-family:monospace;font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.1em;margin-left:auto">{$role}</span>
                </div>
                <div style="padding:16px 20px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px 32px;font-size:13px">
                    <div>
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Date of Birth</div>
                        <div style="color:#1a1a1a">{$dob}</div>
                    </div>
                    <div>
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Email</div>
                        <div style="color:#1a1a1a">{$email}</div>
                    </div>
                    <div>
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Phone</div>
                        <div style="color:#1a1a1a">{$cellHome}</div>
                    </div>
                    <div style="grid-column:1/-1">
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Home Address</div>
                        <div style="color:#1a1a1a">{$address}</div>
                    </div>
                    <div style="grid-column:1/-1">
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Emergency Contact</div>
                        <div style="color:#1a1a1a">{$emergency}</div>
                    </div>
                    <div style="grid-column:1/-1">
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Medical Conditions</div>
                        <div>{$medical}</div>
                    </div>
                    <div style="grid-column:1/-1;padding-top:8px;border-top:1px solid #f0ece6">
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Driver's License</div>
                        <div>{$dlRow}</div>
                    </div>
                    <div style="grid-column:1/-1">
                        <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Hunting License</div>
                        <div>{$licRow}</div>
                    </div>
                </div>
            </div>
            HTML;
        })->join('');

        return new HtmlString("<div style='font-family:system-ui,sans-serif'>{$cards}</div>");
    }
}
