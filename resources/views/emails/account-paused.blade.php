@component('mail::message')
# Your account is paused

Hi {{ $recipientName }},

Your promotional period has ended, so your American Headhunter membership is paused
for now. Your account and data are safe — start a paid plan to pick up right where
you left off.

@component('mail::button', ['url' => $reactivateUrl, 'color' => 'primary'])
Reactivate Your Membership
@endcomponent

Thanks,
**American Headhunter Team**
@endcomponent
