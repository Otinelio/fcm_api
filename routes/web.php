<?php

use Illuminate\Support\Facades\Route;
use App\Events\TestEvent;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    TestEvent::dispatch();
    return 'Événement envoyé !';
});

Route::get('/admin', function () {
    $users = \App\Models\User::all();
    return view('admin', compact('users'));
});
