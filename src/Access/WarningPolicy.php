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

namespace FoF\ModeratorWarnings\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;

class WarningPolicy extends AbstractPolicy
{
    public function edit(User $actor, Warning $warning): ?string
    {
        if ($actor->can('user.manageWarnings')) {
            return $this->allow();
        }

        return null;
    }

    public function delete(User $actor, Warning $warning): ?string
    {
        if ($actor->can('user.deleteWarnings')) {
            return $this->allow();
        }

        return null;
    }

    public function acknowledge(User $actor, Warning $warning): ?string
    {
        // Only the warned user can acknowledge, and only a warning that asked for
        // it. Denied outright for everyone else, admins included: an admin
        // acknowledging on someone's behalf would defeat the point.
        if ($actor->id == $warning->user_id && $warning->requires_acknowledgement && ! $warning->hidden_at) {
            return $this->allow();
        }

        return $this->deny();
    }
}
