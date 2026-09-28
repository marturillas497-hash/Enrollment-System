<?php
// Expects includes/session.php to already be loaded by the including page.
// config/database.php is required here directly (not just assumed) since this file
// calls getDbConnection() itself — require_once is safe to call again even if the
// including page already loaded it.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/helpers/ui_helper.php';

$currentUserData = currentUser();
$role = $currentUserData['role'] ?? null;

// --- Resolve display name + subtitle (program/department) for the profile card ---
$displayName = $currentUserData['username'] ?? '';
$subtitle = '';
$pdo = getDbConnection();

if ($role === 'student') {
    $stmt = $pdo->prepare(
        'SELECT s.first_name, s.last_name, p.program_name
         FROM Student s
         JOIN Enrollment e ON e.enrollment_id = (
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
             ORDER BY e2.enrollment_id DESC LIMIT 1
         )
         JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
         JOIN Program p ON p.program_id = c.program_id
         WHERE s.account_id = :aid'
    );
    $stmt->execute(['aid' => $currentUserData['account_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $displayName = $row['first_name'] . ' ' . $row['last_name'];
        $subtitle = $row['program_name'];
    }
} elseif ($role === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT t.first_name, t.last_name, d.department_name FROM Teacher t
         JOIN Department d ON d.department_id = t.department_id WHERE t.account_id = :aid'
    );
    $stmt->execute(['aid' => $currentUserData['account_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $displayName = $row['first_name'] . ' ' . $row['last_name'];
        $subtitle = $row['department_name'];
    }
} elseif ($role === 'registrar') {
    $stmt = $pdo->prepare(
        'SELECT r.first_name, r.last_name, d.department_name FROM Registrar r
         JOIN Department d ON d.department_id = r.department_id WHERE r.account_id = :aid'
    );
    $stmt->execute(['aid' => $currentUserData['account_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $displayName = $row['first_name'] . ' ' . $row['last_name'];
        $subtitle = $row['department_name'];
    }
} elseif ($role === 'admission_staff') {
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM Admission_Staff WHERE account_id = :aid');
    $stmt->execute(['aid' => $currentUserData['account_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $displayName = $row['first_name'] . ' ' . $row['last_name'];
    }
}
// admin: no profile table — displayName stays as the username, subtitle stays blank.

$roleLabel = $role ? strtoupper(str_replace('_', ' ', $role)) : '';

/** Returns ' active' if the given href matches the currently-loaded script, else ''. */
function navActive(string $href): string
{
    return $_SERVER['SCRIPT_NAME'] === $href ? ' active' : '';
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">

<button type="button" class="mist-menu-toggle" id="mistMenuToggle" aria-label="Open menu">
    <i class="bi bi-list"></i>
</button>
<div class="mist-sidebar-backdrop" id="mistSidebarBackdrop"></div>

<div class="mist-sidebar" id="mistSidebar">
    <div class="brand">
        <img src="<?= BASE_URL ?>/assets/mist-logo.png" alt="MIST Logo">
        <div class="brand-text">
            <strong>MIST</strong>
            <small>ENROLLMENT SYSTEM</small>
        </div>
    </div>

    <a href="<?= BASE_URL ?>/profile.php" class="profile-card">
        <div class="profile-avatar"><i class="bi bi-person-fill"></i></div>
        <div>
            <div class="welcome-label">WELCOME BACK,</div>
            <div class="profile-name"><?= htmlspecialchars($displayName) ?></div>
            <?php if ($subtitle): ?><div class="profile-subtitle"><?= htmlspecialchars($subtitle) ?></div><?php endif; ?>
            <?php if ($roleLabel): ?><span class="role-badge"><?= htmlspecialchars($roleLabel) ?></span><?php endif; ?>
        </div>
    </a>

    <div class="nav-items">
        <?php if ($role === 'admin'): ?>
            <a class="nav-item-link<?= navActive(BASE_URL . '/admin/dashboard.php') ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/admin/register-registrar.php') ?>" href="<?= BASE_URL ?>/admin/register-registrar.php"><i class="bi bi-person-plus"></i> Register Registrar</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/admin/register-teacher.php') ?>" href="<?= BASE_URL ?>/admin/register-teacher.php"><i class="bi bi-person-plus"></i> Register Teacher</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/admin/register-admission-staff.php') ?>" href="<?= BASE_URL ?>/admin/register-admission-staff.php"><i class="bi bi-person-plus"></i> Register Admission Staff</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/admin/staff.php') ?>" href="<?= BASE_URL ?>/admin/staff.php"><i class="bi bi-people"></i> Staff Accounts</a>
        <?php elseif ($role === 'registrar'): ?>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/dashboard.php') ?>" href="<?= BASE_URL ?>/registrar/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/place-student.php') ?>" href="<?= BASE_URL ?>/registrar/place-student.php"><i class="bi bi-person-check"></i> Place Student</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/students.php') ?>" href="<?= BASE_URL ?>/registrar/students.php"><i class="bi bi-people"></i> Students</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/subjects.php') ?>" href="<?= BASE_URL ?>/registrar/subjects.php"><i class="bi bi-journal-bookmark"></i> Subjects</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/prerequisites.php') ?>" href="<?= BASE_URL ?>/registrar/prerequisites.php"><i class="bi bi-diagram-2"></i> Prerequisites</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/curriculum.php') ?>" href="<?= BASE_URL ?>/registrar/curriculum.php"><i class="bi bi-file-earmark-text"></i> Curriculum</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/sections.php') ?>" href="<?= BASE_URL ?>/registrar/sections.php"><i class="bi bi-diagram-3"></i> Sections</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/terms.php') ?>" href="<?= BASE_URL ?>/registrar/terms.php"><i class="bi bi-clock-history"></i> Terms</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/class-offerings.php') ?>" href="<?= BASE_URL ?>/registrar/class-offerings.php"><i class="bi bi-bank"></i> Class Offerings</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/shift-requests.php') ?>" href="<?= BASE_URL ?>/registrar/shift-requests.php"><i class="bi bi-box-arrow-in-right"></i> Shift Requests</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/registrar/irregular-enrollments.php') ?>" href="<?= BASE_URL ?>/registrar/irregular-enrollments.php"><i class="bi bi-list-check"></i> Irregular Enrollments</a>
        <?php elseif ($role === 'admission_staff'): ?>
            <a class="nav-item-link<?= navActive(BASE_URL . '/staff/dashboard.php') ?>" href="<?= BASE_URL ?>/staff/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/staff/review-application.php') ?>" href="<?= BASE_URL ?>/staff/review-application.php"><i class="bi bi-clipboard-check"></i> Review Applications</a>
        <?php elseif ($role === 'teacher'): ?>
            <a class="nav-item-link<?= navActive(BASE_URL . '/teacher/dashboard.php') ?>" href="<?= BASE_URL ?>/teacher/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/teacher/my-subjects.php') ?>" href="<?= BASE_URL ?>/teacher/my-subjects.php"><i class="bi bi-journal-bookmark"></i> My Subjects</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/teacher/my-students.php') ?>" href="<?= BASE_URL ?>/teacher/my-students.php"><i class="bi bi-people"></i> My Students</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/teacher/grades.php') ?>" href="<?= BASE_URL ?>/teacher/grades.php"><i class="bi bi-clipboard-check"></i> Grades</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/teacher/schedule.php') ?>" href="<?= BASE_URL ?>/teacher/schedule.php"><i class="bi bi-calendar-week"></i> Schedule</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/profile.php') ?>" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        <?php elseif ($role === 'student'): ?>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/dashboard.php') ?>" href="<?= BASE_URL ?>/student/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/enrollment.php') ?>" href="<?= BASE_URL ?>/student/enrollment.php"><i class="bi bi-pencil-square"></i> Enrollment</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/my-subjects.php') ?>" href="<?= BASE_URL ?>/student/my-subjects.php"><i class="bi bi-journal-bookmark"></i> My Subjects</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/schedule.php') ?>" href="<?= BASE_URL ?>/student/schedule.php"><i class="bi bi-calendar-week"></i> Schedule</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/grades.php') ?>" href="<?= BASE_URL ?>/student/grades.php"><i class="bi bi-clipboard-check"></i> Grades</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/student/enrollment-history.php') ?>" href="<?= BASE_URL ?>/student/enrollment-history.php"><i class="bi bi-clock-history"></i> Enrollment History</a>
            <a class="nav-item-link<?= navActive(BASE_URL . '/profile.php') ?>" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        <?php endif; ?>
    </div>

    <button type="button" class="logout-link" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
        <i class="bi bi-box-arrow-right"></i> Logout
    </button>
</div>

<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Logout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to log out?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-danger">Log Out</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        const sidebar = document.getElementById('mistSidebar');
        const toggle = document.getElementById('mistMenuToggle');
        const backdrop = document.getElementById('mistSidebarBackdrop');

        function openSidebar() {
            sidebar.classList.add('show');
            backdrop.classList.add('show');
        }
        function closeSidebar() {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        }

        toggle.addEventListener('click', openSidebar);
        backdrop.addEventListener('click', closeSidebar);

        // Tapping a nav link on mobile should close the panel, not leave it open
        // over the next page (it would otherwise still have the .show class).
        sidebar.querySelectorAll('a, button').forEach(function (el) {
            el.addEventListener('click', function () {
                if (window.innerWidth < 768) closeSidebar();
            });
        });
    })();
</script>
