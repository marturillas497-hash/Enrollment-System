<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

// Pending items awaiting THIS registrar's action, all scoped to their department —
// same scoping rule used on every other registrar page.
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Admission_Application a JOIN Program p ON p.program_id = a.program_id
     LEFT JOIN Student st ON st.application_id = a.application_id
     WHERE a.status = 'validated' AND st.student_id IS NULL AND p.department_id = :dept"
);
$stmt->execute(['dept' => $myDepartmentId]);
$pendingPlacements = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Program_shift_request psr
     JOIN Curriculum fc ON fc.curriculum_id = psr.from_curriculum_id
     JOIN Program fp ON fp.program_id = fc.program_id
     WHERE psr.status = 'pending' AND fp.department_id = :dept"
);
$stmt->execute(['dept' => $myDepartmentId]);
$pendingShifts = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Enrollment e
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE e.status = 'pending' AND e.student_standing = 'irregular' AND p.department_id = :dept"
);
$stmt->execute(['dept' => $myDepartmentId]);
$pendingIrregular = (int)$stmt->fetchColumn();

// Active students currently in this department (by their CURRENT program, via latest enrollment).
$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT s.student_id) FROM Student s
     JOIN Enrollment e ON e.enrollment_id = (
         SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
         ORDER BY e2.enrollment_id DESC LIMIT 1
     )
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE s.overall_status = 'active' AND p.department_id = :dept"
);
$stmt->execute(['dept' => $myDepartmentId]);
$activeStudentCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM Section sec JOIN Program p ON p.program_id = sec.program_id WHERE p.department_id = :dept'
);
$stmt->execute(['dept' => $myDepartmentId]);
$sectionCount = (int)$stmt->fetchColumn();

$offeringCount = 0;
if ($currentTerm) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Class_Offering co
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         WHERE co.term_id = :term_id AND p.department_id = :dept'
    );
    $stmt->execute(['term_id' => $currentTerm['term_id'], 'dept' => $myDepartmentId]);
    $offeringCount = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrar Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container">
        <h1>Registrar Dashboard</h1>

        <?php if ($currentTerm): ?>
            <div class="alert alert-success">
                Current term: <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong> (ongoing)
            </div>
        <?php else: ?>
            <div class="alert alert-warning">No term is currently open. <a href="<?= BASE_URL ?>/registrar/terms.php">Open one</a>.</div>
        <?php endif; ?>

        <h2 class="h6 text-muted mt-4 mb-2">Needs Your Attention</h2>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="<?= BASE_URL ?>/registrar/place-student.php" class="text-decoration-none">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6"><?= $pendingPlacements ?></div>
                            <div class="text-muted">Awaiting Placement</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="<?= BASE_URL ?>/registrar/shift-requests.php" class="text-decoration-none">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6"><?= $pendingShifts ?></div>
                            <div class="text-muted">Pending Shift Requests</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="<?= BASE_URL ?>/registrar/irregular-enrollments.php" class="text-decoration-none">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6"><?= $pendingIrregular ?></div>
                            <div class="text-muted">Pending Irregular Enrollments</div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <h2 class="h6 text-muted mb-2">Department Overview</h2>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $activeStudentCount ?></div>
                        <div class="text-muted">Active Students</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $sectionCount ?></div>
                        <div class="text-muted">Sections</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $offeringCount ?></div>
                        <div class="text-muted">Class Offerings This Term</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
