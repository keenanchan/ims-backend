<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Resources\Form\FormTemplateVersionResource;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormTemplateVersionController extends Controller
{
    public function index(FormTemplate $formTemplate): AnonymousResourceCollection
    {
        return FormTemplateVersionResource::collection(
            $formTemplate->versions()->with('user')->orderBy('version_number', 'desc')->get()
        );
    }

    public function show(FormTemplate $formTemplate, FormTemplateVersion $version): FormTemplateVersionResource
    {
        if ($version->template_id !== $formTemplate->id) {
            abort(404);
        }

        return new FormTemplateVersionResource($version->load('user'));
    }
}
