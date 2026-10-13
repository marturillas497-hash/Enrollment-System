<?php
/**
 * Grade corrections after a term closes. A teacher asks, the registrar of the class's department
 * decides, and only approval changes the grade. Every step is written to Audit_log.
 */
require_once __DIR__ . '/academic_helper.php';
require_once __DIR__ . '/audit_helper.php';
require_once __DIR__ . '/flash_helper.php';

const CORRECTION_REASON_MIN = 10;
const CORRECTION_REASON_MAX = 500;

/** Typed input to a valid [grade, remarks, error] triple, with the same rules as the grade entry page. */
function correctionNormalizeResult(string $gradeInput, string $remarkInput): array
{
    $gradeInput = trim($gradeInput);
    $remarkInput = trim($remarkInput);
    if ($gradeInput !== '') {
        if (!preg_match('/^\d+(\.\d+)?$/', $gradeInput) || (float)$gradeInput < 1.00 || (float)$gradeInput > 5.00) {
            return [null, null, 'The grade must be a number from 1.00 to 5.00.'];
        }
        if (in_array($remarkInput, ['Dropped', 'Incomplete'], true)) {
            return [null, null, 'Dropped and Incomplete cannot have a grade. Clear one or the other.'];
        }
        $grade = number_format((float)$gradeInput, 2, '.', '');
        return [$grade, (float)$grade <= 3.00 ? 'Passed' : 'Failed', ''];
    }
    if (in_array($remarkInput, ['Dropped', 'Incomplete'], true)) {
        return [null, $remarkInput, ''];
    }
    return [null, null, 'Enter the corrected grade, or choose Dropped or Incomplete.'];
}

function correctionResultLabel($grade, $remarks): string
{
    if ($grade !== null && $grade !== '') {
        return number_format((float)$grade, 2) . ((float)$grade <= 3.00 ? ' (Passed)' : ' (Failed)');
    }
    return ($remarks !== null && $remarks !== '') ? (string)$remarks : 'No result';
}

function correctionSameResult($gradeA, $remarksA, $gradeB, $remarksB): bool
{
    $g = fn($v) => ($v === null || $v === '') ? null : number_format((float)$v, 2, '.', '');
    $r = fn($v) => ($v === null || $v === '') ? null : (string)$v;
    return $g($gradeA) === $g($gradeB) && $r($remarksA) === $r($remarksB);
}

