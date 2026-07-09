<?php

namespace App\Http\Requests\Sync;

use App\Rules\ContentMatchesTemplateSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResolveConflictRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Two shapes: {"action": "discard"} or a merged {"form_name", "content"}
     * payload validated against the submission's pinned template version schema.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->has('action')) {
            return [
                'action' => ['required', 'in:discard'],
            ];
        }

        $templateVersion = $this->route('syncConflict')?->submission?->templateVersion;

        $contentRules = ['required', 'array'];
        if ($templateVersion) {
            $contentRules[] = new ContentMatchesTemplateSchema($templateVersion->json_schema ?? []);
        }

        return [
            'form_name' => ['required', 'string', 'max:255'],
            'content' => $contentRules,
        ];
    }
}
