<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use App\Models\Review;
use App\Services\NotificationDispatcher;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {
    }
    /**
     * Merchant: Get all reviews for their restaurant
     */
    public function index(Request $request)
    {
        // $request->user() IS the Restaurant model (Sanctum guard for
        // merchant routes authenticates via the restaurants table).
        $restaurant = $request->user();

        $reviews = Review::with('client:id,first_name,last_name,phone')
            ->where('restaurant_id', $restaurant->id)
            ->latest()
            ->get();

        $averageRating = $reviews->avg('rating') ?? 0;
        $totalReviews = $reviews->count();

        return response()->json([
            'average_rating' => round($averageRating, 1),
            'total_reviews' => $totalReviews,
            'reviews' => $reviews,
        ]);
    }

    /**
     * Client: Submit a review for a restaurant
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_id' => 'required|exists:loyalty_cards,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $client = $request->user();
        $card = LoyaltyCard::where('id', $validated['card_id'])
            ->where('client_id', $client->id)
            ->firstOrFail();

        // Check if the client already reviewed recently (optional, skipping for now)
        // or just allow multiple reviews

        $review = Review::create([
            'client_id' => $client->id,
            'restaurant_id' => $card->restaurant_id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'],
        ]);

        $restaurant = Restaurant::find($card->restaurant_id);
        if ($restaurant) {
            $this->notifications->send(
                $restaurant,
                'merchant_new_review',
                'Nouvel avis ⭐',
                "{$client->first_name} vous a laissé un avis ({$validated['rating']}/5).",
                ['review_id' => $review->id, 'rating' => $validated['rating']],
            );
        }

        return response()->json([
            'message' => 'Review submitted successfully',
            'review' => $review,
        ]);
    }
}
