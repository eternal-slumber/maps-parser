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

        return ReviewResource::collection(
            $organization->reviews()
                ->latest('published_at')
                ->latest('id')
                ->paginate(50),
        );
    }
}
