<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels & Authorization
|--------------------------------------------------------------------------
|
| Secure private user channel authorization for FacultyLens real-time
| notifications. User A cannot listen to User B's notifications.
|
*/

// Support API clients using Sanctum at both /api/broadcasting/auth and /broadcasting/auth
Broadcast::routes(['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']]);
Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

Broadcast::channel('users.{userId}.notifications', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

