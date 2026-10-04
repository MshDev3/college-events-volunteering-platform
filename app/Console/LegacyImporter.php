<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Database;
use App\Domain\Role;

/**
 * One-time import from the legacy `project` database (read-only) into the new schema.
 * The mapping and its reasoning are documented in docs/01-audit-and-plan.md §F.
 *
 * Idempotent: a `legacy_imported_at` setting marks completion; re-running is a no-op.
 * Every approximation (default times, capacity, copied translations) is listed in the report.
 */
final class LegacyImporter
{
    /** Legacy dropdown codes (revers.php) → facility codes. */
    private const FACILITY_CODES = ['first' => 'theater', 'second' => 'event_hall', 'third' => 'stadium', 'fourth' => 'gymnasium'];

    /** Legacy dropdown codes (tadwapage.php) → volunteer category codes. */
    private const VOLUNTEER_CODES = ['first' => 'event_management', 'second' => 'design', 'third' => 'translation', 'fourth' => 'tech_support'];

    private const LEGACY_START_TIME = '08:00:00';
    private const LEGACY_END_TIME = '14:00:00';
    private const LEGACY_CAPACITY = 100;

    /** @var list<string> */
    private array $report = [];

    public function __construct(private readonly Database $legacy, private readonly Database $db)
    {
    }

    /** @param callable(string): void $out */
    public function run(callable $out): void
    {
        if ($this->db->value("SELECT value FROM settings WHERE `key` = 'legacy_imported_at'") !== null) {
            $out('Legacy data was already imported — nothing to do.');

            return;
        }

        $this->db->transaction(function (): void {
            $userMap = $this->importUsers();
            $adminId = $this->firstAdminId();
            $this->importActivities('events', $adminId);
            $this->importActivities('volunteer_opportunities', $adminId);
            $this->importVolunteerApplications($userMap);
            $this->importReservations($userMap);
            $this->importProblems($userMap);
            $this->importContacts($userMap);
            $this->db->query("INSERT INTO settings (`key`, value) VALUES ('legacy_imported_at', NOW())");
        });

        $out('Legacy import completed. Report:');
        foreach ($this->report as $line) {
            $out('  - ' . $line);
        }
    }

    /** @return array<string, int> lower(email) => new user id */
    private function importUsers(): array
    {
        $map = [];
        $imported = 0;
        foreach ($this->legacyRows('SELECT id, username, email, password, role FROM users ORDER BY id') as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            $existing = $this->db->value('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing !== null) {
                $map[$email] = (int) $existing;
                $this->report[] = "users#{$row['id']} ($email): already exists in new DB, linked to user #$existing.";
                continue;
            }
            $role = $row['role'] === 'trainer' ? Role::ADMIN : Role::STUDENT;
            $hash = (string) $row['password'];
            if ($role === Role::ADMIN) {
                // Legacy passwords never met any policy; an administrator must not keep one.
                // Unusable random hash: the admin sets a new password with "Forgot password" before first use.
                $hash = self::unusableHash();
                $this->report[] = "users#{$row['id']} ($email): ADMIN — legacy password NOT imported; a password reset (Forgot password) is required before first login.";
            } elseif (preg_match('/^\$(2y|argon2id|argon2i)\$/', $hash) !== 1) {
                // Not a real hash: never import it as a password. The user must use "Forgot password".
                $hash = self::unusableHash();
                $this->report[] = "users#{$row['id']} ($email): password was not hashed — set to unusable; user must reset it.";
            }
            $id = $this->db->insert('users', [
                'full_name' => mb_substr(trim((string) $row['username']) ?: $email, 0, 120),
                'email' => $email,
                'password_hash' => $hash,
                'role_id' => $role->id(),
                'preferred_locale' => 'ar',
            ]);
            $map[$email] = $id;
            $imported++;
        }
        $this->report[] = "users: $imported imported (trainer → ADMIN with a password reset required; trainee → STUDENT with bcrypt hash kept and upgraded to Argon2id at next login; plaintext reset tokens discarded; student ID/phone left empty for users to complete).";

        return $map;
    }

