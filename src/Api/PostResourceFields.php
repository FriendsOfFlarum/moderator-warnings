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

namespace FoF\ModeratorWarnings\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;

class PostResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Relationship\ToMany::make('warnings')
                ->type('warnings')
                ->includable()
                ->get(function (Post $post, Context $context) {
                    $actor = $context->getActor();
                    $author = $post->user;

                    // Only show warnings if the actor is the post author or has permission to view warnings
                    if (! $author || ! ($actor->id === $author->id || $actor->can('viewWarnings', $author))) {
                        return [];
                    }

                    // Load warnings with visibility scope applied
                    return $post->warnings()->whereVisibleTo($actor)->get()->all();
                }),
        ];
    }
}
