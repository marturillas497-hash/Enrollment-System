<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['admission_staff']);
$pdo = getDbConnection();

$applicationId = $_GET['id'] ?? null;
$message = '';
$error = '';

// --- Handle actions (validate / reject / save corrections) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['application_id'] ?? '';

    if ($action === 'save_and_validate') {
        $f = fn(string $key) => trim($_POST[$key] ?? '');
        $evaluatedYearLevel = $f('evaluated_year_level');

        if ($evaluatedYearLevel === '' || !ctype_digit($evaluatedYearLevel)) {
            $error = 'Evaluated year level is required and must be a number.';
            $applicationId = $id;
        } else {
            $stmt = $pdo->prepare(
                'UPDATE Admission_Application SET
                    applicant_last_name = :last_name, applicant_first_name = :first_name,
                    applicant_middle_name = :middle_name, applicant_suffix = :suffix,
                    birthdate = :birthdate,
                    applicant_province = :province, applicant_municipality = :municipality,
                    applicant_barangay = :barangay, applicant_purok = :purok,
                    father_last_name = :father_last_name, father_first_name = :father_first_name,
                    father_middle_name = :father_middle_name, father_suffix = :father_suffix,
                    father_occupation = :father_occupation,
                    mother_maiden_name = :mother_maiden_name, mother_first_name = :mother_first_name,
                    mother_middle_name = :mother_middle_name, mother_occupation = :mother_occupation,
                    contact_no = :contact_no, email_address = :email_address,
                    guardian_name = :guardian_name, guardian_relationship = :guardian_relationship,
                    guardian_contact_no = :guardian_contact_no,
                    status = \'validated\', validated_by = :validated_by,
                    date_validated = CURDATE(), evaluated_year_level = :evaluated_year_level
                WHERE application_id = :id'
            );
            $stmt->execute([
                'last_name' => $f('applicant_last_name'), 'first_name' => $f('applicant_first_name'),
                'middle_name' => $f('applicant_middle_name') ?: null, 'suffix' => $f('applicant_suffix') ?: null,
                'birthdate' => $f('birthdate'),
                'province' => $f('applicant_province') ?: null, 'municipality' => $f('applicant_municipality') ?: null,
                'barangay' => $f('applicant_barangay') ?: null, 'purok' => $f('applicant_purok') ?: null,
                'father_last_name' => $f('father_last_name') ?: null, 'father_first_name' => $f('father_first_name') ?: null,
                'father_middle_name' => $f('father_middle_name') ?: null, 'father_suffix' => $f('father_suffix') ?: null,
                'father_occupation' => $f('father_occupation') ?: null,
                'mother_maiden_name' => $f('mother_maiden_name') ?: null, 'mother_first_name' => $f('mother_first_name') ?: null,
                'mother_middle_name' => $f('mother_middle_name') ?: null, 'mother_occupation' => $f('mother_occupation') ?: null,
                'contact_no' => $f('contact_no') ?: null, 'email_address' => $f('email_address') ?: null,
                'guardian_name' => $f('guardian_name') ?: null, 'guardian_relationship' => $f('guardian_relationship') ?: null,
                'guardian_contact_no' => $f('guardian_contact_no') ?: null,
                'validated_by' => $user['account_id'],
                'evaluated_year_level' => $evaluatedYearLevel,
                'id' => $id,
            ]);
            $message = "Application #$id validated.";
            $applicationId = null; // back to the list
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            $error = 'A rejection reason is required.';
            $applicationId = $id;
        } else {
            $stmt = $pdo->prepare(
                'UPDATE Admission_Application SET
                    status = \'rejected\', rejected_by = :rejected_by,
                    date_rejected = CURDATE(), rejection_reason = :reason
                 WHERE application_id = :id'
            );
            $stmt->execute(['rejected_by' => $user['account_id'], 'reason' => $reason, 'id' => $id]);
            $message = "Application #$id rejected.";
            $applicationId = null;
        }
    }
}

