<?php

namespace App\Services\Lease;

use App\Models\Documents\Document;
use App\Models\Documents\EsignatureRequest;
use App\Models\Lease\Lease;
use App\Models\Lease\SignatureEvent;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Services\Property\PropertyService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the fully-executed in-platform Hunting Lease Agreement to a PDF and
 * stores it as a contract document. Dropbox Sign leases get their executed PDF
 * from the provider; in-platform leases have no PDF until this service builds one.
 */
class LeaseAgreementPdfService extends BaseService
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * Build and store the executed-lease PDF for a completed in-platform request.
     * Sets signed_document_id on the request. Idempotent: returns the existing
     * document if one is already attached. Returns null if not eligible.
     */
    public function generateAndStore(EsignatureRequest $request): ?Document
    {
        if ($request->provider !== 'in_platform' || $request->status !== 'completed') {
            return null;
        }

        if ($request->signed_document_id !== null) {
            return Document::on('documents')->find($request->signed_document_id);
        }

        $lease = Lease::where('id', $request->lease_id)->first();
        if ($lease === null) {
            return null;
        }

        $pdfBytes = $this->render($lease, $request);

        $document = $this->documentService->storeRawBytes(
            bytes:        $pdfBytes,
            ownerUserId:  $request->requester_user_id,
            documentType: 'contract',
            filename:     'signed_lease_' . substr($lease->id, 0, 8) . '.pdf',
        );

        $request->signed_document_id = $document->id;
        $request->save();

        return $document;
    }

    private function render(Lease $lease, EsignatureRequest $request): string
    {
        $property = rescue(fn () => $this->propertyService->find($lease->property_id), null);

        $location = null;
        if ($property) {
            $location = trim(implode(', ', array_filter([
                $property->county ? "{$property->county} County" : null,
                $property->state_code,
            ])));
        }

        // Pull each signer's recorded IP from the permanent signature events (DB 3).
        $signedEvents = SignatureEvent::where('lease_id', $lease->id)
            ->where('event_type', 'signed')
            ->orderBy('occurred_at')
            ->get()
            ->keyBy('user_id');

        $signers = $request->signers()->orderBy('order_num')->get()->map(fn ($s) => [
            'name'      => $s->name,
            'role'      => $s->order_num === 1 ? 'Lessor' : 'Lessee',
            'signed_at' => $s->signed_at?->format('M j, Y g:i A T'),
            'ip'        => $signedEvents->get($s->user_id)?->ip_address,
            'user_id'   => $s->user_id,
        ])->all();

        $html = view('pdf.lease-agreement', [
            'leaseRef'    => strtoupper(substr($lease->id, 0, 8)),
            'documentRef' => strtoupper(substr($request->id, 0, 8)),
            'property'    => [
                'title'    => $property?->title ?? 'Hunting Property',
                'location' => $location ?: null,
                'acres'    => $property ? ($property->huntable_acres ?? $property->total_acres) : null,
            ],
            'startDate'   => $lease->start_date?->format('F j, Y') ?? '—',
            'endDate'     => $lease->end_date?->format('F j, Y') ?? '—',
            'totalPrice'  => number_format((float) $lease->total_price, 2),
            'signers'     => $signers,
        ])->render();

        return Pdf::loadHTML($html)->setPaper('letter')->output();
    }
}
