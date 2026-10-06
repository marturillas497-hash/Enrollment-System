<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$error = '';

// --- Search, filters and sorting. Every value is checked against a whitelist or the
// department's own data before it goes near the SQL. ---
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

// Filter options are built from the students this registrar can actually see (latest
// enrollment, own department), and a filter is only shown when it has more than one
// choice. A dropdown with a single option would just be clutter.
$scopeSql = "FROM Student s
             JOIN Enrollment e ON e.enrollment_id = (
                 SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id AND e2.status <> 'rejected'
                 ORDER BY e2.enrollment_id DESC LIMIT 1
             )
             JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
             JOIN Program p ON p.program_id = c.program_id
             WHERE p.department_id = :dept";

$stmt = $pdo->prepare("SELECT DISTINCT p.program_id, p.program_code $scopeSql ORDER BY p.program_code");
$stmt->execute(['dept' => $myDepartmentId]);
$programOptions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // program_id => program_code

$stmt = $pdo->prepare("SELECT DISTINCT e.year_level $scopeSql ORDER BY e.year_level");
$stmt->execute(['dept' => $myDepartmentId]);
$yearOptions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->prepare("SELECT DISTINCT s.overall_status $scopeSql");
$stmt->execute(['dept' => $myDepartmentId]);
$allStatusLabels = ['active' => 'Active', 'on_leave' => 'On Leave', 'graduated' => 'Graduated', 'dropped' => 'Dropped'];
$statusOptions = array_intersect_key($allStatusLabels, array_flip($stmt->fetchAll(PDO::FETCH_COLUMN)));

$showProgramFilter = count($programOptions) > 1;
$showYearFilter    = count($yearOptions) > 1;
$showStatusFilter  = count($statusOptions) > 1;

// Names are shown as "First Last", so the name sorts use first name to match.
$sortOptions = [
    'name_az' => ['label' => 'Name (A to Z)',      'sql' => 's.first_name, s.last_name'],
    'name_za' => ['label' => 'Name (Z to A)',      'sql' => 's.first_name DESC, s.last_name DESC'],
    'last_az' => ['label' => 'Last name (A to Z)', 'sql' => 's.last_name, s.first_name'],
    'id'      => ['label' => 'ID number',          'sql' => 's.student_id_number'],
];

$filterProgram = 0;
if (is_string($_GET['program'] ?? null) && ctype_digit($_GET['program']) && isset($programOptions[(int) $_GET['program']])) {
    $filterProgram = (int) $_GET['program'];
}
$filterYear = 0;
if (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && in_array((int) $_GET['year'], $yearOptions, true)) {
    $filterYear = (int) $_GET['year'];
}
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!isset($statusOptions[$filterStatus])) { $filterStatus = ''; }
$filterStanding = is_string($_GET['standing'] ?? null) && in_array($_GET['standing'], ['regular', 'irregular'], true) ? $_GET['standing'] : '';
$sortKey = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name_az';
if (!isset($sortOptions[$sortKey])) { $sortKey = 'name_az'; }

// FROM/JOIN/WHERE is built once and shared by the count query and the page query below, so
// the two can never drift out of sync with each other.
$fromWhere = "FROM Student s
              JOIN Enrollment e ON e.enrollment_id = (
                  SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id AND e2.status <> 'rejected'
                  ORDER BY e2.enrollment_id DESC LIMIT 1
              )
              JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
              JOIN Program p ON p.program_id = c.program_id
              LEFT JOIN Section sec ON sec.section_id = e.section_id
              LEFT JOIN School_term term ON term.term_id = e.term_id
              WHERE p.department_id = :dept";
$params = ['dept' => $myDepartmentId];
if ($search !== '') {
    $fromWhere .= ' AND (s.last_name LIKE :q1 OR s.first_name LIKE :q2 OR s.student_id_number LIKE :q3)';
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
}
if ($filterProgram) { $fromWhere .= ' AND p.program_id = :program'; $params['program'] = $filterProgram; }
if ($filterYear)    { $fromWhere .= ' AND e.year_level = :year';    $params['year'] = $filterYear; }
if ($filterStatus !== '') { $fromWhere .= ' AND s.overall_status = :status'; $params['status'] = $filterStatus; }
if ($filterStanding !== '') { $fromWhere .= ' AND e.student_standing = :standing'; $params['standing'] = $filterStanding; }

