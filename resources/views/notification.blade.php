<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; max-width: 600px; }
        h2 { margin-top: 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
        td:first-child { font-weight: 600; width: 40%; color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        <h2>{{ $text }}</h2>
        @if (! empty($fields))
            <table>
                @foreach ($fields as $name => $value)
                    <tr><td>{{ $name }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif
        <p style="color:#9ca3af;font-size:12px;margin-top:16px;">Sent by database-backup-sync</p>
    </div>
</body>
</html>
