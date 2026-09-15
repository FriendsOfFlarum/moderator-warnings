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

namespace FoF\ModeratorWarnings\Forum\Content;

use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Guards the forum-wide warnings page on direct loads.
 *
 * The frontend hides the link and the page from actors without the permission, but a
 * typed or shared URL still reaches the server. Guests get the login prompt (401), and
 * registered users without the permission a 403, the same way core's AssertRegistered
 * guards `/flags`.
 */
class AssertCanViewAllWarnings
{
    public function __invoke(Document $document, Request $request): void
    {
        $actor = RequestUtil::getActor($request);

        $actor->assertRegistered();
        $actor->assertCan('user.viewWarnings');
    }
}