// --- Detail/edit view for one application ---
$application = null;
if ($applicationId) {
    $stmt = $pdo->prepare(
        'SELECT a.*, p.program_code, p.program_name
         FROM Admission_Application a
         JOIN Program p ON p.program_id = a.program_id
         WHERE a.application_id = :id'
    );
    $stmt->execute(['id' => $applicationId]);
    $application = $stmt->fetch();
}

// --- List view: tabs by status; a search looks across every status ---
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$tabKey = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'pending';
if (!in_array($tabKey, ['pending', 'validated', 'rejected'], true)) { $tabKey = 'pending'; }
$applications = [];
$tabCounts = ['pending' => 0, 'validated' => 0, 'rejected' => 0];
$pageInfo = ['page' => 1, 'perPage' => 15, 'offset' => 0, 'totalPages' => 1];
$pageQueryParams = [];
if (!$application) {
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM Admission_Application GROUP BY status')->fetchAll() as $row) {
        if (isset($tabCounts[$row['status']])) { $tabCounts[$row['status']] = (int) $row['n']; }
    }
    $fromSql = ' FROM Admission_Application a JOIN Program p ON p.program_id = a.program_id';
    $listSql = "SELECT a.application_id, a.applicant_last_name, a.applicant_first_name, a.status, a.application_date,
                       p.program_code
                $fromSql";
    if ($search !== '') {
        $whereSql = ' WHERE a.applicant_last_name LIKE :q1 OR a.applicant_first_name LIKE :q2';
        $listParams = ['q1' => "%$search%", 'q2' => "%$search%"];
        $order = 'a.application_date DESC, a.application_id DESC';
        $pageQueryParams = ['q' => $search];
    } else {
        $whereSql = ' WHERE a.status = :status';
        $listParams = ['status' => $tabKey];
        $order = $tabKey === 'pending'
            ? 'a.application_date ASC, a.application_id ASC'
            : 'COALESCE(a.date_validated, a.date_rejected, a.application_date) DESC, a.application_id DESC';
        $pageQueryParams = ['tab' => $tabKey];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*)$fromSql$whereSql");
    $countStmt->execute($listParams);
    $totalApplications = (int) $countStmt->fetchColumn();
    $pageInfo = paginationInfo($totalApplications, 15);

    $stmt = $pdo->prepare($listSql . $whereSql . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset');
    foreach ($listParams as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $pageInfo['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pageInfo['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $applications = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Applications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($application): ?>

        <h1 class="h4 mb-3">
            Reviewing #<?= $application['application_id'] ?> —
            <?= htmlspecialchars($application['applicant_first_name'] . ' ' . $application['applicant_last_name']) ?>
            <?= statusBadge($application['status']) ?>
        </h1>
        <p class="text-muted">
            Applying to <?= htmlspecialchars($application['program_code']) ?> as
            <?= htmlspecialchars($application['student_type']) ?>. Compare every field below against the
            applicant's physical documents and correct anything that doesn't match before validating.
        </p>

        <?php if ($application['status'] !== 'pending'): ?>
            <div class="alert alert-info">
                This application is already <?= htmlspecialchars($application['status']) ?> and can no
                longer be edited here.
            </div>
        <?php else: ?>

        <nav class="section-nav">
            <a href="#sec-applicant">Applicant</a><a href="#sec-address">Address</a><a href="#sec-father">Father</a>
            <a href="#sec-mother">Mother</a><a href="#sec-contact">Contact &amp; Guardian</a><a href="#sec-placement">Placement</a>
        </nav>

        <form method="post" class="card mb-3">
            <div class="card-body">
                <input type="hidden" name="action" value="save_and_validate">
                <input type="hidden" name="application_id" value="<?= $application['application_id'] ?>">

                <?php $v = fn(string $key) => htmlspecialchars($application[$key] ?? ''); ?>

                <div class="form-section-title" id="sec-applicant">Applicant</div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Last Name</label>
                        <input class="form-control" name="applicant_last_name" value="<?= $v('applicant_last_name') ?>" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">First Name</label>
                        <input class="form-control" name="applicant_first_name" value="<?= $v('applicant_first_name') ?>" required></div>
                    <div class="col-md-2 mb-3"><label class="form-label">Middle Name</label>
                        <input class="form-control" name="applicant_middle_name" value="<?= $v('applicant_middle_name') ?>"></div>
                    <div class="col-md-2 mb-3"><label class="form-label">Suffix</label>
                        <input class="form-control" name="applicant_suffix" value="<?= $v('applicant_suffix') ?>"></div>
                </div>
                <div class="mb-3 col-md-3"><label class="form-label">Birthdate</label>
                    <input type="date" class="form-control" name="birthdate" value="<?= $v('birthdate') ?>" required></div>

                <div class="form-section-title" id="sec-address">Address</div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label">Province</label>
                        <input class="form-control" name="applicant_province" value="<?= $v('applicant_province') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Municipality</label>
                        <input class="form-control" name="applicant_municipality" value="<?= $v('applicant_municipality') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Barangay</label>
                        <input class="form-control" name="applicant_barangay" value="<?= $v('applicant_barangay') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Purok</label>
                        <input class="form-control" name="applicant_purok" value="<?= $v('applicant_purok') ?>"></div>
                </div>

                <div class="form-section-title" id="sec-father">Father</div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label">Last Name</label>
                        <input class="form-control" name="father_last_name" value="<?= $v('father_last_name') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">First Name</label>
                        <input class="form-control" name="father_first_name" value="<?= $v('father_first_name') ?>"></div>
                    <div class="col-md-2 mb-3"><label class="form-label">Middle Name</label>
                        <input class="form-control" name="father_middle_name" value="<?= $v('father_middle_name') ?>"></div>
                    <div class="col-md-1 mb-3"><label class="form-label">Suffix</label>
                        <input class="form-control" name="father_suffix" value="<?= $v('father_suffix') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Occupation</label>
                        <input class="form-control" name="father_occupation" value="<?= $v('father_occupation') ?>"></div>
                </div>

                <div class="form-section-title" id="sec-mother">Mother</div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label">Maiden Name</label>
                        <input class="form-control" name="mother_maiden_name" value="<?= $v('mother_maiden_name') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">First Name</label>
                        <input class="form-control" name="mother_first_name" value="<?= $v('mother_first_name') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Middle Name</label>
                        <input class="form-control" name="mother_middle_name" value="<?= $v('mother_middle_name') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Occupation</label>
                        <input class="form-control" name="mother_occupation" value="<?= $v('mother_occupation') ?>"></div>
                </div>

                <div class="form-section-title" id="sec-contact">Contact &amp; Guardian</div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Contact No.</label>
                        <input class="form-control" name="contact_no" value="<?= $v('contact_no') ?>"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Email</label>
                        <input class="form-control" name="email_address" value="<?= $v('email_address') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Guardian Name</label>
                        <input class="form-control" name="guardian_name" value="<?= $v('guardian_name') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Relationship</label>
                        <input class="form-control" name="guardian_relationship" value="<?= $v('guardian_relationship') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Guardian Contact No.</label>
                        <input class="form-control" name="guardian_contact_no" value="<?= $v('guardian_contact_no') ?>"></div>
                </div>

                <div class="form-section-title" id="sec-placement">Placement</div>
                <div class="mb-3 col-md-3">
                    <label class="form-label">Evaluated Year Level</label>
                    <input type="number" min="1" max="5" class="form-control" name="evaluated_year_level"
                           value="<?= $v('evaluated_year_level') ?: '1' ?>" required>
                    <div class="form-text">Freshmen normally start at 1. For transferees, base this on credited
                        units once transferee credit evaluation exists — for now, use your best judgment.</div>
                </div>

                <div class="action-bar">
                    <button type="submit" class="btn btn-success">Save Corrections &amp; Validate</button>
                    <a href="#reject-panel" id="reject-link" class="btn btn-outline-danger">Reject instead</a>
                    <a href="<?= BASE_URL ?>/staff/review-application.php" class="btn btn-link ms-auto">Cancel</a>
                </div>
            </div>
        </form>

        <form method="post" class="card border-danger">
            <details id="reject-panel" class="card-body" <?= (($_POST['action'] ?? '') === 'reject') ? 'open' : '' ?>>
                <summary class="fw-semibold text-danger">Reject this application</summary>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="application_id" value="<?= $application['application_id'] ?>">
                <textarea class="form-control my-2" name="rejection_reason" rows="2"
                          placeholder="Reason (shown to the applicant)"></textarea>
                <button type="submit" class="btn btn-outline-danger"
                        data-confirm="Reject this application? This cannot be undone here."
                        data-confirm-label="Reject" data-confirm-tone="danger">
                    Reject Application
                </button>
            </details>
        </form>
        <script>
            document.getElementById('reject-link').addEventListener('click', function () {
                document.getElementById('reject-panel').open = true;
            });
        </script>

        <?php endif; ?>

        <a href="<?= BASE_URL ?>/staff/review-application.php?tab=<?= htmlspecialchars($application['status']) ?>" class="d-inline-block mt-3">&larr; Back to list</a>

    <?php else: ?>

        <h1 class="h4 mb-3">Applications</h1>

        <form method="get" class="toolbar">
            <div class="toolbar-field toolbar-field-wide">
                <label for="q">Search all applications</label>
                <input type="text" class="form-control" id="q" name="q" placeholder="Applicant name"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search !== ''): ?><a href="review-application.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
        </form>

        <?php if ($search === ''): ?>
            <?= tabBar([
                'pending'   => ['label' => 'Pending',   'count' => $tabCounts['pending'],   'href' => '?tab=pending'],
                'validated' => ['label' => 'Validated', 'count' => $tabCounts['validated'], 'href' => '?tab=validated'],
                'rejected'  => ['label' => 'Rejected',  'count' => $tabCounts['rejected'],  'href' => '?tab=rejected'],
            ], $tabKey) ?>
        <?php else: ?>
            <p class="text-muted small">Search results across every status (<?= $totalApplications ?> found).</p>
        <?php endif; ?>

        <div class="table-responsive">
<table class="table table-hover bg-white">
            <thead>
                <tr><th>#</th><th>Name</th><th>Program</th><th>Submitted</th><?php if ($search !== ''): ?><th>Status</th><?php endif; ?><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $a): ?>
                    <tr>
                        <td><?= $a['application_id'] ?></td>
                        <td><?= htmlspecialchars($a['applicant_first_name'] . ' ' . $a['applicant_last_name']) ?></td>
                        <td><?= htmlspecialchars($a['program_code']) ?></td>
                        <td class="text-nowrap"><?= $a['application_date'] ? htmlspecialchars(date('M j, Y', strtotime($a['application_date']))) : '' ?></td>
                        <?php if ($search !== ''): ?><td><?= statusBadge($a['status']) ?></td><?php endif; ?>
                        <td>
                            <a href="?id=<?= $a['application_id'] ?>" class="btn btn-sm btn-outline-primary">
                                <?= $a['status'] === 'pending' ? 'Review' : 'View' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($applications)): ?>
                    <tr><td colspan="<?= $search !== '' ? 6 : 5 ?>" class="text-muted">
                        <?php if ($search !== ''): ?>No applications match that name.
                        <?php elseif ($tabKey === 'pending'): ?>No pending applications. Nothing to review.
                        <?php else: ?>No <?= $tabKey ?> applications yet.<?php endif; ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
</div>

<?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageQueryParams) ?>

    <?php endif; ?>
</div>
</body>
</html>
