@component('mail::message')
# Your promotion ends {{ $whenLabel }}

Hi {{ $recipientName }},

{{ $statusMessage }}

@component('mail::button', ['url' => $manageUrl, 'color' => 'primary'])
Manage Your Membership
@endcomponent

Thanks,
**American Headhunter Team**
@endcomponent
