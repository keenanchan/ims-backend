<?php

namespace App\Enums;

enum SyncWriteStatus: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Conflict = 'conflict';
}
