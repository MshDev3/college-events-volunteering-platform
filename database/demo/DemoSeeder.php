<?php

declare(strict_types=1);

namespace Database\Demo;

use App\Core\Database;
use App\Domain\Role;
use App\Services\Auth\PasswordHasher;
use DateTimeImmutable;

/**
 * OPTIONAL demo data — LOCAL DEVELOPMENT ONLY (run with `php database/demo/seed.php`).
 * Everything is inserted into the real database and flows through the real queries;
 * dates are relative to "now" so every status (upcoming/ongoing/completed/cancelled) is visible.
 *
 * Not part of the application: it is outside the Composer autoloader, the seed script refuses to
 * run unless APP_ENV=local, and nothing seeds automatically. Credentials come from DEMO_* in .env
 * and must be strong; empty passwords are replaced by random ones that are printed once.
 * No credential is hard-coded anywhere.
 */
final class DemoSeeder
{
    public const MIN_PASSWORD_LENGTH = 12;

    private readonly string $adminEmail;
    private readonly string $adminPassword;
    private readonly string $studentEmail;
    private readonly string $studentPassword;
    /** @var list<string> passwords this run generated (printed once; configured ones never are) */
    private array $generated = [];

    /** @param array{admin_email:string, admin_password:string, student_email:string, student_password:string} $credentials */
    public function __construct(private readonly Database $db, private readonly PasswordHasher $hasher, array $credentials)
    {
        $this->adminEmail = $credentials['admin_email'];
        $this->studentEmail = $credentials['student_email'];
        $this->adminPassword = $this->passwordOrRandom($credentials['admin_password'], 'DEMO_ADMIN_PASSWORD', $this->adminEmail);
        $this->studentPassword = $this->passwordOrRandom($credentials['student_password'], 'DEMO_STUDENT_PASSWORD', $this->studentEmail);
    }

    /** Strong = at least 12 characters with lower case, upper case, a digit and a symbol. */
    public static function isStrong(string $password): bool
    {
        return strlen($password) >= self::MIN_PASSWORD_LENGTH
            && preg_match('/[a-z]/', $password) === 1 && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1 && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }

    public static function randomPassword(): string
    {
        return 'Demo-' . bin2hex(random_bytes(9)) . '-Xq7';
    }

    private function passwordOrRandom(string $configured, string $variable, string $email): string
    {
        if ($configured === '') {
            $password = self::randomPassword();
            $this->generated[] = "$email / $password";

            return $password;
        }
        if (!self::isStrong($configured)) {
            throw new \InvalidArgumentException("$variable is too weak: use at least " . self::MIN_PASSWORD_LENGTH
                . ' characters with upper and lower case letters, a digit and a symbol (or leave it empty for a random one).');
        }

        return $configured;
    }

