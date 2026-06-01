<x-mail::message>
# Your login code

Use this 6-digit code to sign in to Stocks:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

This code expires in {{ $expiresInMinutes }} minutes. If you did not request it, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
