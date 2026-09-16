<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something that happened to a thread itself, rather than in it.
 *
 * Today that is one thing: a rename. It is written for the person who did it
 * and read back only by them, so the store naming a thread "Calculator -
 * refund" never shows the student anything.
 */
class ConversationEvent extends Model
{
    /** Written once and never touched again. */
    public const UPDATED_AT = null;

    /** The thread was given a name, or had its name taken away. */
    public const KIND_RENAMED = 'renamed';

    /** How many of these one thread keeps before the oldest are dropped. */
    public const KEEP_PER_THREAD = 30;

    protected $table = 'conversation_events';

    protected $fillable = [
        'user_id',
        'item_id',
        'other_user_id',
        'kind',
        'name',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'item_id' => 'integer',
        'other_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Record one, and keep the thread's history from growing without end.
     */
    public static function record(int $userId, int $itemId, int $otherUserId, ?string $name): self
    {
        $event = static::create([
            'user_id' => $userId,
            'item_id' => $itemId,
            'other_user_id' => $otherUserId,
            'kind' => self::KIND_RENAMED,
            'name' => $name,
        ]);

        $keep = static::forThread($userId, $itemId, $otherUserId)
            ->orderByDesc('id')
            ->limit(self::KEEP_PER_THREAD)
            ->pluck('id');

        static::forThread($userId, $itemId, $otherUserId)
            ->whereNotIn('id', $keep)
            ->delete();

        return $event;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public static function forThread(int $userId, int $itemId, int $otherUserId)
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('item_id', $itemId)
            ->where('other_user_id', $otherUserId);
    }

    /** @return array{id: int, kind: string, name: ?string, at: ?string} */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
