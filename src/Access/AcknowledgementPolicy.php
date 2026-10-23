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

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Stops a member posting while they have a warning to acknowledge.
 *
 * Registered as a global policy (startDiscussion) and a discussion policy
 * (reply, which core also consults before letting a member edit or hide their
 * own posts). Both decide from the flag on the actor's own row and a cached
 * setting, so the per-discussion canReply checks on a list add no queries.
 */
class AcknowledgementPolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function startDiscussion(User $actor): ?string
    {
        return $this->mustAcknowledge($actor) ? $this->deny() : null;
    }

    public function reply(User $actor): ?string
    {
        return $this->mustAcknowledge($actor) ? $this->deny() : null;
    }

    protected function mustAcknowledge(User $actor): bool
    {
        return $actor->has_unacknowledged_warnings
            && $this->settings->get('fof-moderator-warnings.require_acknowledgement');
    }
}