/** The graded row a correction is about, with its owner, department and whether its term is closed. */
function correctionTarget(PDO $pdo, int $enrolledSubjectId, bool $lock = false): ?array
{
    $stmt = $pdo->prepare(
        'SELECT es.enrolled_subject_id, es.enrollment_id, es.grade, es.remarks, es.offering_id,
                e.student_id, e.status AS enrollment_status, e.term_id, e.curriculum_id,
                co.subject_id, co.teacher_id, co.section_id,
                sub.subject_code, sub.subject_name,
                st.school_year, st.semester, st.status AS term_status,
                s.student_id_number, s.first_name, s.last_name, s.overall_status,
                p.department_id
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject sub ON sub.subject_id = co.subject_id
         JOIN School_term st ON st.term_id = e.term_id
         JOIN Student s ON s.student_id = e.student_id
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         WHERE es.enrolled_subject_id = :id' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['id' => $enrolledSubjectId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function correctionStudentName(array $t): string
{
    return $t['first_name'] . ' ' . $t['last_name'] . ' (' . $t['student_id_number'] . ')';
}

/** Teacher asks for a correction. Returns [ok, message]. */
function correctionCreate(PDO $pdo, array $user, array $teacher, int $enrolledSubjectId, string $gradeInput, string $remarkInput, string $reason): array
{
    $reason = trim($reason);
    if (strlen($reason) < CORRECTION_REASON_MIN) {
        return [false, 'Give a reason of at least ' . CORRECTION_REASON_MIN . ' characters so the registrar can decide.'];
    }
    if (strlen($reason) > CORRECTION_REASON_MAX) {
        return [false, 'The reason is too long. Keep it under ' . CORRECTION_REASON_MAX . ' characters.'];
    }
    [$newGrade, $newRemarks, $problem] = correctionNormalizeResult($gradeInput, $remarkInput);
    if ($problem !== '') {
        return [false, $problem];
    }

    try {
        $pdo->beginTransaction();
        $t = correctionTarget($pdo, $enrolledSubjectId, true);
        if ($t === null || (int)$t['teacher_id'] !== (int)$teacher['teacher_id']) {
            $pdo->rollBack();
            return [false, 'That record is not in one of your classes.'];
        }
        if ($t['term_status'] !== 'closed') {
            $pdo->rollBack();
            return [false, 'This term is still open. Edit the grade directly.'];
        }
        if ($t['enrollment_status'] !== 'approved') {
            $pdo->rollBack();
            return [false, 'Only approved enrollments can be corrected.'];
        }
        if (correctionSameResult($t['grade'], $t['remarks'], $newGrade, $newRemarks)) {
            $pdo->rollBack();
            return [false, 'That is already the recorded result.'];
        }
        $pending = $pdo->prepare("SELECT 1 FROM Grade_correction WHERE enrolled_subject_id = :id AND status = 'pending'");
        $pending->execute(['id' => $enrolledSubjectId]);
        if ($pending->fetch() !== false) {
            $pdo->rollBack();
            return [false, 'A correction for this student is already waiting for the registrar.'];
        }

        $pdo->prepare(
            'INSERT INTO Grade_correction (enrolled_subject_id, old_grade, old_remarks, new_grade, new_remarks, reason, requested_by)
             VALUES (:es, :og, :orem, :ng, :nrem, :reason, :tid)'
        )->execute([
            'es' => $enrolledSubjectId, 'og' => $t['grade'], 'orem' => $t['remarks'],
            'ng' => $newGrade, 'nrem' => $newRemarks, 'reason' => $reason, 'tid' => $teacher['teacher_id'],
        ]);
        $correctionId = (int)$pdo->lastInsertId();
        $pdo->commit();

        auditLog($pdo, $user, 'grade.correction.request', 'grade_correction', $correctionId,
            'Correction requested for ' . correctionStudentName($t) . ' in ' . $t['subject_code'] . ': '
            . correctionResultLabel($t['grade'], $t['remarks']) . ' to ' . correctionResultLabel($newGrade, $newRemarks) . '.',
            ['grade' => $t['grade'], 'remarks' => $t['remarks']], ['grade' => $newGrade, 'remarks' => $newRemarks, 'reason' => $reason]);
        return [true, 'Correction sent to the registrar. The grade changes only if it is approved.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return [false, errorMessage($e, 'Could not send the correction.')];
    }
}

/** Teacher withdraws their own pending request. Returns [ok, message]. */
function correctionCancel(PDO $pdo, array $user, array $teacher, int $correctionId): array
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE Grade_correction SET status = 'cancelled', decided_at = NOW()
             WHERE correction_id = :id AND requested_by = :tid AND status = 'pending'"
        );
        $stmt->execute(['id' => $correctionId, 'tid' => $teacher['teacher_id']]);
        if ($stmt->rowCount() === 0) {
            return [false, 'That request is no longer pending.'];
        }
        auditLog($pdo, $user, 'grade.correction.cancel', 'grade_correction', $correctionId, 'Correction request #' . $correctionId . ' withdrawn by the teacher.');
        return [true, 'Request withdrawn.'];
    } catch (Throwable $e) {
        return [false, errorMessage($e, 'Could not withdraw the request.')];
    }
}

/**
 * Later enrollments (and the Graduated status) that leaned on the old result. These are flags for the
 * registrar to review, never automatic changes. $before and $after are completedSubjectSet() results.
 * Returns a list of [enrollment_id or null, message].
 */
