<?php

namespace App\Http\Controllers\Member;

use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Lease\Lease;
use App\Services\Lease\LeaseDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaseDocumentController extends Controller
{
    public function __construct(
        private readonly LeaseDocumentService $leaseDocumentService,
    ) {}

    /**
     * Upload a document to a lease (landowner/lessor only).
     */
    public function upload(Request $request, string $lease): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessor_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'document'  => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'tag'       => ['required', 'string', 'in:' . implode(',', array_column(LeaseDocumentTag::cases(), 'value'))],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $this->leaseDocumentService->upload(
            $leaseRecord->id,
            $validated['document'],
            $validated['tag'],
            $userId,
            $validated['notes'] ?? null,
        );

        return redirect()->route('member.leases.show', $lease)
            ->with('flash', 'Document uploaded successfully.');
    }

    /**
     * Download a lease document (any party to the lease: lessor or lessee).
     */
    public function download(Request $request, string $lease, string $leaseDocument): StreamedResponse
    {
        $userId = session('auth.user_id');

        return $this->leaseDocumentService->authorizedDownload(
            $leaseDocumentId: $leaseDocument,
            leaseId:          $lease,
            requestingUserId: $userId,
        );
    }
}
