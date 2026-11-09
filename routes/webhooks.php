<?php

use App\Http\Controllers\Webhooks\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Outside the web group: no session, cookies or CSRF. The controller authenticates Telegram's secret header.
Route::post('/webhooks/telegram', TelegramWebhookController::class)->name('webhooks.telegram');
