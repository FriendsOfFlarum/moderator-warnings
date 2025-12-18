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

namespace FoF\ModeratorWarnings\Tests\integration\api\warnings;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use PHPUnit\Framework\Attributes\Test;

class DeleteTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'moderator', 'email' => 'moderator@example.com', 'is_email_confirmed' => true],
                ['id' => 4, 'username' => 'admin2', 'email' => 'admin2@example.com', 'is_email_confirmed' => true],
            ],
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Test Warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
                ['id' => 2, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Hidden Warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4], // moderator group
                ['user_id' => 4, 'group_id' => 1], // admin group
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
                ['group_id' => 1, 'permission' => 'user.deleteWarnings'],
            ],
        ]);
    }

    #[Test]
    public function user_with_delete_permission_can_delete_hidden_warning()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/warnings/2', [
                'authenticatedAs' => 4,
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());

        // Verify deleted from database
        $this->assertNull(Warning::find(2));
    }

    #[Test]
    public function moderator_without_delete_permission_cannot_delete()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/warnings/2', [
                'authenticatedAs' => 3,
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());

        // Verify still in database
        $this->assertNotNull(Warning::find(2));
    }

    #[Test]
    public function normal_user_cannot_delete_their_own_warning()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/warnings/1', [
                'authenticatedAs' => 2,
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());

        // Verify still in database
        $this->assertNotNull(Warning::find(1));
    }

    #[Test]
    public function guest_cannot_delete_warning()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/warnings/1')
        );

        $this->assertEquals(400, $response->getStatusCode());

        $this->assertNotNull(Warning::find(1));
    }

    #[Test]
    public function cannot_delete_nonexistent_warning()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/warnings/999', [
                'authenticatedAs' => 4,
            ])
        );

        $this->assertEquals(404, $response->getStatusCode());
    }
}
