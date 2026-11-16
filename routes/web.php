<?php

use App\Http\Controllers\Admin\BackorderController;
use App\Http\Controllers\Admin\BankTransferSettingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CsvImportController;
use App\Http\Controllers\Admin\FeeSettingController;
use App\Http\Controllers\Admin\InventoryLotController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderSettingController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\PurchaseReceiptController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShipmentController;
use App\Http\Controllers\Admin\StockOperationController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\CustomerOrderController;
use App\Http\Controllers\Shop\ShopController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/product-images/{productImage}/{variant}', [ProductImageController::class, 'show'])
    ->where('variant', 'full|thumbnail')->name('product-images.show');
Route::inertia('/', 'Home')->name('home');
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/{product}', [ShopController::class, 'show'])->name('shop.show');
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::patch('/cart/{product}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{product}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::get('/admin/login', [SessionController::class, 'create'])->name('admin.login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::post('/admin/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('admin.login.store');
    Route::inertia('/register', 'Auth/Register')->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:6,1')->name('register.store');
    Route::inertia('/forgot-password', 'Auth/ForgotPassword')->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (Request $request, string $token) => Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]))->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/email/verify', fn (Request $request) => $request->user()->hasVerifiedEmail()
        ? redirect()->route($request->user()->isAdmin() ? 'admin.dashboard' : 'home')
        : Inertia::render('Auth/VerifyEmail'))->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'update'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [VerificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');
    Route::middleware('verified')->group(function (): void {
        Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
        Route::get('/orders', [CustomerOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [CustomerOrderController::class, 'show'])->name('orders.show');
    });
});
Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:access-admin', 'verified'])->group(function (): void {
    Route::get('/', [UserController::class, 'index'])->name('dashboard');
    $registerCsvImportRoutes = function (string $prefix, string $name, string $type): void {
        Route::prefix($prefix.'/csv-imports')->name($name.'.csv-imports.')->group(function () use ($type): void {
            Route::get('/', [CsvImportController::class, 'index'])->name('index')->defaults('csv_import_type', $type);
            Route::get('create', [CsvImportController::class, 'create'])->name('create')->defaults('csv_import_type', $type);
            Route::post('/', [CsvImportController::class, 'store'])->name('store')->defaults('csv_import_type', $type);
            Route::get('template', [CsvImportController::class, 'template'])->name('template')->defaults('csv_import_type', $type);
            Route::get('{csvImport}', [CsvImportController::class, 'show'])->name('show')->defaults('csv_import_type', $type);
            Route::post('{csvImport}/execute', [CsvImportController::class, 'execute'])->name('execute')->defaults('csv_import_type', $type);
            Route::get('{csvImport}/errors.csv', [CsvImportController::class, 'errors'])->name('errors')->defaults('csv_import_type', $type);
        });
    };
    $registerCsvImportRoutes('products', 'products', 'products');
    $registerCsvImportRoutes('purchase-receipts', 'purchase-receipts', 'purchase_receipts');
    $registerCsvImportRoutes('stock-operations', 'stock-operations', 'stock_operations');
    $registerCsvImportRoutes('shipments', 'shipments', 'shipments');
    Route::resource('categories', CategoryController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('product-groups', ProductGroupController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('products', ProductController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('suppliers', SupplierController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('purchase-receipts', PurchaseReceiptController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::get('inventory-lots', [InventoryLotController::class, 'index'])->name('inventory-lots.index');
    Route::post('inventory-lots/dispose', [InventoryLotController::class, 'dispose'])->name('inventory-lots.dispose');
    Route::resource('stock-operations', StockOperationController::class)->only(['index', 'create', 'store', 'show']);
    Route::resource('orders', OrderController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('shipments', ShipmentController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->name('shipments.cancel');
    Route::get('backorders', [BackorderController::class, 'index'])->name('backorders.index');
    Route::post('backorders/allocate', [BackorderController::class, 'allocate'])->name('backorders.allocate');
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/fees', [FeeSettingController::class, 'edit'])->name('settings.fees.edit');
    Route::put('settings/fees', [FeeSettingController::class, 'update'])->name('settings.fees.update');
    Route::get('settings/bank-transfer', [BankTransferSettingController::class, 'edit'])->name('settings.bank-transfer.edit');
    Route::put('settings/bank-transfer', [BankTransferSettingController::class, 'update'])->name('settings.bank-transfer.update');
    Route::get('order-settings', [OrderSettingController::class, 'edit'])->name('order-settings.edit');
    Route::put('order-settings', [OrderSettingController::class, 'update'])->name('order-settings.update');
    Route::inertia('/users/create', 'Admin/CreateUser')->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->middleware('throttle:10,1')->name('users.store');
});
