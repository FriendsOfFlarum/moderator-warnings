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

namespace FoF\ModeratorWarnings\Tests\integration\api\acknowledgement;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The posting block is decided per discussion (canReply on every listed
 * discussion), so it must not cost a query: it reads a flag on the actor's own
 * row and a cached setting. These tests pin that the same page runs exactly
 * the same queries with the setting off, on, and on with the member blocked.
 */
class AcknowledgementQueryCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        $users = [$this->normalUser()];
        $discussions = [];
        $posts = [];

        // Five discussions, each by a different author, so anything decided
        // per discussion or per author would show up as extra queries.
        for ($i = 0; $i < 5; $i++) {
            $userId = 3 + $i;

            $users[] = ['id' => $userId, 'username' => 'poster'.$userId, 'email' => 'poster'.$userId.'@machine.local', 'is_email_confirmed' => 1];
            $discussions[] = [
                'id' => 1 + $i, 'title' => 'Discussion '.$i, 'user_id' => $userId, 'last_posted_user_id' => $userId,
                'created_at' => Carbon::createFromDate(2024, 1, 1 + $i)->toDateTimeString(),
                'last_posted_at' => Carbon::createFromDate(2024, 1, 1 + $i)->toDateTimeString(),
                'first_post_id' => 1 + $i, 'last_post_id' => 1 + $i, 'last_post_number' => 1, 'comment_count' => 1,
            ];
            $posts[] = [
                'id' => 1 + $i, 'discussion_id' => 1 + $i, 'number' => 1, 'user_id' => $userId, 'type' => 'comment',
                'content' => '<t><p>Post '.$i.'</p></t>', 'created_at' => Carbon::createFromDate(2024, 1, 1 + $i)->toDateTimeString(),
            ];
        }

        $this->prepareDatabase([
            User::class => $users,
            Discussion::class => $discussions,
            Post::class => $posts,
        ]);
    }

    public static function pages(): array
    {
        return [
            'forum page (HTML with preloaded payload)' => ['/'],
            'forum resource' => ['/api'],
            'discussion list' => ['/api/discussions'],
            'discussion with posts' => ['/api/discussions/1'],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function the_block_adds_no_queries(string $path)
    {
        $this->app();
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);

        $off = $this->queriesFor($path);

        $settings->set('fof-moderator-warnings.require_acknowledgement', '1');
        $on = $this->queriesFor($path);

        User::query()->whereKey(2)->update(['has_unacknowledged_warnings' => true]);
        $blocked = $this->queriesFor($path, function (string $raw) use ($path) {
            // Sanity: the block really is in effect on these pages.
            $body = json_decode($raw, true);

            if ($path === '/') {
                $this->assertStringContainsString('"hasUnacknowledgedWarnings":true', $raw);
            }
            if ($path === '/api') {
                $this->assertFalse($body['data']['attributes']['canStartDiscussion']);
            }
            if ($path === '/api/discussions') {
                $this->assertCount(5, $body['data']);
                $this->assertFalse($body['data'][0]['attributes']['canReply']);
            }
        });

        $this->assertSame($off, $on, 'Turning the setting on must not add queries.');
        $this->assertSame($off, $blocked, 'A blocked member must not cost extra queries.');

        // Equal counts alone would pass a check that queried in every state;
        // the block must come from the actor's row, never from the warnings.
        $this->assertEmpty(preg_grep('/acknowledg/i', $blocked), 'No page load may query acknowledgement state.');
    }

    protected function queriesFor(string $path, ?callable $check = null): array
    {
        $db = $this->database();

        // Warm up so lazily loaded, per-process state (settings, permissions)
        // doesn't count against whichever measurement runs first.
        $this->send($this->request('GET', $path, ['authenticatedAs' => 2]));

        $db->flushQueryLog();
        $db->enableQueryLog();

        $response = $this->send($this->request('GET', $path, ['authenticatedAs' => 2]));

        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();

        $this->assertEquals(200, $response->getStatusCode());

        if ($check) {
            $check((string) $response->getBody());
        }

        // Session and access-token bookkeeping writes vary with timing, not with
        // this feature; only reads are compared.
        return array_values(array_filter($queries, fn (string $query) => stripos(ltrim($query), 'select') === 0));
    }
}
