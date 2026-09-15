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
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use PHPUnit\Framework\Attributes\Test;

/**
 * `user.viewWarnings` without `user.manageWarnings`.
 *
 * The UserPolicy lets these users view any user's warnings (and the canViewWarnings
 * attribute says so), but the visibility scope used to narrow them down to their own, so
 * another user's list came back empty. They now see every active warning, while removed
 * warnings and private comments stay with those who can manage warnings.
 */
class ViewPermissionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'warned_user', 'email' => 'warned@machine.local', 'is_email_confirmed' => true],
                ['id' => 4, 'username' => 'viewer', 'email' => 'viewer@machine.local', 'is_email_confirmed' => true],
            ],
            Group::class => [
                ['id' => 5, 'name_singular' => 'Viewer', 'name_plural' => 'Viewers'],
            ],
            'group_user' => [
                ['user_id' => 4, 'group_id' => 5],
            ],
            Permission::class => [
                ['group_id' => 5, 'permission' => 'user.viewWarnings'],
            ],
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '<t><p>Private 1</p></t>', 'public_comment' => '<t><p>Warning 1</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
                ['id' => 2, 'user_id' => 3, 'created_user_id' => 1, 'private_comment' => '<t><p>Private 2</p></t>', 'public_comment' => '<t><p>Warning 2</p></t>', 'strikes' => 2, 'created_at' => Carbon::now()],
                ['id' => 3, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Removed warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 1],
            ],
        ]);
    }

    private function listIds(int $actorId, array $filter = []): array
    {
        $request = $this->request('GET', '/api/warnings', ['authenticatedAs' => $actorId]);

        if ($filter) {
            $request = $request->withQueryParams(['filter' => $filter]);
        }

        $response = $this->send($request);

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $ids = array_map('intval', array_column($body['data'], 'id'));
        sort($ids);

        return $ids;
    }

    #[Test]
    public function viewers_can_list_every_active_warning()
    {
        $this->assertSame([1, 2], $this->listIds(4));
    }

    #[Test]
    public function viewers_can_list_another_users_warnings()
    {
        $this->assertSame([2], $this->listIds(4, ['userId' => 3]));
    }

    #[Test]
    public function viewers_cannot_see_removed_warnings()
    {
        $this->assertSame([1], $this->listIds(4, ['userId' => 2]));
        $this->assertSame([], $this->listIds(4, ['hidden' => '1']));
    }

    #[Test]
    public function viewers_do_not_receive_private_comments()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 4])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertNotEmpty($body['data']);

        foreach ($body['data'] as $warning) {
            $this->assertArrayNotHasKey('privateComment', $warning['attributes']);
        }
    }

    #[Test]
    public function regular_users_still_only_see_their_own_active_warnings()
    {
        $this->assertSame([1], $this->listIds(2));
    }
}
