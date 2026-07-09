<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncPushController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort(501, 'Not implemented');
    }
}
