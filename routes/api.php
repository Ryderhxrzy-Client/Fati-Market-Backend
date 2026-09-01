<?php

use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\FavoritesController;
use App\Http\Controllers\Api\FcmDeviceTokenController;
use App\Http\Controllers\Api\ItemsController;
use App\Http\Controllers\Api\MessagesController;
use App\Http\Controllers\Api\StudentManagementController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public categories routes (can view categories without auth)
Route::get('/categories', [CategoriesController::class, 'getAllCategories']);
Route::get('/categories/{category_id}', [CategoriesController::class, 'getCategoryById']);

// Public items routes (can view items without auth, but supports optional Sanctum auth)
Route::get('/items', [ItemsController::class, 'getAllItems']);
Route::get('/items/{item_id}', [ItemsController::class, 'getItemDetails']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/device-tokens', [FcmDeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [FcmDeviceTokenController::class, 'destroy']);

    // Profile routes
    Route::post('/profile/picture', [AuthController::class, 'updateProfilePicture']);
    Route::get('/wallet', [AuthController::class, 'getWalletBalance']);

    // Messages routes
    Route::prefix('messages')->group(function () {
        Route::post('/{item_id}', [MessagesController::class, 'sendMessage']);
        Route::get('/{item_id}', [MessagesController::class, 'getMessagesByItem']);
        Route::delete('/{message_id}', [MessagesController::class, 'deleteMessage']);
        Route::post('/{message_id}/read', [MessagesController::class, 'markRead']);
    });

    // Conversations routes
    Route::prefix('conversations')->group(function () {
        Route::get('/', [MessagesController::class, 'getConversations']);
        Route::get('/{user_id}', [MessagesController::class, 'getConversationWithUser']);
    });

    // Protected items routes (student seller)
    Route::prefix('items')->group(function () {
        Route::post('/', [ItemsController::class, 'createItem']);
        Route::put('/{item_id}', [ItemsController::class, 'updateItem']);
        Route::delete('/{item_id}', [ItemsController::class, 'deleteItem']);
    });

    // Favorites routes
    Route::prefix('favorites')->group(function () {
        Route::post('/', [FavoritesController::class, 'addFavorite']);
        Route::get('/', [FavoritesController::class, 'getFavorites']);
        Route::delete('/{item_id}', [FavoritesController::class, 'removeFavorite']);
        Route::get('/{item_id}/check', [FavoritesController::class, 'checkFavorite']);
    });

    /*
     * Buyer checkout.
     *
     * `quote` returns the server's own breakdown so the confirmation screen
     * cannot disagree with what is actually charged.
     */
    Route::prefix('checkout')->group(function () {
        Route::get('/quote', [CheckoutController::class, 'quote']);
        Route::get('/payment-details', [CheckoutController::class, 'paymentDetails']);
        Route::post('/', [CheckoutController::class, 'store']);
        Route::post('/{transaction_id}/payment-proof', [CheckoutController::class, 'uploadPaymentProof']);
        Route::post('/{transaction_id}/cancel', [CheckoutController::class, 'cancel']);
    });

    // Transactions routes
    Route::prefix('transactions')->group(function () {
        // Superseded by POST /api/checkout; kept for older app builds.
        Route::post('/', [TransactionController::class, 'createTransaction']);
        Route::get('/', [TransactionController::class, 'getUserTransactions']);
        Route::get('/{transaction_id}/receipt', [TransactionController::class, 'getReceipt']);
    });

    // Points routes
    Route::prefix('points')->group(function () {
        Route::get('/history', [TransactionController::class, 'getPointHistory']);
        Route::get('/given', [TransactionController::class, 'getPointsGiven'])->middleware('admin');
        Route::get('/received', [TransactionController::class, 'getPointsReceived'])->middleware('admin');
    });

    /*
     * Admin-only.
     *
     * Setting the acquisition price, verifying turnover, setting the public
     * price, publishing, verifying payments and completing or cancelling
     * transactions are all gated here by role, read from the token.
     */
    Route::middleware('admin')->group(function () {
        // Student management
        Route::prefix('admin/students')->group(function () {
            Route::get('/', [StudentManagementController::class, 'getAllStudents']);
            Route::get('/pending', [StudentManagementController::class, 'getPendingStudents']);
            Route::put('/{user_id}/approve', [StudentManagementController::class, 'approveStudent']);
            Route::put('/{user_id}/decline', [StudentManagementController::class, 'declineStudent']);
            Route::put('/{user_id}/block', [StudentManagementController::class, 'blockStudent']);
            Route::get('/{user_id}', [StudentManagementController::class, 'getStudentDetails']);
        });

        Route::get('/admin/dashboard', [AuthController::class, 'getDashboardStats']);

        /*
         * Inventory and offers.
         *
         * The literal segments are declared before `{item_id}` so they are not
         * swallowed by the wildcard.
         */
        Route::prefix('admin/items')->group(function () {
            Route::get('/', [AdminInventoryController::class, 'index']);
            Route::get('/pending', [AdminInventoryController::class, 'pending']);
            Route::get('/scan', [AdminInventoryController::class, 'scan']);

            Route::post('/{item_id}/acquisition-price', [AdminInventoryController::class, 'setAcquisitionPrice']);
            Route::post('/{item_id}/meetup', [AdminInventoryController::class, 'setMeetupSchedule']);
            Route::post('/{item_id}/verify-turnover', [AdminInventoryController::class, 'verifyTurnover']);
            Route::post('/{item_id}/seller-payout', [AdminInventoryController::class, 'recordSellerPayout']);
            Route::get('/{item_id}/publish-preview', [AdminInventoryController::class, 'publishPreview']);
            Route::post('/{item_id}/publish', [AdminInventoryController::class, 'publish']);
            Route::post('/{item_id}/unpublish', [AdminInventoryController::class, 'unpublish']);
            Route::post('/{item_id}/reject', [AdminInventoryController::class, 'reject']);

            Route::put('/{item_id}', [AdminInventoryController::class, 'update']);
        });

        // Transaction management
        Route::prefix('admin/transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'getAllTransactions']);
            Route::get('/cash', [TransactionController::class, 'getCashTransactions']);
            Route::get('/trade', [TransactionController::class, 'getTradeTransactions']);
            Route::get('/profit-summary', [TransactionController::class, 'getProfitSummary']);
            Route::post('/expire-abandoned', [TransactionController::class, 'expireAbandoned']);
            // The literal segment must precede the wildcard below.
            Route::get('/scan', [TransactionController::class, 'scan']);

            Route::get('/{transaction_id}', [TransactionController::class, 'getTransaction']);
            Route::post('/{transaction_id}/verify-payment', [TransactionController::class, 'verifyPayment']);
            Route::post('/{transaction_id}/approve-order', [TransactionController::class, 'approveOrder']);
            Route::post('/{transaction_id}/reject-payment', [TransactionController::class, 'rejectPayment']);
            Route::post('/{transaction_id}/ready-for-pickup', [TransactionController::class, 'markReadyForPickup']);
            Route::post('/{transaction_id}/complete', [TransactionController::class, 'complete']);
            Route::post('/{transaction_id}/cancel', [TransactionController::class, 'cancel']);
            Route::put('/{transaction_id}', [TransactionController::class, 'updateTransactionStatus']);
        });

        Route::prefix('admin')->group(function () {
            // Manual point adjustment. This is no longer a seller payout -
            // sellers are paid cash via admin/items/{item}/seller-payout.
            Route::post('/send-points', [TransactionController::class, 'sendPoints']);
            Route::get('/item/{item_id}/points-status', [TransactionController::class, 'checkItemPointsStatus']);
            Route::post('/mark-as-sold', [TransactionController::class, 'markAsSold']);
            Route::post('/mark-as-reserved', [TransactionController::class, 'markAsReserved']);
        });

        // Reports / analytics
        Route::prefix('admin/reports')->group(function () {
            Route::get('/sales', [TransactionController::class, 'getSalesReport']);
            Route::get('/profit', [TransactionController::class, 'getProfitReport']);
            Route::get('/categories', [TransactionController::class, 'getCategoryReport']);
            Route::get('/users', [TransactionController::class, 'getUserReport']);
        });
    });
});
