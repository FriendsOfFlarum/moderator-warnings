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
use Flarum\Notification\Notification;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\ModeratorWarnings\Model\Warning;
use PHPUnit\Framework\Attributes\Test;

class NotificationTest extends TestCase
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
    public function notification_sent_when_warning_created()
    {
        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'You have been warned',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        // Check that a notification was created for the warned user
        $notification = Notification::where('user_id', 2)
            ->where('type', 'warning')
            ->first();

        $this->assertNotNull($notification, 'Notification should be created for warned user');
        $this->assertNull($notification->read_at, 'Notification should be unread');
    }

    #[Test]
    public function notification_sent_when_warning_restored()
    {
        $this->prepareDatabase([
            Warning::class => [
                ['id' => 1, 'user_id' => 2, 'created_user_id' => 3, 'private_comment' => null, 'public_comment' => '<t><p>Test Warning</p></t>', 'strikes' => 1, 'created_at' => Carbon::now(), 'hidden_at' => Carbon::now(), 'hidden_user_id' => 3],
            ],
        ]);

        // Restore the warning
        $response = $this->send(
            $this->request('PATCH', '/api/warnings/1', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'id' => '1',
                        'attributes' => [
                            'hiddenAt' => null,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Check that a notification was created
        $notification = Notification::where('user_id', 2)
            ->where('type', 'warning')
            ->where('subject_id', 1)
            ->first();

        $this->assertNotNull($notification, 'Notification should be sent when warning is restored');
    }

    #[Test]
    public function no_notification_sent_to_other_users()
    {
        $this->prepareDatabase([
            User::class => [
                ['id' => 4, 'username' => 'otheruser', 'email' => 'other@example.com', 'is_email_confirmed' => true],
            ],
        ]);

        $response = $this->send(
            $this->request('POST', '/api/warnings', [
                'authenticatedAs' => 3,
                'json' => [
                    'data' => [
                        'type' => 'warnings',
                        'attributes' => [
                            'userId' => 2,
                            'publicComment' => 'You have been warned',
                            'strikes' => 1,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        // Check that other users didn't receive notification
        $notification = Notification::where('user_id', 4)
            ->where('type', 'warning')
            ->first();

        $this->assertNull($notification, 'Other users should not receive warning notifications');

        // Check that moderator who created warning didn't receive notification
        $notification = Notification::where('user_id', 3)
            ->where('type', 'warning')
            ->first();

        $this->assertNull($notification, 'Moderator who created warning should not receive notification');
    }
}
