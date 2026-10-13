<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/correction_helper.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacher = $stmt->fetch();

$offeringId = $_GET['offering_id'] ?? ($_POST['offering_id'] ?? null);

// Ownership check: this offering must belong to THIS teacher, not just any offering ID.
$stmt = $pdo->prepare(
    'SELECT co.*, s.subject_code, s.subject_name, sec.section_name, st.status AS term_status
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE co.offering_id = :id AND co.teacher_id = :tid'
);
$stmt->execute(['id' => $offeringId, 'tid' => $teacher['teacher_id']]);
$offering = $stmt->fetch();

if ($offering === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'That class offering does not exist or is not assigned to you.</div></div>';
    exit;
}

$termLocked = $offering['term_status'] === 'closed';

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    if ($termLocked) {
        $error = 'This term is closed — grades can no longer be edited.';
    } else {
    $grades = $_POST['grade'] ?? [];   // [enrolled_subject_id => value]
    $remarks = $_POST['remarks'] ?? []; // [enrolled_subject_id => value]

    // offering_id is bound below too — $offeringId was already verified above to belong
    // to this teacher, so a spoofed enrolled_subject_id from another offering just matches
    // zero rows instead of silently updating a class this teacher doesn't own.
    $stmt = $pdo->prepare(
        'UPDATE Enrolled_subject SET grade = :grade, remarks = :remarks
         WHERE enrolled_subject_id = :id AND offering_id = :oid'
    );

    $current = $pdo->prepare("SELECT es.enrolled_subject_id, es.grade, es.remarks, st.student_id_number FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id JOIN Student st ON st.student_id = e.student_id WHERE es.offering_id = :oid AND e.status = 'approved'");
    $current->execute(['oid' => $offeringId]);
    $currentRows = [];
    foreach ($current->fetchAll() as $row) {
        $currentRows[(int)$row['enrolled_subject_id']] = $row;
    }

    $rangeRows = [];
    $ruleRows = [];
    foreach ($grades as $enrolledSubjectId => $gradeValue) {
        if (!isset($currentRows[(int)$enrolledSubjectId])) {
            continue;
        }
        $gradeValue = trim($gradeValue);
        $remarkValue = trim($remarks[$enrolledSubjectId] ?? '');

        // Empty grade = leave ungraded (NULL). Otherwise it must be a valid number in the schema's CHECK range.
        if ($gradeValue === '') {
            $gradeToSave = null;
        } elseif (is_numeric($gradeValue) && $gradeValue >= 1.00 && $gradeValue <= 5.00) {
            $gradeToSave = number_format((float)$gradeValue, 2, '.', '');
        } else {
            $rangeRows[] = $enrolledSubjectId;
            continue;
        }

        // Rows the teacher didn't touch are left alone, so older records aren't re-validated.
        $old = $currentRows[(int)$enrolledSubjectId];
        $oldGrade = $old['grade'] === null ? null : number_format((float)$old['grade'], 2, '.', '');
        if ($oldGrade === $gradeToSave && (string)($old['remarks'] ?? '') === $remarkValue) {
            continue;
        }

        /*
         * The grade decides Passed or Failed, never the remark. Dropped and Incomplete
         * must have no grade, and Passed or Failed cannot exist without one.
         */
        if ($gradeToSave !== null) {
            if (in_array($remarkValue, ['Dropped', 'Incomplete'], true)) {
                $ruleRows[] = $enrolledSubjectId;
                continue;
            }
            $remarkValue = (float)$gradeToSave <= 3.00 ? 'Passed' : 'Failed';
        } elseif (in_array($remarkValue, ['Passed', 'Failed'], true)) {
            $ruleRows[] = $enrolledSubjectId;
            continue;
        }

        $stmt->execute([
            'grade' => $gradeToSave,
            'remarks' => $remarkValue !== '' ? $remarkValue : null,
            'id' => $enrolledSubjectId,
            'oid' => $offeringId,
        ]);

        $newRemarks = $remarkValue !== '' ? $remarkValue : null;
        auditLog($pdo, $user, 'grade.edit', 'enrolled_subject', (int)$enrolledSubjectId,
            'Grade for ' . $old['student_id_number'] . ' in ' . $offering['subject_code'] . ': '
            . correctionResultLabel($old['grade'], $old['remarks']) . ' to ' . correctionResultLabel($gradeToSave, $newRemarks) . '.',
            ['grade' => $old['grade'], 'remarks' => $old['remarks']], ['grade' => $gradeToSave, 'remarks' => $newRemarks]);
    }

    $problems = [];
    if ($rangeRows) {
        $problems[] = 'Some grades were out of range (must be 1.00–5.00).';
    }
    if ($ruleRows) {
        $problems[] = 'Some rows broke the grade rules: Dropped and Incomplete cannot have a grade, and Passed or Failed needs a grade.';
    }
    if ($problems) {
        $error = implode(' ', $problems) . ' Those rows were not saved. Fix and resubmit them.';
    } else {
        $message = 'Grades saved.';
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_correction') {
    [$ok, $text] = correctionCreate(
        $pdo, $user, $teacher, (int)($_POST['enrolled_subject_id'] ?? 0),
        (string)($_POST['new_grade'] ?? ''), (string)($_POST['new_remark'] ?? ''), (string)($_POST['reason'] ?? '')
    );
    if ($ok) { $message = $text; } else { $error = $text; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_correction') {
    [$ok, $text] = correctionCancel($pdo, $user, $teacher, (int)($_POST['correction_id'] ?? 0));
    if ($ok) { $message = $text; } else { $error = $text; }
}

$stmt = $pdo->prepare(
    'SELECT es.enrolled_subject_id, es.grade, es.remarks, es.corrected_at, s.student_id_number, s.last_name, s.first_name
     FROM Enrolled_subject es
     JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
     JOIN Student s ON s.student_id = e.student_id
     WHERE es.offering_id = :oid AND e.status = \'approved\'
     ORDER BY s.last_name, s.first_name'
);
$stmt->execute(['oid' => $offeringId]);
$roster = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT gc.* FROM Grade_correction gc JOIN Enrolled_subject es ON es.enrolled_subject_id = gc.enrolled_subject_id
     WHERE es.offering_id = :oid ORDER BY gc.correction_id DESC'
);
$stmt->execute(['oid' => $offeringId]);
$corrections = $stmt->fetchAll();
$latestCorrection = [];
foreach ($corrections as $c) {
    $latestCorrection[(int)$c['enrolled_subject_id']] ??= $c;
}
$rosterNames = [];
foreach ($roster as $r) {
    $rosterNames[(int)$r['enrolled_subject_id']] = $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['student_id_number'] . ')';
}

$siblings = $pdo->prepare(
    "SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, sec.section_name, sec.year_level, p.program_code,
            (SELECT COUNT(*) FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
             WHERE es.offering_id = co.offering_id AND e.status = 'approved') AS enrolled
     FROM (SELECT o.offering_id, o.subject_id, o.teacher_id, o.section_id, o.term_id, cm.meeting_id, cm.day_of_week, cm.start_time, cm.end_time, cm.room_id, r.room_code AS room FROM Class_Offering o LEFT JOIN Class_Meeting cm ON cm.offering_id = o.offering_id LEFT JOIN Room r ON r.room_id = cm.room_id) co JOIN Section sec ON sec.section_id = co.section_id JOIN Program p ON p.program_id = sec.program_id
     WHERE co.teacher_id = :tid AND co.subject_id = :sub AND co.term_id = :term
     ORDER BY sec.year_level, sec.section_name, FIELD(co.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), co.start_time"
);
$siblings->execute(['tid' => $teacher['teacher_id'], 'sub' => $offering['subject_id'], 'term' => $offering['term_id']]);
$siblings = foldMeetings($siblings->fetchAll(), 'offering_id');

$gradedCount = 0;
foreach ($roster as $r) {
    if ($r['grade'] !== null) { $gradedCount++; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1"><?= htmlspecialchars($offering['subject_code'] . ' — ' . $offering['subject_name']) ?></h1>
    <p class="text-muted mb-3">Section <?= htmlspecialchars($offering['section_name']) ?></p>

    <?php if (count($siblings) > 1): ?>
        <div class="mb-3">
            <label class="form-label small mb-1" for="classSwitch">You teach this subject in <?= count($siblings) ?> classes this term</label>
            <select class="form-select" id="classSwitch" onchange="window.location.href = '?offering_id=' + this.value">
                <?php foreach ($siblings as $sib): ?>
                    <option value="<?= (int)$sib['offering_id'] ?>"<?= (int)$sib['offering_id'] === (int)$offering['offering_id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars(sectionLabel($sib['year_level'], $sib['section_name'], $sib['program_code']) . ' · ' . implode(' / ', $sib['schedule_lines']) . ' · ' . (int)$sib['enrolled'] . ' enrolled') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (!empty($roster)): ?>
        <?= progressMeter($gradedCount, count($roster), 'students graded') ?>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($termLocked): ?><div class="alert alert-secondary">This term is closed. Grades are locked and shown read-only. To fix a wrong grade, or to give a grade to an Incomplete, use Request correction. The registrar decides, and the grade changes only if it is approved.</div><?php endif; ?>

    <?php if (!$termLocked): ?>
        <p class="text-muted small">The grade decides the remark: 3.00 or better is Passed, above 3.00 is Failed. For Dropped or Incomplete, leave the grade empty.</p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_grades">
        <input type="hidden" name="offering_id" value="<?= $offeringId ?>">
        <div class="table-responsive">
<table class="table bg-white">
            <thead><tr><th>ID Number</th><th>Name</th><th class="col-w-120">Grade</th><th>Remarks</th><?php if ($termLocked): ?><th>Correction</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($roster as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['student_id_number']) ?></td>
                    <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td>
                        <input type="text" class="form-control form-control-sm grade-input" <?= $termLocked ? 'disabled' : '' ?>
                               name="grade[<?= $r['enrolled_subject_id'] ?>]" data-original="<?= htmlspecialchars($r['grade'] ?? '') ?>"
                               value="<?= htmlspecialchars($r['grade'] ?? '') ?>" placeholder="1.00–5.00">
                    </td>
                    <td>
                        <select class="form-select form-select-sm" <?= $termLocked ? 'disabled' : '' ?>
                                name="remarks[<?= $r['enrolled_subject_id'] ?>]">
                            <option value="" <?= ($r['remarks'] ?? '') === '' ? 'selected' : '' ?>>—</option>
                            <option value="Passed" <?= ($r['remarks'] ?? '') === 'Passed' ? 'selected' : '' ?>>Passed</option>
                            <option value="Failed" <?= ($r['remarks'] ?? '') === 'Failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="Dropped" <?= ($r['remarks'] ?? '') === 'Dropped' ? 'selected' : '' ?>>Dropped</option>
                            <option value="Incomplete" <?= ($r['remarks'] ?? '') === 'Incomplete' ? 'selected' : '' ?>>Incomplete</option>
                        </select>
                    </td>
                    <?php if ($termLocked): $lc = $latestCorrection[(int)$r['enrolled_subject_id']] ?? null; ?>
                    <td class="text-nowrap">
                        <?= $r['corrected_at'] ? statusBadge('corrected', 'Corrected', 'info') . ' ' : '' ?>
                        <?php if ($lc && $lc['status'] === 'pending'): ?>
                            <?= statusBadge('pending', 'Correction pending') ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#correctionModal"
                                    data-es="<?= (int)$r['enrolled_subject_id'] ?>"
                                    data-name="<?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['student_id_number'] . ')') ?>"
                                    data-current="<?= htmlspecialchars(correctionResultLabel($r['grade'], $r['remarks'])) ?>">Request correction</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($roster)): ?><tr><td colspan="<?= $termLocked ? 5 : 4 ?>" class="text-muted">No students enrolled in this offering.</td></tr><?php endif; ?>
            </tbody>
        </table>
</div>
        <?php if (!empty($roster) && !$termLocked): ?>
            <button type="submit" class="btn btn-primary" id="save-grades-btn">Save Grades</button>
            <span class="text-muted small ms-2" id="unsaved-note" hidden>You have unsaved changes.</span>
        <?php endif; ?>
    </form>

    <?php if ($corrections): ?>
        <h2 class="h6 mt-4 mb-2">Correction requests for this class</h2>
        <div class="table-responsive">
        <table class="table table-sm bg-white align-middle">
            <thead><tr><th>Student</th><th>Change</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($corrections as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($rosterNames[(int)$c['enrolled_subject_id']] ?? 'Student') ?></td>
                    <td class="text-nowrap"><?= htmlspecialchars(correctionResultLabel($c['old_grade'], $c['old_remarks'])) ?> &rarr; <strong><?= htmlspecialchars(correctionResultLabel($c['new_grade'], $c['new_remarks'])) ?></strong></td>
                    <td class="small"><?= htmlspecialchars($c['reason']) ?></td>
                    <td>
                        <?= statusBadge($c['status']) ?>
                        <?php if ($c['status'] === 'rejected' && $c['decision_reason']): ?><div class="small text-muted mt-1"><?= htmlspecialchars($c['decision_reason']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['status'] === 'pending'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="cancel_correction">
                                <input type="hidden" name="offering_id" value="<?= (int)$offeringId ?>">
                                <input type="hidden" name="correction_id" value="<?= (int)$c['correction_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Withdraw this correction request?" data-confirm-label="Withdraw" data-confirm-tone="warning">Withdraw</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($termLocked): ?>
<div class="modal fade" id="correctionModal" tabindex="-1" aria-labelledby="correctionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="request_correction">
            <input type="hidden" name="offering_id" value="<?= (int)$offeringId ?>">
            <input type="hidden" name="enrolled_subject_id" id="corrEs" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="correctionModalLabel">Request a correction</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3"><strong id="corrName"></strong><br><span class="text-muted small">Recorded result: <span id="corrCurrent"></span></span></p>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small" for="corrGrade">Corrected grade</label>
                        <input type="text" class="form-control" id="corrGrade" name="new_grade" placeholder="1.00–5.00" inputmode="decimal" autocomplete="off">
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="corrRemark">Or no grade</label>
                        <select class="form-select" id="corrRemark" name="new_remark">
                            <option value="">Use the grade</option>
                            <option value="Dropped">Dropped</option>
                            <option value="Incomplete">Incomplete</option>
                        </select>
                    </div>
                </div>
                <label class="form-label small" for="corrReason">Reason (the registrar sees this)</label>
                <textarea class="form-control" id="corrReason" name="reason" rows="3" minlength="10" maxlength="500" required></textarea>
                <div class="form-text">The remark follows the grade: 3.00 or better is Passed. The grade changes only if the registrar approves.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Send to registrar</button>
            </div>
        </form>
    </div>
</div>
<script>
    (function () {
        var modal = document.getElementById('correctionModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (ev) {
            var b = ev.relatedTarget;
            document.getElementById('corrEs').value = b ? b.getAttribute('data-es') : '';
            document.getElementById('corrName').textContent = b ? b.getAttribute('data-name') : '';
            document.getElementById('corrCurrent').textContent = b ? b.getAttribute('data-current') : '';
            document.getElementById('corrGrade').value = '';
            document.getElementById('corrRemark').value = '';
            document.getElementById('corrReason').value = '';
        });
    })();
</script>
<?php endif; ?>
<script>
    /*
     * Range check and remark sync as the teacher types, plus a heads-up for unsaved edits.
     * This mirrors, not replaces, the rules the server enforces on save.
     */
    (function () {
        var form = document.querySelector('form');
        if (!form) { return; }
        var unsavedNote = document.getElementById('unsaved-note');
        var dirty = false;

        function checkRange(input) {
            var v = input.value.trim();
            var n = parseFloat(v);
            var outOfRange = v !== '' && (!/^\d+(\.\d+)?$/.test(v) || n < 1 || n > 5);
            input.classList.toggle('is-invalid', outOfRange);
        }

        function remarkSelect(input) {
            return input.closest('tr').querySelector('select[name^="remarks"]');
        }

        function isManual(select) {
            return !!select && ['Dropped', 'Incomplete'].indexOf(select.value) !== -1;
        }

        function applyLock(select, clear) {
            var input = select.closest('tr').querySelector('.grade-input');
            if (!input) { return; }
            var manual = isManual(select);
            if (manual && clear) {
                input.value = '';
                checkRange(input);
            }
            input.readOnly = manual;
        }

        function syncRemark(input) {
            var select = remarkSelect(input);
            if (!select || isManual(select)) { return; }
            var v = input.value.trim();
            var n = parseFloat(v);
            var valid = /^\d+(\.\d+)?$/.test(v) && n >= 1 && n <= 5;
            select.value = valid ? (n <= 3 ? 'Passed' : 'Failed') : '';
        }

        document.querySelectorAll('.grade-input').forEach(function (input) {
            checkRange(input);
            input.addEventListener('input', function () {
                checkRange(input);
                syncRemark(input);
                if (input.value !== input.dataset.original) {
                    dirty = true;
                    if (unsavedNote) { unsavedNote.hidden = false; }
                }
            });
        });
        document.querySelectorAll('select[name^="remarks"]').forEach(function (select) {
            applyLock(select, false);
            select.addEventListener('change', function () {
                var input = select.closest('tr').querySelector('.grade-input');
                applyLock(select, true);
                if (input) { syncRemark(input); }
                dirty = true;
                if (unsavedNote) { unsavedNote.hidden = false; }
            });
        });

        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
    })();
</script>
</body>
</html>