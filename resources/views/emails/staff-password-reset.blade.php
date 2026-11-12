<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #0F172A; line-height: 1.5; font-size: 15px;">
    <p>Hello {{ $name }},</p>

    <p>We received a request to reset your {{ $appName }} password. Use the link below to choose a new one:</p>

    <p><a href="{{ $url }}">{{ $url }}</a></p>

    <p>This link expires in {{ $validMinutes }} minutes and can be used once.</p>

    <p>If you did not ask for this, you can ignore this email and your password will stay the same.</p>

    <p>{{ $appName }}</p>
</body>
</html>
