<?php
/**
 * Audit trail: one Audit_log row per notable action (grade edits and corrections, placements,
 * approvals, status changes, account deactivation). Writing never throws, so a failed log write
 * cannot block or undo the action being logged.
 */
function auditLog(PDO $pdo, ?array $actor, string $action, string $entityType, ?int $entityId, string $summary, ?array $before = null, ?array $after = null): void
{
    try {
        if (strlen($summary) > 255) {
            $summary = substr($summary, 0, 252) . '...';
        }
        $pdo->prepare(
            'INSERT INTO Audit_log (actor_account_id, actor_role, action, entity_type, entity_id, summary, before_json, after_json)
             VALUES (:actor, :role, :action, :etype, :eid, :summary, :before, :after)'
        )->execute([
            'actor' => $actor['account_id'] ?? null,
            'role' => $actor['role'] ?? null,
            'action' => $action,
            'etype' => $entityType,
            'eid' => $entityId,
            'summary' => $summary,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('[MIST audit] ' . $e->getMessage());
    }
}

/** Plain-language name for an action code, for the audit log screen. */
function auditActionLabel(string $action): string
{
    static $labels = [
        'grade.edit' => 'Grade entered',
        'grade.correction.request' => 'Correction requested',
        'grade.correction.approve' => 'Correction approved',
        'grade.correction.reject' => 'Correction rejected',
        'grade.correction.cancel' => 'Correction cancelled',
        'grade.flag.resolve' => 'Flag reviewed',
        'student.place' => 'Student placed',
        'student.status' => 'Student status changed',
        'shift.approve' => 'Shift approved',
        'shift.reject' => 'Shift rejected',
        'enrollment.approve' => 'Enrollment approved',
        'enrollment.reject' => 'Enrollment rejected',
        'account.deactivate' => 'Account deactivated',
        'account.reactivate' => 'Account reactivated',
    ];
    return $labels[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
}
