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
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\Post\Post;
use Flarum\User\User;
use FoF\ModeratorWarnings\Event\WarningWasHidden;
use FoF\ModeratorWarnings\Event\WarningWasRestored;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property Carbon $created_at
 * @property Carbon|null $hidden_at
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
 * @property bool $requires_acknowledgement
 * @property Carbon|null $acknowledged_at
 */
class Warning extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    protected $table = 'warnings';

    protected $casts = [
        'created_at' => 'datetime',
        'hidden_at' => 'datetime',
        'requires_acknowledgement' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

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

    protected static function booted(): void
    {
        // Keep the warned user's has_unacknowledged_warnings flag in step with
        // their warnings, whichever code path creates, hides, restores,
        // acknowledges, reassigns or deletes one. Warnings that never required
        // acknowledgement can't change the flag, so they cost nothing here.
        static::saved(function (self $warning) {
            // `saved` fires before the original attributes are synced, so
            // getOriginal() still holds the values from before this save.
            if (! $warning->requires_acknowledgement && ! $warning->getOriginal('requires_acknowledgement')) {
                return;
            }

            if ($warning->wasRecentlyCreated
                || $warning->wasChanged(['user_id', 'requires_acknowledgement', 'hidden_at', 'acknowledged_at'])) {
                static::syncAcknowledgementFlag($warning->user_id);

                if ($warning->wasChanged('user_id') && $warning->getOriginal('user_id')) {
                    static::syncAcknowledgementFlag($warning->getOriginal('user_id'));
                }
            }
        });

        static::deleted(function (self $warning) {
            if ($warning->requires_acknowledgement) {
                static::syncAcknowledgementFlag($warning->user_id);
            }
        });
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

    /**
     * Hide the warning, retracting it from the warned user.
     *
     * Guarded on the current state so re-hiding an already-hidden warning keeps the
     * original timestamp and actor, as core's Post::hide() and Discussion::hide() do.
     */
    public function hide(?User $actor = null): static
    {
        if (! $this->hidden_at) {
            $this->hidden_at = Carbon::now();
            $this->hidden_user_id = $actor?->id;

            $this->raise(new WarningWasHidden($this, $actor));
        }

        return $this;
    }

    /**
     * Restore a hidden warning, reinstating it against the warned user.
     */
    public function restore(): static
    {
        if ($this->hidden_at !== null) {
            $this->hidden_at = null;
            $this->hidden_user_id = null;

            $this->raise(new WarningWasRestored($this));
        }

        return $this;
    }

    /**
     * Record that the warned user has acknowledged the warning.
     *
     * Guarded like hide(), so acknowledging twice keeps the original timestamp.
     */
    public function acknowledge(): static
    {
        if (! $this->acknowledged_at) {
            $this->acknowledged_at = Carbon::now();
        }

        return $this;
    }

    /**
     * Recompute whether the user has any warning still awaiting acknowledgement.
     */
    public static function syncAcknowledgementFlag(int $userId): void
    {
        // One statement, so two warnings acknowledged at the same moment can't
        // interleave a stale read with a later write and leave the flag set.
        $pending = static::query()
            ->selectRaw('count(*) > 0')
            ->where('user_id', $userId)
            ->where('requires_acknowledgement', true)
            ->whereNull('acknowledged_at')
            ->whereNull('hidden_at');

        User::query()->whereKey($userId)->toBase()->update(['has_unacknowledged_warnings' => $pending]);
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
