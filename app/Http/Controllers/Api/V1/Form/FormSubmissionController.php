<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormSubmissionRequest;
use App\Http\Requests\Form\UpdateFormSubmissionRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FormSubmissionController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FormSubmission::query()
            ->with(['template', 'creator', 'currentVersion.user']);

        if ($request->filled('form_template_id')) {
            $query->where('form_template_id', $request->form_template_id);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->integer('per_page', 15);
        $perPage = min(max($perPage, 1), 100);

        return FormSubmissionResource::collection($query->paginate($perPage));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFormSubmissionRequest $request): FormSubmissionResource
    {
        $template = FormTemplate::findOrFail($request->form_template_id);
        $this->authorize('create', [FormSubmission::class, $template]);

        return DB::transaction(function () use ($request) {
            $submission = FormSubmission::create([
                'form_template_id' => $request->form_template_id,
                'form_template_version_id' => $request->form_template_version_id,
                'created_by' => Auth::guard('api')->id(),
                'priority' => $request->priority,
            ]);
            $submission->load('template');

            $version = $submission->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'form_name' => $request->form_name,
                'content' => $request->content,
                'version_number' => 1,
            ]);

            $submission->update(['current_version_id' => $version->id]);

            $submission->load(['template', 'templateVersion', 'creator', 'currentVersion.user']);
            $this->notifications->notifySubmitted($submission);

            return new FormSubmissionResource($submission);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(FormSubmission $formSubmission): FormSubmissionResource
    {
        return new FormSubmissionResource($formSubmission->load(['template', 'templateVersion', 'creator', 'currentVersion.user', 'versions']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFormSubmissionRequest $request, FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('update', $formSubmission);

        $conflictSubmission = null;

        $result = DB::transaction(function () use ($request, $formSubmission, &$conflictSubmission) {
            $lockedSubmission = FormSubmission::with('currentVersion')
                ->lockForUpdate()
                ->findOrFail($formSubmission->id);
            $currentVersion = $lockedSubmission->currentVersion;

            if ($currentVersion->version_number !== (int) $request->version_number) {
                $conflictSubmission = $lockedSubmission;

                return null;
            }

            $newVersion = $lockedSubmission->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'form_name' => $request->form_name,
                'content' => $request->content,
                'version_number' => $currentVersion->version_number + 1,
            ]);

            $updateData = ['current_version_id' => $newVersion->id];
            if ($request->has('priority')) {
                $updateData['priority'] = $request->priority;
            }

            $lockedSubmission->update($updateData);

            return new FormSubmissionResource($lockedSubmission->load(['template', 'creator', 'currentVersion.user']));
        });

        if ($conflictSubmission) {
            $this->notifications->notifyConflict($conflictSubmission, Auth::guard('api')->user());
            abort(409, 'Version conflict');
        }

        return $result;
    }
}
