<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
Route::post('/payments',[PaymentController::class, 'create']);
Route::post('/webhooks/midtrans', [PaymentController::class, 'webhook']);