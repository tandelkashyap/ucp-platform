<?php

use App\Http\Controllers\AgentCredentialController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CapabilityConfigController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\StoreConnectionController;
use App\Http\Controllers\Ucp\CartController;
use App\Http\Controllers\Ucp\CatalogController;
use App\Http\Controllers\Ucp\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

/*
|--------------------------------------------------------------------------
| UCP-facing routes — the data plane
|--------------------------------------------------------------------------
| Public: hit by AI agents, not logged-in users. Gated by capability_configs
| (Merchant::hasCapability()) AND agent.auth:{scope} (AuthenticateAgent) —
| both need to pass, not either/or. throttle:120,1 is a supplementary
| stopgap against accidental abuse from an otherwise-valid credential, not
| a replacement for either check.
*/
Route::prefix('ucp/{merchant}')->middleware('throttle:120,1')->group(function () {
    Route::get('catalog', [CatalogController::class, 'index'])
        ->middleware('agent.auth:catalog');

    Route::post('carts', [CartController::class, 'store'])
        ->middleware('agent.auth:cart');
    Route::get('carts/{cart}', [CartController::class, 'show'])
        ->middleware('agent.auth:cart');
    Route::patch('carts/{cart}', [CartController::class, 'update'])
        ->middleware('agent.auth:cart');
    Route::post('carts/{cart}/checkout', [CheckoutController::class, 'store'])
        ->middleware('agent.auth:checkout');
});

/*
|--------------------------------------------------------------------------
| Control plane routes — the merchant-facing dashboard backend
|--------------------------------------------------------------------------
| Everything here requires a logged-in user with a role on the merchant
| being acted on — see MerchantPolicy. Auth itself (login/register) is
| assumed to come from a starter kit (Fortify/Breeze), not built here.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::post('merchants', [MerchantController::class, 'store']);
    Route::get('merchants', [MerchantController::class, 'index']);
    Route::get('merchants/{merchant:slug}', [MerchantController::class, 'show']);

    Route::prefix('merchants/{merchant}')->group(function () {
        Route::get('store-connections', [StoreConnectionController::class, 'index']);
        Route::post('store-connections', [StoreConnectionController::class, 'store']);
        Route::delete('store-connections/{connection}', [StoreConnectionController::class, 'destroy']);

        Route::get('capabilities', [CapabilityConfigController::class, 'index']);
        Route::patch('capabilities/{capabilityConfig}', [CapabilityConfigController::class, 'update']);

        Route::get('agent-credentials', [AgentCredentialController::class, 'index']);
        Route::post('agent-credentials', [AgentCredentialController::class, 'store']);
        Route::delete('agent-credentials/{credential}', [AgentCredentialController::class, 'destroy']);
    });
});
