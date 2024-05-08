<?php
use Illuminate\Support\Facades\Route;

Route::post('/cryptomus/webhook', [App\Extensions\Gateways\Cryptomus\Cryptomus::class, 'webhook']);