$countStmt = $pdo->prepare("SELECT COUNT(*) $fromWhere");
$countStmt->execute($params);
$totalStudents = (int) $countStmt->fetchColumn();
$pageInfo = paginationInfo($totalStudents, 15);

$sql = "SELECT s.student_id, s.student_id_number, s.last_name, s.first_name, s.overall_status, s.account_id,
               s.student_type, e.student_standing,
               p.program_code, e.year_level, sec.section_name, term.school_year, term.semester
        $fromWhere
        ORDER BY " . $sortOptions[$sortKey]['sql'] . '
        LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
// LIMIT/OFFSET must be bound as real integers, not the string PDO's execute($array) shorthand
// would use — MySQL rejects a string there under real (non-emulated) prepared statements.
$stmt->bindValue(':limit', $pageInfo['perPage'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pageInfo['offset'], PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();
$isFiltered = ($search !== '' || $filterProgram || $filterYear || $filterStatus !== '' || $filterStanding !== '' || $sortKey !== 'name_az');

// Same filter/search/sort values as the query above, reused so pagination links don't drop them.
$pageQueryParams = array_filter([
    'q' => $search !== '' ? $search : null,
    'program' => $filterProgram ?: null,
    'year' => $filterYear ?: null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
    'standing' => $filterStanding !== '' ? $filterStanding : null,
    'sort' => $sortKey !== 'name_az' ? $sortKey : null,
], fn($v) => $v !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Students</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="get" class="toolbar">
        <div class="toolbar-field toolbar-field-wide">
            <label for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" placeholder="Name or ID number"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <?php if ($showProgramFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="program">Program</label>
            <select name="program" id="program" class="form-select" onchange="this.form.submit()">
                <option value="">All programs</option>
                <?php foreach ($programOptions as $pid => $code): ?>
                    <option value="<?= (int) $pid ?>" <?= $filterProgram === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showYearFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="year">Year level</label>
            <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                <option value="">All years</option>
                <?php foreach ($yearOptions as $y): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showStatusFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="status">Status</label>
            <select name="status" id="status" class="form-select" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $filterStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="standing">Standing</label>
            <select name="standing" id="standing" class="form-select" onchange="this.form.submit()">
                <option value="">All standings</option>
                <option value="regular" <?= $filterStanding === 'regular' ? 'selected' : '' ?>>Regular</option>
                <option value="irregular" <?= $filterStanding === 'irregular' ? 'selected' : '' ?>>Irregular</option>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="sort">Sort by</label>
            <select name="sort" id="sort" class="form-select" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $value => $opt): ?>
                    <option value="<?= $value ?>" <?= $sortKey === $value ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($isFiltered): ?><a href="students.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        <span class="toolbar-count">Showing <?= count($students) ?> of <?= $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?></span>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead>
            <tr><th>ID Number</th><th>Name</th><th>Program</th><th>Section</th><th>Term</th><th>Status</th><th>Standing</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['student_id_number']) ?></td>
                <td><a href="<?= BASE_URL ?>/registrar/student-view.php?id=<?= (int)$s['student_id'] ?>"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></a></td>
                <td><?= htmlspecialchars($s['program_code']) ?></td>
                <td><?= $s['section_name'] ? htmlspecialchars(sectionLabel($s['year_level'], $s['section_name'])) : '<span class="text-muted">—</span>' ?></td>
                <td><?= $s['school_year'] ? htmlspecialchars($s['school_year'] . ' S' . $s['semester']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= statusBadge($s['overall_status']) ?></td>
                <td><?= statusBadge($s['student_standing']) ?></td>
                <td><a href="<?= BASE_URL ?>/registrar/student-view.php?id=<?= (int)$s['student_id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="8" class="text-muted"><?= $isFiltered ? 'No students match these filters.' : 'No students found.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageQueryParams) ?>
</div>
</body>
</html>