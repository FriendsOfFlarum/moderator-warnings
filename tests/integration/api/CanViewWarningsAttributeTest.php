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

namespace FoF\ModeratorWarnings\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The canViewWarnings attribute is relative to the serialized user: "can the
 * request actor view THIS user's warnings". The frontend relies on these
 * exact semantics to decide whether the profile Warnings link is shown
 * (see https://github.com/FriendsOfFlarum/moderator-warnings/issues/6).
 */
class CanViewWarningsAttributeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                [
                    'id' => 3,
                    'username' => 'regular3',
                    'email' => 'regular3@machine.local',
                    'is_email_confirmed' => 1,
                    'password' => 'foobar',
                ],
                [
                    'id' => 4,
                    'username' => 'moderator4',
                    'email' => 'moderator4@machine.local',
                    'is_email_confirmed' => 1,
                    'password' => 'foobar',
                ],
            ],
            Group::class => [
                ['id' => 5, 'name_singular' => 'Warned Mod', 'name_plural' => 'Warned Mods'],
            ],
            'group_user' => [
                ['user_id' => 4, 'group_id' => 5],
            ],
            Permission::class => [
                ['group_id' => 5, 'permission' => 'user.viewWarnings'],
            ],
        ]);
    }

    private function attributeFor(int $actorId, int $targetId): bool
    {
        $response = $this->send(
            $this->request('GET', '/api/users/'.$targetId, ['authenticatedAs' => $actorId])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        return (bool) ($body['data']['attributes']['canViewWarnings'] ?? false);
    }

    #[Test]
    public function regular_users_can_view_their_own_warnings()
    {
        $this->assertTrue($this->attributeFor(2, 2));
    }

    #[Test]
    public function regular_users_cannot_view_other_users_warnings()
    {
        $this->assertFalse($this->attributeFor(2, 3));
    }

    #[Test]
    public function users_with_the_permission_can_view_other_users_warnings()
    {
        $this->assertTrue($this->attributeFor(4, 3));
    }
}
