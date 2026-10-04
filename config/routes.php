<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;
use App\Http\Controllers\Account;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth;
use App\Http\Controllers\PublicSite;
use App\Http\Controllers\Student;

/**
 * Route map. Middleware:
 *   guest       only for signed-out visitors
 *   auth        any signed-in user (else → /login?next=...)
 *   role:X      the DB role must be X (checked on every request)
 * CSRF is verified globally for every non-GET request.
 */
return static function (Router $r): void {
    // ---------------------------------------------------------------- Public
    $r->get('/', [PublicSite\HomeController::class, 'index']);
    $r->get('/about', [PublicSite\HomeController::class, 'about']);
    $r->get('/lang/{code}', [PublicSite\LocaleController::class, 'switch']);

    $r->get('/events', [PublicSite\EventController::class, 'index']);
    $r->get('/events/{id}', [PublicSite\EventController::class, 'show']);
    $r->get('/volunteering', [PublicSite\VolunteerController::class, 'index']);
    $r->get('/volunteering/{id}', [PublicSite\VolunteerController::class, 'show']);
    $r->get('/facilities', [PublicSite\FacilityController::class, 'index']);
    $r->get('/facilities/{id}', [PublicSite\FacilityController::class, 'show']);
    $r->get('/contact', [PublicSite\ContactController::class, 'show']);
    $r->post('/contact', [PublicSite\ContactController::class, 'submit']);

    // ---------------------------------------------------------------- Authentication
    $r->group('', ['guest'], static function (Router $r): void {
        $r->get('/login', [Auth\LoginController::class, 'show']);
        $r->post('/login', [Auth\LoginController::class, 'login']);
        $r->get('/register', [Auth\RegisterController::class, 'show']);
        $r->post('/register', [Auth\RegisterController::class, 'register']);
        $r->get('/forgot-password', [Auth\PasswordResetController::class, 'requestForm']);
        $r->post('/forgot-password', [Auth\PasswordResetController::class, 'sendLink']);
        $r->get('/reset-password/{token}', [Auth\PasswordResetController::class, 'resetForm']);
        $r->post('/reset-password/{token}', [Auth\PasswordResetController::class, 'reset']);
    });
    $r->post('/logout', [Auth\LoginController::class, 'logout'], ['auth']);

    // ---------------------------------------------------------------- Any signed-in user
    $r->group('', ['auth'], static function (Router $r): void {
        $r->get('/notifications', [PublicSite\NotificationController::class, 'index']);
        $r->post('/notifications/read-all', [PublicSite\NotificationController::class, 'readAll']);
        $r->post('/notifications/{id}/read', [PublicSite\NotificationController::class, 'read']);
        $r->get('/api/notifications/unread-count', [PublicSite\NotificationController::class, 'unreadCount']);
        $r->get('/attachments/{id}', [Student\FeedbackController::class, 'attachment']);
        // Account profile, shared by students and admins.
        $r->get('/profile', [Account\ProfileController::class, 'edit']);
        $r->post('/profile', [Account\ProfileController::class, 'update']);
        $r->post('/profile/password', [Account\ProfileController::class, 'password']);
        $r->get('/profile/email/confirm/{token}', [Account\ProfileController::class, 'confirmEmailForm']);
        $r->post('/profile/email/confirm/{token}', [Account\ProfileController::class, 'confirmEmail']);
        $r->post('/profile/email/cancel', [Account\ProfileController::class, 'cancelEmailChange']);
    });

    // ---------------------------------------------------------------- Student
    $r->group('', ['auth', 'role:STUDENT'], static function (Router $r): void {
        $r->post('/events/{id}/register', [PublicSite\EventController::class, 'register']);
        $r->post('/events/{id}/unregister', [PublicSite\EventController::class, 'unregister']);
        $r->post('/volunteering/{id}/register', [PublicSite\VolunteerController::class, 'register']);
        $r->post('/volunteering/{id}/unregister', [PublicSite\VolunteerController::class, 'unregister']);
    });
    $r->group('/student', ['auth', 'role:STUDENT'], static function (Router $r): void {
        $r->get('', static fn () => Response::redirect('/student/dashboard'));
        $r->get('/dashboard', [Student\DashboardController::class, 'index']);
        $r->get('/registrations', [Student\RegistrationController::class, 'index']);
        $r->get('/volunteering', [Student\VolunteeringController::class, 'index']);
        $r->get('/reservations', [Student\ReservationController::class, 'index']);
        $r->get('/reservations/new', [Student\ReservationController::class, 'create']);
        $r->post('/reservations', [Student\ReservationController::class, 'store']);
        $r->post('/reservations/{id}/cancel', [Student\ReservationController::class, 'cancel']);
        $r->get('/feedback', [Student\FeedbackController::class, 'index']);
        $r->get('/feedback/new', [Student\FeedbackController::class, 'create']);
        $r->post('/feedback', [Student\FeedbackController::class, 'store']);
        $r->get('/feedback/{id}', [Student\FeedbackController::class, 'show']);
    });

    // ---------------------------------------------------------------- Admin
    $r->group('/admin', ['auth', 'role:ADMIN', 'admin.nav'], static function (Router $r): void {
        $r->get('', static fn () => Response::redirect('/admin/dashboard'));
        $r->get('/dashboard', [Admin\DashboardController::class, 'index']);

        foreach (['events' => Admin\EventController::class, 'volunteering' => Admin\VolunteerController::class] as $prefix => $controller) {
            $r->get("/$prefix", [$controller, 'index']);
            $r->get("/$prefix/create", [$controller, 'create']);
            $r->post("/$prefix", [$controller, 'store']);
            $r->get("/$prefix/{id}", [$controller, 'show']);
            $r->get("/$prefix/{id}/edit", [$controller, 'edit']);
            $r->post("/$prefix/{id}", [$controller, 'update']);
            $r->post("/$prefix/{id}/cancel", [$controller, 'cancel']);
            $r->post("/$prefix/{id}/delete", [$controller, 'destroy']);
            $r->post("/$prefix/registrations/{id}/attendance", [$controller, 'attendance']);
        }
        $r->post('/volunteering/registrations/{id}/complete', [Admin\VolunteerController::class, 'complete']);
        $r->post('/volunteering/registrations/{id}/cancel', [Admin\VolunteerController::class, 'cancelRegistration']);

        $r->get('/registrations', [Admin\RegistrationController::class, 'index']);

        $r->get('/reservations', [Admin\ReservationController::class, 'index']);
        $r->post('/reservations/{id}/approve', [Admin\ReservationController::class, 'approve']);
        $r->post('/reservations/{id}/reject', [Admin\ReservationController::class, 'reject']);
        $r->post('/reservations/{id}/cancel', [Admin\ReservationController::class, 'cancel']);

        $r->get('/facilities', [Admin\FacilityController::class, 'index']);
        $r->get('/facilities/create', [Admin\FacilityController::class, 'create']);
        $r->post('/facilities', [Admin\FacilityController::class, 'store']);
        $r->get('/facilities/{id}/edit', [Admin\FacilityController::class, 'edit']);
        $r->post('/facilities/{id}', [Admin\FacilityController::class, 'update']);

        $r->get('/feedback', [Admin\FeedbackController::class, 'index']);
        $r->get('/feedback/{id}', [Admin\FeedbackController::class, 'show']);
        $r->post('/feedback/{id}/status', [Admin\FeedbackController::class, 'status']);
        $r->post('/feedback/{id}/reply', [Admin\FeedbackController::class, 'reply']);
        $r->post('/feedback/{id}/archive', [Admin\FeedbackController::class, 'archive']);

        $r->get('/messages', [Admin\MessageController::class, 'index']);
        $r->get('/messages/{id}', [Admin\MessageController::class, 'show']);
        $r->post('/messages/{id}/status', [Admin\MessageController::class, 'status']);

        $r->get('/users', [Admin\UserController::class, 'index']);
        $r->post('/users/{id}/role', [Admin\UserController::class, 'role']);
        $r->post('/users/{id}/active', [Admin\UserController::class, 'active']);

        $r->get('/settings', [Admin\SettingsController::class, 'edit']);
        $r->post('/settings', [Admin\SettingsController::class, 'update']);
    });

    // ---------------------------------------------------------------- Legacy URLs (301 to the new routes)
    $legacy = [
        '/index.php' => '/', '/home.php' => '/student/dashboard', '/hopage.php' => '/student/dashboard',
        '/login.php' => '/login', '/signup.php' => '/register', '/forgot-password.php' => '/forgot-password',
        '/tadwapage.php' => '/volunteering', '/revers.php' => '/student/reservations/new',
        '/issue.php' => '/student/feedback/new', '/contactpage.php' => '/contact', '/edit.php' => '/profile', '/student/profile' => '/profile',
        '/admin.php' => '/admin/dashboard', '/admin/dashboard.php' => '/admin/dashboard',
        '/admin/events.php' => '/admin/events', '/admin/complaints.php' => '/admin/feedback',
    ];
    foreach ($legacy as $old => $new) {
        $r->get($old, static fn () => Response::redirect($new, 301));
    }

    // ---------------------------------------------------------------- Development only
    // Order matters: `local` answers 404 first (production, or any non-loopback client such as the LAN IP),
    // then an admin login is required. Mail files themselves live in storage/ and are never web-served.
    $r->group('/_dev', ['local', 'auth', 'role:ADMIN'], static function (Router $r): void {
        $r->get('/mail', [PublicSite\DevMailController::class, 'index']);
        $r->get('/mail/{mailId}', [PublicSite\DevMailController::class, 'show']);
    });
};
