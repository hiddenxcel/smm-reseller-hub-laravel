<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Redirecting to payment…</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f6f7fb; color: #1c1f2b; }
        .box { text-align: center; padding: 24px; }
        button { margin-top: 16px; padding: 12px 22px; border: 0; border-radius: 10px; background: #1c1f2b; color: #fff; font-size: 16px; }
    </style>
</head>
<body>
    <div class="box">
        <p>Taking you to the secure payment page…</p>
        <form id="pay" method="post" action="{{ $action }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript><button type="submit">Continue to payment</button></noscript>
        </form>
    </div>
    <script>document.getElementById('pay').submit();</script>
</body>
</html>
