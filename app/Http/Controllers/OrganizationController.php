<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            $this->user($request)
                ->organizations()
                ->latest('updated_at')
                ->latest('id')
                ->get(),
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organizations = $this->user($request)->organizations();
        $organization = $organizations->firstOrCreate(
            ['business_id' => $request->businessId()],
            [
                'source_url' => $request->organizationUrl(),
                'sync_status' => Organization::SYNC_PENDING,
                'processed_pages' => 0,
                'processed_reviews' => 0,
                'sync_error' => null,
            ],
        );

        $shouldDispatch = $organization->wasRecentlyCreated;

        if (! $shouldDispatch) {
            $shouldDispatch = $organizations
                ->whereKey($organization->id)
                ->whereNotIn('sync_status', [
                    Organization::SYNC_PENDING,
                    Organization::SYNC_PROCESSING,
                ])
                ->update([
                    'sync_status' => Organization::SYNC_PENDING,
                    'processed_pages' => 0,
                    'processed_reviews' => 0,
                    'sync_error' => null,
                ]) === 1;
        }

        if ($shouldDispatch) {
            try {
                Bus::dispatch(new SyncYandexOrganization($organization->id));
            } catch (Throwable $exception) {
                $organizations
                    ->whereKey($organization->id)
                    ->where('sync_status', Organization::SYNC_PENDING)
                    ->update([
                        'sync_status' => Organization::SYNC_FAILED,
                        'sync_error' => 'Не удалось поставить синхронизацию в очередь.',
                    ]);

                throw $exception;
            }
        }

        return OrganizationResource::make($organization->refresh())
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

    public function destroy(Request $request, int $organization): Response
    {
        $this->user($request)
            ->organizations()
            ->findOrFail($organization)
            ->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
