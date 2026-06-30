@component('mail::message')
# Early Termination — {{ $statusLabel }}

Hi {{ $recipientName }},

Here's the landowner's decision on your request to end your lease for **{{ $propertyTitle }}** early.

{{ $statusMessage }}

@if($refundSummary)
**Refund summary:** {{ $refundSummary }}
@endif

@component('mail::button', ['url' => $leaseUrl, 'color' => 'primary'])
View Lease
@endcomponent

Thanks,
**American Headhunter Team**
@endcomponent
