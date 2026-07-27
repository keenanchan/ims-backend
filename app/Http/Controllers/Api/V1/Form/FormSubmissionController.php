<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormSubmissionRequest;
use App\Http\Requests\Form\UpdateFormSubmissionRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FormSubmissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FormSubmission::query()
            ->with(['template', 'currentVersion.user']);

        if ($request->filled('form_template_id')) {
            $query->where('form_template_id', $request->form_template_id);
        }

        $perPage = $request->integer('per_page', 15);
        $perPage = min(max($perPage, 1), 100);

        return FormSubmissionResource::collection($query->paginate($perPage));
    }

    public function store(StoreFormSubmissionRequest $request): FormSubmissionResource
    {
        $template = FormTemplate::findOrFail($request->form_template_id);
        $this->authorize('create', [FormSubmission::class, $template]);

        return DB::transaction(function () use ($request) {
            $submission = FormSubmission::create([
                'form_template_id' => $request->form_template_id,
                'form_template_version_id' => $request->form_template_version_id,
            ]);
            $submission->load('template');

            $version = $submission->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'form_name' => $request->form_name,
                'content' => $request->content,
                'version_number' => 1,
            ]);

            $submission->update(['current_version_id' => $version->id]);

            return new FormSubmissionResource($submission->load(['template', 'templateVersion', 'currentVersion.user']));
        });
    }

    public function show(FormSubmission $formSubmission): FormSubmissionResource
    {
        return new FormSubmissionResource($formSubmission->load(['template', 'templateVersion', 'currentVersion.user', 'versions']));
    }

    public function update(UpdateFormSubmissionRequest $request, FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('update', $formSubmission);

        return DB::transaction(function () use ($request, $formSubmission) {
            $lockedSubmission = FormSubmission::with('currentVersion')
                ->lockForUpdate()
                ->findOrFail($formSubmission->id);
            $currentVersion = $lockedSubmission->currentVersion;

            if ($currentVersion->version_number !== (int) $request->version_number) {
                abort(409, 'Version conflict');
            }

            $newVersion = $lockedSubmission->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'form_name' => $request->form_name,
                'content' => $request->content,
                'version_number' => $currentVersion->version_number + 1,
            ]);

            $lockedSubmission->update(['current_version_id' => $newVersion->id]);

            return new FormSubmissionResource($lockedSubmission->load(['template', 'templateVersion', 'currentVersion.user']));
        });
    }
}
