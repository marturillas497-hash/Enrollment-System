<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacher = $stmt->fetch();

if ($teacher === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'This account has no teacher profile on record. Contact the admin.</div></div>';
    exit;
}

// Terms this teacher has classes in; the newest ongoing one is the default view.
$stmt = $pdo->prepare(
    'SELECT DISTINCT term.term_id, term.school_year, term.semester, term.status
     FROM Class_Offering co JOIN School_term term ON term.term_id = co.term_id
     WHERE co.teacher_id = :tid ORDER BY term.term_id DESC'
);
$stmt->execute(['tid' => $teacher['teacher_id']]);
$terms = $stmt->fetchAll();

$defaultTerm = '';
foreach ($terms as $t) {
    if ($t['status'] === 'ongoing') { $defaultTerm = (string)$t['term_id']; break; }
}
if ($defaultTerm === '' && $terms) { $defaultTerm = (string)$terms[0]['term_id']; }

$termParam = is_string($_GET['term'] ?? null) ? $_GET['term'] : $defaultTerm;
$validTermIds = array_map('strval', array_column($terms, 'term_id'));
if ($termParam !== 'all' && !in_array($termParam, $validTermIds, true)) { $termParam = $defaultTerm; }

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$filterSubject = ctype_digit((string)($_GET['subject'] ?? '')) ? (int)$_GET['subject'] : 0;
$filterSection = ctype_digit((string)($_GET['section'] ?? '')) ? (int)$_GET['section'] : 0;
$sort = ($_GET['sort'] ?? '') === 'id' ? 'id' : 'name';

// Only approved enrollments count: pending or rejected selections are not in anyone's class.
$sql = "SELECT st_.student_id, st_.student_id_number, st_.first_name, st_.last_name,
               co.offering_id, s.subject_id, s.subject_code, s.subject_name, sec.section_id, sec.section_name, sec.year_level,
               term.school_year, term.semester, es.grade, es.remarks
        FROM Class_Offering co
        JOIN Enrolled_subject es ON es.offering_id = co.offering_id
        JOIN Enrollment e ON e.enrollment_id = es.enrollment_id AND e.status = 'approved'
        JOIN Student st_ ON st_.student_id = e.student_id
        JOIN Subject s ON s.subject_id = co.subject_id
        JOIN Section sec ON sec.section_id = co.section_id
        JOIN School_term term ON term.term_id = co.term_id
        WHERE co.teacher_id = :tid";
$params = ['tid' => $teacher['teacher_id']];
if ($termParam !== 'all' && $termParam !== '') { $sql .= ' AND co.term_id = :term'; $params['term'] = $termParam; }
$sql .= ' ORDER BY st_.last_name, st_.first_name, s.subject_code';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

