<?php

use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\OriginController;
use App\Http\Controllers\Api\PackageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/origins', [OriginController::class, 'index']);
Route::get('/available-origins', [OriginController::class, 'availableOrigins']);

Route::post('/packages/search', [PackageController::class, 'search']);
Route::get('/packages/{package}', [PackageController::class, 'show']);
//Route::post('/packages/available-dates', [PackageController::class, 'getAvailableDates']);
Route::post('/packages/available-nights', [PackageController::class, 'getAvailableNights']);
Route::post('/packages/map-hotels', [PackageController::class, 'mapHotels']);

Route::post('/live-search', [PackageController::class, 'liveSearch']);
Route::post('/live-search-paginated', [PackageController::class, 'paginateLiveSearch']);
Route::post('/filter-data', [PackageController::class, 'getFilterData']);
Route::get('/available-dates', [PackageController::class, 'getAvailableDates']);
Route::get('/has-available-dates', [PackageController::class, 'hasAvailableDates']);
Route::get('/has-available-return', [PackageController::class, 'hasAvailableReturn']);
Route::get('/get-all-flights/{batchId}', [PackageController::class, 'getAllFlights']);
Route::post('/update-flight/{batchId}', [PackageController::class, 'updateFlight']);
Route::get('/offers', [PackageController::class, 'offers']);
Route::get('/ads/{id}', [PackageController::class, 'adsShow']);

Route::get('/destinations', [DestinationController::class, 'index']);
Route::get('/destinations/all', [DestinationController::class, 'indexAll']);
Route::get('/destinations/{destination}/packages', [DestinationController::class, 'showPackagesForDestination']);
Route::get('/destinations/{origin}/plain', [DestinationController::class, 'showDestinationsForOriginPlain']);
Route::get('/destinations/{origin}', [DestinationController::class, 'showDestinationsForOrigin']);

Route::post('/direct-flight', FlightController::class);
