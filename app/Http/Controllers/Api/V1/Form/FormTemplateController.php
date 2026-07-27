<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormTemplateRequest;
use App\Http\Requests\Form\UpdateFormTemplateRequest;
use App\Http\Resources\Form\FormTemplateResource;
use App\Models\FormTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FormTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FormTemplate::query()->with(['creator', 'currentVersion']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if (! $request->user()->can('viewInactive', FormTemplate::class)) {
            $query->where('is_active', true);
        }

        $perPage = $request->integer('per_page', 15);
        $perPage = min(max($perPage, 1), 100);

        return FormTemplateResource::collection($query->paginate($perPage));
    }

    public function store(StoreFormTemplateRequest $request): FormTemplateResource
    {
        return DB::transaction(function () use ($request) {
            $template = FormTemplate::create([
                ...$request->validated(),
                'created_by' => Auth::guard('api')->id(),
            ])->refresh();

            $version = $template->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'name' => $template->name,
                'json_schema' => $template->json_schema,
                'ui_schema' => $template->ui_schema,
                'is_active' => $template->is_active,
                'version_number' => 1,
            ]);

            $template->update(['current_version_id' => $version->id]);

            return new FormTemplateResource($template->load(['creator', 'currentVersion.user']));
        });
    }

    public function show(FormTemplate $formTemplate): FormTemplateResource
    {
        $this->authorize('view', $formTemplate);

        return new FormTemplateResource($formTemplate->load(['creator', 'currentVersion.user']));
    }

    public function update(UpdateFormTemplateRequest $request, FormTemplate $formTemplate): FormTemplateResource
    {
        return DB::transaction(function () use ($request, $formTemplate) {
            $locked = FormTemplate::with('currentVersion')
                ->lockForUpdate()
                ->findOrFail($formTemplate->id);

            if ($locked->currentVersion->version_number !== (int) $request->version_number) {
                abort(409, 'Version conflict');
            }

            $locked->update($request->safe()->except('version_number'));

            $locked->versions()->create([
                'user_id' => Auth::guard('api')->id(),
                'name' => $locked->name,
                'json_schema' => $locked->json_schema,
                'ui_schema' => $locked->ui_schema,
                'is_active' => $locked->is_active,
                'version_number' => $locked->currentVersion->version_number + 1,
            ]);

            $newVersion = $locked->versions()->latest('version_number')->first();
            $locked->update(['current_version_id' => $newVersion->id]);

            return new FormTemplateResource($locked->load(['creator', 'currentVersion.user']));
        });
    }
}
