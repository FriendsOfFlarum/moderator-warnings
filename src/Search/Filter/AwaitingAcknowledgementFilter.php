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

namespace FoF\ModeratorWarnings\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * Warnings that still need acknowledging: the ones the warned user is shown
 * when they next load the forum.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class AwaitingAcknowledgementFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'awaitingAcknowledgement';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $pending = function ($query) {
            $query->where('requires_acknowledgement', true)
                ->whereNull('acknowledged_at')
                ->whereNull('hidden_at');
        };

        // `awaitingAcknowledgement=0`, like `-awaitingAcknowledgement`, asks for
        // every warning that is NOT waiting.
        /** @var DatabaseSearchState $state */
        $this->asBool($value) !== $negate
            ? $state->getQuery()->where($pending)
            : $state->getQuery()->whereNot($pending);
    }
}
