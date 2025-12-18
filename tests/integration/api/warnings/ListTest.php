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

class ListTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'warned_user', 'email' => 'warned@example.com', 'is_email_confirmed' => true],
                ['id' => 4, 'username' => 'moderator', 'email' => 'moderator@example.com', 'is_email_confirmed' => true],
            ],
            Warning::class => [
                // Warning for user 2 (normal user)
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Warning 1</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => null],
                // Warning for user 3 (different user)
                ['id' => 2, 'user_id' => 3, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Warning 2</p></t>', 'strikes' => 2, 'created_at' => Carbon::now(), 'hidden_at' => null],
                // Hidden warning for user 2
                ['id' => 3, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Hidden Warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 1],
            ],
            'group_user' => [
                ['user_id' => 4, 'group_id' => 4], // moderator group
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
            ],
        ]);
    }

    #[Test]
    public function user_can_only_see_their_own_non_hidden_warnings()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 2,
            ])
        );

        $body = $response->getBody()->getContents();

        $this->assertEquals(200, $response->getStatusCode());

        $bodyData = json_decode($body, true);

        // Should only see their own non-hidden warning (ID 1)
        $this->assertCount(1, $bodyData['data']);
        $this->assertEquals(1, $bodyData['data'][0]['id']);
        $this->assertEquals(2, $bodyData['data'][0]['attributes']['userId']);
    }

    #[Test]
    public function user_cannot_see_other_users_warnings()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 2,
            ])->withQueryParams(['filter' => ['userId' => 3]])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function user_cannot_see_hidden_warnings()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 2,
            ])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        // Should not include hidden warning (ID 3)
        $ids = array_column($body['data'], 'id');
        $this->assertNotContains(3, $ids);
    }

    #[Test]
    public function moderator_can_see_all_warnings_including_hidden()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 4,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Moderator should see all warnings (1, 2, 3)
        $this->assertCount(3, $body['data']);
        $ids = array_column($body['data'], 'id');
        $this->assertContains('1', $ids);
        $this->assertContains('2', $ids);
        $this->assertContains('3', $ids);
    }

    #[Test]
    public function moderator_can_filter_warnings_by_userId()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 4,
            ])->withQueryParams(['filter' => ['userId' => 2]])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Should see warnings 1 and 3 for user 2
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $warning) {
            $this->assertEquals(2, $warning['attributes']['userId']);
        }
    }

    #[Test]
    public function admin_can_see_all_warnings()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', [
                'authenticatedAs' => 1,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertCount(3, $body['data']);
    }

    #[Test]
    public function guest_cannot_list_warnings()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings')
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Guests should see no warnings
        $this->assertCount(0, $body['data']);
    }
}
