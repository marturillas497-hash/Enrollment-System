# CLAUDE.md: MIST Enrollment System

Handoff for any Claude session that works on this project. Read this first, then the PRD (`MIST_Enrollment_System_PRD_10-25-26.md`, v2.7) for what each feature should do. This file covers how to work with the owner, how the project is set up, and what changed in the last working session.

## Working with the owner

- School database project (not a capstone) for the **whole Makilala Institute of Science and Technology, every department**. One developer.
- **Never describe or build MIST as a BSIS-only system.** The seed defines six colleges and six programs: College of Technology and Information Systems (BSIS), College of Criminal Justice and Education (BSCRIM), College of Public Administration (BPA), College of Business (BSE), College of Agriculture (BSA), College of Medicine (BSM). Only SQL can add more (no admin screen yet).
- Scope rules in the code: a registrar, teacher, section, class and curriculum belong to a department through the program, and registrars, corrections, shifts and the generator work only inside their own department. Subjects and rooms are shared across departments on purpose. Do not hardcode a program code or department name in logic, scope by `department_id`.
- BSIS and BSM appear everywhere in the seeds and tests only because they are the two colleges that carry test data. That is test data, not a limit of the system.
- **Free tiers only** for everything (Claude, hosting). The live site is on InfinityFree. They may buy one Hostinger mailbox for 12 months, so assume no paid services.
- Git is run from the **VS Code terminal** on Windows (PowerShell). Give plain `git` commands.
- **Code answers:** deliver a **zip that keeps the project paths** (the owner asked for a zip even for 2 or 3 files), and **always state the file path** of every file. Keep code comments minimal. Give a single downloadable file only when they ask for "the whole file".
- **Docs:** do not edit `Test_Run_v5.md` or the PRD while code work is going on. Keep a running list and update both, plus this file, in one batch at the end, when the owner says so.
- Work in **batches**, not one change per message, and say which files each batch changes. Check the UI for inconsistencies in every batch (spacing, wording, buttons, labels).
- If they ask for "the whole file", give a **downloadable file**, not a pasted block.
- Tone: direct, plain. They test everything themselves on localhost, so give exact steps and exact expected results.
- Say clearly what was **run** and what was only **read**. Claude's sandbox has no PHP, no MariaDB and no network (see Testing).

## Environment

| | Local (development) | Live |
| --- | --- | --- |
| Host | XAMPP, Windows | InfinityFree free tier |
| PHP / DB | PHP 8.2, MariaDB 10.4 | PHP 8.4, MariaDB 11.4, phpMyAdmin 4.9 |
| Folder / URL | `C:\xampp\htdocs\enrollment-system`, `/enrollment-system/public` | `htdocs/`, `/public` |
| Database | `enrollment_system` | `if0_43013716_enrollment_system` |

- Table names are capitalized everywhere in `schema.sql`. Windows is case-insensitive, **the live host is case-sensitive**, so always write them capitalized.
- **InfinityFree limits:** no `CREATE VIEW` (error 1142), no cron, no error-log viewer. Design around these.
- **Never overwrite on the server:** `config/app.php` (says production), root `.htaccess`, `config/database.php`, `config/mail.php`. Never put `config/mail.php` (real Brevo key), `notes/`, `.git/` or `MIST_Test_Accounts.md` in a zip that leaves the machine. Rotate credentials if a zip with them was shared.
- Without `config/mail.php` some pages crash (`invite_helper` requires it). Copy `mail.example.php`. Seeded accounts never need email.

## Repository map

- `public/` role folders (`admin`, `registrar`, `teacher`, `student`, `staff`) plus `login.php`, `apply.php`. Only `public/` is web-served.
- `src/helpers/` shared logic: `academic_helper`, `schedule_helper`, `picker_helper`, `generator_helper` (Auto-generate), `correction_helper`, `audit_helper`, `instructor_helper`, `tag_helper`, `invite_helper`, `mail_helper`, `password_helper`, `reset_token_helper`, `account_email_helper`, `username_helper`, `flash_helper`, `ui_helper`.
- `includes/` navbar, session (CSRF filter, guards), `subject_picker.php`, `instructors_page.php`.
- `public/assets/css/`: `tokens.css` (colors), `app.css`, `schedule.css` (weekly grid), `generator.css`.
- `sql/`: **`schema.sql`** (source of truth, 34 tables, 69 FKs, 3 CHECKs), **`seed_test.sql`**, **`seed_generator.sql`**, **`seed_all_years.sql`**. `sql/migrations/` and `sql/enrollment_import.sql` were deleted (still in git history). Rebuild databases from `schema.sql`, never from a phpMyAdmin export (exports drop the CHECKs).

## Conventions that matter

