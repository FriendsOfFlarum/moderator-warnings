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
 * Narrows warnings to removed (`filter[hidden]=1`) or active (`filter[hidden]=0`) ones.
 *
 * Only ever narrows the searcher's visibility scope, never widens it: actors who can't
 * see removed warnings get an empty result for `hidden=1`.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class HiddenFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'hidden';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $hidden = $this->asBool($value) !== $negate;

        /** @var DatabaseSearchState $state */
        if ($hidden) {
            $state->getQuery()->whereNotNull('warnings.hidden_at');
        } else {
            $state->getQuery()->whereNull('warnings.hidden_at');
        }
    }
}
