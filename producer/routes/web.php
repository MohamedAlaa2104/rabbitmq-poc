<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/orders/publish', fn (\App\Services\OrderEventPublisher $p) => response()->json(['published' => $p->publish(1)]));
Route::get('/orders/publish/100', fn (\App\Services\OrderEventPublisher $p) => response()->json(['published' => $p->publish(100)]));
