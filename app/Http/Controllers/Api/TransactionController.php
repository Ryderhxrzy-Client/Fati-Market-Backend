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
                'related_item_id' => ['nullable', 'integer', 'exists:items,item_id'],
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

            // Check if points have already been sent for this item
            if (!empty($validated['related_item_id'])) {
                $existingPointRecords = \App\Models\Point::where('related_item_id', $validated['related_item_id'])
                    ->whereIn('reason', ['sale', 'purchase'])
                    ->get();

                if ($existingPointRecords->isNotEmpty()) {
                    return response()->json([
                        'message' => 'Points have already been sent for this item'
                    ], 409); // 409 Conflict
                }
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
                'related_item_id' => $validated['related_item_id'] ?? null,
            ]);

            // Create point record for admin (opposite transaction)
            $adminReason = match($validated['reason']) {
                'sale' => 'purchase',        // Admin purchases what student sells
                'purchase' => 'sale',        // Admin sells what student purchases
                'markup' => 'sale',          // Admin's markup comes from sale
                'bonus' => 'adjustment',     // Admin's bonus is an adjustment
                'adjustment' => 'adjustment', // Adjustments are adjustments
                default => 'adjustment'
            };

            \App\Models\Point::create([
                'user_id' => $admin->user_id,
                'points_change' => -$validated['points'],
                'reason' => $adminReason,
                'related_item_id' => $validated['related_item_id'] ?? null,
            ]);

            // Create transaction record if this is a sale/purchase with related item
            if (in_array($validated['reason'], ['sale', 'purchase']) && !empty($validated['related_item_id'])) {
                try {
                    $transaction = \App\Models\Transaction::create([
                        'item_id' => $validated['related_item_id'],
                        'buyer_id' => $validated['reason'] === 'sale' ? $admin->user_id : $validated['user_id'],
                        'seller_id' => $validated['reason'] === 'sale' ? $validated['user_id'] : $admin->user_id,
                        'payment_method' => 'points',
                        'points_used' => $validated['points'],
                        'status' => 'completed',
                    ]);
                    Log::info('Transaction record created', ['transaction_id' => $transaction->transaction_id]);
                } catch (\Exception $e) {
                    Log::error('Failed to create transaction record', ['error' => $e->getMessage()]);
                    // Don't throw here - points were already transferred, just log the error
                }
            }

            return response()->json([
                'message' => 'Points sent successfully',
                'data' => [
                    'recipient_id' => $validated['user_id'],
                    'recipient_email' => $recipient->email,
                    'points_sent' => $validated['points'],
                    'recipient_new_balance' => $recipient->wallet_points,
                    'admin_new_balance' => $admin->wallet_points,
                    'reason' => $validated['reason'],
                    'admin_reason' => $adminReason,
                    'related_item_id' => $validated['related_item_id'] ?? null,
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
     * Mark an item as reserved from the chat message admin page
     * POST /api/admin/mark-as-reserved
     */
    public function markAsReserved(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $validated = $request->validate([
                'item_id' => ['required', 'integer', 'exists:items,item_id'],
                'buyer_id' => ['required', 'integer', 'exists:users,user_id'],
            ]);

            $admin = $request->user();
            $buyer = User::where('user_id', $validated['buyer_id'])->first();
            $item = Item::where('item_id', $validated['item_id'])->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            // Check if item is already reserved by someone else, or sold
            if ($item->status !== 'public') {
                
                // If the item is already reserved by this exact buyer, handle gracefully
                $existingReservation = \App\Models\Reservation::where('item_id', $item->item_id)
                    ->where('status', 'active')
                    ->first();
                
                if ($existingReservation && $existingReservation->user_id === $buyer->user_id) {
                     return response()->json([
                        'message' => 'This item is already reserved for other user.',
                    ], 400);
                }

                \App\Models\Message::create([
                    'item_id' => $item->item_id,
                    'sender_id' => $admin->user_id,
                    'receiver_id' => $buyer->user_id,
                    'message' => 'Reservation failed: This item is no longer available.',
                    'sent_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Item is not available for reservation',
                ], 400);
            }

            // Begin database transaction
            $result = DB::transaction(function () use ($item, $buyer, $admin) {
                
                // Create the reservation
                $reservation = \App\Models\Reservation::create([
                    'item_id' => $item->item_id,
                    'user_id' => $buyer->user_id,
                    'status' => 'active',
                    // Optional: Expires in 24 hours
                    'expires_at' => now()->addHours(24),
                ]);

                // Update item status to reserved
                $item->update(['status' => 'reserved']);

                // Insert success message to user in chat
                \App\Models\Message::create([
                    'item_id' => $item->item_id,
                    'sender_id' => $admin->user_id,
                    'receiver_id' => $buyer->user_id,
                    'message' => 'Congratulations! You have successfully reserved this item.',
                    'sent_at' => now(),
                ]);

                return $reservation;
            });

            return response()->json([
                'message' => 'Item reserved successfully',
                'data' => [
                    'reservation_id' => $result->reservation_id,
                    'item_id' => $result->item_id,
                    'user_id' => $result->user_id,
                    'status' => $result->status,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error marking as reserved', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to mark as reserved',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark an item as sold from the chat message admin page
     * POST /api/admin/mark-as-sold
     */
    public function markAsSold(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $validated = $request->validate([
                'item_id' => ['required', 'integer', 'exists:items,item_id'],
                'buyer_id' => ['required', 'integer', 'exists:users,user_id'],
                'payment_method' => ['required', 'string', 'in:points,cash,trade'],
            ]);

            $admin = $request->user();
            $buyer = User::where('user_id', $validated['buyer_id'])->first();
            $item = Item::where('item_id', $validated['item_id'])->first();
            $paymentMethod = $validated['payment_method'];

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            // Verify if there is an active reservation
            $reservation = \App\Models\Reservation::where('item_id', $item->item_id)
                ->where('status', 'active')
                ->first();

            // If it is reserved, validate that the user buying it is the owner
            if ($reservation && $reservation->user_id !== $buyer->user_id) {
                \App\Models\Message::create([
                    'item_id' => $item->item_id,
                    'sender_id' => $admin->user_id,
                    'receiver_id' => $buyer->user_id,
                    'message' => 'Purchase failed: This item is reserved by another user.',
                    'sent_at' => now(),
                ]);

                return response()->json([
                    'message' => 'This item is reserved by another user and cannot be bought.'
                ], 403);
            }

            // Check if item is available for purchase (public OR reserved)
            if (!in_array($item->status, ['public', 'reserved'])) {
                return response()->json([
                    'message' => 'Item is not available for purchase',
                ], 400);
            }

            // Calculate points needed based on payment method
            $pointsNeeded = 0;
            if ($paymentMethod === 'points') {
                $pointsNeeded = $item->markup_points ?? $item->price_points;

                // Check if buyer has enough points
                if ($buyer->wallet_points < $pointsNeeded) {
                    // Insert error message into the chat
                    \App\Models\Message::create([
                        'item_id' => $item->item_id,
                        'sender_id' => $admin->user_id,
                        'receiver_id' => $buyer->user_id,
                        'message' => 'Transaction failed: You do not have enough points. ' . $pointsNeeded . ' points required.',
                        'sent_at' => now(),
                    ]);

                    return response()->json([
                        'message' => 'Insufficient points',
                        'required' => $pointsNeeded,
                        'available' => $buyer->wallet_points,
                    ], 400);
                }
            }

            // Begin database transaction
            $result = DB::transaction(function () use ($item, $buyer, $admin, $pointsNeeded, $reservation, $paymentMethod) {
                $seller = User::where('user_id', $item->seller_id)->first();

                // Create transaction record
                $transaction = Transaction::create([
                    'item_id' => $item->item_id,
                    'buyer_id' => $buyer->user_id,
                    'seller_id' => $seller ? $seller->user_id : $admin->user_id,
                    'payment_method' => $paymentMethod,
                    'points_used' => $pointsNeeded, // 0 if cash/trade
                    'status' => 'completed',
                ]);

                // Transfer points (deduct from buyer, add to seller) only if using points
                if ($paymentMethod === 'points') {
                    $buyer->decrement('wallet_points', $pointsNeeded);
                    if ($seller) {
                        $seller->increment('wallet_points', $pointsNeeded);
                    }

                    // Add point records
                    \App\Models\Point::create([
                        'user_id' => $buyer->user_id,
                        'points_change' => -$pointsNeeded,
                        'reason' => 'purchase',
                        'related_item_id' => $item->item_id,
                    ]);

                    if ($seller) {
                        \App\Models\Point::create([
                            'user_id' => $seller->user_id,
                            'points_change' => $pointsNeeded,
                            'reason' => 'sale',
                            'related_item_id' => $item->item_id,
                        ]);
                    }
                }

                // Update item status to sold
                $item->update(['status' => 'sold']);

                // If a reservation existed, mark it as completed
                if ($reservation) {
                    $reservation->update(['status' => 'completed']);
                }

                // Prepare success message based on payment method
                $successChatMsg = 'Congratulations! You have successfully bought this item.';
                if ($paymentMethod === 'points') {
                    $successChatMsg = 'Congratulations! You have successfully bought this item for ' . $pointsNeeded . ' points.';
                } elseif ($paymentMethod === 'cash') {
                    $successChatMsg = 'Congratulations! You have successfully bought this item using cash.';
                } elseif ($paymentMethod === 'trade') {
                    $successChatMsg = 'Congratulations! You have successfully traded for this item.';
                }

                // Insert success message to user in chat
                \App\Models\Message::create([
                    'item_id' => $item->item_id,
                    'sender_id' => $admin->user_id,
                    'receiver_id' => $buyer->user_id,
                    'message' => $successChatMsg,
                    'sent_at' => now(),
                ]);

                return $transaction;
            });

            return response()->json([
                'message' => 'Item marked as sold successfully',
                'data' => [
                    'transaction_id' => $result->transaction_id,
                    'item_id' => $result->item_id,
                    'buyer_id' => $result->buyer_id,
                    'payment_method' => $result->payment_method,
                    'points_used' => $result->points_used,
                    'status' => $result->status,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error marking as sold', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to mark as sold',
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

    /**
     * Get points given to users (admin only)
     * GET /api/points/given
     */
    public function getPointsGiven(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $pointsGiven = \App\Models\Point::where('points_change', '>', 0)
                ->with(['user' => function ($q) {
                    $q->select('user_id', 'email');
                }, 'relatedItem' => function ($q) {
                    $q->select('item_id', 'title');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            $totalPointsGiven = $pointsGiven->sum('points_change');

            return response()->json([
                'message' => 'Points given retrieved successfully',
                'data' => $pointsGiven,
                'total_points_given' => $totalPointsGiven,
                'count' => $pointsGiven->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting points given', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve points given',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get points received by users (admin only)
     * GET /api/points/received
     */
    public function getPointsReceived(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            $pointsReceived = \App\Models\Point::where('points_change', '<', 0)
                ->with(['user' => function ($q) {
                    $q->select('user_id', 'email');
                }, 'relatedItem' => function ($q) {
                    $q->select('item_id', 'title');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            $totalPointsReceived = abs($pointsReceived->sum('points_change'));

            return response()->json([
                'message' => 'Points received retrieved successfully',
                'data' => $pointsReceived,
                'total_points_received' => $totalPointsReceived,
                'count' => $pointsReceived->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting points received', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve points received',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if points have been sent for a specific item
     * GET /api/admin/item/{item_id}/points-status
     */
    public function checkItemPointsStatus(Request $request, $item_id)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            // Check if item exists
            $item = \App\Models\Item::where('item_id', $item_id)->first();
            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Check if there are any point records for this item
            $pointRecords = \App\Models\Point::where('related_item_id', $item_id)->get();
            
            // Check if there are any transaction records for this item
            $transactionRecords = \App\Models\Transaction::where('item_id', $item_id)->get();

            // Get the most recent point transaction for this item
            $latestPointRecord = $pointRecords->sortByDesc('created_at')->first();
            
            // Check if admin has sent points for this item
            $adminSentPoints = $pointRecords->where('reason', 'sale')->isNotEmpty() || 
                             $pointRecords->where('reason', 'purchase')->isNotEmpty();

            return response()->json([
                'message' => 'Item points status retrieved successfully',
                'data' => [
                    'item_id' => $item_id,
                    'item_title' => $item->title,
                    'item_status' => $item->status,
                    'points_sent' => $adminSentPoints,
                    'point_records_count' => $pointRecords->count(),
                    'transaction_records_count' => $transactionRecords->count(),
                    'latest_point_record' => $latestPointRecord ? [
                        'point_id' => $latestPointRecord->point_id,
                        'user_id' => $latestPointRecord->user_id,
                        'points_change' => $latestPointRecord->points_change,
                        'reason' => $latestPointRecord->reason,
                        'created_at' => $latestPointRecord->created_at,
                    ] : null,
                    'all_point_records' => $pointRecords->map(function ($point) {
                        return [
                            'point_id' => $point->point_id,
                            'user_id' => $point->user_id,
                            'points_change' => $point->points_change,
                            'reason' => $point->reason,
                            'created_at' => $point->created_at,
                        ];
                    }),
                    'transaction_records' => $transactionRecords->map(function ($transaction) {
                        return [
                            'transaction_id' => $transaction->transaction_id,
                            'buyer_id' => $transaction->buyer_id,
                            'seller_id' => $transaction->seller_id,
                            'points_used' => $transaction->points_used,
                            'status' => $transaction->status,
                            'transaction_date' => $transaction->transaction_date,
                        ];
                    }),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to check item points status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cash transactions (admin only)
     * GET /api/admin/transactions/cash
     */
    public function getCashTransactions(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $transactions = Transaction::with(['item', 'buyer', 'seller'])
                ->where('payment_method', 'cash')
                ->orderBy('transaction_date', 'desc')
                ->get();

            return response()->json([
                'message' => 'Cash transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting cash transactions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve cash transactions'], 500);
        }
    }

    /**
     * Get trade transactions (admin only)
     * GET /api/admin/transactions/trade
     */
    public function getTradeTransactions(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $transactions = Transaction::with(['item', 'buyer', 'seller'])
                ->where('payment_method', 'trade')
                ->orderBy('transaction_date', 'desc')
                ->get();

            return response()->json([
                'message' => 'Trade transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting trade transactions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve trade transactions'], 500);
        }
    }

    /**
     * Get profit summary (admin only)
     * GET /api/admin/transactions/profit-summary
     */
    public function getProfitSummary(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $totalProfit = \App\Models\Item::where('status', 'sold')->sum('markup_points');

            $monthlyProfit = \App\Models\Item::where('status', 'sold')
                ->where('updated_at', '>=', now()->subMonth())
                ->sum('markup_points');

            $transactionCount = Transaction::where('status', 'completed')->count();

            return response()->json([
                'message' => 'Profit summary retrieved successfully',
                'data' => [
                    'total_profit_points' => $totalProfit,
                    'monthly_profit_points' => $monthlyProfit,
                    'completed_transactions' => $transactionCount,
                    'average_profit_per_transaction' => $transactionCount > 0 ? $totalProfit / $transactionCount : 0,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting profit summary', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve profit summary', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get sales report (admin only)
     * GET /api/admin/reports/sales
     */
    public function getSalesReport(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $totalItemsSold = Item::where('status', 'sold')->count();
            $totalItemsAcquired = Item::where('status', 'acquired')->count();
            
            $salesByMonth = Transaction::where('status', 'completed')
                ->selectRaw('DATE_FORMAT(transaction_date, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->limit(12)
                ->get();

            $recentSales = Transaction::with(['item', 'buyer', 'seller'])
                ->where('status', 'completed')
                ->orderBy('transaction_date', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'message' => 'Sales report retrieved successfully',
                'data' => [
                    'total_items_sold' => $totalItemsSold,
                    'total_items_acquired' => $totalItemsAcquired,
                    'sales_by_month' => $salesByMonth,
                    'recent_sales' => $recentSales,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting sales report', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve sales report'], 500);
        }
    }

    /**
     * Get profit report (admin only)
     * GET /api/admin/reports/profit
     */
    public function getProfitReport(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $totalMarkupProfit = \App\Models\Item::where('status', 'sold')->sum('markup_points');
            
            $profitByMonth = \App\Models\Item::where('status', 'sold')
                ->selectRaw('DATE_FORMAT(updated_at, "%Y-%m") as month, SUM(markup_points) as profit')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->limit(12)
                ->get();

            $topProfitableItems = \App\Models\Item::where('status', 'sold')
                ->where('markup_points', '>', 0)
                ->orderBy('markup_points', 'desc')
                ->limit(10)
                ->with(['seller'])
                ->get();

            return response()->json([
                'message' => 'Profit report retrieved successfully',
                'data' => [
                    'total_markup_profit' => $totalMarkupProfit,
                    'profit_by_month' => $profitByMonth,
                    'top_profitable_items' => $topProfitableItems,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting profit report', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve profit report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get category report (admin only)
     * GET /api/admin/reports/categories
     */
    public function getCategoryReport(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $categorySales = \App\Models\Category::withCount([
                'items' => function ($query) {
                    $query->where('status', 'sold');
                }
            ])
            ->with(['items' => function ($query) {
                $query->where('status', 'sold')->select('category_id', 'markup_points');
            }])
            ->get()
            ->map(function ($category) {
                $totalMarkup = $category->items->sum('markup_points');
                return [
                    'category_id' => $category->category_id,
                    'category_name' => $category->name,
                    'items_sold' => $category->items_count,
                    'total_markup_profit' => $totalMarkup,
                    'average_markup_per_item' => $category->items_count > 0 ? $totalMarkup / $category->items_count : 0,
                ];
            })
            ->sortByDesc('items_sold')
            ->values();

            $mostSoldCategory = $categorySales->first();

            return response()->json([
                'message' => 'Category report retrieved successfully',
                'data' => [
                    'category_sales' => $categorySales,
                    'most_sold_category' => $mostSoldCategory,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting category report', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve category report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get user report (admin only)
     * GET /api/admin/reports/users
     */
    public function getUserReport(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Admin access required'], 403);
            }

            $activeUsers = User::where('role', 'student')->where('is_active', true)->count();
            $totalStudents = User::where('role', 'student')->count();
            
            $topBuyers = User::where('role', 'student')
                ->withCount(['transactionsAsBuyer' => function ($query) {
                    $query->where('status', 'completed');
                }])
                ->orderBy('transactions_as_buyer_count', 'desc')
                ->limit(10)
                ->get(['user_id', 'email', 'wallet_points']);

            $topSellers = User::where('role', 'student')
                ->withCount(['transactionsAsSeller' => function ($query) {
                    $query->where('status', 'completed');
                }])
                ->orderBy('transactions_as_seller_count', 'desc')
                ->limit(10)
                ->get(['user_id', 'email', 'wallet_points']);

            $userActivityByMonth = User::where('role', 'student')
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->limit(12)
                ->get();

            return response()->json([
                'message' => 'User report retrieved successfully',
                'data' => [
                    'active_users' => $activeUsers,
                    'total_students' => $totalStudents,
                    'top_buyers' => $topBuyers,
                    'top_sellers' => $topSellers,
                    'user_activity_by_month' => $userActivityByMonth,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting user report', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve user report'], 500);
        }
    }
}
