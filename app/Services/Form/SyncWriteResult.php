<?php

namespace App\Services\Form;

use App\Enums\SyncWriteStatus;
use App\Models\FormSubmission;

class SyncWriteResult
{
    public function __construct(
        public readonly SyncWriteStatus $status,
        public readonly FormSubmission $submission,
    ) {}

    public static function applied(FormSubmission $submission): self
    {
        return new self(SyncWriteStatus::Applied, $submission);
    }

    public static function duplicate(FormSubmission $submission): self
    {
        return new self(SyncWriteStatus::Duplicate, $submission);
    }

    public static function conflict(FormSubmission $submission): self
    {
        return new self(SyncWriteStatus::Conflict, $submission);
    }

    public function isApplied(): bool
    {
        return $this->status === SyncWriteStatus::Applied;
    }

    public function isConflict(): bool
    {
        return $this->status === SyncWriteStatus::Conflict;
    }
}