- **No view.** `Class_Schedule` no longer exists. Every query that used it now has the join inline, as `(SELECT o.offering_id, o.subject_id, o.teacher_id, o.section_id, o.term_id, cm.meeting_id, cm.day_of_week, cm.start_time, cm.end_time, cm.room_id, r.room_code AS room FROM Class_Offering o LEFT JOIN Class_Meeting cm ON cm.offering_id = o.offering_id LEFT JOIN Room r ON r.room_id = cm.room_id) co`. Keep using it. A class with no meetings yields one row with empty times (TBA).
- Named PDO placeholders must be unique per statement (real prepared statements).
- Every POST form gets a CSRF token automatically (`includes/session.php`). Logout is POST-only.
- Colors come from `tokens.css`, none raw in `app.css`. Use `background-color`, never the `background:` shorthand, on selects (the shorthand removes the arrow).
- **Section labels:** `sectionLabel()` shows the name only (`A 2025`) and adds `(Year 2 intake)` when the name exists in another year of one program (`sectionNameIsShared()`, one query, uses the global `$pdo`). A section's `year_level` is its **intake year**, not its students' current year.
- Many files have **mixed line endings** (CRLF and LF). Keep each file's endings when editing. Git's "LF will be replaced by CRLF" warnings are harmless.
- Errors never show raw DB text; they log `[MIST XXXXXX]` references.
- The 4-year cap on year levels is application-only.

## Seeds and test accounts

- **Password for every seeded account: `mist2027`.** Login needs the role picked first. Forced password change on `alcantara_ramon` (registrar of the College of Technology and Information Systems) and `2026-00001`; `alcantara_ramon2` is the registrar of the College of Medicine; after the change use `Mist@Test2027`.
- `seed_test.sql` wipes every table, then loads the small dataset (12 accounts, 5 students in `TEST-A`, 3 classes, ongoing term 2027-2028 Semester 1). Importing it again is the **reset**.
- `seed_all_years.sql` adds, after `seed_generator.sql` and **once**: 8 BSIS teachers (`lim_paolo`, `navarro_marco`, `domingo_ruth`, `pascual_ian`, `tan_victor`, `cruz_maricel`, `garcia_noel`, `lopez_dina`), sections `C 2027`, `B 2026`, `C 2026`, `B 2025`, `B 2024`, 107 students (usernames `2026-00301` to `2024-00407`) and 40 validated freshman applicants (`applicant101@mist.test` to `applicant140@mist.test`). Ten BSIS sections need 2 generator runs. Expected: 68 classes, 0 not placed, about 7 without a teacher (3 `PolSci`, 4 PE). Run 1 picks `A 2026`, `A 2027`, `B 2026`, `B 2027`, `C 2026`. Do not mix it with Phase B of `Test_Run_v5.md`. Its numbers come from capacity arithmetic, not from a PHP run, so check them against the owner's first run.
- `seed_generator.sql` adds on top, as test data for the College of Technology and Information Systems (the generator itself works for any department): the real **BSIS 2023** curriculum (49 subjects, 17 prerequisites, year standing), 10 teachers, 5 rooms, 5 sections (`A 2027`, `B 2027`, `A 2026`, `A 2025`, `A 2024`), 82 students and a closed 2026-2027 S2 term. **Import it once, right after `seed_test.sql`.** A second import stops with `Duplicate entry '2'`.
- Deliberate traps in it: Aquino (PE) has a 4-unit cap for 3 PE classes, `PolSci` has no tags, Dizon has no tags, Reyes is deactivated, `CAP 102` and `PRAC 101` have no hours (TBA), `T-301` is inactive, `T-LAB1` and `NET-1` seat 30 while some sections are bigger.
- Expected generator outcome for the five sections: 34 classes, 0 not placed, exactly 2 without a teacher (`PolSci` and one PE class).
- phpMyAdmin: **select the database before every import or query**. Without it `DATABASE()` is empty and `information_schema` counts return 0.

## What changed in the last session (October 24 to 25)

1. **Undo and edited classes.** `public/registrar/class-offerings.php` clears `generation_run_id` when a class is really changed (subject, teacher, section, term or meetings). `generate-classes.php` says so.
2. **`rollBack()` guards** in `curriculum.php` and `shift-requests.php`. **Dropdown arrow:** `.neu-select` in `components.css` used `background:`; `app.css` was already fine.
3. **Section labels:** `src/helpers/ui_helper.php` (`sectionLabel`, `sectionNameIsShared`), `generator_helper.php` uses it, the Sections page says **Intake year**, the roster header shows it. **Curriculum:** subject removal runs in a transaction, reports links removed, confirm text gives counts, buttons inline.
4. **Rooms:** `public/admin/rooms.php` has `roomClashes()` and `roomClashText()`. Merge, no-limit to counted type (room edit) and turning off "No capacity limit" (type edit) are refused when classes overlap in the ongoing term. The old PRD text had the risk backwards.
5. **Generator:** `genBusyTeachers()` and the free/busy teacher list in the Edit panel, start-screen summary, status badges, **Select the next 5**, **Clear**, and the run time in the preview (`seconds` on the draft).
6. **Manual Class Offerings form:** Term, Section, Subject, Teacher order; Subject list filtered by the section's curriculum and the term's semester with **Show all subjects** (JS in the page, Tom Select API); `manualClassWarnings()` in `generator_helper.php`; the generator's lock (`GET_LOCK('mist_generate_classes')`) around the save; the enrolled-student count counts students; warnings shown as a list after saving.
7. **One standing rule:** `enrollmentStandingCounts()` and `standingFromCounts()` in `academic_helper.php`, used by `admin/terms.php` and by corrections.
8. **Seed and docs:** `sql/seed_all_years.sql`; `Test_Run_v5.md` gained Phase C (Rounds 31 to 41) and a password column on every account table; PRD v2.7 (`MIST_Enrollment_System_PRD_10-25-26.md`); this file.
9. Git: the owner commits from the VS Code terminal on branch `phase1-unique-email`. Whether the last batches (generator, class form, standing rule, docs) are committed was not confirmed.

