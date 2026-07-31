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

namespace FoF\ModeratorWarnings;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\User\User;
use FoF\ModeratorWarnings\Access\UserPolicy;
use FoF\ModeratorWarnings\Access\WarningPolicy;
use FoF\ModeratorWarnings\Api\PostResourceFields;
use FoF\ModeratorWarnings\Model\Warning;
use FoF\ModeratorWarnings\Notification\WarningBlueprint;
use FoF\ModeratorWarnings\Provider\WarningProvider;
use FoF\ModeratorWarnings\Search\Filter\UserIdFilter;
use FoF\ModeratorWarnings\Search\WarningSearcher;
use Illuminate\Database\Eloquent\Builder;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\Model(Post::class))
        ->hasMany('warnings', Warning::class, 'post_id'),

    (new Extend\Model(User::class))
        ->hasMany('warnings', Warning::class, 'user_id'),

    (new Extend\View())
        ->namespace('fof-moderator-warnings', __DIR__.'/views'),

    (new Extend\Notification())
        ->type(WarningBlueprint::class, ['alert', 'email']),

    (new Extend\ApiResource(Resource\UserResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewWarnings')
                ->get(fn (User $user, Context $context) => $context->getActor()->can('viewWarnings', $user)),
            Schema\Boolean::make('canManageWarnings')
                ->get(fn (User $user, Context $context) => $context->getActor()->can('user.manageWarnings')),
            Schema\Boolean::make('canDeleteWarnings')
                ->get(fn (User $user, Context $context) => $context->getActor()->can('user.deleteWarnings')),
            Schema\Integer::make('visibleWarningCount')
                // Batched into one aggregate query for all serialized users,
                // instead of one COUNT query per user.
                ->countRelation('warnings', function (Builder $query) {
                    $query->whereNull('hidden_at');
                }),
        ]),

    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(PostResourceFields::class)
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], function (Endpoint\Index|Endpoint\Show $endpoint) {
            // Eager-load the warnings' own relations alongside the include:
            // without this each warning loads its users (and their groups)
            // individually while being serialized.
            return $endpoint
                ->addDefaultInclude(['warnings', 'warnings.warnedUser', 'warnings.addedByUser'])
                ->eagerLoadWhenIncluded([
                    'warnings' => ['warnings.warnedUser', 'warnings.addedByUser'],
                ]);
        }),

    (new Extend\Policy())
        ->modelPolicy(User::class, UserPolicy::class)
        ->modelPolicy(Warning::class, WarningPolicy::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Warning::class, WarningSearcher::class)
        ->addFilter(WarningSearcher::class, UserIdFilter::class),

    (new Extend\ServiceProvider())
        ->register(WarningProvider::class),

    new Extend\ApiResource(Api\Resource\WarningResource::class),
];
