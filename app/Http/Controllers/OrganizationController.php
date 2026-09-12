<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationController extends Controller
{
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->user($request)->organizations()->updateOrCreate(
            ['business_id' => $request->businessId()],
            [
                'source_url' => $request->organizationUrl(),
                'sync_status' => Organization::SYNC_PENDING,
                'processed_pages' => 0,
                'processed_reviews' => 0,
                'sync_error' => null,
            ],
        );

        SyncYandexOrganization::dispatch($organization->id);

        return OrganizationResource::make($organization)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, int $organization): OrganizationResource
    {
        $organization = $this->user($request)
            ->organizations()
            ->findOrFail($organization);

        return OrganizationResource::make($organization);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
