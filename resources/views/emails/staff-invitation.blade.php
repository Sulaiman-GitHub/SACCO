<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #0F172A; line-height: 1.5; font-size: 15px;">
    <p>Hello {{ $name }},</p>

    <p>You have been invited to join <strong>{{ $saccoName }}</strong> on SACCO Credit.</p>

    <p>Use the link below to choose your own password and activate your account:</p>

    <p><a href="{{ $url }}">{{ $url }}</a></p>

    <p>This link can be used once and expires in {{ $validHours }} hours. If it has expired, ask your administrator to send a new invitation.</p>

    <p>If you were not expecting this invitation, you can ignore this email and no account will be activated.</p>

    <p>{{ $appName }}</p>
</body>
</html>
