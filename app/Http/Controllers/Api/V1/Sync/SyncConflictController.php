<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncConflictController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort(501, 'Not implemented');
    }

    public function show(SyncConflict $syncConflict): JsonResponse
    {
        abort(501, 'Not implemented');
    }

    public function resolve(Request $request, SyncConflict $syncConflict): JsonResponse
    {
        abort(501, 'Not implemented');
    }
}