function correctionFlagMessages(PDO $pdo, array $t, array $before, array $after): array
{
    $subjectId = (int)$t['subject_id'];
    $wasDone = isset($before[$subjectId]);
    $isDone = isset($after[$subjectId]);
    if ($wasDone === $isDone) {
        return [];
    }

    $studentId = (int)$t['student_id'];
    $out = [];
    $later = $pdo->prepare(
        "SELECT e.enrollment_id, e.curriculum_id, st.school_year, st.semester
         FROM Enrollment e JOIN School_term st ON st.term_id = e.term_id
         WHERE e.student_id = :sid AND e.status <> 'rejected'
           AND (st.school_year > :sy1 OR (st.school_year = :sy2 AND st.semester > :sem))
         ORDER BY st.school_year, st.semester"
    );
    $later->execute(['sid' => $studentId, 'sy1' => $t['school_year'], 'sy2' => $t['school_year'], 'sem' => (int)$t['semester']]);

    foreach ($later->fetchAll() as $en) {
        $eid = (int)$en['enrollment_id'];
        $cid = (int)$en['curriculum_id'];
        $parts = [];

        if (!$isDone) {
            $stmt = $pdo->prepare(
                'SELECT sub.subject_code
                 FROM Enrolled_subject es
                 JOIN Class_Offering co ON co.offering_id = es.offering_id
                 JOIN Subject sub ON sub.subject_id = co.subject_id
                 JOIN Curriculum_prerequisite cp ON cp.curriculum_id = :cid AND cp.subject_id = co.subject_id AND cp.prerequisite_subject_id = :req
                 WHERE es.enrollment_id = :eid'
            );
            $stmt->execute(['cid' => $cid, 'req' => $subjectId, 'eid' => $eid]);
            $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($codes) {
                $parts[] = implode(', ', $codes) . (count($codes) === 1 ? ' requires ' : ' require ') . $t['subject_code'] . ', which is no longer completed.';
            }

            $standBefore = studentStanding($pdo, $studentId, $cid, $before);
            $standAfter = studentStanding($pdo, $studentId, $cid, $after);
            if ($standAfter < $standBefore) {
                $stmt = $pdo->prepare(
                    'SELECT sub.subject_code
                     FROM Enrolled_subject es
                     JOIN Class_Offering co ON co.offering_id = es.offering_id
                     JOIN Subject sub ON sub.subject_id = co.subject_id
                     JOIN Curriculum_subject cs ON cs.curriculum_id = :cid AND cs.subject_id = co.subject_id
                     WHERE es.enrollment_id = :eid AND cs.required_standing IS NOT NULL
                       AND cs.required_standing > :after AND cs.required_standing <= :before'
                );
                $stmt->execute(['cid' => $cid, 'eid' => $eid, 'after' => $standAfter, 'before' => $standBefore]);
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if ($codes) {
                    $parts[] = implode(', ', $codes) . (count($codes) === 1 ? ' needs' : ' need') . ' a year standing the student no longer has.';
                }
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM Enrolled_subject es JOIN Class_Offering co ON co.offering_id = es.offering_id
                 WHERE es.enrollment_id = :eid AND co.subject_id = :sub LIMIT 1'
            );
            $stmt->execute(['eid' => $eid, 'sub' => $subjectId]);
            if ($stmt->fetch() !== false) {
                $parts[] = $t['subject_code'] . ' is taken again here, but it now counts as completed.';
            }
        }

        if ($parts) {
            $out[] = [$eid, $en['school_year'] . ' Semester ' . $en['semester'] . ': ' . implode(' ', $parts)];
        }
    }

    if (!$isDone && ($t['overall_status'] ?? '') === 'graduated') {
        $stmt = $pdo->prepare(
            "SELECT curriculum_id FROM Enrollment WHERE student_id = :sid AND status <> 'rejected' ORDER BY enrollment_id DESC LIMIT 1"
        );
        $stmt->execute(['sid' => $studentId]);
        $curriculumId = (int)$stmt->fetchColumn();
        $missing = $curriculumId > 0 ? missingCurriculumSubjects($pdo, $studentId, $curriculumId) : [];
        if ($missing) {
            $out[] = [null, 'Marked Graduated, but these are not completed now: ' . implode(', ', array_slice($missing, 0, 8))
                . (count($missing) > 8 ? ' and ' . (count($missing) - 8) . ' more' : '') . '.'];
        }
    }
    return $out;
}

/** Registrar approves. Changes the grade, recomputes that enrollment's standing, flags dependants. Returns [ok, message]. */
function correctionApprove(PDO $pdo, array $user, array $registrar, int $correctionId): array
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM Grade_correction WHERE correction_id = :id FOR UPDATE');
        $stmt->execute(['id' => $correctionId]);
        $c = $stmt->fetch();
        if ($c === false || $c['status'] !== 'pending') {
            $pdo->rollBack();
            return [false, 'This request was already handled.'];
        }
        $t = correctionTarget($pdo, (int)$c['enrolled_subject_id'], true);
        if ($t === null || (int)$t['department_id'] !== (int)$registrar['department_id']) {
            $pdo->rollBack();
            return [false, 'That class is not in your department.'];
        }
        if ($t['term_status'] !== 'closed' || $t['enrollment_status'] !== 'approved') {
            $pdo->rollBack();
            return [false, 'Only approved enrollments in a closed term can be corrected here.'];
        }
        if (!correctionSameResult($t['grade'], $t['remarks'], $c['old_grade'], $c['old_remarks'])) {
            $pdo->rollBack();
            return [false, 'The recorded result changed after this was requested. Reject it and ask the teacher to send a new request.'];
        }

        $studentId = (int)$t['student_id'];
        $before = completedSubjectSet($pdo, $studentId);
        $stmt = $pdo->prepare('SELECT student_standing FROM Enrollment WHERE enrollment_id = :id');
        $stmt->execute(['id' => $t['enrollment_id']]);
        $standingBefore = (string)$stmt->fetchColumn();

        $pdo->prepare('UPDATE Enrolled_subject SET grade = :g, remarks = :r, corrected_at = NOW() WHERE enrolled_subject_id = :id')
            ->execute(['g' => $c['new_grade'], 'r' => $c['new_remarks'], 'id' => $c['enrolled_subject_id']]);

        $after = completedSubjectSet($pdo, $studentId);
        $standingAfter = recomputeEnrollmentStanding($pdo, (int)$t['enrollment_id']);
        $flags = correctionFlagMessages($pdo, $t, $before, $after);

        $insertFlag = $pdo->prepare(
            'INSERT INTO Grade_correction_flag (correction_id, enrollment_id, student_id, message) VALUES (:cid, :eid, :sid, :msg)'
        );
        foreach ($flags as [$flagEnrollmentId, $flagMessage]) {
            $insertFlag->execute(['cid' => $correctionId, 'eid' => $flagEnrollmentId, 'sid' => $studentId, 'msg' => substr($flagMessage, 0, 500)]);
        }

        $pdo->prepare("UPDATE Grade_correction SET status = 'approved', decided_by = :by, decided_at = NOW() WHERE correction_id = :id")
            ->execute(['by' => $registrar['registrar_id'], 'id' => $correctionId]);
        $pdo->commit();

        auditLog($pdo, $user, 'grade.correction.approve', 'grade_correction', $correctionId,
            'Correction approved for ' . correctionStudentName($t) . ' in ' . $t['subject_code'] . ': '
            . correctionResultLabel($c['old_grade'], $c['old_remarks']) . ' to ' . correctionResultLabel($c['new_grade'], $c['new_remarks']) . '.',
            ['grade' => $c['old_grade'], 'remarks' => $c['old_remarks'], 'standing' => $standingBefore],
            ['grade' => $c['new_grade'], 'remarks' => $c['new_remarks'], 'standing' => $standingAfter, 'flags' => count($flags)]);

        $message = 'Correction approved. ' . $t['school_year'] . ' Semester ' . $t['semester'] . ' is now ' . $standingAfter . ' standing.';
        if ($flags) {
            $message .= ' ' . count($flags) . ' item' . (count($flags) === 1 ? '' : 's') . ' flagged for review on the student\'s page.';
        }
        return [true, $message];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return [false, errorMessage($e, 'Could not approve the correction.')];
    }
}