    /** A valid Argon2id hash of a random secret nobody knows: the account exists but cannot log in until reset. */
    public static function unusableHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
    }

    private function importActivities(string $table, ?int $adminId): void
    {
        $isEvent = $table === 'events';
        $otherType = (int) $this->db->value("SELECT id FROM event_types WHERE code = 'other'");
        $defaultCategory = (int) $this->db->value("SELECT id FROM volunteer_categories WHERE code = 'event_management'");
        $imported = 0;

        foreach ($this->legacyRows("SELECT id, title, description, event_date FROM `$table` ORDER BY id") as $row) {
            $date = (string) $row['event_date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date === '0000-00-00') {
                $this->report[] = "$table#{$row['id']}: invalid date '$date' — skipped.";
                continue;
            }
            $title = mb_substr(trim((string) $row['title']), 0, 200);
            $description = trim((string) $row['description']);
            $data = [
                'title_ar' => $title, 'title_en' => $title,
                'description_ar' => $description, 'description_en' => $description,
                'location_ar' => 'يُحدَّد لاحقًا', 'location_en' => 'To be announced',
                'start_datetime' => "$date " . self::LEGACY_START_TIME,
                'end_datetime' => "$date " . self::LEGACY_END_TIME,
                'capacity' => self::LEGACY_CAPACITY,
                'created_by' => $adminId,
            ];
            if ($isEvent) {
                $data['event_type_id'] = $otherType;
            } else {
                $data['category_id'] = $defaultCategory;
                $data['volunteer_hours'] = 0;
            }
            $newId = $this->db->insert($table, $data);
            $imported++;
            $this->report[] = "$table#{$row['id']} → #$newId \"$title\": REVIEW time (set to 08:00–14:00), location, capacity (100)"
                . ($isEvent ? '' : ', hours (0), category') . ' and English text (copied from Arabic).';
        }
        $this->report[] = "$table: $imported imported.";
    }

    /** @param array<string, int> $userMap */
    private function importVolunteerApplications(array $userMap): void
    {
        $rows = $this->legacyRows('SELECT name, email, major, id, msg FROM register');
        if ($rows === []) {
            $this->report[] = 'register (volunteer applications): 0 rows.';

            return;
        }
        $archives = [];
        $linked = 0;
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            $userId = $userMap[$email] ?? null;
            $categoryCode = self::VOLUNTEER_CODES[(string) $row['major']] ?? 'event_management';
            if ($userId === null) {
                $this->archiveAsMessage(null, $row, 'طلب تطوع سابق — ' . $categoryCode, "Legacy volunteer application ($categoryCode)");
                continue;
            }
            $archives[$categoryCode] ??= $this->archiveOpportunity($categoryCode);
            $this->db->query(
                'INSERT IGNORE INTO volunteer_registrations (opportunity_id, user_id, motivation) VALUES (?, ?, ?)',
                [$archives[$categoryCode], $userId, (string) $row['msg']],
            );
            $this->fillStudentId($userId, (string) $row['id']);
            $linked++;
        }
        $this->report[] = "register: $linked linked to archived \"legacy applications\" opportunities; unmatched rows kept as contact messages.";
    }

    /** Legacy reservations have no date/time → archived as messages, never as fake bookings. @param array<string, int> $userMap */
    private function importReservations(array $userMap): void
    {
        $count = 0;
        foreach ($this->legacyRows('SELECT name, email, hgz, id, msg FROM hajz') as $row) {
            $code = self::FACILITY_CODES[(string) $row['hgz']] ?? null;
            $facility = $code !== null
                ? $this->db->fetch('SELECT name_ar, name_en FROM facilities WHERE code = ?', [$code])
                : null;
            $nameAr = $facility['name_ar'] ?? (string) $row['hgz'];
            $nameEn = $facility['name_en'] ?? (string) $row['hgz'];
            $userId = $userMap[mb_strtolower(trim((string) $row['email']))] ?? null;
            $this->archiveAsMessage($userId, $row, "طلب حجز سابق — $nameAr", "Legacy facility request — $nameEn");
            $count++;
        }
        $this->report[] = "hajz: $count archived as contact messages (legacy requests had no date/time, so no reservations were invented).";
    }

    /** @param array<string, int> $userMap */
    private function importProblems(array $userMap): void
    {
        $count = 0;
        $other = (int) $this->db->value("SELECT id FROM feedback_categories WHERE code = 'other'");
        foreach ($this->legacyRows('SELECT name, email, type, msg FROM problem') as $row) {
            $typeText = trim((string) $row['type']);
            $isSuggestion = preg_match('/اقتراح|إقتراح|suggest/iu', $typeText) === 1;
            $email = mb_strtolower(trim((string) $row['email']));
            $userId = $userMap[$email] ?? null;
            $this->db->insert('feedback', [
                'user_id' => $userId,
                'submitter_name' => $userId === null ? mb_substr((string) $row['name'], 0, 120) : null,
                'submitter_email' => $userId === null ? $email : null,
                'type' => $isSuggestion ? 'SUGGESTION' : 'COMPLAINT',
                'category_id' => $other,
                'subject' => mb_substr($typeText !== '' ? $typeText : '—', 0, 200),
                'message' => (string) $row['msg'],
            ]);
            $count++;
        }
        $this->report[] = "problem: $count imported into feedback (type inferred from text, category 'other', status OPEN).";
    }

    /** @param array<string, int> $userMap */
    private function importContacts(array $userMap): void
    {
        $count = 0;
        foreach ($this->legacyRows('SELECT name, email, subject, message FROM contact ORDER BY id') as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            $this->db->insert('contact_messages', [
                'user_id' => $userMap[$email] ?? null,
                'name' => mb_substr((string) $row['name'], 0, 120),
                'email' => mb_substr($email, 0, 190),
                'subject' => mb_substr((string) $row['subject'], 0, 200),
                'message' => (string) $row['message'],
                'status' => 'READ',
            ]);
            $count++;
        }
        $this->report[] = "contact: $count imported (status READ; original dates unknown — the import time is used).";
    }

    /** @param array<string, mixed> $row */
    private function archiveAsMessage(?int $userId, array $row, string $subjectAr, string $subjectEn): void
    {
        $body = "[Imported from the legacy system]\n"
            . 'Name: ' . $row['name'] . "\n"
            . 'Student ID: ' . $row['id'] . "\n"
            . 'Email: ' . $row['email'] . "\n\n"
            . (string) $row['msg'];
        $this->db->insert('contact_messages', [
            'user_id' => $userId,
            'name' => mb_substr((string) $row['name'], 0, 120),
            'email' => mb_substr(mb_strtolower((string) $row['email']), 0, 190),
            'subject' => mb_substr("$subjectAr / $subjectEn", 0, 200),
            'message' => $body,
            'status' => 'READ',
        ]);
    }

    private function archiveOpportunity(string $categoryCode): int
    {
        $category = $this->db->fetch('SELECT id, name_ar, name_en FROM volunteer_categories WHERE code = ?', [$categoryCode]);
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('volunteer_opportunities', [
            'category_id' => (int) $category['id'],
            'title_ar' => 'طلبات تطوع سابقة — ' . $category['name_ar'],
            'title_en' => 'Legacy volunteer applications — ' . $category['name_en'],
            'description_ar' => 'سجل مؤرشف لطلبات التطوع المقدمة في النظام السابق.',
            'description_en' => 'Archived record of volunteer applications submitted in the previous system.',
            'location_ar' => '—', 'location_en' => '—',
            'start_datetime' => $now,
            'end_datetime' => date('Y-m-d H:i:s', time() + 60),
            'volunteer_hours' => 0,
            'capacity' => 10000,
            'cancelled_at' => $now,
            'cancel_reason' => 'Legacy archive',
        ]);
    }

    private function fillStudentId(int $userId, string $legacyId): void
    {
        $legacyId = trim($legacyId);
        if ($legacyId === '' || preg_match('/^\d{4,20}$/', $legacyId) !== 1) {
            return;
        }
        $taken = $this->db->value('SELECT id FROM users WHERE student_id = ?', [$legacyId]);
        if ($taken === null) {
            $this->db->query('UPDATE users SET student_id = ? WHERE id = ? AND student_id IS NULL', [$legacyId, $userId]);
        }
    }

    private function firstAdminId(): ?int
    {
        $id = $this->db->value('SELECT id FROM users WHERE role_id = ? ORDER BY id LIMIT 1', [Role::ADMIN->id()]);

        return $id === null ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function legacyRows(string $sql): array
    {
        try {
            return $this->legacy->fetchAll($sql);
        } catch (\PDOException $e) {
            $this->report[] = 'Legacy query failed (table missing?): ' . $sql;

            return [];
        }
    }
}
