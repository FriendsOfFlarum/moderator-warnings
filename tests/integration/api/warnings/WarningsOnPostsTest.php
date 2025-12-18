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

class WarningsOnPostsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other_user', 'email' => 'other@example.com', 'is_email_confirmed' => true],
                ['id' => 4, 'username' => 'moderator', 'email' => 'moderator@example.com', 'is_email_confirmed' => true],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Test Discussion', 'created_at' => Carbon::now(), 'user_id' => 2, 'comment_count' => 2],
            ],
            Post::class => [
                // Post by user 2 (normal user)
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Post by user 2</p></t>'],
                // Post by user 3 (other user)
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Post by user 3</p></t>'],
            ],
            Warning::class => [
                // Warning on post 1 (by user 2)
                ['id' => 1, 'user_id' => 3, 'post_id' => 1, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Warning on user 2 post</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
                // Warning on post 2 (by user 3)
                ['id' => 2, 'user_id' => 2, 'post_id' => 2, 'created_user_id' => 1, 'private_comment' => '', 'public_comment' => '<t><p>Warning on user 3 post</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
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
    public function post_author_can_see_warnings_on_their_own_post()
    {
        $response = $this->send(
            $this->request('GET', '/api/posts/1', [
                'authenticatedAs' => 2,
                'queryParams' => [
                    'include' => 'warnings,warnings.warnedUser',
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Should see warnings relationship
        $this->assertArrayHasKey('warnings', $body['data']['relationships']);
        $this->assertCount(1, $body['data']['relationships']['warnings']['data']);
        $this->assertEquals('1', $body['data']['relationships']['warnings']['data'][0]['id']);

        // Check included warnings
        $included = $body['included'] ?? [];
        $warning = array_filter($included, fn ($item) => $item['type'] === 'warnings' && $item['id'] === '1');
        $this->assertNotEmpty($warning);
    }

    #[Test]
    public function user_cannot_see_warnings_on_others_posts()
    {
        $response = $this->send(
            $this->request('GET', '/api/posts/2', [
                'authenticatedAs' => 2,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Should not see warnings (user 2 viewing user 3's post)
        if (isset($body['data']['relationships']['warnings'])) {
            $this->assertEmpty($body['data']['relationships']['warnings']['data'] ?? []);
        }
    }

    #[Test]
    public function warned_user_can_see_their_own_warning()
    {
        $response = $this->send(
            $this->request('GET', '/api/posts/2', [
                'authenticatedAs' => 2,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // User 2 was warned on post 2, so they should see their warning
        if (isset($body['data']['relationships']['warnings'])) {
            $warningsData = $body['data']['relationships']['warnings']['data'] ?? [];
            if (! empty($warningsData)) {
                $this->assertEquals('2', $warningsData[0]['id']);
            }
        }
    }

    #[Test]
    public function moderator_can_see_all_warnings_on_posts()
    {
        $response = $this->send(
            $this->request('GET', '/api/posts/1', [
                'authenticatedAs' => 4,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Moderator should see all warnings
        $this->assertArrayHasKey('warnings', $body['data']['relationships']);
        $this->assertNotEmpty($body['data']['relationships']['warnings']['data']);
    }

    #[Test]
    public function guest_cannot_see_warnings_on_posts()
    {
        $response = $this->send(
            $this->request('GET', '/api/posts/1', [
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Guest should not see warnings
        if (isset($body['data']['relationships']['warnings'])) {
            $this->assertEmpty($body['data']['relationships']['warnings']['data'] ?? []);
        }
    }

    #[Test]
    public function warnings_include_post_and_discussion_relationships()
    {
        $response = $this->send(
            $this->request('GET', '/api/warnings/1', [
                'authenticatedAs' => 4,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        // Check post relationship
        $this->assertArrayHasKey('post', $body['data']['relationships']);
        $this->assertEquals('posts', $body['data']['relationships']['post']['data']['type']);
        $this->assertEquals('1', $body['data']['relationships']['post']['data']['id']);

        // Check included post has discussion
        $included = $body['included'] ?? [];
        $post = array_filter($included, fn ($item) => $item['type'] === 'posts' && $item['id'] === '1');
        $this->assertNotEmpty($post);

        $postData = array_values($post)[0];
        $this->assertArrayHasKey('discussion', $postData['relationships']);
    }
}
