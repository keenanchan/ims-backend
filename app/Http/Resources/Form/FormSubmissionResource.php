<?php

namespace App\Http\Resources\Form;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            // 'form_template_id' => $this->form_template_id,
            // 'current_version_id' => $this->current_version_id,
            'priority' => $this->priority?->value,
            'status' => $this->status?->value,
            'template' => new FormTemplateResource($this->whenLoaded('template')),
            'template_version' => new FormTemplateVersionResource($this->whenLoaded('templateVersion')),
            'current_version' => new FormSubmissionVersionResource($this->whenLoaded('currentVersion')),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'assignee_type' => $this->assignee_type,
            'assignee_id' => $this->assignee_id,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'type' => $this->assignee_type,
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            // 'versions' => FormSubmissionVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
