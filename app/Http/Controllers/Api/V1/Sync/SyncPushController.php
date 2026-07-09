<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPushRequest;
use App\Services\Sync\SyncPushHandler;
use Illuminate\Http\JsonResponse;

class SyncPushController extends Controller
{
    /**
     * Replay an ordered batch of offline operations.
     *
     * Always returns 200 with per-op results when the envelope is valid;
     * individual op failures (conflict, forbidden, invalid) are data, not
     * HTTP errors.
     */
    public function __invoke(SyncPushRequest $request, SyncPushHandler $handler): JsonResponse
    {
        $payload = $handler->handle($request->user('api'), $request->validated('operations'));

        return response()->json([
            'results' => $payload['results'],
            'mappings' => (object) $payload['mappings'],
        ]);
    }
}
