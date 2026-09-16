<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's view of one thread: its name, whether it is pinned or
 * archived, and when they last cleared it.
 *
 * A thread is an item and the other party. The row is per user, so each side
 * keeps its own name and its own pin - the store tidying its inbox never
 * rearranges the student's.
 */
class ConversationSetting extends Model
{
    protected $table = 'conversation_settings';

    protected $fillable = [
        'user_id',
        'item_id',
        'other_user_id',
        'custom_name',
        'is_pinned',
        'pinned_at',
        'is_archived',
        'cleared_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_archived' => 'boolean',
        'pinned_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    /** The row for this person and thread, created blank if there is none yet. */
    public static function forThread(int $userId, int $itemId, int $otherUserId): self
    {
        return static::firstOrCreate([
            'user_id' => $userId,
            'item_id' => $itemId,
            'other_user_id' => $otherUserId,
        ]);
    }

    /** What the clients read: the same keys the conversation list carries. */
    public function toClientArray(): array
    {
        return [
            'item_id' => (int) $this->item_id,
            'other_user_id' => (int) $this->other_user_id,
            'custom_name' => $this->custom_name,
            'is_pinned' => (bool) $this->is_pinned,
            'pinned_at' => $this->pinned_at,
            'is_archived' => (bool) $this->is_archived,
            'cleared_at' => $this->cleared_at,
        ];
    }
}
