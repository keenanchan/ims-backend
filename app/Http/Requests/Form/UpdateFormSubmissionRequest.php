<?php

namespace App\Http\Requests\Form;

use App\Enums\SubmissionPriority;
use App\Rules\ContentMatchesTemplateSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateFormSubmissionRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Validate against the pinned template version's schema, not the live template schema.
        // The submission was authored against a specific version; updates must remain valid
        // under that same schema even if the template has since been updated.
        $templateVersion = $this->route('form_submission')?->templateVersion;

        $contentRules = ['required', 'array'];

        if ($templateVersion) {
            $contentRules[] = new ContentMatchesTemplateSchema($templateVersion->json_schema);
        }

        return [
            'form_name' => ['required', 'string', 'max:255'],
            'content' => $contentRules,
            'version_number' => ['required', 'integer'],
            'priority' => ['nullable', new Enum(SubmissionPriority::class)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
