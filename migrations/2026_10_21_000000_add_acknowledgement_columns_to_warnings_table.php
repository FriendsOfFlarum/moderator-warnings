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

return Migration::addColumns('warnings', [
    'requires_acknowledgement' => ['boolean', 'default' => false],
    'acknowledged_at' => ['dateTime', 'nullable' => true],
]);
