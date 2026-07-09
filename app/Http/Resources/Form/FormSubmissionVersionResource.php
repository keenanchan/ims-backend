<?php

namespace App\Http\Resources\Form;

use App\Http\Resources\Identity\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormSubmissionVersionResource extends JsonResource
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
            'submission_id' => $this->submission_id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'form_name' => $this->form_name,
            'content' => $this->content,
            'version_number' => $this->version_number,
            'base_version_number' => $this->base_version_number,
            'client_uuid' => $this->client_uuid,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
