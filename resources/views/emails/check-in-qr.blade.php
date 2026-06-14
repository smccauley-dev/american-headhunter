@component('mail::message')
# Your Check-In QR Code

Hi {{ $recipientName }},

Here is the check-in QR code for **{{ $propertyTitle }}**. Scan it at the gate when you arrive to log your entry — it's attached to this email as **check-in-qr.png**, and you can also tap the button below.

@component('mail::button', ['url' => $scanUrl, 'color' => 'primary'])
Check In
@endcomponent

Save the attached image to your phone so you have it even without signal at the property.

Thanks,
**American Headhunter Team**

*Logging your check-in helps us keep everyone on the property safe.*
@endcomponent
