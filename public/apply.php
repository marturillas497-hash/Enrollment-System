<?php
require_once __DIR__ . '/../src/helpers/ui_helper.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();
$error = '';
$success = null; // will hold ['application_id' => ..., 'name' => ...] after success

$programs = $pdo->query(
    'SELECT p.program_id, p.program_code, p.program_name, d.department_name
     FROM Program p
     JOIN Department d ON d.department_id = p.department_id
     ORDER BY d.department_name, p.program_code'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn(string $key) => trim($_POST[$key] ?? '');

    $lastName    = $f('applicant_last_name');
    $firstName   = $f('applicant_first_name');
    $middleName  = $f('applicant_middle_name');
    $suffix      = $f('applicant_suffix');
    $birthdate   = $f('birthdate');
    $province    = $f('applicant_province');
    $municipality = $f('applicant_municipality');
    $barangay    = $f('applicant_barangay');
    $purok       = $f('applicant_purok');

    $fatherLast  = $f('father_last_name');
    $fatherFirst = $f('father_first_name');
    $fatherMid   = $f('father_middle_name');
    $fatherSuf   = $f('father_suffix');
    $fatherOcc   = $f('father_occupation');

    $motherMaiden = $f('mother_maiden_name');
    $motherFirst  = $f('mother_first_name');
    $motherMid    = $f('mother_middle_name');
    $motherOcc    = $f('mother_occupation');

    $contactNo    = $f('contact_no');
    $email        = $f('email_address');
    $guardianName = $f('guardian_name');
    $guardianRel  = $f('guardian_relationship');
    $guardianContact = $f('guardian_contact_no');

    $studentType = $f('student_type');
    $programId   = $f('program_id');
    $yearLevel   = $f('applicant_year_level');

    // Required fields mirror the schema's NOT NULL columns.
    $validProgramIds = array_column($programs, 'program_id');
    if ($lastName === '' || $firstName === '' || $birthdate === ''
        || !in_array($studentType, ['freshman', 'transferee'], true) || $programId === '') {
        $error = 'Please fill in your name, birthdate, student type, and program.';
    } elseif ($studentType === 'transferee' && (!ctype_digit($yearLevel) || (int)$yearLevel < 1 || (int)$yearLevel > 4)) {
        $error = 'Transferees must select their current year level.';
    } elseif (!in_array((int)$programId, $validProgramIds, true)) {
        // Never trust the raw program_id — the dropdown only ever lists real
        // programs, but a tampered request could send anything.
        $error = 'Please select a valid program.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look valid.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO Admission_Application (
                    applicant_last_name, applicant_first_name, applicant_middle_name, applicant_suffix,
                    birthdate, applicant_province, applicant_municipality, applicant_barangay, applicant_purok,
                    father_last_name, father_first_name, father_middle_name, father_suffix, father_occupation,
                    mother_maiden_name, mother_first_name, mother_middle_name, mother_occupation,
                    contact_no, email_address, guardian_name, guardian_relationship, guardian_contact_no,
                    student_type, applicant_year_level, program_id, application_date, status
                ) VALUES (
                    :last_name, :first_name, :middle_name, :suffix,
                    :birthdate, :province, :municipality, :barangay, :purok,
                    :father_last_name, :father_first_name, :father_middle_name, :father_suffix, :father_occupation,
                    :mother_maiden_name, :mother_first_name, :mother_middle_name, :mother_occupation,
                    :contact_no, :email_address, :guardian_name, :guardian_relationship, :guardian_contact_no,
                    :student_type, :applicant_year_level, :program_id, CURDATE(), \'pending\'
                )'
            );

            $stmt->execute([
                'last_name' => $lastName, 'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'suffix' => $suffix !== '' ? $suffix : null,
                'birthdate' => $birthdate,
                'province' => $province !== '' ? $province : null,
                'municipality' => $municipality !== '' ? $municipality : null,
                'barangay' => $barangay !== '' ? $barangay : null,
                'purok' => $purok !== '' ? $purok : null,
                'father_last_name' => $fatherLast !== '' ? $fatherLast : null,
                'father_first_name' => $fatherFirst !== '' ? $fatherFirst : null,
                'father_middle_name' => $fatherMid !== '' ? $fatherMid : null,
                'father_suffix' => $fatherSuf !== '' ? $fatherSuf : null,
                'father_occupation' => $fatherOcc !== '' ? $fatherOcc : null,
                'mother_maiden_name' => $motherMaiden !== '' ? $motherMaiden : null,
                'mother_first_name' => $motherFirst !== '' ? $motherFirst : null,
                'mother_middle_name' => $motherMid !== '' ? $motherMid : null,
                'mother_occupation' => $motherOcc !== '' ? $motherOcc : null,
                'contact_no' => $contactNo !== '' ? $contactNo : null,
                'email_address' => $email !== '' ? $email : null,
                'guardian_name' => $guardianName !== '' ? $guardianName : null,
                'guardian_relationship' => $guardianRel !== '' ? $guardianRel : null,
                'guardian_contact_no' => $guardianContact !== '' ? $guardianContact : null,
                'student_type' => $studentType,
                'applicant_year_level' => $studentType === 'transferee' ? (int)$yearLevel : null,
                'program_id' => $programId,
            ]);

            $success = [
                'application_id' => $pdo->lastInsertId(),
                'name' => "$firstName $lastName",
            ];
        } catch (Exception $e) {
            // Never surface $e->getMessage() here — unlike the logged-in registrar
            // screens, this page is public with no login, so a raw DB error string
            // must never reach an anonymous visitor.
            $error = 'Something went wrong submitting your application. Please check your entries and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admission Application — MIST Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/apply.css">
</head>
<body>
<div class="apply-box">
    <div class="apply-header">
        <img src="<?= BASE_URL ?>/assets/mist-logo.png" alt="MIST Logo">
        <div>
            <a href="<?= BASE_URL ?>/login.php" class="back-link small d-block mb-1">&larr; Back to Login</a>
            <h1 class="h4 mb-0">Admission Application</h1>
        </div>
    </div>

    <div class="apply-body">

    <?php if ($success): ?>
        <div class="alert alert-success">
            <h2 class="h5">Application submitted</h2>
            <p>Thank you, <strong><?= htmlspecialchars($success['name']) ?></strong>. Your reference number is
                <strong>#<?= htmlspecialchars($success['application_id']) ?></strong>.</p>
            <p class="mb-0">Next: visit the school in person and find Admission Staff at the counter. Bring your
                physical documents (birth certificate, report card, etc.) so they can verify this application
                against them.</p>
        </div>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($programs)): ?>
            <div class="alert alert-warning">
                No programs are set up yet, so applications can't be submitted. Add rows to
                <code>Department</code> and <code>Program</code> in phpMyAdmin first.
            </div>
        <?php else: ?>

        <form method="post" novalidate id="apply-form" data-steps="<?= $error ? 'off' : 'on' ?>">
            <?php $v = fn(string $key) => htmlspecialchars($_POST[$key] ?? ''); ?>

            <?= stepper(['Applicant', 'Family', 'Contact', 'Program'], 1) ?>

            <div class="apply-step active" data-step="1">

            <div class="section-title">Applicant Information</div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control neu-input" name="applicant_last_name" value="<?= $v('applicant_last_name') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control neu-input" name="applicant_first_name" value="<?= $v('applicant_first_name') ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control neu-input" name="applicant_middle_name" value="<?= $v('applicant_middle_name') ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Suffix</label>
                    <input type="text" class="form-control neu-input" name="applicant_suffix" value="<?= $v('applicant_suffix') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Birthdate</label>
                    <input type="date" class="form-control neu-input" name="birthdate" value="<?= $v('birthdate') ?>" required>
                </div>
            </div>

            <div class="section-title">Address</div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control neu-input" name="applicant_province" value="<?= $v('applicant_province') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Municipality/City</label>
                    <input type="text" class="form-control neu-input" name="applicant_municipality" value="<?= $v('applicant_municipality') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Barangay</label>
                    <input type="text" class="form-control neu-input" name="applicant_barangay" value="<?= $v('applicant_barangay') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Purok</label>
                    <input type="text" class="form-control neu-input" name="applicant_purok" value="<?= $v('applicant_purok') ?>">
                </div>
            </div>

            </div>
            <div class="apply-step" data-step="2">
            <div class="section-title">Father's Information</div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control neu-input" name="father_last_name" value="<?= $v('father_last_name') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control neu-input" name="father_first_name" value="<?= $v('father_first_name') ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control neu-input" name="father_middle_name" value="<?= $v('father_middle_name') ?>">
                </div>
                <div class="col-md-1 mb-3">
                    <label class="form-label">Suffix</label>
                    <input type="text" class="form-control neu-input" name="father_suffix" value="<?= $v('father_suffix') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Occupation</label>
                    <input type="text" class="form-control neu-input" name="father_occupation" value="<?= $v('father_occupation') ?>">
                </div>
            </div>

            <div class="section-title">Mother's Information</div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Maiden Name</label>
                    <input type="text" class="form-control neu-input" name="mother_maiden_name" value="<?= $v('mother_maiden_name') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control neu-input" name="mother_first_name" value="<?= $v('mother_first_name') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control neu-input" name="mother_middle_name" value="<?= $v('mother_middle_name') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Occupation</label>
                    <input type="text" class="form-control neu-input" name="mother_occupation" value="<?= $v('mother_occupation') ?>">
                </div>
            </div>

            </div>
            <div class="apply-step" data-step="3">
            <div class="section-title">Contact &amp; Guardian</div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Contact No.</label>
                    <input type="text" class="form-control neu-input" name="contact_no" value="<?= $v('contact_no') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control neu-input" name="email_address" value="<?= $v('email_address') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Guardian Name</label>
                    <input type="text" class="form-control neu-input" name="guardian_name" value="<?= $v('guardian_name') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Relationship</label>
                    <input type="text" class="form-control neu-input" name="guardian_relationship" value="<?= $v('guardian_relationship') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Guardian Contact No.</label>
                    <input type="text" class="form-control neu-input" name="guardian_contact_no" value="<?= $v('guardian_contact_no') ?>">
                </div>
            </div>

            </div>
            <div class="apply-step" data-step="4">
            <div class="section-title">Program</div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Applying As</label>
                    <select class="form-select neu-select" name="student_type" required>
                        <option value="">Select</option>
                        <option value="freshman" <?= ($_POST['student_type'] ?? '') === 'freshman' ? 'selected' : '' ?>>Freshman</option>
                        <option value="transferee" <?= ($_POST['student_type'] ?? '') === 'transferee' ? 'selected' : '' ?>>Transferee</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Program</label>
                    <select class="form-select neu-select" name="program_id" required>
                        <option value="">Select a program</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= $p['program_id'] ?>" <?= ($_POST['program_id'] ?? '') == $p['program_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['program_code'] . ' — ' . $p['program_name'] . ' (' . $p['department_name'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3" id="year-level-wrap"<?= ($_POST['student_type'] ?? '') === 'transferee' ? '' : ' hidden' ?>>
                    <label class="form-label">Current Year Level</label>
                    <select class="form-select neu-select" name="applicant_year_level" id="applicant-year-level"<?= ($_POST['student_type'] ?? '') === 'transferee' ? ' required' : ' disabled' ?>>
                        <option value="">Select</option>
                        <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $n => $label): ?>
                            <option value="<?= $n ?>" <?= ($_POST['applicant_year_level'] ?? '') == $n ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            </div>

            <div class="apply-nav">
                <button type="button" class="btn btn-outline-secondary" id="apply-back" hidden>Back</button>
                <button type="button" class="btn btn-neu-primary ms-auto" id="apply-next" hidden>Next</button>
                <button type="submit" class="btn btn-neu-primary w-100 mt-3" id="apply-submit">Submit Application</button>
            </div>
        </form>

        <script>
        (function () {
            var form = document.getElementById('apply-form');
            // After a server-side error the whole form stays visible, so the error and the
            // values the applicant typed are easy to find. Steps only run on a fresh form.
            if (!form || form.dataset.steps !== 'on') { return; }

            var steps = form.querySelectorAll('.apply-step');
            var dots = form.querySelectorAll('.stepper-step');
            var back = document.getElementById('apply-back');
            var next = document.getElementById('apply-next');
            var submit = document.getElementById('apply-submit');
            var total = steps.length;
            var current = 1;

            form.classList.add('apply-steps-on');
            submit.classList.remove('w-100', 'mt-3');

            function show(n) {
                current = n;
                steps.forEach(function (el) { el.classList.toggle('active', Number(el.dataset.step) === n); });
                dots.forEach(function (el, i) {
                    var num = i + 1;
                    el.classList.toggle('current', num === n);
                    el.classList.toggle('done', num < n);
                    el.querySelector('.stepper-dot').innerHTML = num < n ? '&#10003;' : String(num);
                });
                back.hidden = n === 1;
                next.hidden = n === total;
                submit.hidden = n !== total;
                document.querySelector('.apply-box').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            function stepIsValid() {
                var fields = steps[current - 1].querySelectorAll('input, select');
                for (var i = 0; i < fields.length; i++) {
                    var el = fields[i];
                    if (el.required && el.value.trim() === '') {
                        el.setCustomValidity('Please fill out this field.');
                        el.reportValidity();
                        el.setCustomValidity('');
                        return false;
                    }
                    if (!el.checkValidity()) { el.reportValidity(); return false; }
                }
                return true;
            }

            next.addEventListener('click', function () { if (stepIsValid()) { show(current + 1); } });
            back.addEventListener('click', function () { show(current - 1); });
            // Enter in a text box should go to the next step, not submit a half-filled form.
            form.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT' && current < total) {
                    e.preventDefault();
                    next.click();
                }
            });
            show(1);
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>

    </div>
</div>
<script>
(function () {
    var type = document.querySelector('select[name="student_type"]');
    var wrap = document.getElementById('year-level-wrap');
    var year = document.getElementById('applicant-year-level');
    if (!type || !wrap || !year) { return; }
    function sync() {
        var on = type.value === 'transferee';
        wrap.hidden = !on;
        year.required = on;
        year.disabled = !on;
        if (!on) { year.value = ''; }
    }
    type.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>