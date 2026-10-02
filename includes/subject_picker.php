<?php
// Shared checkbox subject picker. Expects $pickerRows (from buildPickerRows). Renders the cards only, not the <form>.
/** @var array $pickerRows set by the including page */
$pickerRows = $pickerRows ?? [];
$pickerGroups = [];
foreach ($pickerRows as $r) {
    $pickerGroups[$r['year_level']][$r['semester']][] = $r;
}
$pickerOfferingData = [];
foreach ($pickerRows as $r) {
    foreach ($r['offerings'] as $o) {
        $pickerOfferingData[(int)$o['offering_id']] = [
            'units' => (float)$r['units'], 'day' => $o['day_of_week'],
            'start' => $o['start_time'], 'end' => $o['end_time'], 'label' => $r['subject_code'],
        ];
    }
}
?>
<?php foreach ($pickerGroups as $yl => $semesters): ?>
    <?php foreach ($semesters as $sem => $rows): ?>
        <div class="card mb-3 picker-group">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Year <?= (int)$yl ?> — Semester <?= (int)$sem ?></strong>
                <label class="small mb-0"><input type="checkbox" class="picker-select-all"> Select all available</label>
            </div>
            <div class="table-responsive">
            <table class="table bg-white mb-0 align-middle">
                <thead><tr><th class="col-w-50"></th><th>Code</th><th>Subject</th><th>Units</th><th>Class</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="<?= $row['locked'] ? 'table-secondary' : '' ?>">
                        <td><input type="checkbox" class="picker-check" name="pick[<?= $row['subject_id'] ?>]" value="1" <?= $row['locked'] ? 'disabled' : '' ?>></td>
                        <td><?= htmlspecialchars($row['subject_code']) ?></td>
                        <td><?= htmlspecialchars($row['subject_name']) ?></td>
                        <td><?= $row['units'] ?></td>
                        <td>
                            <?php if ($row['locked']): ?>
                                <span class="text-muted small">
                                    <?= $row['missing_prereqs'] ? 'Requires: ' . htmlspecialchars(implode(', ', $row['missing_prereqs'])) : 'Not offered this term' ?>
                                </span>
                            <?php else: ?>
                                <select class="form-select form-select-sm picker-offering" name="offering[<?= $row['subject_id'] ?>]">
                                    <?php foreach ($row['offerings'] as $o): ?>
                                        <option value="<?= $o['offering_id'] ?>"><?= htmlspecialchars(sectionLabel($o['year_level'], $o['section_name']) . ' — ' . formatSchedule($o['day_of_week'], $o['start_time'], $o['end_time'], $o['room'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php if (empty($pickerRows)): ?>
    <div class="alert alert-secondary">No subjects left to add.</div>
<?php else: ?>
    <p class="mb-2">Total units selected: <strong><span id="unit-total">0</span></strong></p>
    <div id="conflict-warning" class="alert alert-warning" hidden>
        Two selected classes overlap in time: <span id="conflict-text"></span>
        You can still submit, but double-check the schedule first.
    </div>
<?php endif; ?>
<script>
(function () {
    var data = <?= json_encode($pickerOfferingData, JSON_HEX_TAG) ?>;
    function mins(t) { var p = t.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
    function recalc() {
        var chosen = [];
        document.querySelectorAll('.picker-check:checked').forEach(function (cb) {
            var sel = cb.closest('tr').querySelector('.picker-offering');
            if (sel && data[sel.value]) { chosen.push(data[sel.value]); }
        });
        var total = 0;
        chosen.forEach(function (o) { total += o.units; });
        var out = document.getElementById('unit-total');
        if (out) { out.textContent = total % 1 === 0 ? total : total.toFixed(2); }
        var conflicts = [];
        for (var i = 0; i < chosen.length; i++) {
            for (var j = i + 1; j < chosen.length; j++) {
                var a = chosen[i], b = chosen[j];
                if (a.day === b.day && mins(a.start) < mins(b.end) && mins(b.start) < mins(a.end)) {
                    conflicts.push(a.label + ' and ' + b.label + ' (' + a.day + ')');
                }
            }
        }
        var w = document.getElementById('conflict-warning');
        if (w) { document.getElementById('conflict-text').textContent = conflicts.join(', ') + '.'; w.hidden = conflicts.length === 0; }
    }
    document.querySelectorAll('.picker-check, .picker-offering').forEach(function (el) { el.addEventListener('change', recalc); });
    document.querySelectorAll('.picker-select-all').forEach(function (all) {
        all.addEventListener('change', function () {
            all.closest('.picker-group').querySelectorAll('.picker-check:not(:disabled)').forEach(function (cb) { cb.checked = all.checked; });
            recalc();
        });
    });
    recalc();
})();
</script>