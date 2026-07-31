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

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `warnings` post relationship is a default include on the posts
 * endpoints, so every post listing loads it. Loading it per post means one
 * query per post on every page of a discussion; these tests pin both the
 * batched query count and who may see which warnings.
 */
class PostWarningsQueryCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $users = [$this->normalUser()];
        $posts = [];
        $warnings = [];

        // Ten posts in one discussion, alternating between two authors, each
        // post carrying a warning.
        for ($i = 0; $i < 10; $i++) {
            $postId = 100 + $i;
            $authorId = $i % 2 === 0 ? 2 : 3;

            $posts[] = [
                'id' => $postId,
                'discussion_id' => 1,
                'number' => $i + 1,
                'created_at' => Carbon::parse('2024-01-01')->addMinutes($i)->toDateTimeString(),
                'user_id' => $authorId,
                'type' => 'comment',
                'content' => '<t><p>post '.$i.'</p></t>',
            ];

            $warnings[] = [
                'id' => 200 + $i,
                'user_id' => $authorId,
                'post_id' => $postId,
                'created_user_id' => 1,
                'strikes' => 1,
                // Comments are rendered by TextFormatter, so they must be
                // stored as its XML representation.
                'public_comment' => '<t><p>Warning on post '.$postId.'</p></t>',
                'private_comment' => '<t><p>Warning on post '.$postId.'</p></t>',
                'created_at' => Carbon::parse('2024-01-01')->addMinutes($i)->toDateTimeString(),
            ];
        }

        $users[] = [
            'id' => 3,
            'username' => 'otherauthor',
            'email' => 'otherauthor@machine.local',
            'is_email_confirmed' => 1,
            'password' => 'foobar',
        ];

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Warned discussion', 'created_at' => Carbon::parse('2024-01-01')->toDateTimeString(), 'last_posted_at' => Carbon::parse('2024-01-01')->toDateTimeString(), 'user_id' => 2, 'last_posted_user_id' => 3, 'comment_count' => 10, 'first_post_id' => 100],
            ],
            Post::class => $posts,
            User::class => $users,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return array{status: int, warningQueries: int, byPostId: array<string, array<int>>}
     */
    private function listPosts(int $actorId): array
    {
        $this->app();

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        $response = $this->send(
            $this->request('GET', '/api/posts', ['authenticatedAs' => $actorId])
                ->withQueryParams(['filter' => ['discussion' => 1], 'include' => 'warnings'])
        );

        $warningQueries = 0;

        foreach ($db->getQueryLog() as $query) {
            if (stripos($query['query'], 'warnings') !== false) {
                $warningQueries++;
            }
        }

        $db->disableQueryLog();

        $body = json_decode($response->getBody()->getContents(), true);
        $byPostId = [];

        foreach ($body['data'] ?? [] as $resource) {
            $byPostId[$resource['id']] = array_map(
                'intval',
                array_column($resource['relationships']['warnings']['data'] ?? [], 'id')
            );
        }

        return ['status' => $response->getStatusCode(), 'warningQueries' => $warningQueries, 'byPostId' => $byPostId];
    }

    #[Test]
    public function post_warnings_are_loaded_in_one_batched_query()
    {
        // Admin can view everyone's warnings, so every post carries one.
        $result = $this->listPosts(1);

        $this->assertEquals(200, $result['status']);
        $this->assertCount(10, $result['byPostId']);
        $this->assertSame([200], $result['byPostId']['100']);
        $this->assertSame([209], $result['byPostId']['109']);

        $this->assertSame(
            1,
            $result['warningQueries'],
            "Post warnings must be loaded in one batched query for the whole page, not one per post (got {$result['warningQueries']})."
        );
    }

    #[Test]
    public function authors_see_warnings_on_their_own_posts_only()
    {
        // User 2 has no viewWarnings permission: they may see warnings on
        // their own posts (even-numbered ids) but not on user 3's.
        $result = $this->listPosts(2);

        $this->assertEquals(200, $result['status']);

        $this->assertSame([200], $result['byPostId']['100']);
        $this->assertSame([], $result['byPostId']['101']);
        $this->assertSame([202], $result['byPostId']['102']);
        $this->assertSame([], $result['byPostId']['103']);
    }

    #[Test]
    public function hidden_warnings_are_still_listed_for_permitted_actors()
    {
        // Pin current behaviour: post warnings are not filtered by hidden_at
        // (unlike the visibleWarningCount attribute), so a hidden warning
        // still appears for an actor allowed to see it.
        $this->database()->table('warnings')->where('id', 200)->update([
            'hidden_at' => Carbon::parse('2024-02-01')->toDateTimeString(),
        ]);

        $result = $this->listPosts(1);

        $this->assertSame([200], $result['byPostId']['100']);
    }
}
