@component('mail::message')
# Proof of Ownership — {{ $statusLabel }}

Hi {{ $recipientName }},

Here's an update on the proof of ownership you submitted for **{{ $propertyTitle }}**.

{{ $statusMessage }}

@component('mail::button', ['url' => $propertyUrl, 'color' => 'primary'])
View Property Status
@endcomponent

Thanks,
**American Headhunter Team**
@endcomponent
