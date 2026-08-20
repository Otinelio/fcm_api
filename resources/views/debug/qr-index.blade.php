<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>QR codes marchands — debug</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0e0f12; color: #eee; padding: 32px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
        .card { background: #1a1b1f; border-radius: 12px; padding: 16px; text-align: center; }
        .card img { width: 100%; background: #fff; border-radius: 8px; }
        .card h3 { font-size: 14px; margin: 12px 0 4px; }
        .card code { font-size: 11px; color: #888; word-break: break-all; }
    </style>
</head>
<body>
    <h1>QR codes marchands (local uniquement)</h1>
    <p>Scanner avec l'app client (bouton scan) pour tester le flux "rejoindre".</p>
    <div class="grid">
        @foreach ($restaurants as $restaurant)
            <div class="card">
                <img src="{{ url('/debug/restaurants/' . $restaurant->id . '/qr') }}" alt="QR {{ $restaurant->name }}">
                <h3>{{ $restaurant->name }}</h3>
                <code>{{ $restaurant->qr_token }}</code>
            </div>
        @endforeach
    </div>
</body>
</html>
