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
use Flarum\Audit\AuditLog;
use Flarum\Audit\AuditLogger;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the optional flarum/audit integration declared in extend.php.
 *
 * The integration is gated behind (new Extend\Conditional())->whenExtensionEnabled('flarum-audit'),
 * so entries are only written when the audit extension is enabled alongside this one.
 */
class AuditTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        // Lifecycle events fired outside the test transaction shouldn't create stray entries.
        AuditLogger::$testMode = true;

        $this->extension('flarum-audit', 'fof-moderator-warnings');

        $this->prepareDatabase([
            'audit_log' => [],
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
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 3, 'post_id' => 1, 'public_comment' => '<t><p>Existing warning</p></t>', 'private_comment' => '<t><p>Private note</p></t>', 'strikes' => 2, 'created_at' => Carbon::now()],
                // Seeded already hidden, so the restore test only has to unhide it.
                ['id' => 2, 'user_id' => 2, 'created_user_id' => 3, 'public_comment' => '<t><p>Hidden warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 3],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4], // moderator group
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
                ['group_id' => 4, 'permission' => 'user.deleteWarnings'],
            ],
        ]);
    }

    protected function assertLogged(string $action, array $payload, int $actorId = 3): void
    {
        /** @var AuditLog|null $log */
        $log = AuditLog::query()->where('action', $action)->first();

        $this->assertNotNull($log, "Expected an audit log entry for $action.");
        $this->assertEquals($actorId, $log->actor_id, 'Asserting logged actor');
        $this->assertEquals($payload, $log->payload, 'Asserting logged payload');
    }

    protected function assertNotLogged(string $action): void
    {
        $this->assertNull(
            AuditLog::query()->where('action', $action)->first(),
            "Expected no audit log entry for $action."
        );
    }

    #[Test]
    public function creating_a_warning_writes_an_audit_log_entry()
    {
        $response = $this->send($this->request('POST', '/api/warnings', [
            'authenticatedAs' => 3,
            'json' => [
                'data' => [
                    'type' => 'warnings',
                    'attributes' => [
                        'userId' => 2,
                        'publicComment' => 'Please read the rules',
                        'privateComment' => 'Third offence',
                        'strikes' => 3,
                    ],
                    'relationships' => [
                        'post' => ['data' => ['type' => 'posts', 'id' => '1']],
                    ],
                ],
            ],
        ]));

        $body = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode(), $body);

        $id = (int) json_decode($body, true)['data']['id'];

        $this->assertLogged('warning.created', [
            'warning_id'    => $id,
            'user_id'       => 2,
            'post_id'       => 1,
            'discussion_id' => 1,
            'strikes'       => 3,
        ]);
    }

    #[Test]
    public function warning_comments_are_never_logged()
    {
        $response = $this->send($this->request('POST', '/api/warnings', [
            'authenticatedAs' => 3,
            'json' => [
                'data' => [
                    'type' => 'warnings',
                    'attributes' => [
                        'userId' => 2,
                        'publicComment' => 'Public reason',
                        'privateComment' => 'Moderators only, do not leak',
                        'strikes' => 1,
                    ],
                ],
            ],
        ]));

        $this->assertEquals(201, $response->getStatusCode(), $response->getBody()->getContents());

        /** @var AuditLog|null $log */
        $log = AuditLog::query()->where('action', 'warning.created')->first();

        $this->assertNotNull($log);

        $payload = json_encode($log->payload);

        $this->assertStringNotContainsString('Moderators only', $payload);
        $this->assertStringNotContainsString('Public reason', $payload);
    }

    #[Test]
    public function hiding_a_warning_writes_an_audit_log_entry()
    {
        $response = $this->send($this->request('PATCH', '/api/warnings/1', [
            'authenticatedAs' => 3,
            'json' => [
                'data' => [
                    'type' => 'warnings',
                    'id' => '1',
                    'attributes' => [
                        'isHidden' => true,
                    ],
                ],
            ],
        ]));

        $this->assertEquals(200, $response->getStatusCode(), $response->getBody()->getContents());

        $this->assertLogged('warning.hidden', [
            'warning_id'    => 1,
            'user_id'       => 2,
            'post_id'       => 1,
            'discussion_id' => 1,
            'strikes'       => 2,
        ]);

        $this->assertNotLogged('warning.restored');
    }

    #[Test]
    public function restoring_a_hidden_warning_writes_an_audit_log_entry()
    {
        // Warning 2 is seeded as already hidden.
        $response = $this->send($this->request('PATCH', '/api/warnings/2', [
            'authenticatedAs' => 3,
            'json' => [
                'data' => [
                    'type' => 'warnings',
                    'id' => '2',
                    'attributes' => [
                        'isHidden' => false,
                    ],
                ],
            ],
        ]));

        $this->assertEquals(200, $response->getStatusCode(), $response->getBody()->getContents());

        // Warning 2 has no post, so both post keys are null.
        $this->assertLogged('warning.restored', [
            'warning_id'    => 2,
            'user_id'       => 2,
            'post_id'       => null,
            'discussion_id' => null,
            'strikes'       => 1,
        ]);

        $this->assertNotLogged('warning.hidden');
    }

    #[Test]
    public function deleting_a_warning_writes_an_audit_log_entry()
    {
        $response = $this->send($this->request('DELETE', '/api/warnings/1', [
            'authenticatedAs' => 3,
        ]));

        $this->assertEquals(204, $response->getStatusCode(), $response->getBody()->getContents());

        // The event fires before the row is removed, so the payload still carries its attributes.
        $this->assertLogged('warning.deleted', [
            'warning_id'    => 1,
            'user_id'       => 2,
            'post_id'       => 1,
            'discussion_id' => 1,
            'strikes'       => 2,
        ]);
    }

    #[Test]
    public function every_registered_action_has_a_translation()
    {
        // AuditItem renders `flarum-audit.lib.browser.<action>` and falls back to dumping
        // the raw payload JSON when the key is missing, so a registered action without a
        // message is a user-visible bug rather than a silent one.
        $messages = Yaml::parseFile(__DIR__.'/../../../../resources/locale/en.yml');

        // Boot the app so extend.php runs and the extender registers its actions.
        $this->send($this->request('GET', '/api/warnings', ['authenticatedAs' => 3]));

        $actions = AuditLogger::$registeredActions['fof-moderator-warnings'] ?? [];

        $this->assertNotEmpty($actions, 'Expected the extension to register audit actions.');

        foreach ($actions as $action) {
            $this->assertNotNull(
                Arr::get($messages, 'flarum-audit.lib.browser.'.$action),
                "Missing audit browser translation for action '$action'."
            );
        }
    }

    #[Test]
    public function editing_a_warning_without_changing_visibility_logs_nothing()
    {
        $response = $this->send($this->request('PATCH', '/api/warnings/1', [
            'authenticatedAs' => 3,
            'json' => [
                'data' => [
                    'type' => 'warnings',
                    'id' => '1',
                    'attributes' => [
                        'publicComment' => 'Reworded warning',
                    ],
                ],
            ],
        ]));

        $this->assertEquals(200, $response->getStatusCode(), $response->getBody()->getContents());

        $this->assertNotLogged('warning.hidden');
        $this->assertNotLogged('warning.restored');
    }
}