/** Registrar rejects, with a required reason. Returns [ok, message]. */
function correctionReject(PDO $pdo, array $user, array $registrar, int $correctionId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A rejection reason is required.'];
    }
    if (strlen($reason) > CORRECTION_REASON_MAX) {
        return [false, 'The reason is too long. Keep it under ' . CORRECTION_REASON_MAX . ' characters.'];
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM Grade_correction WHERE correction_id = :id FOR UPDATE');
        $stmt->execute(['id' => $correctionId]);
        $c = $stmt->fetch();
        if ($c === false || $c['status'] !== 'pending') {
            $pdo->rollBack();
            return [false, 'This request was already handled.'];
        }
        $t = correctionTarget($pdo, (int)$c['enrolled_subject_id']);
        if ($t === null || (int)$t['department_id'] !== (int)$registrar['department_id']) {
            $pdo->rollBack();
            return [false, 'That class is not in your department.'];
        }
        $pdo->prepare(
            "UPDATE Grade_correction SET status = 'rejected', decided_by = :by, decision_reason = :reason, decided_at = NOW() WHERE correction_id = :id"
        )->execute(['by' => $registrar['registrar_id'], 'reason' => $reason, 'id' => $correctionId]);
        $pdo->commit();

        auditLog($pdo, $user, 'grade.correction.reject', 'grade_correction', $correctionId,
            'Correction rejected for ' . correctionStudentName($t) . ' in ' . $t['subject_code'] . '. Reason: ' . $reason,
            null, ['reason' => $reason]);
        return [true, 'Correction rejected. The grade stays as it was.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return [false, errorMessage($e, 'Could not reject the correction.')];
    }
}

/** Registrar marks a flag as reviewed. Scoped to the student whose page they are on. Returns [ok, message]. */
function correctionResolveFlag(PDO $pdo, array $user, array $registrar, int $studentId, int $flagId): array
{
    try {
        $stmt = $pdo->prepare(
            'UPDATE Grade_correction_flag SET resolved_by = :by, resolved_at = NOW()
             WHERE flag_id = :id AND student_id = :sid AND resolved_at IS NULL'
        );
        $stmt->execute(['by' => $registrar['registrar_id'], 'id' => $flagId, 'sid' => $studentId]);
        if ($stmt->rowCount() === 0) {
            return [false, 'That flag was already reviewed.'];
        }
        auditLog($pdo, $user, 'grade.flag.resolve', 'grade_correction_flag', $flagId, 'Correction flag #' . $flagId . ' marked as reviewed.');
        return [true, 'Marked as reviewed.'];
    } catch (Throwable $e) {
        return [false, errorMessage($e, 'Could not update the flag.')];
    }
}

/** Open flags for one student, oldest first. */
function correctionOpenFlags(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        'SELECT f.flag_id, f.message, f.created_at, f.correction_id
         FROM Grade_correction_flag f WHERE f.student_id = :sid AND f.resolved_at IS NULL ORDER BY f.flag_id'
    );
    $stmt->execute(['sid' => $studentId]);
    return $stmt->fetchAll();
}
