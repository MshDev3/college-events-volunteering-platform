<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Domain\Role;
use App\Domain\User;

/**
 * Users. Only findForLogin() / passwordHash() ever read password_hash;
 * everything else selects the safe column list.
 */
final class UserRepository
{
    use Concerns\FilterClauses;

    private const SAFE_COLUMNS = 'u.id, u.full_name, u.student_id, u.email, u.phone, u.role_id, u.department_id,
        u.preferred_locale, u.is_active, u.auth_version, u.last_login_at, u.created_at, u.updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?User
    {
        $row = $this->db->fetch('SELECT ' . self::SAFE_COLUMNS . ' FROM users u WHERE u.id = ?', [$id]);

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Login lookup by email (case-insensitive) or student ID.
     * @return array{user:User, password_hash:string}|null
     */
    public function findForLogin(string $identifier): ?array
    {
        $row = $this->db->fetch(
            'SELECT ' . self::SAFE_COLUMNS . ', u.password_hash FROM users u
             WHERE u.email = ? OR u.student_id = ? LIMIT 1',
            [mb_strtolower($identifier), $identifier],
        );
        if ($row === null) {
            return null;
        }

        return ['user' => User::fromRow($row), 'password_hash' => (string) $row['password_hash']];
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetch('SELECT ' . self::SAFE_COLUMNS . ' FROM users u WHERE u.email = ?', [mb_strtolower($email)]);

        return $row === null ? null : User::fromRow($row);
    }

    public function passwordHash(int $id): ?string
    {
        $hash = $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$id]);

        return $hash === null ? null : (string) $hash;
    }

    /** @param array{full_name:string, student_id:?string, email:string, phone:?string, department_id:?int, preferred_locale:string} $data */
    public function create(array $data, #[\SensitiveParameter] string $passwordHash, Role $role = Role::STUDENT): int
    {
        return $this->db->insert('users', [
            'full_name' => $data['full_name'],
            'student_id' => $data['student_id'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'],
            'department_id' => $data['department_id'],
            'preferred_locale' => $data['preferred_locale'],
            'password_hash' => $passwordHash,
            'role_id' => $role->id(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $id, array $data): void
    {
        $this->db->update('users', $data, ['id' => $id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db->update('users', ['password_hash' => $hash], ['id' => $id]);
    }

    /** Invalidate every session of this user (sessions carry the auth_version they were created with). */
    public function bumpAuthVersion(int $id): void
    {
        $this->db->query('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$id]);
    }

    /**
     * Change password and invalidate every other session in one statement. A pending email change is
     * dropped too: if the account was taken over, resetting the password must also stop the attacker's
     * request to move it to their address.
     */
    public function changePassword(int $id, string $hash): void
    {
        $this->db->query(
            'UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?',
            [$hash, $id],
        );
        $this->db->query('DELETE FROM email_change_requests WHERE user_id = ?', [$id]);
    }

    /** Replace the login email and invalidate every session in one statement (after the new address was confirmed). */
    public function changeEmail(int $id, string $email): void
    {
        $this->db->query(
            'UPDATE users SET email = ?, auth_version = auth_version + 1 WHERE id = ?',
            [mb_strtolower($email), $id],
        );
    }

    public function touchLogin(int $id): void
    {
        $this->db->query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function setLocale(int $id, string $locale): void
    {
        $this->db->update('users', ['preferred_locale' => $locale], ['id' => $id]);
    }

    public function setRole(int $id, Role $role): void
    {
        $this->db->update('users', ['role_id' => $role->id()], ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        // Deactivation also bumps auth_version so the user's open sessions end immediately.
        $this->db->query(
            'UPDATE users SET is_active = ?, auth_version = auth_version + IF(? = 0, 1, 0) WHERE id = ?',
            [$active ? 1 : 0, $active ? 1 : 0, $id],
        );
        if (!$active) {
            $this->db->query('DELETE FROM email_change_requests WHERE user_id = ?', [$id]);
        }
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [Role::ADMIN->id()]);
    }

    /**
     * Take the lock that serialises every action that could remove an admin, then return the number of active admins.
     * Call inside a transaction, before reading the target. The lock is the ADMIN row of the `roles` lookup table:
     * a single primary-key row, so concurrent callers simply queue (locking the admin users by a range scan can
     * deadlock), and whoever gets the lock next counts after the previous one has committed. A plain count lets two
     * admins who demote each other at the same moment both see "2 admins".
     */
    public function lockAdminSetAndCount(): int
    {
        $this->db->value('SELECT id FROM roles WHERE id = ? FOR UPDATE', [Role::ADMIN->id()]);

        return $this->countActiveAdmins();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    /**
     * Admin listing with search + filters.
     * @param array{q?:string, role?:string, status?:string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        $this->addSearch($where, $params, $filters['q'] ?? '', ['u.full_name', 'u.email', 'u.student_id']);
        if (in_array($filters['role'] ?? '', ['STUDENT', 'ADMIN'], true)) {
            $where[] = 'u.role_id = ?';
            $params[] = Role::from((string) $filters['role'])->id();
        }
        if (($filters['status'] ?? '') === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $where[] = 'u.is_active = 0';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        $items = $this->db->fetchAll(
            'SELECT ' . self::SAFE_COLUMNS . ", d.name_ar AS department_name_ar, d.name_en AS department_name_en
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE $whereSql ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function countStudents(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role_id = ?', [Role::STUDENT->id()]);
    }
}
