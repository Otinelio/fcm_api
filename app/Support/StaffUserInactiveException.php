<?php

namespace App\Support;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserInactiveException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Ce compte a été désactivé. Contactez votre administrateur.',
        ], 401);
    }
}
