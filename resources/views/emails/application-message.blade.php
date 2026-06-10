@component('mail::message')
# New Message — American Headhunter

Hi {{ $recipientName }},

**{{ $senderRoleLabel }}** has sent you a message regarding your lease application.

---

{{ $messageBody }}

---

**You cannot reply directly to this email.** To reply, log in to your account and view your application.

@component('mail::button', ['url' => $loginUrl, 'color' => 'primary'])
Log In & Reply
@endcomponent

Thanks,
**American Headhunter Team**

*This message was sent because you have an active application on American Headhunter. Application ID: {{ strtoupper(substr($applicationId, 0, 8)) }}*
@endcomponent
