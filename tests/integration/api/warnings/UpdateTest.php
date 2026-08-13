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

class UpdateTest extends TestCase
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
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 1, 'private_comment' => '<t><p>Private note</p></t>', 'public_comment' => '<t><p>Original warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now()],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4], // moderator group
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
            ],
        ]);
    }

    #[Test]
    public function hidden_at_is_not_writable()
    {
        // Core keeps `hiddenAt` read-only and drives hiding through `isHidden`, so that
        // the timestamp and the acting user are always stamped by the server.
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'hiddenAt' => Carbon::now()->toIso8601String(),
                        ],
                    ],
                ],
            ])
        );

        $body = $response->getBody()->getContents();

        $this->assertEquals(403, $response->getStatusCode(), $body);
        $this->assertStringContainsString('not writable', $body);

        $warning = Warning::find(1);
        $this->assertNull($warning->hidden_at);
    }

    #[Test]
    public function hidden_at_is_only_serialized_when_the_warning_is_hidden()
    {
        // Matches core, where `hiddenAt` is omitted entirely unless the record is hidden.
        $response = $this->send(
            $this->request('GET', '/api/warnings/1', ['authenticatedAs' => 3])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayNotHasKey('hiddenAt', $body['data']['attributes']);

        // Hide it, and the timestamp becomes visible.
        $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => ['isHidden' => true],
                    ],
                ],
            ])
        );

        $response = $this->send(
            $this->request('GET', '/api/warnings/1', ['authenticatedAs' => 3])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('hiddenAt', $body['data']['attributes']);
        $this->assertNotNull($body['data']['attributes']['hiddenAt']);
    }

    #[Test]
    public function hiding_a_warning_is_idempotent()
    {
        // Core's hide()/restore() guard on current state so a repeated call is a no-op
        // rather than re-stamping the timestamp and actor.
        $this->app();

        $original = Carbon::now()->subDay();

        $warning = Warning::find(1);
        $warning->hidden_at = $original;
        $warning->hidden_user_id = 1;
        $warning->save();

        $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => ['isHidden' => true],
                    ],
                ],
            ])
        );

        $warning = Warning::find(1);

        $this->assertEquals($original->toDateTimeString(), $warning->hidden_at->toDateTimeString());
        $this->assertEquals(1, $warning->hidden_user_id, 'Re-hiding must not re-stamp the actor.');
    }

    #[Test]
    public function moderator_can_hide_warning_with_is_hidden()
    {
        // `isHidden` is the attribute the frontend saves, mirroring core's Post and
        // Discussion resources, where `isHidden` is writable and `hiddenAt` is read-only.
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
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
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), $response->getBody()->getContents());

        $warning = Warning::find(1);
        $this->assertNotNull($warning->hidden_at);
        $this->assertEquals(3, $warning->hidden_user_id);
    }

    #[Test]
    public function moderator_can_restore_warning_with_is_hidden()
    {
        $this->app();

        $warning = Warning::find(1);
        $warning->hidden_at = Carbon::now();
        $warning->hidden_user_id = 3;
        $warning->save();

        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'isHidden' => false,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), $response->getBody()->getContents());

        $warning = Warning::find(1);
        $this->assertNull($warning->hidden_at);
        $this->assertNull($warning->hidden_user_id);
    }

    #[Test]
    public function is_hidden_is_serialized_on_warnings()
    {
        // The frontend derives its hide/restore controls from this attribute, so it has
        // to be present on the response for both a visible and a hidden warning.
        $response = $this->send(
            $this->request('GET', '/api/warnings/1', ['authenticatedAs' => 3])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('isHidden', $body['data']['attributes']);
        $this->assertFalse($body['data']['attributes']['isHidden']);
    }

    #[Test]
    public function normal_user_cannot_hide_warning_with_is_hidden()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'isHidden' => true,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());

        $warning = Warning::find(1);
        $this->assertNull($warning->hidden_at);
    }

    #[Test]
    public function is_hidden_cannot_be_set_on_create()
    {
        // Mirrors core: `isHidden` is writable only when updating, never at create time.
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'A new warning',
                            'strikes' => 1,
                            'isHidden' => true,
                        ],
                    ],
                ],
            ])
        );

        $body = $response->getBody()->getContents();

        // The `updating()` guard rejects the field outright rather than ignoring it.
        $this->assertEquals(403, $response->getStatusCode(), $body);
        $this->assertStringContainsString('not writable', $body);
    }

    #[Test]
    public function normal_user_cannot_update_warning()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'publicComment' => 'Trying to reword my own warning',
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());

        // Verify unchanged in database
        $warning = Warning::find(1);
        $this->assertStringContainsString('Original warning', $warning->public_comment);
    }

    #[Test]
    public function guest_cannot_update_warning()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'isHidden' => true,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(400, $response->getStatusCode());
    }

    #[Test]
    public function admin_can_update_warning()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 1,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'isHidden' => true,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $warning = Warning::find(1);
        $this->assertNotNull($warning->hidden_at);
    }
}
