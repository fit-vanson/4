<?php

use App\Http\Controllers\CronController;
use App\Http\Controllers\GoogleAdsenseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});






Route::get('/googleadsense', [GoogleAdsenseController::class, 'getIndex']);
Route::post('/googleadsense', [GoogleAdsenseController::class, 'postIndex']);

Route::resource('home', 'HomeController');
//Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
//Route::get('/', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get('/cron', [CronController::class, 'getIndex']);

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
