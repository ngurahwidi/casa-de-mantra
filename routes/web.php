<?php

use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\BookingController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\FaqController;
use App\Http\Controllers\Site\GalleryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LocationController;
use App\Http\Controllers\Site\PolicyController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\Site\VillaController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/villa', [VillaController::class, 'index'])->name('villas.index');
Route::get('/villa/{slug}', [VillaController::class, 'show'])->name('villas.show');

Route::get('/villa/{villa:slug}/book', [BookingController::class, 'create'])->name('booking.create');
Route::post('/villa/{villa:slug}/book', [BookingController::class, 'store'])
    ->middleware('throttle:booking')
    ->name('booking.store');
Route::get('/booking/success/{code}', [BookingController::class, 'success'])->name('booking.success');

Route::get('/gallery', GalleryController::class)->name('gallery');
Route::get('/location', LocationController::class)->name('location');
Route::get('/about', AboutController::class)->name('about');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('/faq', FaqController::class)->name('faq');

Route::get('/policies', [PolicyController::class, 'index'])->name('policies.index');
Route::get('/policies/{page:slug}', [PolicyController::class, 'show'])->name('policies.show');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
