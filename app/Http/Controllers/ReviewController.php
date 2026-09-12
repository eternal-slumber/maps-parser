<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class ReviewController extends Controller
{
    public function index(Request $request, int $organization): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $organization = $user->organizations()->findOrFail($organization);

        $request->validate(
            ['rating' => ['nullable', 'integer', 'between:1,5']],
            [
                'rating.integer' => 'Оценка должна быть целым числом.',
                'rating.between' => 'Оценка должна быть от 1 до 5.',
            ],
        );

        $reviews = $organization->reviews()
            ->latest('published_at')
            ->latest('id');

        if ($request->filled('rating')) {
            $reviews->where('rating', $request->integer('rating'));
        }

        return ReviewResource::collection($reviews->paginate(50));
    }
}
