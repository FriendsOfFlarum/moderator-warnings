<?php

namespace FoF\ModeratorWarnings\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * @implements FilterInterface<DatabaseSearchState>
 */
class UserIdFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'userId';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $userId = (int) $this->asString($value);

        /** @var DatabaseSearchState $state */
        $state->getQuery()->where('user_id', $negate ? '!=' : '=', $userId);
    }
}
