<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$message = '';
$tab = ($_GET['tab'] ?? 'rooms') === 'types' ? 'types' : 'rooms';
$reopen = null;

/** Rooms that look like the same place written differently ("Room 101", "Rm 101", "room-101"). */
function roomKey(string $code): string
{
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    return preg_replace('/^(room|rm)/', '', $k);
}

function roomMeetingCount(PDO $pdo, int $roomId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM Class_Meeting WHERE room_id = :id');
    $stmt->execute(['id' => $roomId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Two classes in one room at the same time in the ongoing term. $together treats every given room as one
 * room (a merge). $involve keeps only pairs that include that room. Returns the first 3 pairs and the total.
 */
function roomClashes(PDO $pdo, array $roomIds, bool $together = false, int $involve = 0): array
{
    $roomIds = array_values(array_map('intval', $roomIds));
    if (!$roomIds) {
        return ['total' => 0, 'rows' => []];
    }
    $in = implode(',', array_fill(0, count($roomIds), '?'));
    $params = $roomIds;
    $sameRoom = 'b.room_id = a.room_id';
    if ($together) {
        $sameRoom = "b.room_id IN ($in)";
        $params = array_merge($params, $roomIds);
    }
    $involveSql = '';
    if ($involve) {
        $involveSql = ' AND (a.room_id = ? OR b.room_id = ?)';
        $params[] = $involve;
        $params[] = $involve;
    }
    $stmt = $pdo->prepare(
        "SELECT sa.subject_code AS code_a, seca.section_name AS section_a, ra.room_code AS room_a, a.day_of_week, a.start_time AS start_a, a.end_time AS end_a,
                sb.subject_code AS code_b, secb.section_name AS section_b, rb.room_code AS room_b, b.start_time AS start_b, b.end_time AS end_b,
                COUNT(*) OVER () AS total
         FROM Class_Meeting a
         JOIN Class_Offering ca ON ca.offering_id = a.offering_id
         JOIN School_term st ON st.term_id = ca.term_id AND st.status = 'ongoing'
         JOIN Class_Meeting b ON b.meeting_id > a.meeting_id AND b.day_of_week = a.day_of_week
              AND b.start_time < a.end_time AND a.start_time < b.end_time
         JOIN Class_Offering cb ON cb.offering_id = b.offering_id AND cb.term_id = ca.term_id
         JOIN Subject sa ON sa.subject_id = ca.subject_id
         JOIN Subject sb ON sb.subject_id = cb.subject_id
         JOIN Section seca ON seca.section_id = ca.section_id
         JOIN Section secb ON secb.section_id = cb.section_id
         JOIN Room ra ON ra.room_id = a.room_id
         JOIN Room rb ON rb.room_id = b.room_id
         WHERE a.room_id IN ($in) AND $sameRoom$involveSql
         ORDER BY a.day_of_week, a.start_time
         LIMIT 3"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return ['total' => $rows ? (int)$rows[0]['total'] : 0, 'rows' => $rows];
}

function roomClashText(array $clashes): string
{
    $parts = [];
    foreach ($clashes['rows'] as $r) {
        $parts[] = $r['code_a'] . ' (' . $r['section_a'] . ') ' . formatSchedule($r['day_of_week'], $r['start_a'], $r['end_a'], $r['room_a'])
            . ' and ' . $r['code_b'] . ' (' . $r['section_b'] . ') ' . formatSchedule($r['day_of_week'], $r['start_b'], $r['end_b'], $r['room_b']);
    }
    $more = $clashes['total'] - count($parts);
    return implode('; ', $parts) . ($more > 0 ? '; and ' . $more . ' more' : '') . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = ctype_digit((string)($_POST['room_id'] ?? '')) ? (int)$_POST['room_id'] : 0;

    if ($action === 'save_room') {
        $tab = 'rooms';
        $code = trim($_POST['room_code'] ?? '');
        $typeId = ctype_digit((string)($_POST['room_type_id'] ?? '')) ? (int)$_POST['room_type_id'] : 0;
        $capacityRaw = trim($_POST['capacity'] ?? '');

        $type = $pdo->prepare('SELECT is_unlimited FROM Room_type WHERE room_type_id = :id');
        $type->execute(['id' => $typeId]);
        $type = $type->fetch();

        if ($code === '' || mb_strlen($code) > 100) {
            $error = 'Room code is required and must be 100 characters or fewer.';
        } elseif ($type === false) {
            $error = 'Pick a room type.';
        } elseif ($capacityRaw !== '' && (!ctype_digit($capacityRaw) || (int)$capacityRaw < 1 || (int)$capacityRaw > 5000)) {
            $error = 'Capacity must be a whole number from 1 to 5000, or left empty.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM Room WHERE LOWER(room_code) = LOWER(:c) AND room_id <> :id');
            $dup->execute(['c' => $code, 'id' => $id]);
            $clashError = '';
            if ($id && (int)$type['is_unlimited'] !== 1) {
                $was = $pdo->prepare('SELECT rt.is_unlimited FROM Room r JOIN Room_type rt ON rt.room_type_id = r.room_type_id WHERE r.room_id = :id');
                $was->execute(['id' => $id]);
                if ((int)$was->fetchColumn() === 1) {
                    $clashes = roomClashes($pdo, [$id]);
                    if ($clashes['total'] > 0) {
                        $clashError = 'This room is in a type with no limit, and classes overlap in it this term: ' . roomClashText($clashes)
                            . ' A type that counts capacity allows one class at a time. Reschedule those classes first.';
                    }
                }
            }
            if ($dup->fetch() !== false) {
                $error = 'A room with that code already exists.';
            } elseif ($clashError !== '') {
                $error = $clashError;
            } else {
                $capacity = ((int)$type['is_unlimited'] === 1 || $capacityRaw === '') ? null : (int)$capacityRaw;
                try {
                    if ($id) {
                        $pdo->prepare('UPDATE Room SET room_code = :c, room_type_id = :t, capacity = :cap WHERE room_id = :id')
                            ->execute(['c' => $code, 't' => $typeId, 'cap' => $capacity, 'id' => $id]);
                        $message = 'Room updated.';
                    } else {
                        $pdo->prepare('INSERT INTO Room (room_code, room_type_id, capacity, is_active) VALUES (:c, :t, :cap, 1)')
                            ->execute(['c' => $code, 't' => $typeId, 'cap' => $capacity]);
                        $message = 'Room added.';
                    }
                } catch (Exception $e) {
                    $error = errorMessage($e, 'Could not save the room.');
                }
            }
        }
        if ($error !== '') { $reopen = 'room'; }
    } elseif ($action === 'toggle_room') {
        $tab = 'rooms';
        $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
        try {
            $pdo->prepare('UPDATE Room SET is_active = :a WHERE room_id = :id')->execute(['a' => $active, 'id' => $id]);
            $message = $active ? 'Room reactivated.' : 'Room deactivated. Classes already scheduled there keep it.';
        } catch (Exception $e) {
            $error = errorMessage($e, 'Could not change the room.');
        }
    } elseif ($action === 'remove_room') {
        $tab = 'rooms';
        if (roomMeetingCount($pdo, $id) === 0) {
            try {
                $pdo->prepare('DELETE FROM Room WHERE room_id = :id')->execute(['id' => $id]);
                $message = 'Room removed.';
            } catch (Exception $e) {
                $error = errorMessage($e, 'Could not remove the room.');
            }
        } else {
            $pdo->prepare('UPDATE Room SET is_active = 0 WHERE room_id = :id')->execute(['id' => $id]);
            $message = 'That room appears in a schedule, so it was deactivated instead of removed.';
        }
    } elseif ($action === 'merge_room') {
        $tab = 'rooms';
        $into = ctype_digit((string)($_POST['into_room_id'] ?? '')) ? (int)$_POST['into_room_id'] : 0;
        $rows = $pdo->prepare('SELECT r.room_id, rt.is_unlimited FROM Room r JOIN Room_type rt ON rt.room_type_id = r.room_type_id WHERE r.room_id IN (:a, :b)');
        $rows->execute(['a' => $id, 'b' => $into]);
        $found = $rows->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($id === 0 || $into === 0 || $id === $into || count($found) !== 2) {
            $error = 'Pick two different rooms to merge.';
        } else {
            $clashes = ['total' => 0, 'rows' => []];
            if ((int)$found[$into] !== 1) {
                $clashes = roomClashes($pdo, [$id, $into], true, $id);
            }
            if ($clashes['total'] > 0) {
                $error = 'Merging would put two classes in the same room at the same time this term: ' . roomClashText($clashes)
                    . ' Move or reschedule one of them first.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE Class_Meeting SET room_id = :into WHERE room_id = :from')->execute(['into' => $into, 'from' => $id]);
                    $pdo->prepare('DELETE FROM Room WHERE room_id = :from')->execute(['from' => $id]);
                    $pdo->commit();
                    $message = 'Rooms merged. Every class that used the removed room now uses the other one.';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = errorMessage($e, 'Could not merge the rooms.');
                }
            }
        }
    } elseif ($action === 'save_type') {
        $tab = 'types';
        $typeId = ctype_digit((string)($_POST['room_type_id'] ?? '')) ? (int)$_POST['room_type_id'] : 0;
        $name = trim($_POST['type_name'] ?? '');
        $unlimited = isset($_POST['is_unlimited']) ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Type name is required and must be 100 characters or fewer.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM Room_type WHERE LOWER(type_name) = LOWER(:n) AND room_type_id <> :id');
            $dup->execute(['n' => $name, 'id' => $typeId]);
            $clashError = '';
            if ($typeId && !$unlimited) {
                $was = $pdo->prepare('SELECT is_unlimited FROM Room_type WHERE room_type_id = :id');
                $was->execute(['id' => $typeId]);
                if ((int)$was->fetchColumn() === 1) {
                    $typeRooms = $pdo->prepare('SELECT room_id FROM Room WHERE room_type_id = :id');
                    $typeRooms->execute(['id' => $typeId]);
                    $clashes = roomClashes($pdo, $typeRooms->fetchAll(PDO::FETCH_COLUMN));
                    if ($clashes['total'] > 0) {
                        $clashError = 'Rooms of this type already have overlapping classes this term: ' . roomClashText($clashes)
                            . ' Counting capacity would double-book them. Reschedule those classes first.';
                    }
                }
            }
            if ($dup->fetch() !== false) {
                $error = 'A room type with that name already exists.';
            } elseif ($clashError !== '') {
                $error = $clashError;
            } else {
                try {
                    if ($typeId) {
                        $pdo->prepare('UPDATE Room_type SET type_name = :n, is_unlimited = :u WHERE room_type_id = :id')
                            ->execute(['n' => $name, 'u' => $unlimited, 'id' => $typeId]);
                        $message = 'Room type updated.';
                    } else {
                        $pdo->prepare('INSERT INTO Room_type (type_name, is_unlimited) VALUES (:n, :u)')->execute(['n' => $name, 'u' => $unlimited]);
                        $message = 'Room type added.';
                    }
                } catch (Exception $e) {
                    $error = errorMessage($e, 'Could not save the room type.');
                }
            }
        }
        if ($error !== '') { $reopen = 'type'; }
    } elseif ($action === 'delete_type') {
        $tab = 'types';
        $typeId = ctype_digit((string)($_POST['room_type_id'] ?? '')) ? (int)$_POST['room_type_id'] : 0;
        $used = $pdo->prepare('SELECT COUNT(*) FROM Room WHERE room_type_id = :id');
        $used->execute(['id' => $typeId]);
        $usedBy = (int)$used->fetchColumn();
        if ($usedBy > 0) {
            $error = 'This room type is in use by ' . $usedBy . ' room(s). Move or remove those rooms first.';
        } else {
            try {
                $pdo->prepare('DELETE FROM Room_type WHERE room_type_id = :id')->execute(['id' => $typeId]);
                $message = 'Room type deleted.';
            } catch (Exception $e) {
                $error = errorMessage($e, 'Could not delete the room type.');
            }
        }
    }
}

