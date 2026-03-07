<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Create a transaction (purchase item)
     * POST /api/transactions
     */
    public function createTransaction(Request $request)
    {
        try {
            $validated = $request->validate([
                'item_id' => ['required', 'integer', 'exists:items,item_id'],
                'payment_method' => ['required', 'in:points,cash,trade'],
                'points_used' => ['integer', 'min:0'],
            ]);

            $item = Item::where('item_id', $validated['item_id'])->first();
            
            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Check if item is available for purchase
            if (!in_array($item->status, ['public', 'reserved'])) {
                return response()->json([
                    'message' => 'Item is not available for purchase',
                ], 400);
            }

            $buyer = $request->user();
            $seller = User::where('user_id', $item->seller_id)->first();

            // Calculate points needed
            $pointsNeeded = $item->markup_points ?? $item->price_points;

            // Validate points if payment method is points
            if ($validated['payment_method'] === 'points') {
                if ($buyer->wallet_points < $pointsNeeded) {
                    return response()->json([
                        'message' => 'Insufficient points',
                        'required' => $pointsNeeded,
                        'available' => $buyer->wallet_points,
                    ], 400);
                }
                $validated['points_used'] = $pointsNeeded;
            }

            // Create transaction in a database transaction
            $result = DB::transaction(function () use ($validated, $item, $buyer, $seller) {
                // Create transaction record
                $transaction = Transaction::create([
                    'item_id' => $validated['item_id'],
                    'buyer_id' => $buyer->user_id,
                    'seller_id' => $seller->user_id,
                    'payment_method' => $validated['payment_method'],
                    'points_used' => $validated['points_used'] ?? 0,
                    'status' => 'reserved',
                ]);

                // If payment is points, transfer points
                if ($validated['payment_method'] === 'points') {
                    $buyer->decrement('wallet_points', $validated['points_used']);
                    $seller->increment('wallet_points', $validated['points_used']);

                    // Add point records for both buyer and seller
                    \App\Models\Point::create([
                        'user_id' => $buyer->user_id,
                        'points_change' => -$validated['points_used'],
                        'reason' => 'purchase',
                        'related_item_id' => $item->item_id,
                    ]);

                    \App\Models\Point::create([
                        'user_id' => $seller->user_id,
                        'points_change' => $validated['points_used'],
                        'reason' => 'sale',
                        'related_item_id' => $item->item_id,
                    ]);
                }

                // Update item status
                $item->update(['status' => 'reserved']);

                return $transaction;
            });

            return response()->json([
                'message' => 'Transaction created successfully',
                'data' => [
                    'transaction_id' => $result->transaction_id,
                    'item_id' => $result->item_id,
                    'buyer_id' => $result->buyer_id,
                    'seller_id' => $result->seller_id,
                    'payment_method' => $result->payment_method,
                    'points_used' => $result->points_used,
                    'status' => $result->status,
                    'transaction_date' => $result->transaction_date,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating transaction', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to create transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all transactions (admin only)
     * GET /api/admin/transactions
     */
    public function getAllTransactions(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $transactions = Transaction::with([
                'item' => function ($q) {
                    $q->select('item_id', 'title', 'price_points', 'markup_points');
                },
                'buyer' => function ($q) {
                    $q->select('user_id', 'email');
                },
                'seller' => function ($q) {
                    $q->select('user_id', 'email');
                }
            ])->orderBy('transaction_date', 'desc')->get();

            return response()->json([
                'message' => 'Transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting transactions', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's transactions
     * GET /api/transactions
     */
    public function getUserTransactions(Request $request)
    {
        try {
            $user = $request->user();

            $transactions = Transaction::with([
                'item' => function ($q) {
                    $q->select('item_id', 'title', 'price_points', 'markup_points', 'photos');
                },
                'buyer' => function ($q) {
                    $q->select('user_id', 'email');
                },
                'seller' => function ($q) {
                    $q->select('user_id', 'email');
                }
            ])
            ->where(function ($query) use ($user) {
                $query->where('buyer_id', $user->user_id)
                      ->orWhere('seller_id', $user->user_id);
            })
            ->orderBy('transaction_date', 'desc')
            ->get();

            return response()->json([
                'message' => 'User transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting user transactions', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update transaction status (admin only)
     * PUT /api/admin/transactions/{transaction_id}
     */
    public function updateTransactionStatus(Request $request, $transactionId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $validated = $request->validate([
                'status' => ['required', 'in:reserved,completed,cancelled'],
            ]);

            $transaction = Transaction::where('transaction_id', $transactionId)->first();

            if (!$transaction) {
                return response()->json([
                    'message' => 'Transaction not found',
                ], 404);
            }

            // Update transaction
            $transaction->update(['status' => $validated['status']]);

            // If completed, update item status to sold
            if ($validated['status'] === 'completed') {
                $item = Item::where('item_id', $transaction->item_id)->first();
                $item->update(['status' => 'sold']);
            }
            // If cancelled, update item status back to public
            elseif ($validated['status'] === 'cancelled') {
                $item = Item::where('item_id', $transaction->item_id)->first();
                $item->update(['status' => 'public']);
                
                // Refund points if payment was points
                if ($transaction->payment_method === 'points' && $transaction->points_used > 0) {
                    $buyer = User::where('user_id', $transaction->buyer_id)->first();
                    $seller = User::where('user_id', $transaction->seller_id)->first();
                    
                    $buyer->increment('wallet_points', $transaction->points_used);
                    $seller->decrement('wallet_points', $transaction->points_used);
                    
                    // Add refund records
                    \App\Models\Point::create([
                        'user_id' => $buyer->user_id,
                        'points_change' => $transaction->points_used,
                        'reason' => 'adjustment',
                        'related_item_id' => $transaction->item_id,
                    ]);
                    
                    \App\Models\Point::create([
                        'user_id' => $seller->user_id,
                        'points_change' => -$transaction->points_used,
                        'reason' => 'adjustment',
                        'related_item_id' => $transaction->item_id,
                    ]);
                }
            }

            return response()->json([
                'message' => 'Transaction status updated successfully',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => $transaction->status,
                    'updated_at' => $transaction->updated_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating transaction status', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to update transaction status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send points to a user (admin only)
     * POST /api/admin/send-points
     */
    public function sendPoints(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,user_id'],
                'points' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'in:purchase,sale,markup,bonus,adjustment'],
            ]);

            $recipient = User::where('user_id', $validated['user_id'])->first();
            $admin = $request->user();

            // Check if admin has sufficient points
            if ($admin->wallet_points < $validated['points']) {
                return response()->json([
                    'message' => 'Insufficient points',
                    'required' => $validated['points'],
                    'available' => $admin->wallet_points,
                ], 400);
            }

            // Deduct points from admin's wallet
            $admin->decrement('wallet_points', $validated['points']);

            // Add points to user's wallet
            $recipient->increment('wallet_points', $validated['points']);

            // Create point record for recipient
            \App\Models\Point::create([
                'user_id' => $validated['user_id'],
                'points_change' => $validated['points'],
                'reason' => $validated['reason'],
                'related_item_id' => null,
            ]);

            // Create point record for admin (negative balance)
            \App\Models\Point::create([
                'user_id' => $admin->user_id,
                'points_change' => -$validated['points'],
                'reason' => 'purchase', // Admin is "purchasing" from student
                'related_item_id' => null,
            ]);

            return response()->json([
                'message' => 'Points sent successfully',
                'data' => [
                    'recipient_id' => $validated['user_id'],
                    'recipient_email' => $recipient->email,
                    'points_sent' => $validated['points'],
                    'recipient_new_balance' => $recipient->wallet_points,
                    'admin_new_balance' => $admin->wallet_points,
                    'reason' => $validated['reason'],
                    'sent_by' => $request->user()->user_id,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error sending points', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send points',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's point history
     * GET /api/points/history
     */
    public function getPointHistory(Request $request)
    {
        try {
            $user = $request->user();

            $points = \App\Models\Point::where('user_id', $user->user_id)
                ->with(['relatedItem' => function ($q) {
                    $q->select('item_id', 'title');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'message' => 'Point history retrieved successfully',
                'data' => $points,
                'current_balance' => $user->wallet_points,
                'count' => $points->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting point history', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve point history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
