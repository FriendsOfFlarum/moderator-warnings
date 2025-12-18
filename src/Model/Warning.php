<?php

/*
 * This file is part of fof/moderator-warnings
 *
 * Copyright (c) Alexander Skvortsov.
 * Copyright (c) FriendsOfFlarum
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace FoF\ModeratorWarnings\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Formatter\Formatter;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property Carbon $created_at
 * @property Carbon $hidden_at
 * @property User $addedByUser
 * @property User $warnedUser
 * @property User|null $hiddenByUser
 * @property int $user_id
 * @property Post|null $post
 * @property int|null $post_id
 * @property string $public_comment
 * @property string $private_comment
 * @property int $strikes
 * @property int|null $hidden_user_id
 * @property int|null $created_user_id
 */
class Warning extends AbstractModel
{
    use ScopeVisibilityTrait;

    protected $table = 'warnings';

    protected $casts = ['created_at' => 'datetime', 'hidden_at' => 'datetime'];

    /**
     * The text formatter instance.
     *
     * @var \Flarum\Formatter\Formatter
     */
    protected static $formatter;

    /**
     * Get the text formatter instance.
     *
     * @return \Flarum\Formatter\Formatter
     */
    public static function getFormatter()
    {
        return static::$formatter;
    }

    /**
     * Set the text formatter instance.
     */
    public static function setFormatter(Formatter $formatter): void
    {
        static::$formatter = $formatter;
    }

    public function warnedUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function addedByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function hiddenByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_user_id');
    }

    public function post(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public static function strikesForUser(User $user): int
    {
        return self::where('user_id', $user->id)->get()->filter(function ($warning) {
            return $warning->hidden_at === null;
        })->map(function ($warning) {
            return $warning->strikes;
        })->sum();
    }

    /**
     * Scope query to only include warnings visible to the actor.
     */
    public function scopeWhereVisibleTo(Builder $query, User $actor): Builder
    {
        // Users with manage permissions can see all warnings (including hidden)
        if ($actor->can('user.manageWarnings')) {
            return $query;
        }

        // Regular users can see:
        // 1. Their own non-hidden warnings
        // 2. Warnings on posts they authored (non-hidden)
        if ($actor->exists) {
            return $query->where(function ($q) use ($actor) {
                // Their own warnings
                $q->where('user_id', $actor->id);

                // OR warnings on their posts
                $q->orWhereHas('post', function ($postQuery) use ($actor) {
                    $postQuery->where('user_id', $actor->id);
                });
            })->whereNull('hidden_at');
        }

        // Guests can't see any warnings
        return $query->whereRaw('1 = 0');
    }
}
