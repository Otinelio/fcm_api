<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Génère les QR codes marchands à la volée pour test manuel (scan avec
 * l'app client). N'existe qu'en environnement local — voir routes/web.php.
 */
class DebugQrController extends Controller
{
    public function index()
    {
        $restaurants = Restaurant::query()
            ->whereNotNull('qr_token')
            ->orderBy('name')
            ->get(['id', 'name', 'qr_token']);

        return view('debug.qr-index', compact('restaurants'));
    }

    public function qr(Restaurant $restaurant): Response
    {
        abort_if(!$restaurant->qr_token, 404, 'Ce restaurant n\'a pas encore de qr_token.');

        $renderer = new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd(),
        );
        $svg = (new Writer($renderer))->writeString($restaurant->qr_token);

        // Copie locale pour inspection manuelle — jamais versionnée (voir .gitignore).
        Storage::disk('local')->put("qr-debug/{$restaurant->id}.svg", $svg);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }
}
