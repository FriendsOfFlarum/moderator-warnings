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
use Flarum\Extend;
use Flarum\Notification\Notification;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class AcknowledgementTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-moderator-warnings');

        // Each test posts several times in a row; core's 10-second flood
        // throttle would answer 429 before the permission check is reached.
        $this->extend((new Extend\ThrottleApi())->remove('postTimeout'));

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'moderator', 'email' => 'moderator@example.com', 'is_email_confirmed' => true],
                ['id' => 4, 'username' => 'bystander', 'email' => 'bystander@example.com', 'is_email_confirmed' => true],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Test Discussion', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'last_post_number' => 1, 'comment_count' => 1],
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

    protected function enable(bool $enabled = true): void
    {
        $this->setting('fof-moderator-warnings.require_acknowledgement', $enabled ? '1' : '0');
    }

    protected function warn(array $attributes = []): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => array_merge([
                            'userId' => 2,
                            'publicComment' => 'Please read the rules',
                            'strikes' => 1,
                        ], $attributes),
                    ],
                ],
            ])
        );
    }

    protected function warnAndGetId(array $attributes = []): string
    {
        $response = $this->warn($attributes);

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['id'];
    }

    protected function acknowledge(string $id, ?int $as = 2): ResponseInterface
    {
        return $this->send(
            $this->request('POST', "/api/warnings/$id/acknowledge", $as ? ['authenticatedAs' => $as] : [])
        );
    }

    protected function reply(int $as = 2): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/posts', [
                'authenticatedAs' => $as,
                'json' => [
                    'data' => [
                        'type' => 'posts',
                        'attributes' => ['content' => 'A reply long enough to pass validation'],
                        'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
                    ],
                ],
            ])
        );
    }

    protected function startDiscussion(int $as = 2): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/discussions', [
                'authenticatedAs' => $as,
                'json' => [
                    'data' => [
                        'type' => 'discussions',
                        'attributes' => ['title' => 'A new discussion', 'content' => 'With enough content to pass validation'],
                    ],
                ],
            ])
        );
    }

    protected function editOwnPost(): ResponseInterface
    {
        return $this->send(
            $this->request('PATCH', '/api/posts/1', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'type' => 'posts',
                        'id' => '1',
                        'attributes' => ['content' => 'An edited post, long enough'],
                    ],
                ],
            ])
        );
    }

    protected function assertCanPost(): void
    {
        $this->assertEquals(201, $this->reply()->getStatusCode(), 'The member should be able to reply.');
        $this->assertEquals(201, $this->startDiscussion()->getStatusCode(), 'The member should be able to start a discussion.');
    }

    protected function assertCannotPost(): void
    {
        $this->assertEquals(403, $this->reply()->getStatusCode(), 'The member must not be able to reply.');
        $this->assertEquals(403, $this->startDiscussion()->getStatusCode(), 'The member must not be able to start a discussion.');
    }

    protected function flag(int $userId = 2): bool
    {
        return (bool) User::query()->whereKey($userId)->value('has_unacknowledged_warnings');
    }

    /** The `hasUnacknowledgedWarnings` attribute as $as sees it on user 2, or null when not serialized. */
    protected function userAttribute(?int $as = 2): ?bool
    {
        $response = $this->send(
            $this->request('GET', '/api/users/2', $as ? ['authenticatedAs' => $as] : [])
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data']['attributes']['hasUnacknowledgedWarnings'] ?? null;
    }

    #[Test]
    public function setting_off_by_default_leaves_posting_unchanged()
    {
        $id = $this->warnAndGetId(['requiresAcknowledgement' => true]);

        $warning = Warning::find($id);
        $this->assertFalse($warning->requires_acknowledgement, 'The flag must be ignored while the setting is off.');
        $this->assertFalse($this->flag());
        $this->assertFalse($this->userAttribute());

        // Before replying: once someone replies, core's default `allow_post_editing`
        // stops the member editing post 1 regardless of warnings.
        $this->assertEquals(200, $this->editOwnPost()->getStatusCode());
        $this->assertCanPost();
    }

    #[Test]
    public function setting_on_requires_acknowledgement_by_default_and_blocks_posting()
    {
        $this->enable();

        $response = $this->warn();
        $this->assertEquals(201, $response->getStatusCode());
        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertTrue($attributes['requiresAcknowledgement']);
        $this->assertNull($attributes['acknowledgedAt']);
        $this->assertTrue($this->flag());
        $this->assertTrue($this->userAttribute());

        $this->assertCannotPost();
        $this->assertEquals(403, $this->editOwnPost()->getStatusCode(), 'Editing own posts goes through `reply` and must be blocked too.');
    }

    #[Test]
    public function blocked_member_sees_cannot_reply_and_cannot_start_discussion()
    {
        $this->enable();
        $this->warnAndGetId();

        $discussion = json_decode((string) $this->send(
            $this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2])
        )->getBody(), true);
        $this->assertFalse($discussion['data']['attributes']['canReply']);

        $forum = json_decode((string) $this->send(
            $this->request('GET', '/api', ['authenticatedAs' => 2])
        )->getBody(), true);
        $this->assertFalse($forum['data']['attributes']['canStartDiscussion']);
    }

    #[Test]
    public function setting_on_but_unticked_does_not_block()
    {
        $this->enable();

        $id = $this->warnAndGetId(['requiresAcknowledgement' => false]);

        $this->assertFalse(Warning::find($id)->requires_acknowledgement);
        $this->assertFalse($this->flag());
        $this->assertCanPost();
    }

    #[Test]
    public function acknowledging_records_the_time_and_unblocks()
    {
        $this->enable();
        $id = $this->warnAndGetId();

        $response = $this->acknowledge($id);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];
        $this->assertNotNull($attributes['acknowledgedAt']);
        $this->assertNotNull(Warning::find($id)->acknowledged_at);

        $this->assertFalse($this->flag());
        $this->assertFalse($this->userAttribute());
        // Before replying: once someone replies, core's default `allow_post_editing`
        // stops the member editing post 1 regardless of warnings.
        $this->assertEquals(200, $this->editOwnPost()->getStatusCode());
        $this->assertCanPost();
    }

    #[Test]
    public function acknowledging_twice_keeps_the_first_timestamp()
    {
        $this->enable();
        $id = $this->warnAndGetId();

        $this->acknowledge($id);
        $first = Warning::find($id)->acknowledged_at;

        Carbon::setTestNow(Carbon::now()->addHour());

        try {
            $this->assertEquals(200, $this->acknowledge($id)->getStatusCode());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertEquals($first, Warning::find($id)->acknowledged_at);
    }

    #[Test]
    public function every_pending_warning_must_be_acknowledged()
    {
        $this->enable();
        $first = $this->warnAndGetId();
        $second = $this->warnAndGetId(['publicComment' => 'And another thing']);

        $this->acknowledge($first);
        $this->assertTrue($this->flag());
        $this->assertCannotPost();

        $this->acknowledge($second);
        $this->assertFalse($this->flag());
        $this->assertCanPost();
    }

    #[Test]
    public function only_the_warned_member_can_acknowledge()
    {
        $this->enable();
        $id = $this->warnAndGetId();

        // A guest POST has no session and so no CSRF token; it is rejected
        // before the endpoint is reached, as with every other write.
        $this->assertEquals(400, $this->acknowledge($id, null)->getStatusCode(), 'Guest');
        $this->assertEquals(404, $this->acknowledge($id, 4)->getStatusCode(), 'Another member cannot even see the warning');
        $this->assertEquals(403, $this->acknowledge($id, 3)->getStatusCode(), 'Moderator');
        $this->assertEquals(403, $this->acknowledge($id, 1)->getStatusCode(), 'Admin');

        $this->assertNull(Warning::find($id)->acknowledged_at);
        $this->assertTrue($this->flag());
    }

    #[Test]
    public function a_warning_that_did_not_ask_cannot_be_acknowledged()
    {
        $this->enable();
        $id = $this->warnAndGetId(['requiresAcknowledgement' => false]);

        $this->assertEquals(403, $this->acknowledge($id)->getStatusCode());
        $this->assertNull(Warning::find($id)->acknowledged_at);
    }

    #[Test]
    public function only_the_member_is_told_they_have_a_warning_to_acknowledge()
    {
        $this->enable();
        $this->warnAndGetId();

        $this->assertTrue($this->userAttribute(2));
        $this->assertNull($this->userAttribute(3), 'Moderator');
        $this->assertNull($this->userAttribute(1), 'Admin');
        $this->assertNull($this->userAttribute(4), 'Another member');
        $this->assertNull($this->userAttribute(null), 'Guest');
    }

    #[Test]
    public function moderator_sees_acknowledgement_on_the_warnings_list()
    {
        $this->enable();
        $pending = $this->warnAndGetId();
        $acknowledged = $this->warnAndGetId(['publicComment' => 'Second']);
        $this->acknowledge($acknowledged);

        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 3])
                ->withQueryParams(['filter' => ['userId' => '2']])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $byId = [];
        foreach (json_decode((string) $response->getBody(), true)['data'] as $warning) {
            $byId[$warning['id']] = $warning['attributes'];
        }

        $this->assertCount(2, $byId);
        $this->assertTrue($byId[$pending]['requiresAcknowledgement']);
        $this->assertNull($byId[$pending]['acknowledgedAt']);
        $this->assertTrue($byId[$acknowledged]['requiresAcknowledgement']);
        $this->assertNotNull($byId[$acknowledged]['acknowledgedAt']);
    }

    #[Test]
    public function awaiting_acknowledgement_filter_lists_only_pending_warnings()
    {
        $this->enable();
        $pending = $this->warnAndGetId();
        $acknowledged = $this->warnAndGetId(['publicComment' => 'Second']);
        $this->acknowledge($acknowledged);
        $third = $this->warnAndGetId(['publicComment' => 'Third', 'requiresAcknowledgement' => false]);

        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['userId' => '2', 'awaitingAcknowledgement' => '1']])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $ids = array_column(json_decode((string) $response->getBody(), true)['data'], 'id');
        $this->assertSame([$pending], $ids);

        $response = $this->send(
            $this->request('GET', '/api/warnings', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['userId' => '2', 'awaitingAcknowledgement' => '0']])
        );
        $this->assertEquals(200, $response->getStatusCode());

        $ids = array_column(json_decode((string) $response->getBody(), true)['data'], 'id');
        sort($ids);
        $this->assertSame([$acknowledged, $third], $ids, '`awaitingAcknowledgement=0` lists the rest.');
    }

    #[Test]
    public function deleting_an_unacknowledged_warning_unblocks()
    {
        $this->enable();
        $id = $this->warnAndGetId();
        $this->assertCannotPost();

        $response = $this->send(
            $this->request('DELETE', "/api/warnings/$id", ['authenticatedAs' => 3])
        );
        $this->assertEquals(204, $response->getStatusCode());

        $this->assertFalse($this->flag());
        $this->assertCanPost();
    }

    #[Test]
    public function removing_an_unacknowledged_warning_unblocks_and_restoring_blocks_again()
    {
        $this->enable();
        $id = $this->warnAndGetId();

        $setHidden = fn (bool $hidden) => $this->send(
            $this->request('PATCH', "/api/warnings/$id", [
                'authenticatedAs' => 3,
                'json' => ['data' => ['type' => 'warnings', 'id' => $id, 'attributes' => ['isHidden' => $hidden]]],
            ])
        );

        $this->assertEquals(200, $setHidden(true)->getStatusCode());
        $this->assertFalse($this->flag());
        $this->assertCanPost();

        $this->assertEquals(200, $setHidden(false)->getStatusCode());
        $this->assertTrue($this->flag());
        $this->assertCannotPost();
    }

    #[Test]
    public function a_removed_warning_cannot_be_acknowledged()
    {
        $this->enable();
        $id = $this->warnAndGetId();
        Warning::find($id)->hide()->save();

        // Hidden warnings are invisible to the warned member.
        $this->assertEquals(404, $this->acknowledge($id)->getStatusCode());
    }

    #[Test]
    public function a_removed_warning_cannot_be_acknowledged_even_when_visible()
    {
        $this->enable();

        // A warned moderator can still see their own removed warning, so this
        // reaches the policy rather than stopping at the visibility scope.
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 1,
                'json' => ['data' => ['type' => 'warnings', 'attributes' => ['userId' => 3, 'publicComment' => 'Even moderators', 'strikes' => 0]]],
            ])
        );
        $id = json_decode((string) $response->getBody(), true)['data']['id'];
        Warning::find($id)->hide()->save();

        $this->assertEquals(403, $this->acknowledge($id, 3)->getStatusCode());
        $this->assertNull(Warning::find($id)->acknowledged_at);
    }

    #[Test]
    public function moving_a_warning_to_another_member_moves_the_block()
    {
        $this->enable();
        $id = $this->warnAndGetId();
        $this->assertTrue($this->flag(2));

        $response = $this->send(
            $this->request('PATCH', "/api/warnings/$id", [
                'authenticatedAs' => 3,
                'json' => ['data' => ['type' => 'warnings', 'id' => $id, 'attributes' => ['userId' => 4]]],
            ])
        );
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertFalse($this->flag(2), 'The member the warning was moved away from is no longer blocked.');
        $this->assertTrue($this->flag(4), 'The member it was moved to is.');
        $this->assertCanPost();
        $this->assertEquals(403, $this->reply(4)->getStatusCode());
    }

    #[Test]
    public function the_flag_is_kept_for_any_code_path_that_saves_a_warning()
    {
        $this->enable();
        $this->app();

        // Not through the API: the flag is maintained by the model itself.
        $warning = new Warning();
        $warning->user_id = 2;
        $warning->created_user_id = 3;
        $warning->strikes = 0;
        $warning->public_comment = '<t><p>From code</p></t>';
        $warning->requires_acknowledgement = true;
        $warning->created_at = Carbon::now();
        $warning->save();
        $this->assertTrue($this->flag());

        $warning->requires_acknowledgement = false;
        $warning->save();
        $this->assertFalse($this->flag(), 'Lifting the requirement in code unblocks.');

        $warning->requires_acknowledgement = true;
        $warning->save();
        $this->assertTrue($this->flag());

        $warning->delete();
        $this->assertFalse($this->flag());
    }

    #[Test]
    public function turning_the_setting_off_lifts_the_block()
    {
        // Warned while the setting was on, then the setting turned off: the
        // stored flag and warning stay, but nothing is enforced any more.
        $this->prepareDatabase([
            'warnings' => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 3, 'strikes' => 0, 'public_comment' => '<t><p>Warned</p></t>', 'created_at' => Carbon::now(), 'requires_acknowledgement' => true],
            ],
        ]);
        $this->enable(false);
        $this->app();
        User::query()->whereKey(2)->update(['has_unacknowledged_warnings' => true]);

        $this->assertFalse($this->userAttribute());
        $this->assertCanPost();
    }

    #[Test]
    public function requires_acknowledgement_cannot_be_changed_after_creation()
    {
        $this->enable();
        $id = $this->warnAndGetId();

        $response = $this->send(
            $this->request('PATCH', "/api/warnings/$id", [
                'authenticatedAs' => 3,
                'json' => ['data' => ['type' => 'warnings', 'id' => $id, 'attributes' => ['requiresAcknowledgement' => false]]],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertTrue(Warning::find($id)->requires_acknowledgement);
        $this->assertTrue($this->flag());
    }

    #[Test]
    public function other_members_and_moderators_are_unaffected()
    {
        $this->enable();
        $this->warnAndGetId();

        $this->assertEquals(201, $this->reply(4)->getStatusCode());
        $this->assertEquals(201, $this->reply(3)->getStatusCode());
        $this->assertEquals(201, $this->startDiscussion(4)->getStatusCode());
    }

    #[Test]
    public function a_warned_moderator_is_blocked_too()
    {
        $this->enable();

        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 1,
                'json' => ['data' => ['type' => 'warnings', 'attributes' => ['userId' => 3, 'publicComment' => 'Even moderators', 'strikes' => 0]]],
            ])
        );
        $this->assertEquals(201, $response->getStatusCode());

        $this->assertEquals(403, $this->reply(3)->getStatusCode());
    }

    #[Test]
    public function guests_are_unaffected()
    {
        $this->enable();
        $this->warnAndGetId();

        $response = $this->send($this->request('GET', '/api/discussions/1'));
        $this->assertEquals(200, $response->getStatusCode());

        $response = $this->send($this->request('GET', '/api/users/2'));
        $this->assertArrayNotHasKey('hasUnacknowledgedWarnings', json_decode((string) $response->getBody(), true)['data']['attributes']);
    }

    #[Test]
    public function the_warning_notification_is_sent_as_before()
    {
        $this->enable();
        $this->warnAndGetId();

        $this->assertEquals(1, Notification::where('user_id', 2)->where('type', 'warning')->count());
    }
}
