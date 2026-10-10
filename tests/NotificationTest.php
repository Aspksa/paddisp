<?php

declare(strict_types=1);

namespace Tests;

final class NotificationTest extends TestCase
{
    public function testUserCanMarkOnlyOwnNotificationsRead(): void
    {
        $this->app->notifications->notify($this->users['employee'], 'test', 'Моё', '', '/requests');
        $this->app->notifications->notify($this->users['employee2'], 'test', 'Чужое');
        $mine = (int) self::$db->value('SELECT id FROM notifications WHERE user_id = ?', [$this->users['employee']]);
        $theirs = (int) self::$db->value('SELECT id FROM notifications WHERE user_id = ?', [$this->users['employee2']]);

        $client = $this->client('employee');
        $this->assertRedirect($client->post("/notifications/$theirs/read"), '/notifications');
        $this->assertSame(1, $this->app->notifications->unreadCount($this->users['employee2']));
        $this->assertRedirect($client->post("/notifications/$mine/read"), '/requests');
        $this->assertSame(0, $this->app->notifications->unreadCount($this->users['employee']));
        $this->assertTrue(!str_contains($client->get('/notifications')->body, 'Чужое'));
    }

    public function testMarkAllRead(): void
    {
        $this->submitRequest();
        $this->submitRequest();
        $this->assertSame(2, $this->app->notifications->unreadCount($this->users['dispatcher']));
        $this->client('dispatcher')->post('/notifications/read-all');
        $this->assertSame(0, $this->app->notifications->unreadCount($this->users['dispatcher']));
    }
}