$subjectOptions = [];
$sectionOptions = [];
foreach ($allRows as $r) {
    $subjectOptions[$r['subject_id']] = $r['subject_code'] . ' — ' . $r['subject_name'];
    $sectionOptions[$r['section_id']] = [$r['year_level'], $r['section_name']];
}
asort($subjectOptions);
uasort($sectionOptions, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

$students = [];
foreach ($allRows as $r) {
    if ($filterSubject && (int)$r['subject_id'] !== $filterSubject) { continue; }
    if ($filterSection && (int)$r['section_id'] !== $filterSection) { continue; }
    if ($search !== '') {
        $hay = mb_strtolower($r['first_name'] . ' ' . $r['last_name'] . ' ' . $r['student_id_number']);
        if (mb_strpos($hay, mb_strtolower($search)) === false) { continue; }
    }
    $sid = $r['student_id'];
    $students[$sid]['id'] = $r['student_id_number'];
    $students[$sid]['name'] = $r['last_name'] . ', ' . $r['first_name'];
    $students[$sid]['classes'][] = [
        'offering_id' => $r['offering_id'],
        'subject' => $r['subject_code'],
        'subject_name' => $r['subject_name'],
        'section' => sectionLabel($r['year_level'], $r['section_name']),
        'term' => $r['school_year'] . ' S' . $r['semester'],
        'grade' => $r['grade'],
        'remarks' => $r['remarks'],
    ];
}
if ($sort === 'id') {
    uasort($students, fn($a, $b) => strcmp($a['id'], $b['id']));
}
$modalData = [];
foreach ($students as $sid => $st) { $modalData[$sid] = $st; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">My Students</h1>
    <p class="text-muted">Each student appears once, with every class of yours they are in.</p>

    <form method="get" class="toolbar">
        <div class="toolbar-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" class="form-control" placeholder="Name or ID number" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="term">Term</label>
            <select name="term" id="term" class="form-select" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= $t['term_id'] ?>" <?= $termParam === (string)$t['term_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['school_year'] . ' S' . $t['semester']) ?><?= $t['status'] === 'ongoing' ? ' (ongoing)' : '' ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $termParam === 'all' ? 'selected' : '' ?>>All terms</option>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="subject">Subject</label>
            <select name="subject" id="subject" class="form-select" onchange="this.form.submit()">
                <option value="">All subjects</option>
                <?php foreach ($subjectOptions as $id => $label): ?>
                    <option value="<?= $id ?>" <?= $filterSubject === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="section">Section</label>
            <select name="section" id="section" class="form-select" onchange="this.form.submit()">
                <option value="">All sections</option>
                <?php foreach ($sectionOptions as $id => $sec): ?>
                    <option value="<?= $id ?>" <?= $filterSection === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars(sectionLabel($sec[0], $sec[1])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="sort">Sort by</label>
            <select name="sort" id="sort" class="form-select" onchange="this.form.submit()">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID number</option>
            </select>
        </div>
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <a href="my-students.php" class="btn btn-outline-secondary">Reset</a>
        <span class="toolbar-count">Showing <?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></span>
    </form>

    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>ID Number</th><th>Name</th><th>My Classes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($students as $sid => $st): ?>
            <tr>
                <td><?= htmlspecialchars($st['id']) ?></td>
                <td><?= htmlspecialchars($st['name']) ?></td>
                <td>
                    <?php foreach ($st['classes'] as $cl): ?>
                        <a class="badge text-bg-light border text-decoration-none"
                           href="<?= BASE_URL ?>/teacher/grade-entry.php?offering_id=<?= (int)$cl['offering_id'] ?>"
                           title="Open grade sheet"><?= htmlspecialchars($cl['subject'] . ' · ' . $cl['section']) ?></a>
                    <?php endforeach; ?>
                </td>
                <td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="viewStudent(<?= (int)$sid ?>)" title="View"><i class="bi bi-eye-fill"></i> View</button></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="4" class="text-muted">No students match.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="studentModalTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Only your own classes for this student are shown.</p>
        <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Subject</th><th>Section</th><th>Term</th><th>Grade</th><th>Remarks</th><th></th></tr></thead>
            <tbody id="studentModalBody"></tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var studentData = <?= json_encode($modalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var gradeEntryUrl = <?= json_encode(BASE_URL . '/teacher/grade-entry.php?offering_id=') ?>;
function cell(tr, text) { var td = document.createElement('td'); td.textContent = text; tr.appendChild(td); }
function viewStudent(id) {
    var st = studentData[id];
    document.getElementById('studentModalTitle').textContent = st.name + ' (' + st.id + ')';
    var body = document.getElementById('studentModalBody');
    body.innerHTML = '';
    st.classes.forEach(function (c) {
        var tr = document.createElement('tr');
        cell(tr, c.subject + ' — ' + c.subject_name);
        cell(tr, c.section);
        cell(tr, c.term);
        cell(tr, c.grade === null ? '—' : c.grade);
        cell(tr, c.remarks || '—');
        var td = document.createElement('td');
        var a = document.createElement('a');
        a.href = gradeEntryUrl + c.offering_id;
        a.className = 'btn btn-sm btn-outline-primary';
        a.textContent = 'Grade sheet';
        td.appendChild(a);
        tr.appendChild(td);
        body.appendChild(tr);
    });
    new bootstrap.Modal(document.getElementById('studentModal')).show();
}
</script>
</body>
</html>
