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
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use PHPUnit\Framework\Attributes\Test;

/**
 * The frontend inserts a newly created warning straight into the list, the post footer
 * and the profile badge instead of reloading the page. That only works if the create
 * response carries everything those views render, so this pins that contract.
 */
class CreateResponsePayloadTest extends TestCase
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
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Test Discussion', 'created_at' => Carbon::now(), 'user_id' => 2, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Test post</p></t>'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4],
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
                ['group_id' => 4, 'permission' => 'user.deleteWarnings'],
            ],
        ]);
    }

    protected function createWarning(array $attributes = [], array $relationships = []): array
    {
        $data = [
            'type' => 'warnings',
            'attributes' => array_merge([
                'userId' => 2,
                'publicComment' => 'Please read the rules',
                'strikes' => 2,
            ], $attributes),
        ];

        if ($relationships) {
            $data['relationships'] = $relationships;
        }

        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => ['data' => $data],
            ])
        );

        $body = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $body);

        return json_decode($body, true);
    }

    #[Test]
    public function create_response_carries_the_attributes_the_list_renders()
    {
        $body = $this->createWarning();

        $attributes = $body['data']['attributes'];

        // WarningListItem renders the strike count, the creation time and the comment.
        $this->assertEquals(2, $attributes['strikes']);
        $this->assertArrayHasKey('createdAt', $attributes);
        $this->assertStringContainsString('Please read the rules', $attributes['publicComment']);

        // A brand new warning is never hidden, so the controls render Remove (not Restore).
        $this->assertFalse($attributes['isHidden']);
    }

    #[Test]
    public function create_response_includes_the_added_by_user()
    {
        // WarningListItem shows the moderator's avatar and username as the item heading,
        // so the relationship has to be resolvable from the create response alone.
        $body = $this->createWarning();

        $this->assertEquals(
            ['type' => 'users', 'id' => '3'],
            $body['data']['relationships']['addedByUser']['data']
        );

        $included = array_column($body['included'] ?? [], null, 'id');

        $this->assertArrayHasKey('3', $included, 'Expected the addedByUser to be included.');
        $this->assertEquals('moderator', $included['3']['attributes']['username']);
    }

    #[Test]
    public function create_response_includes_the_post_relationship_when_warning_a_post()
    {
        // Warning from the post controls inserts into that post's footer, which needs
        // the post relationship on the returned warning.
        $body = $this->createWarning([], [
            'post' => ['data' => ['type' => 'posts', 'id' => '1']],
        ]);

        $this->assertEquals(
            ['type' => 'posts', 'id' => '1'],
            $body['data']['relationships']['post']['data']
        );
    }

    #[Test]
    public function visible_warning_count_increases_when_a_warning_is_created()
    {
        // The profile badge reads this attribute. The frontend adjusts it locally rather
        // than refetching, so the server must agree on what it counts.
        $this->assertEquals(0, $this->visibleWarningCount());

        $this->createWarning();

        $this->assertEquals(1, $this->visibleWarningCount());
    }

    #[Test]
    public function visible_warning_count_ignores_hidden_warnings()
    {
        $body = $this->createWarning();
        $id = $body['data']['id'];

        $this->assertEquals(1, $this->visibleWarningCount());

        $this->send(
            $this->request('PATCH', "/api/warnings/$id", [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => (string) $id,
                        'attributes' => ['isHidden' => true],
                    ],
                ],
            ])
        );

        $this->assertEquals(0, $this->visibleWarningCount(), 'Hiding a warning must drop the badge count.');
    }

    #[Test]
    public function visible_warning_count_decreases_when_a_warning_is_deleted()
    {
        $body = $this->createWarning();
        $id = $body['data']['id'];

        $this->assertEquals(1, $this->visibleWarningCount());

        // Hard delete requires the warning to be hidden first.
        $this->send(
            $this->request('PATCH', "/api/warnings/$id", [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => (string) $id,
                        'attributes' => ['isHidden' => true],
                    ],
                ],
            ])
        );

        $response = $this->send(
            $this->request('DELETE', "/api/warnings/$id", ['authenticatedAs' => 3])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $this->assertNull(Warning::find($id), 'The warning should be gone from the database.');
        $this->assertEquals(0, $this->visibleWarningCount());
    }

    protected function visibleWarningCount(): int
    {
        $response = $this->send(
            $this->request('GET', '/api/users/2', ['authenticatedAs' => 3])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        return $body['data']['attributes']['visibleWarningCount'];
    }
}
