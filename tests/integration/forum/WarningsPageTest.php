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

namespace FoF\ModeratorWarnings\Tests\integration\forum;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The forum-wide warnings page: its route guard, and the forum attribute the frontend
 * uses to decide whether to link to it.
 */
class WarningsPageTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'viewer', 'email' => 'viewer@machine.local', 'is_email_confirmed' => true],
            ],
            Group::class => [
                ['id' => 5, 'name_singular' => 'Viewer', 'name_plural' => 'Viewers'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 5],
            ],
            Permission::class => [
                ['group_id' => 5, 'permission' => 'user.viewWarnings'],
            ],
        ]);
    }

    private function forumAttribute(?int $actorId): mixed
    {
        $response = $this->send(
            $this->request('GET', '/api', $actorId ? ['authenticatedAs' => $actorId] : [])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        return $body['data']['attributes']['canViewAllWarnings'] ?? null;
    }

    #[Test]
    public function guests_are_asked_to_log_in()
    {
        $response = $this->send($this->request('GET', '/warnings'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function users_without_the_permission_are_refused()
    {
        $response = $this->send(
            $this->request('GET', '/warnings', ['authenticatedAs' => 2])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function users_with_the_permission_can_open_the_page()
    {
        $response = $this->send(
            $this->request('GET', '/warnings', ['authenticatedAs' => 3])
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function admins_can_open_the_page()
    {
        $response = $this->send(
            $this->request('GET', '/warnings', ['authenticatedAs' => 1])
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function the_forum_attribute_follows_the_permission()
    {
        $this->assertTrue($this->forumAttribute(1));
        $this->assertTrue($this->forumAttribute(3));
        $this->assertFalse($this->forumAttribute(2));
        $this->assertFalse($this->forumAttribute(null));
    }
}
