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

/**
 * The order of the warnings list. The dates deliberately don't follow the ids, so neither
 * ascending nor descending id order can pass for a date order.
 */
class ListSortTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $warning = fn (int $id, int $daysAgo) => [
            'id' => $id,
            'user_id' => 2,
            'created_user_id' => 1,
            'private_comment' => '',
            'public_comment' => '<t><p>Warning '.$id.'</p></t>',
            'strikes' => 1,
            'created_at' => Carbon::now()->subDays($daysAgo),
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
            Warning::class => [
                $warning(1, 2),
                $warning(2, 4),
                $warning(3, 1),
                $warning(4, 3),
            ],
        ]);
    }

    private function listIds(array $query): array
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 1])
                ->withQueryParams($query)
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        return array_map('intval', array_column($body['data'], 'id'));
    }

    #[Test]
    public function the_list_is_newest_first_by_default()
    {
        $this->assertSame([3, 1, 4, 2], $this->listIds([]));
    }

    #[Test]
    public function a_users_list_is_newest_first_by_default()
    {
        // The request the profile's warning list makes.
        $this->assertSame([3, 1, 4, 2], $this->listIds(['filter' => ['userId' => '2']]));
    }

    #[Test]
    public function the_list_can_be_sorted_newest_first()
    {
        $this->assertSame([3, 1, 4, 2], $this->listIds(['sort' => '-createdAt']));
    }

    #[Test]
    public function the_list_can_be_sorted_oldest_first()
    {
        $this->assertSame([2, 4, 1, 3], $this->listIds(['sort' => 'createdAt']));
    }
}
