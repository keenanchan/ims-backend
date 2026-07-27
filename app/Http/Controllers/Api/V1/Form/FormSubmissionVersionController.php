<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Resources\Form\FormSubmissionVersionResource;
use App\Models\FormSubmission;
use App\Models\FormSubmissionVersion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormSubmissionVersionController extends Controller
{
    public function index(FormSubmission $formSubmission): AnonymousResourceCollection
    {
        return FormSubmissionVersionResource::collection(
            $formSubmission->versions()->with('user')->orderBy('version_number', 'desc')->get()
        );
    }

    public function show(FormSubmission $formSubmission, FormSubmissionVersion $version): FormSubmissionVersionResource
    {
        // Ensure the version belongs to the submission
        if ($version->submission_id !== $formSubmission->id) {
            abort(404);
        }

        return new FormSubmissionVersionResource($version->load('user'));
    }
}
