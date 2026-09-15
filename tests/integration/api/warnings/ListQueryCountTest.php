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
use PHPUnit\Framework\Attributes\Test;

/**
 * The forum-wide warnings page lists warnings for many different users, each with its
 * warned user, moderator, post and discussion. These relations must be loaded in batches,
 * so a full page costs the same number of queries as a single warning.
 */
class ListQueryCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const INCLUDE = 'warnedUser,addedByUser,post,post.discussion';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $users = [];
        $posts = [];
        $warnings = [];

        // Six warnings, each given to a different user on a different post.
        for ($i = 0; $i < 6; $i++) {
            $userId = 2 + $i;
            $postId = 100 + $i;

            $users[] = [
                'id' => $userId,
                'username' => 'member'.$userId,
                'email' => 'member'.$userId.'@machine.local',
                'is_email_confirmed' => 1,
                'password' => 'foobar',
            ];

            $posts[] = [
                'id' => $postId,
                'discussion_id' => 1,
                'number' => $i + 1,
                'created_at' => Carbon::parse('2024-01-01')->addMinutes($i)->toDateTimeString(),
                'user_id' => $userId,
                'type' => 'comment',
                'content' => '<t><p>post '.$i.'</p></t>',
            ];

            $warnings[] = [
                'id' => 200 + $i,
                'user_id' => $userId,
                'post_id' => $postId,
                'created_user_id' => 1,
                'strikes' => 1,
                'public_comment' => '<t><p>Warning '.$i.'</p></t>',
                'private_comment' => '<t><p>Warning '.$i.'</p></t>',
                'created_at' => Carbon::parse('2024-01-01')->addMinutes($i)->toDateTimeString(),
            ];
        }

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Warned discussion', 'created_at' => Carbon::parse('2024-01-01')->toDateTimeString(), 'last_posted_at' => Carbon::parse('2024-01-01')->toDateTimeString(), 'user_id' => 2, 'last_posted_user_id' => 7, 'comment_count' => 6, 'first_post_id' => 100],
            ],
            Post::class => $posts,
            User::class => $users,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return array{queries: int, warnings: int, users: int}
     */
    private function listWarnings(int $limit): array
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 1])
                ->withQueryParams(['include' => self::INCLUDE, 'page' => ['limit' => $limit]])
        );

        $queries = count($db->getQueryLog());

        $db->disableQueryLog();

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $users = array_filter($body['included'] ?? [], fn (array $resource) => $resource['type'] === 'users');

        return ['queries' => $queries, 'warnings' => count($body['data']), 'users' => count($users)];
    }

    #[Test]
    public function a_page_of_warnings_costs_as_many_queries_as_one()
    {
        $this->app();

        // Warm up: settings, extensions and the actor are loaded on the first request.
        $this->listWarnings(6);

        $one = $this->listWarnings(1);
        $six = $this->listWarnings(6);

        // Sanity: the relations are actually included. The admin issued every warning,
        // so six warned users plus the admin appear on the full page.
        $this->assertSame(1, $one['warnings']);
        $this->assertSame(6, $six['warnings']);
        $this->assertSame(7, $six['users']);

        $this->assertSame(
            $one['queries'],
            $six['queries'],
            "Listing six warnings must not cost more queries than listing one (1: {$one['queries']}, 6: {$six['queries']})."
        );
    }
}