$types = $pdo->query(
    'SELECT rt.*, (SELECT COUNT(*) FROM Room r WHERE r.room_type_id = rt.room_type_id) AS room_count
     FROM Room_type rt ORDER BY rt.type_name'
)->fetchAll();

$rooms = $pdo->query(
    'SELECT r.*, rt.type_name, rt.is_unlimited,
            (SELECT COUNT(*) FROM Class_Meeting cm WHERE cm.room_id = r.room_id) AS meeting_count
     FROM Room r JOIN Room_type rt ON rt.room_type_id = r.room_type_id
     ORDER BY rt.type_name, r.room_code'
)->fetchAll();

/* Meetings in the ongoing term, per room, for the deactivate warning. */
$upcoming = [];
$stmt = $pdo->query(
    "SELECT cm.room_id, s.subject_code, sec.section_name, cm.day_of_week, cm.start_time, cm.end_time
     FROM Class_Meeting cm
     JOIN Class_Offering co ON co.offering_id = cm.offering_id
     JOIN School_term st ON st.term_id = co.term_id AND st.status = 'ongoing'
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     WHERE cm.room_id IS NOT NULL ORDER BY cm.day_of_week, cm.start_time"
);
foreach ($stmt->fetchAll() as $m) {
    $upcoming[(int)$m['room_id']][] = $m['subject_code'] . ' (' . $m['section_name'] . ') ' . formatSchedule($m['day_of_week'], $m['start_time'], $m['end_time']);
}

