<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/identity.php';
    require __DIR__.'/api/v1/form-templates.php';
    require __DIR__.'/api/v1/form-submissions.php';
    require __DIR__.'/api/v1/form-permissions.php';
    require __DIR__.'/api/v1/notifications.php';
});
