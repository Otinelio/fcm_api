<?php

namespace App\Http\Middleware;

use App\Support\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CurrentActor::resolve($request)->isAdmin()) {
            return response()->json([
                'message' => 'Réservé à l\'administrateur.',
            ], 403);
        }

        return $next($request);
    }
}
