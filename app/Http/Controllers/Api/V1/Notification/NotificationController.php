<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Notification::where('user_id', Auth::guard('api')->id())
            ->latest();

        if ($request->boolean('unread')) {
            $query->unread();
        }

        return NotificationResource::collection($query->paginate(20));
    }

    public function markRead(Notification $notification): NotificationResource
    {
        abort_if(
            $notification->user_id !== Auth::guard('api')->id(),
            403
        );

        $notification->update(['read_at' => now()]);

        return new NotificationResource($notification);
    }

    public function markAllRead(): JsonResponse
    {
        Notification::where('user_id', Auth::guard('api')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
