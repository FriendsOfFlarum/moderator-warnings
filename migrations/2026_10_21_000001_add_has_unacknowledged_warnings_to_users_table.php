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

use Flarum\Database\Migration;

// Kept on the user row, like flarum/suspend's suspended_until, so the posting
// block can be decided from the actor that is already loaded on every request
// rather than by querying the warnings table.
return Migration::addColumns('users', [
    'has_unacknowledged_warnings' => ['boolean', 'default' => false],
]);
