<?php

namespace App\Http\Requests\Form;

use App\Enums\SubmissionPriority;
use App\Models\FormTemplateVersion;
use App\Rules\ContentMatchesTemplateSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreFormSubmissionRequest extends FormRequest
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
        $contentRules = ['required', 'array'];

        $templateVersion = FormTemplateVersion::find($this->form_template_version_id);
        if ($templateVersion) {
            $contentRules[] = new ContentMatchesTemplateSchema($templateVersion->json_schema);
        }

        return [
            'form_template_id' => ['required', 'exists:form_templates,id'],
            'form_template_version_id' => [
                'required',
                'exists:form_template_versions,id',
                // Ensure the version belongs to the submitted template.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $version = FormTemplateVersion::find($value);
                    if ($version && (int) $version->template_id !== (int) $this->form_template_id) {
                        $fail('The selected template version does not belong to the given form template.');
                    }
                },
            ],
            'form_name' => ['required', 'string', 'max:255'],
            'content' => $contentRules,
            'priority' => ['nullable', new Enum(SubmissionPriority::class)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
