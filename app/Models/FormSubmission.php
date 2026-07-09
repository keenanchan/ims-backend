<?php

namespace App\Models;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_template_id',
        'form_template_version_id',
        'created_by',
        'current_version_id',
        'client_uuid',
        'priority',
        'status',
        'assignee_type',
        'assignee_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => SubmissionPriority::class,
            'status' => SubmissionStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    /** The specific template version that was active when this submission was created. */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(FormTemplateVersion::class, 'form_template_version_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormSubmissionVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormSubmissionVersion::class, 'submission_id');
    }

    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }
}
