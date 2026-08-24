<?php

namespace App\Http\Middleware;

use App\Support\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Circuit-breaker universel pour le groupe marchand : rejette une requête
 * dès qu'un opérateur désactivé se présente avec un token Sanctum encore
 * valide. CurrentActor::resolve() lève déjà StaffUserInactiveException dans
 * ce cas (rendue en 401 via sa propre méthode render()) — ce middleware se
 * contente de forcer cette résolution sur toutes les routes du groupe, pas
 * seulement celles qui l'invoquaient déjà pour d'autres besoins (admin.only,
 * /me, attribution...).
 */
class EnsureStaffActive
{
    public function handle(Request $request, Closure $next): Response
    {
        CurrentActor::resolve($request);

        return $next($request);
    }
}
