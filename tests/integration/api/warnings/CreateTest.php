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
use Flarum\Extend;

class CreateTest extends TestCase
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
                ['user_id' => 3, 'group_id' => 4], // moderator group
            ],
            'group_permission' => [
                ['group_id' => 4, 'permission' => 'user.viewWarnings'],
                ['group_id' => 4, 'permission' => 'user.manageWarnings'],
            ],
        ]);
    }

    #[Test]
    public function moderator_can_create_warning_without_post()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'This is a warning',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $body = $response->getBody()->getContents();

        $this->assertEquals(201, $response->getStatusCode());

        $bodyData = json_decode($body, true);

        $this->assertEquals('warnings', $bodyData['data']['type']);
        $this->assertEquals(2, $bodyData['data']['attributes']['userId']);
        $this->assertEquals(1, $bodyData['data']['attributes']['strikes']);
        $this->assertStringContainsString('This is a warning', $bodyData['data']['attributes']['publicComment']);

        // Verify in database
        $warning = Warning::find($bodyData['data']['id']);
        $this->assertNotNull($warning);
        $this->assertEquals(2, $warning->user_id);
        $this->assertEquals(3, $warning->created_user_id);
        $this->assertNull($warning->post_id);
    }

    #[Test]
    public function moderator_can_create_warning_with_post()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'Warning about this post',
                            'privateComment' => 'Internal note',
                            'strikes' => 2,
                        ],
                        'relationships' => [
                            'post' => [
                                'data' => [
                                    'type' => 'posts',
                                    'id' => '1',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals(2, $body['data']['attributes']['strikes']);

        // Verify post relationship in database
        $warning = Warning::find($body['data']['id']);
        $this->assertEquals(1, $warning->post_id);
    }

    #[Test]
    public function normal_user_cannot_create_warning()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 1,
                            'publicComment' => 'Trying to warn admin',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function guest_cannot_create_warning()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'Guest warning',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(400, $response->getStatusCode());
    }

    #[Test]
    public function cannot_create_warning_without_userId()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'publicComment' => 'Warning without user',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function cannot_create_warning_without_public_comment()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function strikes_must_be_between_0_and_5()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'Too many strikes',
                            'strikes' => 10,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }
}
