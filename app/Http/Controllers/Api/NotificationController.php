<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications ou /api/merchant/notifications — `$request->user()`
     * résout déjà vers `Client` ou `Restaurant` selon le token, ce contrôleur
     * n'a donc besoin d'aucune branche par rôle.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            Notification::where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->getKey())
                ->latest()
                ->paginate(20)
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        Notification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues.']);
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->delete();

        return response()->json(['message' => 'Notification supprimée.']);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();

        Notification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->delete();

        return response()->json(['message' => 'Toutes les notifications ont été supprimées.']);
    }

    private function authorizeOwnership(Request $request, Notification $notification): void
    {
        $user = $request->user();

        abort_if(
            $notification->notifiable_type !== $user->getMorphClass()
                || $notification->notifiable_id !== $user->getKey(),
            404
        );
    }
}
