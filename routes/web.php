<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\AmzFeedController;
use App\Http\Controllers\AmzReportController;
use App\Http\Controllers\Catch\ProductController as CatchProductController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        // return view('welcome');
        return redirect('dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/user/profile', [ProfileController::class, 'index'])->name('user.profile');
    Route::get('/products', [ProductController::class, 'index'])->name('products');
    Route::get('/product/edit/{id}', [ProductController::class, 'edit'])->name('product.edit');
    Route::post('/product/save', [ProductController::class, 'save'])->name('product.save');

    Route::get('/amazon/feeds', [AmzFeedController::class, 'amazonFeeds'])->name('amazon.feeds');
    Route::get('/amazon/feed/download', [AmzFeedController::class, 'downloadFile'])->name('amazon.feed.download');
    
    Route::get('/amazon/reports', [AmzReportController::class, 'amazonReports'])->name('amazon.reports');
    Route::get('/amazon/report/download', [AmzReportController::class, 'downloadReport'])->name('amazon.report.download');

    Route::get('/get/producttypes', [ProductTypeController::class, 'getProductTypes'])->name('get.productTypes');

    Route::prefix('/catch')->name('catch.')->group(function(){
        Route::get('/products', [CatchProductController::class, 'index'])->name('products');
        Route::get('/product/edit/{id}', [CatchProductController::class, 'edit'])->name('product.edit');
        Route::post('/product/save', [CatchProductController::class, 'save'])->name('product.save');
    });

});
