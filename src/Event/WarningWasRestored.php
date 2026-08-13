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

namespace FoF\ModeratorWarnings\Event;

use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;

/**
 * A previously hidden warning was restored, reinstating it against the warned user.
 */
class WarningWasRestored
{
    public function __construct(
        public Warning $warning,
        public ?User $actor = null
    ) {
    }
}
