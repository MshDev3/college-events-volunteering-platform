<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\RateLimiter;
use App\Core\Validator;
use App\Domain\FeedbackStatus;
use App\Domain\User;
use App\Repositories\FeedbackRepository;
use App\Repositories\LookupRepository;

/** Suggestions & complaints (legacy "problem" table, now with categories, statuses and replies). */
final class FeedbackService
{
    /** Submissions per student per hour: plenty for real use, stops flooding (each may carry a 5 MB file). */
    public const MAX_PER_HOUR = 10;

    public function __construct(
        private readonly Database $db,
        private readonly FeedbackRepository $repo,
        private readonly Validator $validator,
        private readonly LookupRepository $lookups,
        private readonly UploadService $uploads,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $attachment
     */
    public function submit(int $userId, array $input, ?array $attachment): int
    {
        $data = $this->validator->validate($input, [
            'type' => 'required|in:SUGGESTION,COMPLAINT',
            'category_id' => 'required|integer|in:' . $this->lookups->idList('feedback_categories'),
            'subject' => 'required|string|min:5|max:200',
            'message' => 'required|string|min:10|max:5000',
        ]);

        // Counted before the attachment touches the disk.
        if (!$this->limiter->attempt('feedback:' . $userId, self::MAX_PER_HOUR, 3600)) {
            throw ValidationException::withMessage('message', t('feedback.errors.too_many'));
        }

        // Validate + store the file before the DB row, so a bad file never leaves an orphan record.
        $stored = $attachment !== null ? $this->uploads->storePrivate($attachment, 'attachment') : null;

        return $this->db->transaction(function () use ($userId, $data, $stored): int {
            $id = $this->repo->create(['user_id' => $userId] + $data);
            if ($stored !== null) {
                $this->repo->addAttachment(['feedback_id' => $id] + $stored);
            }

            return $id;
        });
    }

    /** Owner or admin only; others get 404 so existence is not revealed. @return array<string, mixed> */
    public function getFor(User $viewer, int $id): array
    {
        $item = $this->repo->find($id);
        if ($item === null || (!$viewer->isAdmin() && (int) $item['user_id'] !== $viewer->id)) {
            throw new HttpException(404);
        }
        $item['attachments'] = $this->repo->attachments($id);

        return $item;
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $page, int $limit): Paginator
    {
        $result = $this->repo->paginate($filters, $limit, Paginator::offset($page, $limit));

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    public function updateStatus(int $adminId, int $id, string $status): void
    {
        $new = FeedbackStatus::tryFrom($status) ?? throw new HttpException(422);
        $item = $this->repo->find($id) ?? throw new HttpException(404);
        if ($item['status'] === $new->value) {
            return;
        }
        $this->repo->update($id, ['status' => $new->value]);
        $this->audit->log($adminId, 'feedback.status', 'feedback', $id, ['from' => $item['status'], 'to' => $new->value]);
        if ($item['user_id'] !== null) {
            $this->notifications->notify((int) $item['user_id'], NotificationService::FEEDBACK_STATUS, [
                'subject' => (string) $item['subject'],
                'status_key' => $new->labelKey(),
            ], '/student/feedback/' . $id);
        }
    }

    /** @param array<string, mixed> $input */
    public function reply(int $adminId, int $id, array $input): void
    {
        $data = $this->validator->validate($input, [
            'admin_reply' => 'required|string|min:2|max:5000',
            'status' => 'required|in:OPEN,IN_PROGRESS,RESOLVED,CLOSED',
        ]);
        $item = $this->repo->find($id) ?? throw new HttpException(404);
        $this->repo->update($id, [
            'admin_reply' => $data['admin_reply'],
            'status' => $data['status'],
            'replied_by' => $adminId,
            'replied_at' => date('Y-m-d H:i:s'),
        ]);
        if ($item['user_id'] !== null) {
            $this->notifications->notify((int) $item['user_id'], NotificationService::FEEDBACK_REPLIED, [
                'subject' => (string) $item['subject'],
            ], '/student/feedback/' . $id);
        }
    }

    public function setArchived(int $adminId, int $id, bool $archived): void
    {
        $this->repo->find($id) ?? throw new HttpException(404);
        $this->repo->update($id, ['archived_at' => $archived ? date('Y-m-d H:i:s') : null]);
        $this->audit->log($adminId, $archived ? 'feedback.archived' : 'feedback.unarchived', 'feedback', $id);
    }

    /** @return array{path:string, name:string, mime:string} */
    public function attachmentFor(User $viewer, int $attachmentId): array
    {
        $attachment = $this->repo->attachment($attachmentId);
        if ($attachment === null || (!$viewer->isAdmin() && (int) $attachment['user_id'] !== $viewer->id)) {
            throw new HttpException(404);
        }
        $path = $this->uploads->privatePath((string) $attachment['stored_name']) ?? throw new HttpException(404);

        return ['path' => $path, 'name' => (string) $attachment['original_name'], 'mime' => (string) $attachment['mime_type']];
    }

    public function countOpenComplaints(): int
    {
        return $this->repo->countOpenComplaints();
    }

    public function countOpen(): int
    {
        return $this->repo->countOpen();
    }
}
