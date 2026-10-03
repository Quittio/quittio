<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quittio preview</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 48rem; padding: 0 1rem; color: #111827; }
        h1 { font-size: 1.5rem; }
        table { border-collapse: collapse; width: 100%; }
        td { padding: .5rem; border-bottom: 1px solid #e5e7eb; }
        a { color: #1e40af; margin-right: 1rem; }
    </style>
</head>
<body>
    <h1>Quittio preview</h1>
    <p>Sample invoices rendered with the configured template. Add <code>?template=</code> or <code>?locale=</code> to the links.</p>
    <table>
        @foreach ($scenarios as $scenario)
            <tr>
                <td>{{ $scenario }}</td>
                <td>
                    <a href="{{ route('quittio.preview.show', ['scenario' => $scenario]) }}">HTML</a>
                    <a href="{{ route('quittio.preview.show', ['scenario' => $scenario, 'format' => 'pdf']) }}">PDF</a>
                </td>
            </tr>
        @endforeach
    </table>
</body>
</html>
