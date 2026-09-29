<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof Client) {
            abort(403, 'Accès réservé aux clients.');
        }

        return $next($request);
    }
}
