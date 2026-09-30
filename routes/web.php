<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/login', '/admin/login')->name('login');



// QR codes marchands à scanner pour tester le flux "rejoindre" — jamais
// exposé hors local (les qr_token permettent de rejoindre n'importe quel
// restaurant, à ne pas divulguer en prod).
// Redirection directe vers les réseaux sociaux des établissements
Route::get('/r/{identifier}/{platform}', [\App\Http\Controllers\SocialRedirectController::class, 'redirect'])
    ->name('social.redirect');

