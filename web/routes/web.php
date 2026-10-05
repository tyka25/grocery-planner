<?php

use App\Http\Controllers\MatchingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShoppingListController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => (bool) config('grocery_planner.registration_open'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/list', [ShoppingListController::class, 'show'])->name('list.show');
    Route::post('/list/items', [ShoppingListController::class, 'addItem'])->name('list.items.store');
    Route::patch('/list/items/{listItem}', [ShoppingListController::class, 'updateItem'])->name('list.items.update');
    Route::delete('/list/items/{listItem}', [ShoppingListController::class, 'removeItem'])->name('list.items.destroy');
    Route::post('/list/items/{listItem}/move', [ShoppingListController::class, 'move'])->name('list.items.move');
    Route::post('/list/staples', [ShoppingListController::class, 'addStaples'])->name('list.staples');
    Route::post('/list/plan', [ShoppingListController::class, 'plan'])->name('list.plan');
    Route::post('/list/check-stock', [ShoppingListController::class, 'checkStock'])->name('list.check-stock');
    Route::post('/list/finish',[ShoppingListController::class, 'finish'])->name('list.finish');
    Route::post('/items/{canonicalItem}/staple', [ShoppingListController::class, 'toggleStaple'])->name('items.staple');

    Route::get('/matching',[MatchingController::class, 'index'])->name('matching.index');
    Route::get('/matching/products/{storeProduct}/similar', [MatchingController::class, 'similar'])->name('matching.similar');
    Route::post('/matching/products/{storeProduct}/confirm', [MatchingController::class, 'confirm'])->name('matching.confirm');
    Route::post('/matching/products/{storeProduct}/reject', [MatchingController::class, 'reject'])->name('matching.reject');
    Route::post('/matching/items', [MatchingController::class, 'storeItem'])->name('matching.items.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
