<?php

namespace App\Http\Requests\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncPushRequest extends FormRequest
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
     * Only the envelope is validated here; each operation's shape differs per
     * op type and is validated individually by SyncPushHandler so one bad
     * operation yields a per-op "invalid" result instead of failing the batch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operations' => ['required', 'array', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operations.max' => 'A push batch may contain at most :max operations.',
        ];
    }
}
