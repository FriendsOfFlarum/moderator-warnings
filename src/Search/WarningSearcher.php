<?php

namespace FoF\ModeratorWarnings\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use Illuminate\Database\Eloquent\Builder;

class WarningSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Warning::whereVisibleTo($actor)->select('warnings.*');
    }
}
