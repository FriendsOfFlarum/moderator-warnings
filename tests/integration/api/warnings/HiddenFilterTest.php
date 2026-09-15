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

class HiddenFilterTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'moderator', 'email' => 'moderator@machine.local', 'is_email_confirmed' => true],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4],
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
            ],
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Active</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
                ['id' => 2, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Removed</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 1],
            ],
        ]);
    }

    private function listIds(int $actorId, array $filter): array
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => $actorId])
                ->withQueryParams(['filter' => $filter])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        return array_map('intval', array_column($body['data'], 'id'));
    }

    #[Test]
    public function managers_can_list_only_removed_warnings()
    {
        $this->assertSame([2], $this->listIds(3, ['hidden' => '1']));
    }

    #[Test]
    public function managers_can_list_only_active_warnings()
    {
        $this->assertSame([1], $this->listIds(3, ['hidden' => '0']));
    }

    #[Test]
    public function the_filter_can_be_negated()
    {
        $this->assertSame([1], $this->listIds(3, ['-hidden' => '1']));
    }

    #[Test]
    public function the_filter_never_reveals_removed_warnings_to_their_recipient()
    {
        $this->assertSame([], $this->listIds(2, ['hidden' => '1']));
    }
}