## Testing

- **Owner runs:** `Test_Run_v5.md` on localhost. Phase B first if generation is the priority. Results go in its bug log.
- **Claude's sandbox** has no PHP, MariaDB or network, so the web app was never executed there. In the last session it did have Node and a headless Chromium (Playwright), used to run page scripts. What was run for real:
  - schema and seeds loaded into SQLite (converted), foreign keys on, strict-mode column limits and data rules checked;
  - a PHP lexer (brackets, strings, `if/endif` balance) over all 82 files, and a check of called function names;
  - 483 SQL statements pulled out of the PHP and compiled against the seeded data, no repeated named placeholders;
  - the weekly-view script in headless Chromium (35 checks);
  - the generator's placement, teacher choice, validation, Save and Undo ported to Python and run 300 times on the seed.
  The harness only existed in the sandbox. It reproduces the PHP rules but not PHP's random numbers.
- Result of those runs: seeds and v5's data claims hold. The generator drafts 34 classes with 0 unplaced and exactly 2 teacherless; Save writes 37 classes with no overlaps; Undo removes 16 and keeps 18.
- **Last session, run for real:** the room clash query and the standing query on SQLite stand-ins, all three seeds loaded into a stand-in database with unique keys, the generator start-screen script and the Class Offerings subject filter script in Chromium (the Tom Select branch only against a stand-in object, **not the real Tom Select 2.3.1**).
- **Not covered, needs a real run:** page rendering, sessions, CSRF, email, Bootstrap modal behaviour, the Class Offerings Week tab (checked statically only), grading, term close, corrections, shifts, transferee credits, the start-screen capacity estimate, MariaDB specifics.

## Findings worth remembering

- A class drafted **without a teacher** is placed before any teacher is known, so a teacher assigned by hand can already be busy. Check again then reports `The teacher is already teaching ...` and Save stays off. The Edit panel's teacher list now splits **Free** and **Busy at this time** (as of the last check).
- The generator's **5 section limit** comes from the preview form (every day, time and room is a field) and the 10 second budget. Each class posts about 9 fields, so 5 sections is far under a 1000 `max_input_vars`, but nobody has measured the live host or the run time. The preview now shows the seconds.
- The Class Offerings subject filter assumes Tom Select 2.3.1 fires a native `change` event on the original select and supports `clearOptions`, `addOptions` and `refreshOptions`. If the list does not narrow after picking a section, check that first.
- Rarely (about 1 in 100 runs) the generator's relaxed last pass puts a class in a room smaller than the section; the preview warns.
- `seed_test.sql` has `TST102` requiring `TST101` in the same semester. That is intentional (v4 relies on it); the Curriculum screen forbids it for new edits.
- The PRD described a Week view in the generator preview before the code had one. Treat the code as the truth and update the PRD.

## Known gaps and what is next

- Run `Test_Run_v5.md` on localhost (Phase B, then Phase C, then Phase A) and write the run times down; fix what it finds. Then deploy: reset the live DB (drop all tables, import `schema.sql`, then a seed), upload code, keep the server-only files.
- PRD v2.7 is current. Keep it in step with the code: when a feature or file changes, update the matching section and add a history entry.
- No SQL file installs only the structure, departments, programs, room types and the Admin account without test data (`enrollment_import.sql` was deleted). A production install needs one.
- The v2.6 open issues about section drift, curriculum removal, room double bookings, the manual form's checks and Undo are fixed in v2.7. Still open in PRD 6.1: the section unique key (a second curriculum cannot have its own Year 1 `A`, so name sections per batch), the manual form's warnings appear after saving, no tag match or load next to each teacher in its dropdown, no enrolled count column.
- Backlog from PRD 9.2: teacher load table and bulk assign in the preview (Save is off until every class has a teacher, so a run with 6 gaps means 6 manual picks), locks and partial save, `Generation_run` reproducibility, teacher availability, section shifts, run settings, drafts saved in the database, login throttling, notifications.
