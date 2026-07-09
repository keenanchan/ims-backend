<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmissionVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'user_id',
        'form_name',
        'content',
        'version_number',
        'base_version_number',
        'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version_number' => 'integer',
            'base_version_number' => 'integer',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
