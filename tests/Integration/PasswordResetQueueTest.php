<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Services\Auth\PasswordResetService;
use App\Services\MailQueue;

/**
 * L-8: a reset request only queues a secret-free job; the token and the email are produced later,
 * so the request does the same work for registered and unknown addresses.
 */
final class PasswordResetQueueTest extends IntegrationTestCase
{
    private function ask(string $email): void
    {
        app(PasswordResetService::class)->request($email, new Request('POST', '/forgot-password', [], [], [], [], ['REMOTE_ADDR' => '198.51.100.20']));
    }

    public function testRequestQueuesAJobWithoutSecretsAndSendsNothing(): void
    {
        $mail = $this->captureMail();
        $user = $this->user();
        $email = (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$user]);

        $this->ask($email);
        $this->ask("nobody.{$user}@test.local");

        self::assertCount(0, $mail, 'no email is sent during the request');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM password_reset_tokens'), 'no token exists yet');
        $jobs = $this->db->fetchAll('SELECT * FROM mail_queue');
        self::assertCount(1, $jobs, 'only the registered address queues a job');
        self::assertSame([$user, 'password_reset'], [(int) $jobs[0]['user_id'], $jobs[0]['kind']]);
        self::assertSame(
            ['attempts', 'available_at', 'created_at', 'id', 'kind', 'last_error', 'locked_until', 'requested_ip', 'user_id'],
            (static function (array $keys): array { sort($keys); return $keys; })(array_keys($jobs[0])),
            'the queue row has no column that could hold a token or message body',
        );
    }

    public function testWorkerCreatesTheTokenAndSendsTheLink(): void
    {
        $mail = $this->captureMail();
        $user = $this->user();
        $this->ask((string) $this->db->value('SELECT email FROM users WHERE id = ?', [$user]));

        self::assertSame(['sent' => 1, 'retried' => 0, 'dropped' => 0], app(MailQueue::class)->work());
        self::assertCount(1, $mail);
        self::assertMatchesRegularExpression('#/reset-password/([a-f0-9]{64})#', $mail[0]->text);
        preg_match('#/reset-password/([a-f0-9]{64})#', $mail[0]->text, $m);
        self::assertSame(hash('sha256', $m[1]), $this->db->value('SELECT token_hash FROM password_reset_tokens WHERE user_id = ?', [$user]), 'the DB holds only the hash');
        self::assertSame('198.51.100.20', $this->db->value('SELECT requested_ip FROM password_reset_tokens WHERE user_id = ?', [$user]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM mail_queue'), 'finished jobs are removed');
    }

    public function testFailedDeliveryIsRetriedWithBackoffThenDropped(): void
    {
        $this->captureMail();
        app(Container::class)->instance(\App\Core\Mail\Mailer::class, new class implements \App\Core\Mail\Mailer {
            public function send(\App\Core\Mail\MailMessage $message): void
            {
                throw new \RuntimeException('SMTP down');
            }
        });
        $user = $this->user();
        $this->ask((string) $this->db->value('SELECT email FROM users WHERE id = ?', [$user]));
        $queue = app(MailQueue::class);

        self::assertSame(['sent' => 0, 'retried' => 1, 'dropped' => 0], $queue->work());
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM mail_queue WHERE available_at > NOW() AND locked_until IS NULL'), 'retried later, not immediately');
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 0], $queue->work(), 'nothing is due yet');

        $this->db->query('UPDATE mail_queue SET attempts = 4, available_at = NOW()');
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 1], $queue->work(), 'given up after 5 attempts');
    }

    public function testStaleRequestsAreDroppedWithoutEmail(): void
    {
        $mail = $this->captureMail();
        $user = $this->user();
        $this->ask((string) $this->db->value('SELECT email FROM users WHERE id = ?', [$user]));
        $this->db->query('UPDATE mail_queue SET created_at = NOW() - INTERVAL 2 HOUR');

        app(MailQueue::class)->work();
        self::assertCount(0, $mail, 'a link requested hours ago is not sent any more');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM mail_queue'));
    }
}
