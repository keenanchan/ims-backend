<?php

namespace App\Models;

use App\Enums\SyncConflictStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_submission_id',
        'user_id',
        'base_version_number',
        'submitted_form_name',
        'submitted_content',
        'status',
        'resolved_version_id',
    ];

    protected function casts(): array
    {
        return [
            'submitted_content' => 'array',
            'base_version_number' => 'integer',
            'status' => SyncConflictStatus::class,
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvedVersion(): BelongsTo
    {
        return $this->belongsTo(FormSubmissionVersion::class, 'resolved_version_id');
    }
}
