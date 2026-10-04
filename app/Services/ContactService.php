<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;
use App\Domain\ContactStatus;
use App\Repositories\ContactRepository;

/** Contact-us messages (legacy "contact" table). Open to guests, rate-limited per IP. */
final class ContactService
{
    private const MAX_PER_HOUR = 5;

    public function __construct(
        private readonly ContactRepository $repo,
        private readonly Validator $validator,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function submit(array $input, ?int $userId, Request $request): int
    {
        $data = $this->validator->validate($input, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190',
            'subject' => 'required|string|min:3|max:200',
            'message' => 'required|string|min:10|max:5000',
        ]);

        // Honeypot: a hidden field real users never fill in.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return 0;
        }

        if (!$this->limiter->attempt('contact:' . $request->ip(), self::MAX_PER_HOUR, 3600)) {
            throw ValidationException::withMessage('message', t('contact.errors.too_many'));
        }

        return $this->repo->create($data + ['user_id' => $userId, 'ip_address' => $request->ip()]);
    }

    /** @param array{status?:string, q?:string, from?:string, to?:string} $filters */
    public function list(array $filters, int $page, int $limit): Paginator
    {
        $result = $this->repo->paginate($filters, $limit, Paginator::offset($page, $limit));

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /** Opening an unread message marks it read. @return array<string, mixed> */
    public function open(int $id): array
    {
        $message = $this->repo->find($id) ?? throw new HttpException(404);
        if ($message['status'] === ContactStatus::UNREAD->value) {
            $this->repo->setStatus($id, ContactStatus::READ);
            $message['status'] = ContactStatus::READ->value;
        }

        return $message;
    }

    public function setStatus(int $id, string $status): void
    {
        $new = ContactStatus::tryFrom($status) ?? throw new HttpException(422);
        $this->repo->find($id) ?? throw new HttpException(404);
        $this->repo->setStatus($id, $new);
    }

    public function countUnread(): int
    {
        return $this->repo->countUnread();
    }
}
