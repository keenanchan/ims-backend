<?php

namespace App\Enums;

enum SyncConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Discarded = 'discarded';
}