    /** @param callable(string): void $out */
    public function run(callable $out): void
    {
        if ($this->db->value('SELECT id FROM users WHERE email = ?', [$this->adminEmail]) !== null) {
            $out('Demo data already present — skipped.');

            return;
        }

        $this->db->transaction(function () use ($out): void {
            $adminId = $this->user('مدير النظام', null, $this->adminEmail, $this->adminPassword, Role::ADMIN, null, '0500000001');
            $studentId = $this->user('عبدالله محمد العتيبي', '441100001', $this->studentEmail, $this->studentPassword, Role::STUDENT, 1, '0551234567');
            $others = [];
            $names = ['سارة أحمد القحطاني', 'Omar Khalid', 'فهد سعد الدوسري', 'Lina Hassan', 'ريم عبدالعزيز الشهري', 'Yousef Ali'];
            foreach ($names as $i => $name) {
                $others[] = $this->user($name, '4411000' . str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT), 'student' . ($i + 2) . '@college.test', $this->studentPassword, Role::STUDENT, ($i % 6) + 1, null);
            }
            $out('Users: 1 admin, ' . (count($others) + 1) . ' students');

            $types = $this->ids('event_types', 'code');
            $events = [
                ['ملتقى التوظيف التقني 2026', 'Tech Career Fair 2026', 'لقاء مباشر مع أكثر من 30 جهة توظيف في القطاعين العام والخاص، مع ورش لكتابة السيرة الذاتية ومقابلات فورية.', 'Meet 30+ public- and private-sector employers, with CV workshops and on-the-spot interviews.', 'exhibition', 'قاعة المناسبات والاحتفالات', 'Events & Ceremonies Hall', '+2 days 10:00', '+2 days 14:00', 50, null],
                ['ورشة أساسيات الأمن السيبراني', 'Cybersecurity Fundamentals Workshop', 'ورشة تطبيقية لحماية الحسابات والشبكات واكتشاف رسائل التصيد.', 'Hands-on workshop on protecting accounts and networks and spotting phishing.', 'workshop', 'معمل الحاسب 3', 'Computer Lab 3', '+5 days 09:00', '+5 days 12:00', 25, null],
                ['مسابقة البرمجة السنوية', 'Annual Programming Contest', 'تنافس فرق من ثلاثة متدربين لحل مسائل خوارزمية خلال خمس ساعات.', 'Teams of three solve algorithmic problems in five hours.', 'competition', 'مبنى تقنية الحاسب', 'Computer Technology building', '+9 days 08:00', '+9 days 13:00', 60, null],
                ['بطولة كرة القدم بين الأقسام', 'Inter-Department Football Cup', 'بطولة رياضية بين أقسام الكلية على الاستاد الرياضي.', 'A football tournament between college departments at the stadium.', 'sports', 'الاستاد الرياضي', 'Sports Stadium', '+14 days 16:00', '+14 days 20:00', 200, null],
                ['محاضرة ريادة الأعمال', 'Entrepreneurship Talk', 'كيف تحوّل مشروع تخرجك إلى شركة ناشئة؟ تجارب ملهمة من رواد أعمال سعوديين.', 'How to turn your graduation project into a startup: stories from Saudi founders.', 'lecture', 'المسرح', 'Theater', '-1 hours', '+2 hours', 300, null],
                ['معرض مشاريع التخرج', 'Graduation Projects Exhibition', 'عرض مشاريع تخرج متدربي الفصل الماضي.', 'Showcase of last semester\'s graduation projects.', 'exhibition', 'قاعة المناسبات والاحتفالات', 'Events & Ceremonies Hall', '-10 days 09:00', '-10 days 13:00', 150, null],
                ['اليوم الوطني', 'National Day Celebration', 'احتفال الكلية باليوم الوطني السعودي بفعاليات ثقافية وتراثية.', 'The college celebrates Saudi National Day with cultural and heritage activities.', 'cultural', 'الساحة الرئيسية', 'Main courtyard', '-1 days 17:00', '-1 days 21:00', 400, null],
                ['ورشة التصميم الجرافيكي', 'Graphic Design Workshop', 'ورشة تم إلغاؤها لتعذر حضور المدرب.', 'Workshop cancelled because the trainer is unavailable.', 'workshop', 'معمل الوسائط', 'Media Lab', '+6 days 10:00', '+6 days 12:00', 20, null],
            ];
            $eventIds = [];
            foreach ($events as $i => $e) {
                $eventIds[] = $this->db->insert('events', [
                    'title_ar' => $e[0], 'title_en' => $e[1], 'description_ar' => $e[2], 'description_en' => $e[3],
                    'event_type_id' => $types[$e[4]], 'location_ar' => $e[5], 'location_en' => $e[6],
                    'start_datetime' => $this->at($e[7]), 'end_datetime' => $this->at($e[8]),
                    'capacity' => $e[9], 'image_path' => $e[10], 'created_by' => $adminId,
                    'cancelled_at' => $i === 7 ? $this->at('-1 days') : null,
                    'cancel_reason' => $i === 7 ? 'تعذر حضور المدرب / Trainer unavailable' : null,
                ]);
            }
            // Cybersecurity workshop almost full: 24 / 25.
            foreach (array_slice($others, 0, 6) as $uid) {
                $this->db->insert('event_registrations', ['event_id' => $eventIds[1], 'user_id' => $uid]);
            }
            for ($i = 0; $i < 17; $i++) {
                $uid = $this->user("Demo Student $i", '44120' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), "demo$i@college.test", $this->studentPassword, Role::STUDENT, 1, null);
                $this->db->insert('event_registrations', ['event_id' => $eventIds[1], 'user_id' => $uid]);
            }
            foreach ([0, 4, 5, 6, 7] as $idx) {
                $this->db->insert('event_registrations', [
                    'event_id' => $eventIds[$idx], 'user_id' => $studentId,
                    'status' => in_array($idx, [5, 6], true) ? 'ATTENDED' : 'REGISTERED',
                ]);
            }
            foreach ($others as $uid) {
                $this->db->insert('event_registrations', ['event_id' => $eventIds[0], 'user_id' => $uid]);
            }
            $out('Events: ' . count($eventIds));

            $cats = $this->ids('volunteer_categories', 'code');
            $opps = [
                ['تنظيم ملتقى التوظيف', 'Career Fair Organizing Team', 'استقبال الزوار وتنظيم الأجنحة خلال ملتقى التوظيف.', 'Welcome visitors and coordinate booths during the career fair.', 'event_management', 'قاعة المناسبات', 'Events Hall', '+2 days 08:00', '+2 days 15:00', 6, 15],
                ['تصميم هوية اليوم المفتوح', 'Open Day Visual Identity', 'تصميم المطبوعات والمنشورات الرقمية لليوم المفتوح.', 'Design print and social media material for the Open Day.', 'design', 'عن بُعد', 'Remote', '+4 days 09:00', '+11 days 17:00', 10, 5],
                ['ترجمة دليل المتدرب', 'Trainee Handbook Translation', 'ترجمة دليل المتدرب من العربية إلى الإنجليزية.', 'Translate the trainee handbook from Arabic to English.', 'translation', 'مكتب شؤون المتدربين', 'Trainee Affairs Office', '+3 days 10:00', '+17 days 14:00', 12, 4],
                ['الدعم التقني لمعامل الحاسب', 'Computer Labs Tech Support', 'مساعدة فريق الدعم الفني في تجهيز المعامل مع بداية الفصل.', 'Help the IT team prepare the labs for the new semester.', 'tech_support', 'مبنى تقنية الحاسب', 'Computer Technology building', '+7 days 08:00', '+7 days 14:00', 6, 10],
                ['حملة التبرع بالدم', 'Blood Donation Drive', 'تنظيم حملة التبرع بالدم بالتعاون مع بنك الدم.', 'Organize the blood donation drive with the blood bank.', 'event_management', 'الساحة الرئيسية', 'Main courtyard', '-20 days 08:00', '-20 days 14:00', 6, 20, 'assets/img/defaults/volunteering/blood-donation.jpg'],
                ['إعداد معرض مشاريع التخرج', 'Graduation Exhibition Setup', 'تجهيز القاعة واستقبال لجان التحكيم.', 'Prepare the hall and host the judging panels.', 'event_management', 'قاعة المناسبات', 'Events Hall', '-10 days 07:00', '-10 days 14:00', 7, 12],
            ];
            $oppIds = [];
            foreach ($opps as $o) {
                $oppIds[] = $this->db->insert('volunteer_opportunities', [
                    'title_ar' => $o[0], 'title_en' => $o[1], 'description_ar' => $o[2], 'description_en' => $o[3],
                    'category_id' => $cats[$o[4]], 'location_ar' => $o[5], 'location_en' => $o[6],
                    'start_datetime' => $this->at($o[7]), 'end_datetime' => $this->at($o[8]),
                    'volunteer_hours' => $o[9], 'capacity' => $o[10], 'image_path' => $o[11] ?? null, 'created_by' => $adminId,
                ]);
            }
            $this->db->insert('volunteer_registrations', ['opportunity_id' => $oppIds[0], 'user_id' => $studentId, 'motivation' => 'أرغب في اكتساب خبرة في تنظيم الفعاليات.']);
            $this->db->insert('volunteer_registrations', ['opportunity_id' => $oppIds[4], 'user_id' => $studentId, 'status' => 'COMPLETED', 'hours_awarded' => 6, 'reviewed_by' => $adminId, 'completed_at' => $this->at('-19 days')]);
            $this->db->insert('volunteer_registrations', ['opportunity_id' => $oppIds[5], 'user_id' => $studentId, 'status' => 'COMPLETED', 'hours_awarded' => 7, 'reviewed_by' => $adminId, 'completed_at' => $this->at('-9 days')]);
            $this->db->insert('volunteer_registrations', ['opportunity_id' => $oppIds[5], 'user_id' => $others[0], 'status' => 'ATTENDED']);
            $this->db->insert('volunteer_registrations', ['opportunity_id' => $oppIds[5], 'user_id' => $others[1], 'status' => 'REGISTERED']);
            $out('Volunteer opportunities: ' . count($oppIds));

            $fac = $this->ids('facilities', 'code');
            $this->db->insert('facility_reservations', ['facility_id' => $fac['theater'], 'user_id' => $studentId, 'purpose' => 'بروفة نادي المسرح', 'start_datetime' => $this->at('+3 days 16:00'), 'end_datetime' => $this->at('+3 days 18:00'), 'expected_attendees' => 25]);
            $this->db->insert('facility_reservations', ['facility_id' => $fac['gymnasium'], 'user_id' => $studentId, 'purpose' => 'تدريب فريق كرة السلة', 'start_datetime' => $this->at('+4 days 18:00'), 'end_datetime' => $this->at('+4 days 20:00'), 'expected_attendees' => 12, 'status' => 'APPROVED', 'decided_by' => $adminId, 'decided_at' => $this->at('-1 days')]);
            $this->db->insert('facility_reservations', ['facility_id' => $fac['event_hall'], 'user_id' => $others[2], 'purpose' => 'Robotics club meetup', 'start_datetime' => $this->at('+8 days 10:00'), 'end_datetime' => $this->at('+8 days 12:00'), 'expected_attendees' => 40]);
            $this->db->insert('facility_reservations', ['facility_id' => $fac['stadium'], 'user_id' => $others[3], 'purpose' => 'Friendly match', 'start_datetime' => $this->at('+14 days 16:00'), 'end_datetime' => $this->at('+14 days 18:00'), 'status' => 'REJECTED', 'admin_note' => 'Stadium is booked for the inter-department cup.', 'decided_by' => $adminId, 'decided_at' => $this->at('-2 days')]);

            $fcat = $this->ids('feedback_categories', 'code');
            $this->db->insert('feedback', ['user_id' => $studentId, 'type' => 'SUGGESTION', 'category_id' => $fcat['services'], 'subject' => 'تمديد ساعات عمل المكتبة', 'message' => 'أقترح تمديد ساعات عمل المكتبة خلال فترة الاختبارات النهائية حتى العاشرة مساءً.', 'status' => 'IN_PROGRESS']);
            $this->db->insert('feedback', ['user_id' => $others[1], 'type' => 'COMPLAINT', 'category_id' => $fcat['facilities'], 'subject' => 'Air conditioning in lab 2', 'message' => 'The air conditioning in computer lab 2 has not been working for a week.', 'status' => 'OPEN']);
            $this->db->insert('contact_messages', ['name' => 'ولي أمر', 'email' => 'parent@example.com', 'subject' => 'الاستفسار عن مواعيد التسجيل', 'message' => 'متى يبدأ التسجيل للفصل القادم؟']);

            $this->db->insert('notifications', ['user_id' => $studentId, 'type' => 'welcome', 'data' => '{}', 'link' => '/student/dashboard']);
            $this->db->insert('notifications', ['user_id' => $studentId, 'type' => 'reservation_approved', 'data' => json_encode(['facility_ar' => 'الصالة الرياضية', 'facility_en' => 'Gymnasium'], JSON_UNESCAPED_UNICODE), 'link' => '/student/reservations']);
            $this->db->insert('notifications', ['user_id' => $studentId, 'type' => 'volunteer_completed', 'data' => json_encode(['title_ar' => 'إعداد معرض مشاريع التخرج', 'title_en' => 'Graduation Exhibition Setup', 'hours' => '7'], JSON_UNESCAPED_UNICODE), 'link' => '/student/volunteering']);
            $out('Reservations, feedback, messages and notifications created.');
        });

        $out("Demo accounts: {$this->adminEmail} (Admin), {$this->studentEmail} and student2-7@college.test (Student).");
        if ($this->generated !== []) {
            $out('Generated passwords (shown once, put them in .env as DEMO_*_PASSWORD to keep using them): ' . implode('  |  ', $this->generated));
        }
    }

    private function user(string $name, ?string $studentId, string $email, string $password, Role $role, ?int $department, ?string $phone): int
    {
        return $this->db->insert('users', [
            'full_name' => $name, 'student_id' => $studentId, 'email' => $email, 'phone' => $phone,
            'password_hash' => $this->hasher->hash($password), 'role_id' => $role->id(), 'department_id' => $department,
        ]);
    }

    /** @return array<string, int> code => id */
    private function ids(string $table, string $column): array
    {
        $map = [];
        foreach ($this->db->fetchAll("SELECT id, `$column` FROM `$table`") as $row) {
            $map[(string) $row[$column]] = (int) $row['id'];
        }

        return $map;
    }

    private function at(string $relative): string
    {
        return (new DateTimeImmutable($relative))->format('Y-m-d H:i:s');
    }
}
