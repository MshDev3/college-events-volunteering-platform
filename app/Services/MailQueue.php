<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Deferred;
use App\Core\Logger;
use App\Services\Auth\PasswordResetService;

/**
 * Queue for emails that must not be sent during the request (table `mail_queue`, migration 005).
 *
 * Jobs carry no secrets — a password-reset job is just "user X asked for a link"; the token is
 * created when the job runs. Jobs run after the response (MAIL_QUEUE=after_response, default) and/or
 * from cron with `php bin/console mail:work` (MAIL_QUEUE=cron). Failures are retried with back-off.
 */
final class MailQueue
{
    public const PASSWORD_RESET = 'password_reset';
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Container $container,
        private readonly Deferred $deferred,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    private bool $scheduled = false;

    public function push(string $kind, int $userId, ?string $ip = null): void
    {
        $this->db->insert('mail_queue', ['kind' => $kind, 'user_id' => $userId, 'requested_ip' => $ip]);
        if (!$this->scheduled && $this->config->get('mail.queue', 'after_response') !== 'cron') {
            $this->scheduled = true;
            $this->deferred->push(function (): void {
                $this->scheduled = false;
                $this->work();
            });
        }
    }

    /**
     * Process due jobs. Safe to run concurrently: each job is claimed with a conditional UPDATE.
     * @return array{sent:int, retried:int, dropped:int}
     */
    public function work(int $limit = 50): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        $ids = array_column($this->db->fetchAll(
            'SELECT id FROM mail_queue WHERE available_at <= NOW() AND (locked_until IS NULL OR locked_until < NOW()) ORDER BY id LIMIT ?',
            [$limit],
        ), 'id');

        foreach ($ids as $id) {
            $claimed = $this->db->query(
                'UPDATE mail_queue SET locked_until = NOW() + INTERVAL 5 MINUTE, attempts = attempts + 1
                 WHERE id = ? AND (locked_until IS NULL OR locked_until < NOW())',
                [(int) $id],
            )->rowCount() === 1;
            if (!$claimed) {
                continue; // another worker has it
            }
            $job = $this->db->fetch('SELECT * FROM mail_queue WHERE id = ?', [(int) $id]);
            if ($job === null) {
                continue;
            }

            $error = null;
            try {
                $done = $this->handle($job);
            } catch (\Throwable $e) {
                $done = false;
                $error = $e->getMessage();
            }

            if ($done) {
                $this->db->query('DELETE FROM mail_queue WHERE id = ?', [(int) $id]);
                $result['sent']++;
            } elseif ((int) $job['attempts'] >= self::MAX_ATTEMPTS) {
                $this->db->query('DELETE FROM mail_queue WHERE id = ?', [(int) $id]);
                $this->logger->error('Mail job dropped after repeated failures', ['kind' => $job['kind'], 'id' => (int) $id, 'error' => $error]);
                $result['dropped']++;
            } else {
                $this->db->query(
                    'UPDATE mail_queue SET locked_until = NULL, available_at = NOW() + INTERVAL ? MINUTE, last_error = ? WHERE id = ?',
                    [(int) $job['attempts'] ** 2, mb_substr((string) ($error ?? 'delivery failed'), 0, 255), (int) $id],
                );
                $result['retried']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $job @return bool true when the job is finished (sent, or no longer needed) */
    private function handle(array $job): bool
    {
        return match ($job['kind']) {
            self::PASSWORD_RESET => $this->container->get(PasswordResetService::class)
                ->sendResetLink((int) $job['user_id'], $job['requested_ip'] !== null ? (string) $job['requested_ip'] : null, (string) $job['created_at']),
            default => throw new \RuntimeException('Unknown mail job kind: ' . $job['kind']),
        };
    }
}
