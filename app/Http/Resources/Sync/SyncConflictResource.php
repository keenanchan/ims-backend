<?php

namespace App\Http\Resources\Sync;

use App\Http\Resources\Form\FormSubmissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncConflictResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The nested submission carries its current server version (when loaded)
     * so clients can render a diff against the submitted payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_submission_id' => $this->form_submission_id,
            'base_version_number' => $this->base_version_number,
            'submitted_form_name' => $this->submitted_form_name,
            'submitted_content' => $this->submitted_content,
            'status' => $this->status?->value,
            'resolved_version_id' => $this->resolved_version_id,
            'submission' => new FormSubmissionResource($this->whenLoaded('submission')),
            'created_at' => $this->created_at,
        ];
    }
}
