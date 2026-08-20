<?php

use Illuminate\Support\Facades\Route;
use App\Events\TestEvent;
use App\Http\Controllers\DebugQrController;

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

// QR codes marchands à scanner pour tester le flux "rejoindre" — jamais
// exposé hors local (les qr_token permettent de rejoindre n'importe quel
// restaurant, à ne pas divulguer en prod).
if (app()->environment('local')) {
    Route::get('/debug/restaurants', [DebugQrController::class, 'index']);
    Route::get('/debug/restaurants/{restaurant}/qr', [DebugQrController::class, 'qr']);
}