$groups = [];
foreach ($rooms as $r) {
    $groups[roomKey($r['room_code'])][] = $r['room_code'];
}

$e = fn($v): string => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rooms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Rooms</h1>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="6000"><?= $e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error && !$reopen): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

    <?= tabBar([
        'rooms' => ['label' => 'Rooms', 'href' => '?tab=rooms', 'count' => count($rooms)],
        'types' => ['label' => 'Room types', 'href' => '?tab=types', 'count' => count($types)],
    ], $tab) ?>

<?php if ($tab === 'rooms'): ?>
    <div class="my-3">
        <button type="button" class="btn btn-primary" onclick="openRoomModal({})"<?= $types ? '' : ' disabled title="Add a room type first"' ?>><i class="bi bi-plus-lg"></i> Add Room</button>
        <?php if (!$types): ?><span class="text-muted small ms-2">Add a room type first.</span><?php endif; ?>
    </div>
    <p class="text-muted small">Registrars pick from these rooms when scheduling a class. A room that appears in any schedule cannot be deleted, so Remove deactivates it instead. The "Off-campus" kind of type has no capacity limit and never conflicts.</p>

    <div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead><tr><th>Code</th><th>Type</th><th>Capacity</th><th>Status</th><th>Meetings</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rooms as $r): $same = array_diff($groups[roomKey($r['room_code'])], [$r['room_code']]); ?>
            <tr>
                <td>
                    <?= $e($r['room_code']) ?>
                    <?php if ($same): ?><div class="small text-warning-emphasis">Possible duplicate of <?= $e(implode(', ', $same)) ?></div><?php endif; ?>
                </td>
                <td><?= $e($r['type_name']) ?></td>
                <td><?= (int)$r['is_unlimited'] === 1 ? '<span class="text-muted">No limit</span>' : ($r['capacity'] !== null ? (int)$r['capacity'] : '<span class="text-muted">Not set</span>') ?></td>
                <td><?= statusBadge($r['is_active'] ? 'active' : 'inactive') ?></td>
                <td><?= (int)$r['meeting_count'] ?></td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-room="<?= $e(json_encode(['room_id' => (int)$r['room_id'], 'room_code' => $r['room_code'], 'room_type_id' => (string)$r['room_type_id'], 'capacity' => $r['capacity'] === null ? '' : (string)$r['capacity']])) ?>"
                            onclick="openRoomModal(JSON.parse(this.dataset.room))">Edit</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="openMergeModal(<?= (int)$r['room_id'] ?>, <?= $e(json_encode($r['room_code'])) ?>)">Merge</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="toggle_room">
                        <input type="hidden" name="room_id" value="<?= (int)$r['room_id'] ?>">
                        <?php if ($r['is_active']): ?>
                            <input type="hidden" name="active" value="0">
                            <?php $soon = $upcoming[(int)$r['room_id']] ?? []; ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm-label="Deactivate" data-confirm-tone="warning"
                                    data-confirm="<?= $e($soon
                                        ? 'This room is used by ' . count($soon) . ' meeting(s) this term: ' . implode('; ', array_slice($soon, 0, 6)) . (count($soon) > 6 ? '; and more' : '') . '. They keep the room, but it cannot be picked for new meetings. Deactivate?'
                                        : 'Deactivate this room? It cannot be picked for new meetings.') ?>">Deactivate</button>
                        <?php else: ?>
                            <input type="hidden" name="active" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Reactivate</button>
                        <?php endif; ?>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="remove_room">
                        <input type="hidden" name="room_id" value="<?= (int)$r['room_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm-label="Remove" data-confirm-tone="danger"
                                data-confirm="<?= $e((int)$r['meeting_count'] > 0 ? 'This room is used in schedules, so it will be deactivated instead of deleted. Continue?' : 'Delete this room? It has never been used.') ?>">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rooms): ?><tr><td colspan="6" class="text-muted">No rooms yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="modal fade" id="roomModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" class="modal-content">
          <input type="hidden" name="action" value="save_room">
          <input type="hidden" name="room_id" id="roomId">
          <div class="modal-header">
            <h5 class="modal-title" id="roomModalTitle">Add Room</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if ($reopen === 'room'): ?><div class="alert alert-danger js-modal-alert"><?= $e($error) ?></div><?php endif; ?>
            <div class="mb-3"><label class="form-label">Room code</label><input class="form-control" name="room_code" id="roomCode" placeholder="Room 101" maxlength="100" required></div>
            <div class="mb-3">
                <label class="form-label">Type</label>
                <select class="form-select" name="room_type_id" id="roomType" required>
                    <?php foreach ($types as $t): ?><option value="<?= (int)$t['room_type_id'] ?>"><?= $e($t['type_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Capacity</label><input type="number" min="1" max="5000" class="form-control" name="capacity" id="roomCapacity" placeholder="Leave empty if unknown">
                <div class="form-text">Used for a warning when a class is bigger than the room. Ignored for types with no limit.</div></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" class="modal-content">
          <input type="hidden" name="action" value="merge_room">
          <input type="hidden" name="room_id" id="mergeFrom">
          <div class="modal-header">
            <h5 class="modal-title">Merge room</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Every class that uses <strong id="mergeName"></strong> will use the room you pick below, and <strong id="mergeName2"></strong> will be deleted. Use this to join the same room typed in different ways. It is refused if two classes would then share the room at the same time.</p>
            <label class="form-label">Keep this room</label>
            <select class="form-select" name="into_room_id" id="mergeInto" required>
                <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['room_id'] ?>"><?= $e($r['room_code']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Merge</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    function openRoomModal(d) {
        document.getElementById('roomId').value = d.room_id || '';
        document.getElementById('roomCode').value = d.room_code || '';
        if (d.room_type_id) { document.getElementById('roomType').value = d.room_type_id; }
        document.getElementById('roomCapacity').value = d.capacity || '';
        document.getElementById('roomModalTitle').textContent = d.room_id ? 'Edit Room' : 'Add Room';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('roomModal')).show();
    }
    function openMergeModal(id, name) {
        document.getElementById('mergeFrom').value = id;
        document.getElementById('mergeName').textContent = name;
        document.getElementById('mergeName2').textContent = name;
        var sel = document.getElementById('mergeInto');
        Array.prototype.forEach.call(sel.options, function (o) { o.hidden = o.disabled = (String(o.value) === String(id)); });
        for (var i = 0; i < sel.options.length; i++) { if (!sel.options[i].disabled) { sel.selectedIndex = i; break; } }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mergeModal')).show();
    }
    <?php if ($reopen === 'room'): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openRoomModal(<?= json_encode(['room_id' => $_POST['room_id'] ?? '', 'room_code' => $_POST['room_code'] ?? '', 'room_type_id' => $_POST['room_type_id'] ?? '', 'capacity' => $_POST['capacity'] ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    });
    <?php endif; ?>
    </script>

<?php else: ?>
    <div class="my-3"><button type="button" class="btn btn-primary" onclick="openTypeModal({})"><i class="bi bi-plus-lg"></i> Add Room Type</button></div>
    <p class="text-muted small">A type groups rooms of the same kind. Tick "No capacity limit" for places such as off-campus or clinical sites, which never conflict and are never too small.</p>
    <div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead><tr><th>Type</th><th>Capacity</th><th>Rooms</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
            <tr>
                <td><?= $e($t['type_name']) ?></td>
                <td><?= (int)$t['is_unlimited'] === 1 ? 'No limit' : 'Counted' ?></td>
                <td><?= (int)$t['room_count'] ?></td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-type="<?= $e(json_encode(['room_type_id' => (int)$t['room_type_id'], 'type_name' => $t['type_name'], 'is_unlimited' => (int)$t['is_unlimited']])) ?>"
                            onclick="openTypeModal(JSON.parse(this.dataset.type))">Edit</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="delete_type">
                        <input type="hidden" name="room_type_id" value="<?= (int)$t['room_type_id'] ?>">
                        <?php if ((int)$t['room_count'] > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Rooms use this type">Delete</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this room type?" data-confirm-label="Delete" data-confirm-tone="danger">Delete</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$types): ?><tr><td colspan="4" class="text-muted">No room types yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="modal fade" id="typeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" class="modal-content">
          <input type="hidden" name="action" value="save_type">
          <input type="hidden" name="room_type_id" id="typeId">
          <div class="modal-header">
            <h5 class="modal-title" id="typeModalTitle">Add Room Type</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if ($reopen === 'type'): ?><div class="alert alert-danger js-modal-alert"><?= $e($error) ?></div><?php endif; ?>
            <div class="mb-3"><label class="form-label">Type name</label><input class="form-control" name="type_name" id="typeName" maxlength="100" required></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_unlimited" id="typeUnlimited"><label class="form-check-label" for="typeUnlimited">No capacity limit, never conflicts</label></div>
            <div class="form-text">Turning this off later is refused while classes overlap in rooms of this type.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    function openTypeModal(d) {
        document.getElementById('typeId').value = d.room_type_id || '';
        document.getElementById('typeName').value = d.type_name || '';
        document.getElementById('typeUnlimited').checked = !!Number(d.is_unlimited);
        document.getElementById('typeModalTitle').textContent = d.room_type_id ? 'Edit Room Type' : 'Add Room Type';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('typeModal')).show();
    }
    <?php if ($reopen === 'type'): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openTypeModal(<?= json_encode(['room_type_id' => $_POST['room_type_id'] ?? '', 'type_name' => $_POST['type_name'] ?? '', 'is_unlimited' => isset($_POST['is_unlimited']) ? 1 : 0], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    });
    <?php endif; ?>
    </script>
<?php endif; ?>
</div>
</body>
</html>