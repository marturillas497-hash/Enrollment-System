# MIST Enrollment System

**Product Requirements Document**

*Makilala Institute of Science and Technology, all departments*

Version 2.7 · Reconstructed from the current implementation, updated through Batches 3 to 12 (curricula, prerequisites, year standing, classes and meetings, rooms, tags, teacher load, the class generator, grade corrections and the audit log), the Week views, the removal of the `Class_Schedule` view, the test seed files and the October 24 to 25 fixes

October 2026

## Document History

**v2.7 (October 25, 2026)**, changes from v2.6:

- **Undo skips hand-edited classes.** A class changed on Class Offerings (subject, teacher, section, term or meetings) leaves its generator run, so Undo never removes it. Saving with no change keeps it in the run. The start screen says so (3.14, 3.17).
- **Section labels.** A section's year level is its **intake year**, the year students are placed into it, not the year its students are in now. Lists show the section name (`A 2025`, or `BSIS · A 2025`) and add `(Year 2 intake)` only when the same name exists in another year of one program. The Sections page calls the field **Intake year** (3.5, 6.1).
- **Curriculum.** Removing a subject runs in a transaction with error handling, reports how many prerequisite links went with it, and the confirm text gives counts instead of a list of students. Creating a curriculum and saving a shift evaluation no longer call `rollBack()` without an open transaction (3.13).
- **Rooms.** Merging rooms, moving a room from a no-limit type to a counted type, and turning off "No capacity limit" on a type are refused when classes would overlap in one room in the ongoing term, and the message names them. The v2.6 wording had the risk backwards: becoming unlimited cannot double-book, leaving unlimited can (3.15, 6.1).
- **Generator.** The start screen shows how many sections still need classes and how many runs that takes, a status badge per section, and a button that selects the next sections needing classes. The preview shows how long a run took. The Edit panel's teacher list is split into free and busy at the time shown, with the reason (3.17).
- **Manual Class Offerings form.** The Subject list is narrowed to the section's curriculum for the term's semester (**Show all subjects** lifts it), and the form is ordered Term, Section, Subject, Teacher. Saving takes the generator's lock. After saving it lists warnings for tag match, room type, school days and hours, a missing room and a catch-up subject, besides room size and teacher load. The enrolled-student count counts students, not rows (3.14).
- **One standing rule.** Term close and grade corrections call the same functions, `enrollmentStandingCounts()` and `standingFromCounts()` (3.6, 3.18).
- **Seed.** `seed_all_years.sql` adds 8 teachers, 5 sections, 107 students and 40 validated applicants for a four-year generator test (8).
- **Dropdown arrow.** `.neu-select` in `components.css` no longer removes the arrow on the login, apply and forgot-password pages (6.2).
- **Status.** `Test_Run_v5.md` gains Phase C (Rounds 31 to 41) and a password column for every account. The "headers already sent" warning could not be reproduced (the Apache error log has no such entry) and is closed. None of this was executed on PHP in the sandbox: the SQL was run on a stand-in database, the scripts in a browser (1, 6.2).

**v2.6 (October 23, 2026)**, changes from v2.5:

- **Scope wording fixed.** MIST is a system for the whole institute and every department, not for the BSIS department. The title line said "BSIS Department" by mistake. Section 1 now states the scope and lists the six colleges and six programs the seed defines. BSIS and BSM are the two colleges that carry test data, which is test data and not a limit of the system.
- **No more `Class_Schedule` view.** InfinityFree's free plan refuses `CREATE VIEW`, so the join is written inline in every query that used it (16 pages and helpers). The data model is 34 tables, 69 foreign keys, 3 CHECK constraints and no views (3.14, 4, 5, 8).
- **Week views.** Class Offerings has **List** and **Week** tabs (3.14). The generator preview has a weekly view window, opened from the toolbar or from a section's panel (3.17). The v2.5 text described a Week view inside the preview that the code did not have yet. It is now built, in the form described in 3.17.
- **SQL files.** `schema.sql`, `seed_test.sql` and `seed_generator.sql` are the only SQL files. `migrations/`, `enrollment_import.sql` and the demo seeds were removed from the project (they remain in git history). The live database was rebuilt from `schema.sql` on October 13, 2026 (4, 8).
- **Real curriculum in the generator seed.** `seed_generator.sql` carries the school's own BSIS 2023 curriculum (49 subjects, 17 prerequisites, year standing on 10 subjects), 10 more teachers, 5 rooms, 5 sections and 82 students, with deliberate traps for the generator (8).
- **Test run.** `Test_Run_v5.md` replaces v3 and v4: Phase A is the old run on the small seed, Phase B is class generation at scale. The development sandbox has no PHP or MariaDB, so it could only exercise the SQL on a stand-in database, the PHP structure, the weekly view script in a browser and a port of the generator (1, 6.2, 8).
- **Generator behaviour written down.** A class drafted without a teacher is placed before any teacher is known, so a teacher assigned by hand can already be busy at that time (3.17, 6.1). In about 1 run in 100 the last, relaxed pass puts a class in a room smaller than its section, with a warning.
- **Corrections to earlier text.** 3.10 said Change email updates `Student.email_address`. That column does not exist, only `Accounts.email` changes. Section 6.2 no longer says the live database is at 23 tables.

**v2.5 (October 12, 2026)**, changes from v2.4:

- **The Phase 1 to 3 plan is built.** What filled Section 9 in v2.4 now exists on the development copy: several open curricula per program with the registrar choosing at placement, prerequisites and year standing per curriculum (3.13), My Curriculum for students and Class History for teachers (3.19), classes with weekly meetings (3.14), rooms and room types, tags, subject requirements and teacher load (3.15), the Instructors pages (3.16) and Auto-generate Classes (3.17). Section 9 is rewritten as what remains.
- **Classes and meetings:** a class is one subject for one section in one term with one teacher, and a student has one graded row per class. Its weekly meetings are separate rows, and a class may have none (TBA). The old "one offering is one weekly meeting" gap is closed (3.14).
- **Hours per meeting:** a subject's weekly parts store hours per meeting (0 to 5 in half hours) and meetings per week (3.15).
- **Generator placement (Batch 11b):** the day and time of every meeting are chosen by cost, so a section's classes sit close together with little waiting and days start early; a teacher gets at most 2 classes in a row; up to 8 arrangements are tried and the best kept; the start screen estimates whether teachers and rooms can cover the chosen sections; and the preview has collapsible sections, a weekly grid per section and a sticky action bar (3.17).
- **Grade corrections and the audit log (Batch 12):** after a term closes a teacher requests a correction, the registrar of that department decides, and approval recalculates that term's standing and flags later work that relied on the old result. An audit log records grade entries, corrections, placements, approvals, status changes and account deactivation (3.18).
- **Data model:** 34 tables and the `Class_Schedule` view, 69 foreign keys and 3 CHECK constraints. New tables: `Class_Meeting`, `Generation_run`, `Room_type`, `Room`, `Tag`, `Subject_tag`, `Teacher_tag`, `Subject_requirement`, `Curriculum_prerequisite`, `Audit_log`, `Grade_correction`, `Grade_correction_flag`. `Prerequisite` is gone. New columns include `Section.curriculum_id`, `Curriculum_subject.required_standing`, `Teacher.max_units`, `Teacher.max_hours_per_week` and `Enrolled_subject.corrected_at` (Section 4).
- **Corrections to earlier text:** term close counts a Dropped subject as a failure, so a student with a Dropped subject becomes irregular (3.6). The only unique key on a student's email is `uq_account_email`; `uq_student_email` was dropped by the Phase 1 migration (3.12).
- **Status:** everything after the October 6 build was built and checked structurally only, because the development environment has no PHP. It is not deployed to the live site and the owner has not yet run test passes (Sections 1 and 6.2).
**v2.4 (October 8, 2026)**, changes from v2.3:

- **Every account's email is unique**, students and staff alike. One check now covers every account and every open application. The staff duplicate-email warning became a hard refusal; only a duplicate **name** still warns. Backed by `uq_account_email` (3.1, 3.2, 3.12).
- **Sign in and recover by username or email.** The login box and the forgot-password box accept either. Usernames can no longer contain `@` (3.1, 3.11).
- **Subject year level:** every subject stores a year level from 1 to 4, shown and filterable on the Subjects page, with a non-blocking warning in the curriculum checklist when it differs from the year being placed into (3.5).
- Data model: still **23 tables** and **47 foreign keys**. New column `Subject.year_level` and new unique key `uq_account_email`. `student_email_helper` is replaced by `account_email_helper` (Section 4, Section 5).
- **New Section 9** records the plan for everything after Phase 1: teacher Class History, several active curricula chosen at placement, prerequisites per curriculum, year standing, My Curriculum, classes with meetings, rooms, tags, teacher load, the Instructors page and the class-offering generator. It also holds the gap review (9.6) and the open decisions (9.7).
- Status: the Phase 1 changes (items 1 to 3) are written in the working tree and are **not deployed or tested**. Two database updates, a localhost and a live version, must run first (Section 8).

**v2.3 (October 7, 2026)**, changes from v2.2:

- **Invite links replace temporary passwords for every new account** (staff and students). A new account is stored with a placeholder password that can never match, and a 24-hour invite is emailed. Nobody ever sees, receives or knows another person's password, and no screen shows a password or a link. Regenerate Password is gone everywhere. Replaced by **Resend invite** (never activated) and **Send reset link** (already activated) (3.1).
- **Email is required for students and must be unique among students** (public application, admission validation, placement, Change email). Backed by `uq_student_email` (3.2, 3.3, 3.10, 3.12).
- **Admin Staff Accounts** gained a details modal, status badges, Resend invite, Send reset link, Edit details (names and email, with an email-change warning), and Deactivate and Reactivate (3.1).
- **Registrar student page:** the Password tab is now the **Account** tab (username, email on file, status, link actions, Change email). The General tab gained **student status** (Active, On Leave, Dropped, Graduated) and a document checklist editor (3.10).
- **School terms are opened and closed by the Admin.** The registrar's Terms page is read-only. Closing is blocked while any enrollment is pending, and ungraded subjects become **Incomplete** (3.5, 3.6).
- **Security hardening:** CSRF token on every POST form, POST-only logout, Secure session cookie over HTTPS, session id regenerated at login and password change, account deactivation (3.1).
- **Grade rules changed:** the grade alone decides Passed or Failed. Dropped and Incomplete must have no grade (3.6).
- **Class offerings** can be deleted (open term, own department, nobody enrolled). **One teacher per subject per section per term** is enforced everywhere classes are chosen (3.5).
- **Subjects** show where they are used and cannot be deleted while in use. Sections cannot be deleted while referenced (3.5).
- **Rejected irregular enrollments** now require a reason, show it to the student, and offer **Start Over**. Rejected enrollments no longer count as a student's latest enrollment (3.4, 3.9).
- **Shift approval** re-validates the target section, the credited subjects, the open term and the absence of an existing enrollment (3.7).
- **Students** whose next year is beyond the curriculum cannot enroll. Students who are not active cannot enroll (3.4).
- **Profile:** students cannot change their username. Other usernames have length and format rules (3.11).
- Data model: **23 tables** (new `Account_email_change`), **47 foreign keys**, new `Accounts` columns `is_active` and `activated_at`, new unique key `uq_student_email`. Section 4 updated.
- Section 5 updated (CSRF is no longer a gap). Section 6 rewritten. Section 8 updated with the fresh-install file and the migrations.

**v2.2 (October 4, 2026)**, changes from v2.1:

- Documented behavior that shipped between v2.1 and v2.2 but was never written down (Batches 1 to 6): forced first-login password change, duplicate-staff warning with post/redirect/get, student-picks-subjects shift flow, the shared subject picker, section roster and schedule views, grade auto-remarks, generic errors with a reference code, edit modals, teacher My Students, enroll preview, admin profile.
- Batch 7 rules: schedule conflicts are a **hard block** everywhere classes are added, only **one term may be ongoing**, failed, dropped and incomplete subjects are **retakeable**, enrollments cannot be left empty (except a fresh shift approval), a subject code must be unique and a duplicate subject name warns, a subject the student already has a record for cannot be credited, every year level is capped at **1 to 4**, `student/enrollment.php` removed.
- Batch 8: class offerings can be **edited**, a teacher with the same subject in several sections gets a **class switcher** on the grade page, registrar sidebar groups are static headings.
- Batch 9: `Admission_Application.applicant_year_level` for transferees, a registrar **student detail page** with General, Schedule, Subjects and Password tabs.
- Sidebar redesigned as a fixed column.

**v2.1 (September 30, 2026):** self-service password recovery, emailed credentials, session invalidation, 22 tables, InnoDB and CHECK requirements, Brevo email.

## 1. Overview

The MIST Enrollment System is a full enrollment-management application for the Makilala Institute of Science and Technology, built as a database/class school project (not a capstone). It replaces manual, paper-based admission, placement, enrollment, grading, and academic-records workflows with a single web application covering the full student lifecycle, from public application through graduation-track academic records, across five distinct user roles.

**Scope: the whole institute, every department.** MIST has colleges (departments), each owning one or more programs. A registrar, teacher, section, class and curriculum all belong to a department through the program. Registrars, grade corrections, shift requests and the class generator work only inside their own department, while subjects and rooms are shared on purpose (a room's schedule shows every department's classes). Nothing in the code or the rules is specific to one college. The seed loads these six colleges and programs, and only SQL can add more (6.1):

| College (department) | Program |
| --- | --- |
| College of Technology and Information Systems | BSIS, Bachelor of Science in Information Systems |
| College of Criminal Justice and Education | BSCRIM, Bachelor of Science in Criminology |
| College of Public Administration | BPA, Bachelor of Science in Public Administration |
| College of Business | BSE, Bachelor of Science in Entrepreneurship |
| College of Agriculture | BSA, Bachelor of Science in Agriculture |
| College of Medicine | BSM, Bachelor of Science in Midwifery |

The test seeds carry data for two of them, BSIS (a full four-year curriculum, used for the generator) and BSM, because two departments are enough to exercise department scoping. Where this document or a test names BSIS or BSM, it is describing that test data.

This document describes the system as currently implemented: what each role can do, how the core academic workflows behave, the data model that supports them, and the gaps that remain open. It is written to bring a new contributor (human or AI) up to speed without reading the codebase first. Implementation-level working notes (deployment quirks, past incidents, naming conventions) live in the project handoff `CLAUDE.md` and in `notes/`. `Password_Change_How_It_Works.md` explains the account and password system step by step, including the database rows. This file describes *what* the system does, those describe *how it got that way and how to work on it*.

The live site is on InfinityFree's free tier. Its database was rebuilt from `schema.sql` on October 13, 2026 (34 tables) with the `seed_test.sql` test data, and the code of that day was uploaded, including the inlined schedule query. **Later work (the Week tab, the weekly view window, `seed_generator.sql`, `seed_all_years.sql` and the v2.7 changes) exists on the development copy (XAMPP, localhost) and is not confirmed uploaded.** The owner has not finished a test pass on localhost (`Test_Run_v5.md`). The sandbox where the code was written has no PHP or MariaDB, so the PHP was never executed there. It was checked as far as it can be without them: PHP structure (brackets, strings, control structures, called functions), the schema and seeds loaded into a stand-in database with foreign keys on, the SQL pulled out of the PHP compiled against the seeded data, the weekly view script run in a browser, and the generator's rules ported and run 300 times. The live site should be updated again only after the localhost pass. Sections 6.2 and 8 track status.

## 2. User Roles

The system has five roles, all authenticated through one shared login table and separated by a required role selection at login:

| **Role** | **Represents** | **Primary responsibilities** |
| --- | --- | --- |
| Admin | System owner | Creates registrar / teacher / admission staff accounts (invite links), setting a new teacher's tags and load limits; views, filters, sorts and searches all staff accounts; resends invites, sends reset links, edits staff names and email, deactivates and reactivates staff; **opens and closes school terms** for every department; **manages rooms, room types and tags**; edits every instructor's academic profile (Instructors); **reads the audit log**; has a profile page where an email can be set (needed for password recovery). No profile record of their own. |
| Registrar | Department-level academic records officer | Places validated applicants as enrolled students, choosing a curriculum and a section; manages Subjects (with tags and weekly requirements), Curricula (several open at once, with prerequisites and year standing), Sections (each follows one curriculum) and Classes with their meetings; **auto-generates a term's classes**; views terms read-only; handles shift requests, irregular-enrollment approvals, transferee credit evaluation, section rosters, schedules and **grade corrections**; views Instructors read-only; opens a per-student detail page (status, documents, account and email, correction flags); scoped to their own department. |
| Admission Staff | Front-line applicant reviewer | Reviews, corrects, validates, rejects or reopens public admission applications, manually checking physical documents brought in person. Cannot change an applicant's program. |
| Teacher | Instructor | Views assigned classes, enters and edits grades for their own classes while the term is open, **requests a correction once it has closed**, switches between their classes for the same subject, views class rosters, schedule, My Students and **Class History**. |
| Student | Enrolled learner | Self-enrolls (regular path) or requests enrollment (irregular path); views subjects, grades (marked **Corrected** where a grade was corrected), schedule, enrollment history and **My Curriculum**; can request a program shift. Cannot change their username (it is their ID number). |

## 3. Functional Requirements

### 3.1 Authentication, Passwords & Session Management

**Accounts and login**

- A single shared Accounts table (username, optional email, password_hash, role, must_change_password, session_version, is_active, activated_at) backs all five roles.
- Login requires an explicit role selection. The sign-in box takes a **username or an email address**; when one value could match both, the username wins. A wrong username, email or password always gives the same generic message. The role-mismatch and deactivated messages appear only after the correct password was typed, so they reveal nothing to a stranger.
- Sessions persist for 24 hours (explicit cookie lifetime and matching gc_maxlifetime, httponly). The cookie is **Secure over HTTPS**. The session id is regenerated at login and at password change.
- Guard functions (currentUser, requireLogin, requireRole) are enforced at the top of every protected page.

**Invite links (first password)**

- Nobody ever sees, receives or chooses a password on behalf of someone else. Staff and registrars only press buttons that **email a one-time link**.
- When an account is created (admin registers staff, registrar places a student), the account is stored with a **placeholder password hash** (`!` followed by random hex). It is not a valid hash, so no password can ever match it and a pending account just gets the normal "invalid username or password" message at login.
- Immediately after the creation commits, a **24-hour invite** is emailed with the username and a link. The owner opens it, chooses their own password, and `activated_at` is set. They are not logged in automatically and are sent to the login page with "Your password has been saved. Please log in with it."
- If the invite email cannot be sent, the account still exists. The registrar or admin sees a warning and uses **Resend invite**. There is no on-screen fallback.
- One function decides the kind of link: if `activated_at` is empty the link is an **invite (24 hours)**, otherwise it is a **reset link (30 minutes)**. The same function backs the automatic send after creation, Resend invite and Send reset link.
- Links are built from the configured site URL, never from the request's Host header.

**Link rules (shared by invites and resets)**

- A link works once, expires, and only the **newest** link for an account works (issuing a new one voids earlier unused links).
- Only a SHA-256 hash of the link's secret is stored. The link is consumed when the new password is **submitted**, not when the page is opened, so mail scanners that pre-open links do not burn them.
- Requests are throttled to 1 per 60 seconds and 3 per hour per account, shared between invites and resets.
- The reset page sends no-referrer and no-store headers.

**Self-service recovery**

- The forgot-password screen takes a **username or an email address**. If a matching active account has an email, a 30-minute reset link is emailed to it. The response is identical whether anything matches, the account has no email, it is throttled, or the send fails. The reset email names the username, so someone who forgot both can still recover.
- A never-activated account that uses this screen receives a 30-minute link that also activates it.

**Changing a password while logged in**

- Profile has a **Change Password** button that opens `change-password.php`. A successful change bumps the account's `session_version` (ending every other login) and keeps the current session signed in. The page does not ask for the current password (see 6.1).
- An account flagged `must_change_password` is redirected to `change-password.php` on every protected page until it changes the password. Only `change-password.php` and `logout.php` are allowed meanwhile. The page has a Log out button. Nothing in the code sets the flag any more (all inserts write 0), so only older accounts that were issued temporary passwords carry it.
- Password rules, shared by every screen: at least 8 characters, must match the confirmation, and must not equal the username.

**Session invalidation and deactivation**

- Every account has a `session_version`. A logged-in session is ended, with a notice on the login screen, when the account's version changes. The version is bumped on password change, invite or reset completion, deactivate and reactivate.
- Admin can **deactivate** a staff account (`is_active = 0`). A deactivated account cannot log in, link requests for it are refused, and its open sessions end on the next click with "Your account has been deactivated". Deactivated teachers are not offered in teacher dropdowns. Reactivating restores access.

**Staff accounts (Admin → Staff Accounts)**

- Register Registrar, Register Teacher and Register Admission Staff require a valid email. An email already used by **any other account**, or by an open application, is refused outright. They warn about a **possible duplicate name** and require **Create Anyway**, and the chosen department stays selected on that warning screen. Creation uses post/redirect/get so refreshing never creates a second account.
- The list shows status badges (**Active**, **Invite pending**, **Invite expired**, **Inactive**) and supports role filter, sorts and search by name or username.
- The details modal shows username, role, department, status and date added, plus: **Resend invite** or **Send reset link** (inline confirmation, never shows the link), **Edit details** (names and email), **Deactivate** or **Reactivate** (inline confirmation).
- **Deactivating a teacher** who teaches classes in the ongoing term first shows a warning that lists them. Deactivation and reactivation are written to the audit log (3.18).
- **Email change safeguards** (staff and students alike, see also 3.12): whoever controls an inbox can reset the account's password, so changing the email shows an old-to-new warning first. On confirm, every open link is cancelled, the old address is notified (with the new address partly hidden), a never-activated account gets a fresh invite at the new address, and the change is logged in `Account_email_change`. Any other account (student or staff), or an open application, already using the address is refused.
- The admin's own email is edited on the Profile page. It is a plain update: it does not cancel links, notify the old address or write a log row. An address already used by another account or an open application is refused.

**Request integrity**

- Every POST form carries a hidden **CSRF token**, added automatically by an output filter in `includes/session.php` and checked at the start of every POST. A missing or wrong token ends the request with a **"This page expired"** page (HTTP 403) that offers a reload. Cookies must be enabled.
- Logout is **POST-only**. Typing the logout address does nothing. Every Log Out button is a form.

### 3.2 Public Admission

- A public, no-login application form (`apply.php`) captures applicant details in four steps (applicant, family, contact, program). It does **not** collect uploaded documents; the applicant is told to bring physical documents to the admission counter. Server-side validation rejects an unknown or tampered program with a friendly generic error, never a raw database message.
- **Email is required** on the contact step, must be valid (255 characters or fewer), and must be unique across every account and open application (3.12). The message when it clashes is deliberately generic and does not say who holds the address.
- **Transferees must choose a current year level** (1st to 4th Year) on the program step. The field is hidden for freshmen, required for transferees, and re-checked on the server. It is stored in `Admission_Application.applicant_year_level` (null for freshmen).
- Admission staff review applications in Pending / Validated / Rejected views, with a name search that spans all statuses. On the review screen they can correct applicant fields, set the **evaluated year level** (1 to 4, pre-filled from the applicant's reported year with a note, otherwise 1) when validating, and must give a **rejection reason** when rejecting. They cannot change the applicant's program.
- **Validating requires a valid email** that does not clash with any account or another open application. The refusal names the specific reason (another student, a staff account, or another application). Rejecting never requires an email.
- Validated and rejected applications open a **read-only view** (who, when, evaluated year, reason, and whether the application was placed). The Validated tab shows Year and Placement columns, the Rejected tab shows the reason. A validated application that has not been placed can be **reopened for review**.
- Validate and reject require the application to still be `pending`, so a replayed or duplicated request cannot re-process it.
- Admission staff manually validate or reject each application against physical documents. There is no digital document-review screen; `Enrollment_Document` rows recording which document types were actually verified are created at placement time (3.3).
- `Enrollment_Document` records are keyed to the application, not to a student, since a Student record does not exist yet when verification is first recorded.

### 3.3 Student Placement

- Registrar places a validated applicant into a program, section and curriculum, which creates the Account, Student, initial Enrollment, Enrolled_subject rows, records the Enrollment_Document verified/missing checklist (four fixed document types), and links everything together in one action.
- **Email gate:** an application without a valid, non-clashing email cannot reach the confirm screen or be placed. The registrar adds or corrects it in the review panel, which also requires it.
- The account is created with a **placeholder password** and no temporary password. After the commit the **24-hour invite** is sent. The result card says an invite was emailed to the address, shows the student ID number, subjects enrolled and any warning, and offers **View Student** and **Place Another Student**. No password appears. If the email fails, the student is still placed and the warning points to the student's Account tab (Resend invite).
- The registrar picks the term from the ongoing term and chooses a **curriculum** from the program's open curricula (newest effective year preselected). Only sections of the applicant's program and evaluated year level that **follow the chosen curriculum** are offered (3.13). The confirm screen lists the scheduled classes that match the curriculum's subjects for that term, section, year level and semester. **Only the ticked classes become Enrolled_subject rows** (one per class), and **at least one must be ticked**: placing with nothing ticked is refused. A subject with no class for the section is shown but skipped, so classes must exist before students are placed (Auto-generate Classes can create them, 3.17). The server re-checks that every submitted class belongs to that list.
- The server also re-checks that the term is open, that the curriculum belongs to the program and is open for placement, that the section belongs to the program and year level and follows the chosen curriculum, and that no subject ends up with two teachers. Placement is written to the audit log (3.18).
- If the chosen section is at or over its configured capacity (`max_slots`), the registrar sees a non-blocking warning before confirming. See 3.5 for how capacity is counted.
- Student usernames are the student number (`YYYY-NNNNN`); staff usernames follow lastname_firstname (collision-handled by an appended number).
- The registrar may reassign a validated applicant to a program in a different department at placement time (evaluated year level 1 to 4); doing so moves the application into that department's queue.
- The Place Student page has two tabs: **Awaiting Placement** and a read-only **Rejected** tab with the reasons, both scoped to the registrar's department.

### 3.4 Self-Enrollment (Student)

- Regular-standing students enroll automatically for the next term via a single action. A **preview** (term, year level, section, each subject with its schedule, total units, any "not scheduled" warning) is shown before **Confirm Enroll**. The student keeps the same section, and year level advances only when crossing into semester 1 of a new school year; no approval is needed. The server re-verifies the student's standing is `regular`.
- **Only active students can enroll.** A student marked On Leave, Dropped or Graduated sees a message instead of the enroll actions, and the server refuses.
- **Past the final year:** when the next year level would be beyond the last year of the student's curriculum, the dashboard says "You have gone through every year of your curriculum. Ask the registrar about your graduation." and enrollment is refused. A student marked Graduated sees a congratulations message.
- If the preview has **no scheduled classes**, enrollment is refused with a message ("No classes are scheduled for you this term yet...") and nothing is created.
- Irregular-standing students use the **shared subject picker** (3.11) and submit as pending, requiring registrar approval. The picker enforces the prerequisites and year standing of the curriculum on the student's enrollment (3.13; a graded MIST pass, a Shift_credit or a passing Transferee_credit all count), locks subjects that are not offered, and shows a live unit total. The server re-verifies the student's standing is `irregular`.
- **Retakes:** a subject the student failed, dropped or left incomplete stays selectable and carries a **Retake** badge. Only passed or credited subjects are hidden from the picker. Prerequisites still depend on having passed.
- **Schedule conflicts are a hard block.** If two chosen classes (or a chosen class and one already on the enrollment) overlap in time on the same day, a red notice names them and the submit button is disabled; the server re-checks and refuses ("... overlap on Monday. Nothing was saved."). A class ending at 10:00 and another starting at 10:00 do not overlap. A selection that would give one subject two teachers is also refused.
- **Rejected selections:** when the registrar rejects a pending selection, a reason is required. The student's dashboard shows the reason in a red alert with a **Start Over** button, and Enrollment History shows it too. Start Over deletes the rejected request and its subject rows (one enrollment per student per term is a database rule), then the student can choose subjects again or request a program shift. Until then the irregular page asks them to start over.
- A student with a pending program shift request cannot self-enroll. An irregular student with a pending selection may **withdraw** it to shift programs instead.
- After an approved shift, the student's empty enrollment is filled from the same picker in **shift mode** (3.7). The enroll-irregular page refuses to submit nothing ("Select at least one subject").
- The old `student/enrollment.php` page is removed; the dashboard and Enrollment History cover the same ground.
- Approvals and rejections of irregular enrollments are written to the audit log (3.18).

### 3.5 Academic Records Management (Registrar)

- Manage Subjects, Curricula, Sections and Classes (3.13, 3.14), with schedule-conflict detection covering teacher, section and physical room double-booking. Room conflicts are checked across all departments (a room has no department of its own); teacher and section conflicts are naturally scoped by department. School terms are managed by the Admin (3.6); the registrar sees them read-only.
- **Subjects:** the code must be **unique** (case-insensitive) and is also enforced by `uq_subject_code`. A duplicate **name** under a different code shows a yellow warning with **Save Anyway** (the seed data legitimately repeats one name). A **Used by** column shows where each subject is used (curricula and departments, classes, prerequisite links, credit records). **Delete is disabled while a subject is in use**, and a forced delete is refused with a message that lists the usage. Each subject stores a **year level** (1 to 4): required in the form, shown in the list and filterable by year. It is a catalog guide only, and the year a curriculum places the subject in stays authoritative. Tags and weekly requirements are edited on the same form (3.15).
- **Sections:** year level 1 to 4, unique per (program, year level, name) both in the application and via a database constraint. Each section **follows one curriculum** (3.13). A section **cannot be deleted** while an enrollment or class references it. The Program dropdown and column are hidden when the department has exactly one program. Each section has a **Roster** page (3.9). A section's year level is its **intake year**: the year students are placed into it. Students keep their section as they move up, so the year is not shown as their current year (a student's own year comes from their enrollment). Lists show the name (`A 2025`, or `BSIS · A 2025` when the department has several programs) and add the intake year, as in `A (Year 2 intake)`, only when the same name exists in another year of the same program. The name is unique per program and intake year, not per curriculum, so sections are named per batch (for example `A 2025`), see 6.1.
- **Terms (read-only for the registrar):** the registrar's School Terms page lists terms and their status, with a line saying the administrator opens and closes them. Where a page needs an open term it says to ask the administrator.
- Capacity (`max_slots`) is a **non-blocking warning**, shown where a student is newly assigned to a section (placement, shift approval, roster move), never at self-enrollment. Occupancy counts active students whose most recent **non-rejected** enrollment points at that section, so a student holding a seat is counted even if their request this term was rejected or their latest enrollment is from an earlier term.
- Curriculum, Sections and Classes lists are scoped to the registrar's own department at the query level, with a server-side re-check. This applies to every registrar page that touches a specific student or enrollment.
- **Schedule views:** By Section, By Teacher and By Room with a term selector, drawn as a half-hour time-slot grid. The room view includes other departments' classes on purpose.
- Subjects are shared across departments on purpose (for example General Education subjects). Prerequisites and year standing are not shared: they belong to a curriculum (3.13).

### 3.6 Terms, Grading & Term Close

**Terms (Admin → Terms)**

- The admin opens and closes school terms for every department. The school year must look like `2027-2028` and the semester is 1, 2 or Summer. A term can only be opened if **no other term is ongoing** (the button is disabled with a hint, and the server refuses). A school year plus semester cannot repeat (also enforced by `uq_term_year_semester`).
- Each term row shows its counts (ungraded subjects, pending enrollments). **Closing is blocked while any enrollment in the term is pending**: the button is disabled and the server refuses, naming the number, until registrars approve or reject them.
- Closing requires the term to be ongoing and a confirmation. On close, in one transaction: ungraded subjects of approved enrollments are marked **Incomplete**, each student's standing (regular or irregular) is recalculated from final remarks or grade (any grade above 3.00, any Dropped subject and any Incomplete subject make the enrollment irregular, as does an enrollment with no subjects), and further grade entry for that term is locked. The result message names the regular and irregular counts and how many ungraded subjects were marked Incomplete. An approved enrollment with no subjects stays irregular and is counted in the message. The message no longer links to a list; the registrar dashboard has an **Irregular Students** card that opens the filtered list. Term close and grade corrections use one shared rule (`enrollmentStandingCounts()` and `standingFromCounts()` in `academic_helper.php`).
- The admin dashboard has a **Current Term** card.

**Grading**

- Teachers enter and edit grades only for classes they own; ownership is checked server-side on every write. Grades are only editable for approved enrollments in an ongoing term.
- Grades must be between 1.00 and 5.00. Out-of-range values are flagged as the teacher types, refused by the server (other rows still save), and blocked at the database by a CHECK constraint (see Section 4 for its verification status). Term averages exclude ungraded subjects rather than counting them as zero.
- **The grade alone decides the remark.** A grade of 3.00 or better is **Passed**, above 3.00 is **Failed**, in the browser and again on the server. For a graded row the server ignores any posted Passed or Failed remark and derives it from the grade. **Dropped** and **Incomplete** are manual and **must have no grade**: choosing either locks and clears the grade box, and the server refuses a row that has both (that row is skipped, others save). **Passed** or **Failed** without a grade is refused. The rule is final: no override. A row with no grade never counts as passed.
- An explicit remark of "Incomplete" forces irregular standing until resolved, the same as a failure.
- Grade entries in an open term are written to the audit log. Once a term closes, a grade changes only through a correction approved by the registrar (3.18).
- A teacher with the same subject in **several classes in the same term** sees a **class switcher** on the grade page (section, schedule and enrolled count per class); grades remain saved per class.
- **My Students** shows one row per student with a chip for each of the teacher's classes, search, term/subject/section filters, sort and a detail modal.

### 3.7 Program Shift Requests

- A student may request a shift to a different program only while a term is open and before they have enrolled in it (irregular students may also shift, after withdrawing a pending selection). The student chooses the target program and one of its **open** curricula; the server validates the chosen curriculum against the allowed list.
- The sidebar always shows **Shift Request**; when it is unavailable it shows a lock icon with a tooltip giving the reason.
- The registrar evaluates which already-passed subjects carry over into the new curriculum (Shift_credit) and sets the target section (which must follow the target curriculum) and year level (1 to 4). Approval is disabled until the credit evaluation has been saved. If the target section is at or over capacity, the registrar sees a warning before approving. Rejection requires a reason.
- **Approval re-validates on the server:** the target section must belong to the target program, the chosen year level and follow the target curriculum; every credited subject must be one the student passed (3.00 or better) and belong to the target curriculum, each used once; the term must still be open; and the student must not already have an enrollment for it. Rejecting a request deletes its saved credits. Only credits from **approved** requests count toward prerequisites and completed subjects.
- **Approval creates an empty, irregular-standing enrollment** tied to the shift request, and the registrar lands on the **Approved** tab with a message (no cross-department redirect). The approved row opens a read-only record and shows "Waiting on student", then "N chosen" once the student has picked.
- The **student chooses their own subjects** from the shared picker in shift mode: a single submit fills that enrollment, no second approval is needed, and it cannot be submitted twice. Credited subjects are not offered.
- A request that has already been approved or rejected cannot be re-processed; the server checks its status before acting. Approvals and rejections are written to the audit log (3.18).
- Shift request lists are scoped by the student's **original (from) department**, so an approved request stays in the approving registrar's Approved tab.

### 3.8 Transferee Credit Evaluation

- The registrar opens it from the student's detail page (Subjects tab, transferees only). **Credits** shows the student's full curriculum grouped by year and semester with a progress meter and credits individual subjects inline; **Add Subjects** adds the remaining, non-credited subjects.
- Transferee_credit is free-text (previous school, subject description) plus a previous grade between 1.00 and 5.00, since there is no graded MIST record for outside coursework. This is distinct from Shift_credit, which references a real, already-graded Enrolled_subject.
- A subject the student **already has an enrollment record for** (graded or not) cannot be credited.
- Add Subjects uses the shared picker (prerequisites with credits counted, class chosen from any section in the department, live unit total) and applies the same **hard schedule-conflict block** and the one-teacher-per-subject check, including against classes already on the student's roster. It is scoped to the student's own department.

### 3.9 Section Roster (Registrar)

- Each section has a roster page for the **ongoing term**: the students enrolled this term, and below them a muted list of students who **hold a seat but are not enrolled this term** (for example a student whose request this term was rejected, who still holds the seat through an earlier enrollment). The header reads "N of M seats held · N enrolled this term".
- **Move section** (regular students only): moves the student to another section of the same program, year level and curriculum. The enrollment's section changes and its subjects are remapped to the new section's classes for the same subjects; subjects with no class there stay on their old schedule and are reported. Capacity is a warning. The move is **refused if the result would put two classes at the same time or give a subject two teachers**, and is blocked once any grade or remark exists on the enrollment.
- **Change classes** (irregular students): swap a subject to another class of the same subject, remove subjects, and add subjects through the picker. The result must contain **at least one subject**, **no overlapping classes** and **no subject with two teachers**, otherwise nothing is saved. Rows that already have a grade or remark can never be swapped or removed.
- Both actions are department-scoped and re-validated on the server.

### 3.10 Registrar Student Detail

- Clicking a student in the registrar's list (or **View**) opens `student-view.php?id=`, scoped by the student's current department (via their latest non-rejected enrollment). Another department's student shows "not found".
- **General:**
  - Read-only cards for enrollment (including username and account email), application (including the applicant-reported and evaluated year and the document checklist), personal details, and family and guardian.
  - **Student status:** Active, On Leave, Dropped or Graduated, changed with a confirmation. **Graduated is only allowed once every subject in the student's curriculum is completed**; otherwise the refusal lists the missing subject codes. Only active students can enroll (3.4).
  - **Correction flags:** when an approved grade correction may have affected this student, a yellow notice lists what to review, each with **Mark reviewed** (3.18). Status changes are written to the audit log.
  - **Documents:** after placement the registrar can mark each document verified or missing (for example when a late report card arrives). Only the student's own application rows can change.
- **Schedule:** the latest enrollment's time-slot grid.
- **Subjects:** every term with its subjects, grades (a Corrected badge marks corrected grades), remarks and standing, plus a table of credited subjects (transferee and shift). Transferees also get **Credit evaluation** and **Add subjects** buttons.
- **Account** (formerly Password; the URL key is still `tab=password`):
  - Shows the username, the email on file and a status badge (**Active**, **Invite pending**, **Invite expired**). Passwords and links are never shown.
  - **Resend invite** (never activated, 24 hours) or **Send reset link** (activated, 30 minutes), each with a confirmation. Both go through the throttle in 3.1 and are unavailable if no valid email is on file.
  - **Change email** opens a modal. Continue shows an inline warning (old to new). Confirming updates `Accounts.email` (the `Student` table has no email column), cancels every open link, notifies the old address, logs the change in `Account_email_change`, and sends a fresh invite if the student was never activated. A clash with another student's email is refused inside the modal (3.12).
- The student list itself supports search and program, year and status filters, a Standing column and filter, and pagination.

### 3.11 Shared Views & UI

- A shared profile page shows each user's username, role and role-appropriate details, a **Change Password** button, and (admin only) an email field.
- **Usernames:** a student's username is their ID number and cannot be changed. Other roles can change theirs: at least 4 and at most 100 characters, unique, and not shaped like a student ID (`YYYY-NNNNN`) or `PENDING-...`, and without an `@` (so a username can never be mistaken for an email).
- Subjects marked CREDITED are shown with a distinct badge on the student's dashboard and subject pages, and corrected grades carry a Corrected badge. Irregular is amber and incomplete is red wherever they appear; pending-approval alerts are amber with an hourglass; Inactive staff have their own badge.
- Student, teacher and registrar schedules render as a real time-slot calendar grid (half-hour rows, days as columns). Times and schedules read like `Mon 8:00 AM – 10:00 AM · T-101`. Semester 3 is shown as Summer.
- **Sidebar:** a fixed column. The logo and a compact profile card stay at the top, the menu scrolls on its own, and Log Out is pinned at the bottom so it is always on screen. The current page is a solid white pill. The admin menu has Dashboard and Profile plus three static headings: **People** (the three Register pages, Staff Accounts, Instructors), **Academic Setup** (Terms, Rooms, Tags) and **System** (Audit Log). The registrar menu has two static headings, **Enrollment** (Place Student, Students, Shift Requests, Irregular Enrollments, Grade Corrections) and **Academic Setup** (Subjects, Curriculum, Sections, Terms, Class Offerings, Schedule, Instructors), with every link always visible. Colors come from tokens in `tokens.css`. Below 768px the sidebar is off-canvas.
- Pre-login pages (login, apply, forgot password) have a manual back link. There is no browser-history back action on logged-in pages.
- Irreversible actions (deletes, approvals, rejections, term close, resends, deactivation) use one shared styled confirmation dialog. Because two Bootstrap modals cannot stack, **inside a modal** the confirmation is an inline panel instead.
- Long lists are paginated at 15 rows per page with search and filter parameters carried in the links. Table headers stay visible while a long table scrolls.
- **Modals** for Subjects, Sections, Class Offerings and Curriculum reopen with the typed values and an in-modal error when a save fails, and those server alerts are cleared when the modal closes so they never reappear on another row. Searchable dropdowns (Tom Select) open above the modal.
- **Errors:** pages never show raw database text. A failure is logged with a six-character reference (`[MIST XXXXXX]`) and the user sees a generic message with that reference. The free InfinityFree plan has no error-log viewer, so the reference cannot be looked up there (see 6.1).

### 3.12 Account Email Rules

| Rule | Detail |
| --- | --- |
| Required | Staff at registration, students at application, validation and placement. |
| Valid | Must pass email validation and be at most 255 characters. |
| Unique across accounts | No two accounts may share an email, whatever the role. An email may not match another **account** (student, teacher, registrar, admission staff or admin), or another **pending or validated application that has not been placed**. Rejected and already-placed applications never count, so a rejected applicant may re-apply with the same address. The admin's email may be empty. A person with two roles needs a second address. |
| Matching | Case-insensitive, and spaces at the ends are trimmed. `name+1@gmail.com` and `name@gmail.com` are different addresses on purpose (plus-addressing is used in testing). |
| Staff | A duplicate staff email is refused like any other clash, at registration and in Edit details. Only a duplicate **name** still warns at registration. |
| Backstop | `uq_account_email` on `Accounts.email` (null allowed more than once). It is the only unique key on a student's email; the earlier `uq_student_email` was dropped by the Phase 1 migration. A race that reaches the key shows the generic error with a reference code. |
| Changing it | Warning first, then links cancelled, old address notified, change logged, fresh invite if never activated (3.1, 3.10). |

### 3.13 Curricula, Prerequisites and Year Standing

**Curricula**

- **Several curricula per program may be open.** A curriculum's `is_active` flag means **open for new placements**, not "the only one". Creating a curriculum, including by cloning, no longer retires the others. Each curriculum has its own **Retire** and **Reactivate** action, and the list shows how many students follow it. A curriculum name is unique within its program (`uq_curriculum_program_name`).
- Retiring a curriculum never affects students already on it. It only stops new placements, new shifts into it and new sections for it.
- **Students are never moved to another curriculum.** A student keeps the curriculum they were placed in. A program shift is the only way to change, and it creates a new enrollment under a different program's curriculum (3.7).
- **A section follows one curriculum** (`Section.curriculum_id`). Placement offers only sections that follow the chosen curriculum. Move section offers only sections with the same program, year level and curriculum. Shift approval requires the target section to follow the target curriculum. A new section can only be created for an open curriculum.
- Subjects are added through an **Add Subjects checklist modal**: Year Level (1 to 4) and Semester in the header, a search box, a checklist of every subject with its code, name, units and usual year, and a live selected count. Subjects already in the curriculum are greyed out with a badge showing where they are, and a non-blocking warning names any ticked subject whose usual year differs from the Year Level being added to. Many subjects are added in one transaction.
- **Removing a subject** is refused while any student already has a record for it, and the refusal lists them. Otherwise it runs in one transaction and removes the subject's prerequisites in that curriculum too, and the message says how many links went. The confirm dialog shows how many prerequisite links will go and how many students follow the curriculum.

**Prerequisites** (`Curriculum_prerequisite`, edited per curriculum)

- Both subjects must belong to that curriculum. A prerequisite must come in an earlier year or semester than its subject (within a year: Semester 1, Semester 2, Summer). Self-references, duplicates and cycles of any length are refused, scoped to the curriculum.
- They are edited on the subject's row in the Curriculum page (a Requires column and an edit action). The old `prerequisites.php` page and its sidebar link are gone and return not found. Cloning a curriculum copies its prerequisites.
- Edits apply to future selections only. Past enrollments are never re-checked, and the editing screen shows how many students follow the curriculum.
- A student is checked against the curriculum on their enrollment. After a shift, the new curriculum's rules apply. Completion means a graded pass, a shift credit or a passing transferee credit.
- Limit: every listed prerequisite is required. "A or B", co-requisites and "consent of the dean" are not supported.

**Year standing**

- An optional `Curriculum_subject.required_standing` (2nd, 3rd or 4th year) is set in the same edit screen as prerequisites. It may not exceed the subject's own year level in that curriculum.
- A student has **N-year standing** when every subject in the years before N of their curriculum is completed (all semesters). Failed, dropped, incomplete and ongoing subjects do not count. Credits count.
- Enforced wherever prerequisites are: the irregular picker, shift mode, roster changes and transferee Add Subjects. Locked subjects show a chip such as "3rd year standing". Placement enforces neither prerequisites nor standing.
- Standing is computed in one pass over the curriculum, not one query per subject. It shows on the student dashboard and the registrar's student page. It is separate from the year level stored on an enrollment.

### 3.14 Classes and Meetings

- A **class** (`Class_Offering`) is one subject for one section in one term with one teacher, unique per (term, section, subject) (`uq_class_term_section_subject`). It is what students enroll in and are graded against: one `Enrolled_subject` row and one grade per student per class. "One teacher per subject per section per term" is therefore true by construction.
- A class has zero or more **meetings** (`Class_Meeting`: day, start time, end time, room). A class with no meetings shows as **TBA** (for subjects such as OJT, thesis or a clinical rotation) and can still be enrolled in. Queries that need a class's schedule join `Class_Offering` to `Class_Meeting` and `Room` inline, which gives one row per meeting and one row with empty times for a class with none. There is no database view, because InfinityFree's free plan does not allow one.
- The registrar's **Class Offerings** page adds, edits and deletes classes with their meetings. A meeting needs a day from Monday to Saturday, a start before its end and a room picked from the room list. Two meetings of one class may not overlap. Teacher, section and room conflicts are hard blocks, and room conflicts are checked across all departments.
- The class form lists **Term, Section, Subject and Teacher** in that order. The Subject list is narrowed to the subjects of the section's curriculum in the term's semester (every year, sorted by year, each marked with its year), and **Show all subjects** lifts the filter for catch-up classes. Changing the section or term clears a pick that is no longer listed, and editing a class always keeps its own subject.
- Saving takes the same lock as the generator's Save, so a manual save and a generated save cannot overlap ("Another registrar is saving classes right now"). After saving, non-blocking warnings are listed: room size against the expected attendance, a teacher over their units or hours limit, teacher tag match, room type against the subject's preferred and fallback types, a meeting on Saturday, outside 7:30 AM to 5:30 PM or in the lunch hour, a meeting with no room, and a subject outside the section's curriculum for that semester. The checks use the generator's own constants and wording.
- Class Offerings has **List** and **Week** tabs. **Week** draws the same weekly grid as the Schedule page for one term, **Show by** Section, Teacher or Room, then the one to show. A class with no meetings is listed under the grid as TBA. The room view draws only the registrar's own department's classes and says so (Schedule, By Room, shows every department).
- **Edit** while the term is ongoing: with nobody enrolled every field is editable. Once students are enrolled, subject, section and term lock and only the teacher and meetings can change, with the same conflict checks (excluding the class itself) and a check that the new times do not overlap an enrolled student's other classes. Closed-term classes have no Edit button and the server refuses.
- **Delete** only in an ongoing term, within the registrar's department, and only when **nobody is enrolled**. If students are enrolled, a modal says how many and links to the section roster so they can be moved first.
- Deactivated teachers are not offered in the Teacher dropdown.
- A class created by Auto-generate Classes remembers its run (`Generation_run`) so the run can be undone (3.17). A class changed by hand on Class Offerings (subject, teacher, section, term or meetings) leaves its run, so Undo never removes it. Saving a class without changing anything keeps it in its run.
- Teachers with the same subject in several classes in the same term get a class switcher on the grade page. Grades are saved per class.

### 3.15 Subject Requirements, Tags, Rooms and Teacher Load

**Weekly requirements** (`Subject_requirement`, edited on the Subjects form)

- A subject has zero or more weekly parts. Each part has a kind (lecture, lab, activity and so on), **hours per meeting** (0 to 5 in half hours, because a block must fit before or after lunch), **meetings per week**, a preferred room type and an optional fallback room type. The weekly time is hours per meeting times meetings per week. The subject list shows it as, for example, "Lecture 1.5h x2 (3h/week)".
- A subject with no parts is treated as one lecture block of its units in hours (capped at 5) in a Lecture Room. A part with zero hours makes a TBA class. As a guideline, 1 lecture unit is about 1 hour and 1 lab unit about 3 hours, and the registrar enters the real figures.

**Tags** (`Tag`, `Subject_tag`, `Teacher_tag`)

- Tags are unique ignoring capitals. The admin's **Tags** page renames, merges (moving every subject and teacher to the surviving tag) and deletes. Admin and registrars can also add a tag while editing a subject or teacher.
- A teacher's match to a subject is **full** (the teacher has all of the subject's tags), **partial** (at least one), **none**, or **untagged** (the subject has no tags). The generator prefers full, then partial (flagged in the preview), and never assigns none. A registrar may still assign any teacher by hand, with a warning.

**Rooms and room types** (Admin, Rooms page with two tabs)

- A room has a code, a type, a capacity and an active flag. Registrars only pick rooms. Room codes and type names are unique.
- **Remove** deletes a room or type that has never been used. A room that appears in any schedule is deactivated instead, and a type in use cannot be deleted. Deactivating a room that is used in the ongoing term warns and lists those meetings. Two rooms can be **merged**, moving every class from the removed room to the other. A merge is refused if two classes would then share the room at the same time in the ongoing term, and the message names them. Overlaps in closed terms do not count.
- A type can be flagged unlimited (for example "Off-campus or clinical site"): it has no capacity limit and never conflicts. Moving a room from such a type to a counted type, or turning off the limit on the type, is refused while classes overlap in those rooms in the ongoing term.
- Room capacity is a non-blocking warning: on the manual Class Offerings form it is compared with the expected attendance (the section plus students joining from other sections), and in the generator preview with the section's size.

**Teacher load** (`Teacher.max_units`, `Teacher.max_hours_per_week`)

- Each teacher has a unit cap (default 24) and an optional weekly hours cap (suggested 30), set on the Register Teacher form together with tags and editable on Instructors (3.16).
- Load is counted per class (distinct subject and section), not per meeting. The generator never exceeds the caps. A manual assignment over a cap warns instead of blocking. Lowering a cap below the current load is allowed with a warning.

### 3.16 Instructors

- The admin's **Instructors** page holds each teacher's academic profile: department, tags, units cap and weekly hours cap (editable), load against the cap, and classes this term and in past terms. Staff Accounts keeps the account actions (invite, reset link, email, deactivate).
- Registrars get a read-only Instructors view of their own department.
- Deactivating a teacher who has classes in the ongoing term warns and lists them first (3.1). Deactivated teachers are not offered in teacher dropdowns.
- The admin sidebar is grouped into **People** (Register Registrar, Register Teacher, Register Admission Staff, Staff Accounts, Instructors), **Academic Setup** (Terms, Rooms, Tags) and **System** (Audit Log).

### 3.17 Auto-generate Classes (Registrar)

Opened from the Class Offerings page. It builds a draft schedule for the ongoing term. **Nothing is saved until the registrar presses Save**, and classes that already exist are never changed.

**Start screen**

- The registrar ticks up to **5 sections** of their department and sets a **teach year** (1 to 4) for each. The default comes from the students' latest enrollments, advanced to the next year and capped at the curriculum's last year, falling back to the section's year level. An unsaved draft shows a banner with **Continue editing it**.
- A summary above the list says how many sections still have classes to schedule for their teach year, how many runs that takes at 5 sections a run, and why there is a limit (the preview is one form with a field for every day, time and room, and the host cuts off forms past a set size). Each section carries a badge (N classes to schedule, All scheduled, Nothing to schedule) that follows the teach year. **Select the next 5 that need classes** ticks the first sections, in list order, that still need classes, and **Clear** unticks all. Classes saved by one run count as taken in the next.
- A **readiness check** lists subjects without tags or weekly requirements, teachers without tags, and room types with no rooms.
- A **capacity check** updates as sections and years are ticked. For teachers, subjects are grouped by which teachers could take them, and each group shows the units and weekly hours it needs against what those teachers can still teach (weekly hours count at most 35, which is 5 days of 7 hours). For rooms, each meeting is fitted into what is left of every room's morning and afternoon windows, respecting section size and the fallback room type and counting classes already saved. Each line is marked fine, tight or short. It is an estimate: a teacher with several tags counts in every group they match.

**How a draft is built**

1. The context is loaded: active teachers with tags, caps and current load; rooms and types; everything already scheduled this term.
2. For each chosen section and teach year, the curriculum's subjects for the term's semester become classes, skipping subjects the section already has a class for. Each class asks for weekly meetings from its subject's requirements, longest first.
3. Up to **8 arrangements** are tried (at least 5 when everything places) inside a 10 second budget. Each has its own random seed and one of three orderings: largest classes first, fewest eligible teachers first, or scarcest room type first.
4. For each class, candidate teachers are those with a full tag match, then a partial match, least loaded first, who stay within their unit and weekly hours caps. The first teacher whose meetings fit is chosen. If none fit, the class is drafted with no teacher and the note says why. If the meetings cannot be placed at all, the class is listed as not placed with the reason.
5. Meetings are placed in up to three passes. Pass 1 is strict: different days for one class, only the preferred room type, rooms that seat the section, a 7-hour day cap for a section or a teacher, and no more than 2 classes in a row for a teacher. Pass 2 also allows the fallback room type. Pass 3 relaxes same-day meetings, room size and the day cap and chain rules, and the preview then warns.
6. Every feasible day and start is scored and the cheapest wins. Starts are on a 30 minute grid, before lunch or after lunch. The cost favors classes packed right after each other (waiting between a section's classes costs most), days that start at 7:30, few days per section, and the meetings of one class at the same start time on a standard day pair (Monday and Thursday, Tuesday and Friday, Monday and Wednesday, Tuesday and Thursday, or Wednesday and Friday). Late ends and heavy days cost more. Each candidate must have a free room.
7. The best attempt is kept: fewest unplaced classes, then fewest without a teacher, then fewest teacher stretches over 2, long waits, single-class days and idle minutes.

The calendar is **Monday to Friday, 7:30 AM to 5:30 PM, with no classes from 11:30 AM to 12:30 PM**.

| Rule | Kind | Overridable in the preview |
| --- | --- | --- |
| Teacher not in two places at once | Hard | No |
| Section not in two classes at once | Hard | No |
| Room not double-booked | Hard | No |
| One teacher per subject per section per term | Hard | No |
| Teacher has a matching tag | Soft | Yes, with a warning |
| Teacher within units and weekly hours | Soft | Yes, with a warning |
| Room matches the preferred type | Soft | Yes, with a warning that names the room's type, the preferred types and the fallback |
| Room big enough | Soft | Yes, with a warning |
| Inside school days and hours | Soft | Yes, with a warning |
| Teacher with no more than 2 classes in a row | Strict while generating, a warning on edits | Yes, with a warning |

**Preview**

- The top card shows counts (classes drafted, need a teacher, with a conflict, with a warning, not placed), a quality line (total waiting, long waits of 90 minutes or more, teacher stretches over 2, single-class days), a **Show only classes that need attention** switch, Expand all and Collapse all, and a **Week view** button (below). The card also shows how long the run took (`Best of N arrangements tried in X s`), which is the number to look at before raising the section limit.
- Each section is a collapsible panel with a chevron that points down when closed and up when open. Sections with problems open by themselves and clean ones stay closed. The panel header shows the year, class count, problem chips and the section's total waiting.
- Each class is a compact row: subject, teacher and match level, its meetings as text (`Wed 12:30 PM – 3:30 PM · CL-3`), a status chip and an **Edit** button. The Edit panel holds the teacher, each meeting's day, times and room, and **Remove from draft**. Rows with a conflict open their panel by themselves, and the row text updates as fields change.
- A **Week view** button in the toolbar, and a **Week** button on each section's panel header, open a window with a weekly grid. It is drawn in the browser from the form fields, so edits that are not checked yet show, with a dashed outline. **Show by** Section, Teacher or Room, then pick which one. The grid has the same style as the other schedule grids with the lunch hour shaded, and in Section view the waiting time between classes is drawn as dashed boxes (amber at 90 minutes or more). Classes with a conflict or that need attention are red, warnings amber, and overlapping classes sit side by side. Classes with no meetings (TBA) are not drawn. Clicking a block closes the window and opens that class's Edit panel.
- A **sticky action bar** keeps **Check again**, **Save**, **Try a different arrangement** and **Discard** on screen with a status line. **Check again** revalidates the draft with the registrar's edits and keeps the scroll position. **Save** stays off until the checked draft has no conflicts and no class without a teacher. Edited rows show "Edited, not checked". **Try a different arrangement** discards the registrar's edits.
- A class drafted with no teacher is placed before any teacher is known. A teacher the registrar then picks by hand can already be busy at that time. **Check again** reports it ("The teacher is already teaching ..."), Save stays off, and the registrar picks another teacher or changes the day and time. The Edit panel's teacher list is split into **Free at this time** and **Busy at this time**, using the times at the last check and counting saved classes and the other drafted classes, with a line naming who is busy and with which subject. Free teachers are sorted by tag match. After changing a time, press Check again to refresh the marks.
- The draft lives in the session. A hidden field detects a form cut off by the host's input limit, which is why a run is limited to 5 sections.

**Save and undo**

- Save re-validates every conflict inside a transaction under a lock, because rooms are shared across departments and two registrars may save at once. It writes the classes and their meetings and records the run (`Generation_run`).
- **Undo this run** removes only the classes from that run that nobody is enrolled in. A class changed by hand on Class Offerings has left the run and stays.
- Limits: the generator plans for sections, not for retakes, so catch-up classes are added by hand. Combined classes, lab-group splits and team teaching are not modelled. Teachers have no availability settings. A conflict-free arrangement can still leave a few single-class days.

### 3.18 Grade Corrections and the Audit Log

**Why:** grades lock when a term closes, so a wrong grade, or an Incomplete that later receives a grade, could not be fixed. Corrections add a controlled way to do it. Closed terms stay locked for direct edits.

**Teacher requests**

- On the grade page of a closed term, each student row has **Request correction**. The request holds the corrected grade (1.00 to 5.00) or **Dropped** or **Incomplete** (which carry no grade), and a required reason of 10 to 500 characters. The remark follows the grade: 3.00 or better is Passed, above 3.00 is Failed. A request that matches the recorded result is refused.
- Only the teacher who owns the class can ask, only for an approved enrollment in a closed term, and only one request can be pending per graded row. The page lists the class's requests with their status and the registrar's rejection reason, and the teacher can **Withdraw** a pending one.

**Registrar decides** (Grade Corrections page, with a card on the dashboard)

- The page has three tabs: **Pending**, **Decided** and **Flags to review**. It is scoped to the registrar's department, taken from the class's section and program, and has search and pagination.
- **Approve** changes the grade and remark and stamps the row corrected (`Enrolled_subject.corrected_at`). It is refused if the recorded result changed after the request was made ("reject it and ask the teacher to send a new request"). **Reject** needs a reason, which the teacher sees. Only approval changes a grade.
- On approval, in one transaction: that enrollment's standing is recomputed exactly as term close does (3.6), and later work that relied on the old result is **flagged**, never changed.

**Flags** (`Grade_correction_flag`)

- A flag is raised only when the correction changes whether the student has completed that subject at all (a pass elsewhere or a credit can keep it completed). For each later enrollment of the student, the flag says what to review:
  - a later subject requires the corrected one as a prerequisite in that enrollment's curriculum, and it is no longer completed;
  - a later subject needs a year standing the student no longer has;
  - the student takes the subject again in a later enrollment, but it now counts as completed.
- If the student is marked **Graduated** and the correction leaves a curriculum subject uncompleted, a flag says so. The status is not changed for them.
- Flags show on the registrar's student page (General tab) with **Mark reviewed**, and in the Flags tab of the Grade Corrections page. Resolving a flag is logged.

**Students and others**

- A corrected grade shows a **Corrected** badge on the student's grades, subjects and dashboard pages, and on the registrar's Subjects tab. It also shows on the teacher's grade page.

**Audit log** (`Audit_log`, Admin → System → Audit Log)

- Records who, when, what and, where useful, the values before and after. Actions logged: grade entered in an open term, correction requested, approved, rejected and withdrawn, correction flag reviewed, student placed, shift approved and rejected, irregular enrollment approved and rejected, student status changed, and staff account deactivated and reactivated.
- The page lists newest first with filters (action, search by summary text or username, date range), 15 per page, and a Details row with the before and after values. Entries cannot be edited. A failed log write never blocks or undoes the action being logged.

### 3.19 My Curriculum and Class History

**My Curriculum (student, read-only)**

- Shows the curriculum name, program and effective year, with a note when newer students follow a different curriculum.
- Every curriculum subject is grouped by year and semester with a status: Passed (with the grade), Failed, Dropped, Incomplete, Taking now, Credited, or Not yet taken. A not-yet-taken subject shows its prerequisite or year standing lock.
- Progress is shown as units completed of total, with the current standing.
- A shifted student sees the current curriculum, earlier credits as Credited, and any subject taken outside the curriculum listed separately. A subject taken more than once shows the latest attempt and the attempt count.

**Class History (teacher, read-only)**

- Classes are grouped by school year and semester, newest first, with a subject filter. Each class shows its section, schedule, enrolled count, passed, failed, incomplete and dropped counts, and the class average.
- Opening a class shows its grade sheet (student, grade, remark). Closed terms stay locked: nothing is editable from this page.
- A teacher sees only classes they own, checked on the server. Class History does not show the Corrected marker (6.1).

## 4. Data Model (Key Entities)

The system is backed by a relational schema of **34 tables** and no views, all InnoDB with utf8mb4. Full column-level detail lives in `sql/schema.sql`, the source of truth for structure. The live database was rebuilt from it on October 13, 2026 and has the structure below. There are no migrations any more.

| **Entity** | **Purpose** |
| --- | --- |
| Accounts | Shared login table for all roles: username, optional but unique email (`uq_account_email`), password_hash (or the `!` placeholder while an invite is pending), role, must_change_password, session_version, **is_active**, **activated_at** (empty means never activated, which decides invite versus reset). |
| Password_reset | One row per invite or reset link: account (deleted with the account), SHA-256 token hash (unique), expires_at, used_at (set when consumed or voided), created_at. Also the data behind the throttle. |
| Account_email_change | Audit log of email changes: account, who changed it (`changed_by`), old and new email, time. |
| Student / Teacher / Registrar / Admission_Staff | One profile table per role, each linked to Accounts (admin has no profile table). Student holds `overall_status` (active, on_leave, graduated, dropped). Teacher holds `max_units` and `max_hours_per_week`. |
| Department / Program | Academic structure: departments own programs. `Department.max_units_per_term` exists but nothing uses it. |
| Curriculum / Curriculum_subject / Curriculum_prerequisite | A program has several curricula (open for placement or retired, name unique per program). A curriculum is made of subjects per year and semester, with an optional `required_standing`. Prerequisites belong to a curriculum, with composite foreign keys to `Curriculum_subject` so both subjects must be in it. |
| Subject | Global catalog shared across departments. `subject_code` is unique. Each subject has a `year_level` (1 to 4), a catalog guide separate from the year a curriculum places it in. |
| Subject_requirement | Weekly parts of a subject: kind, `hours_per_meeting`, `meetings_per_week`, preferred and fallback room type. |
| Tag / Subject_tag / Teacher_tag | Tags (unique ignoring capitals) and which subjects and teachers carry them. |
| School_term / Section | Term calendar (unique per school year plus semester) and sections per program and year (unique on `program_id + year_level + section_name`), each with the `curriculum_id` it follows. |
| Class_Offering / Class_Meeting | A class (subject, section, term, teacher; unique per term, section and subject) and its weekly meetings (day, start, end, room). A class with no meetings is TBA. Queries join them inline, there is no view. |
| Generation_run | One row per saved Auto-generate run, so the run can be undone. `Class_Offering.generation_run_id` points to it. |
| Room_type / Room | Room types (one may be unlimited) and rooms (code, type, capacity, active). |
| Admission_Application / Enrollment_Document | A prospective student's application (including `applicant_year_level` for transferees and the evaluated year level) and the checklist of verified documents created at placement time. |
| Enrollment / Enrolled_subject | A student's registration in a term (standing, section, curriculum, optional `source_shift_request_id`, rejection reason) and the classes taken under it, one row per class with grade, remarks and `corrected_at`. |
| Program_shift_request / Shift_credit | A request to move programs and the passed subjects credited into the new curriculum on approval. |
| Transferee_credit | Free-text credit for coursework completed at an outside school, evaluated per subject in the target curriculum. |
| Audit_log | One row per notable action: actor (kept as a role if the account is deleted), action, entity, summary, before and after values, time. |
| Grade_correction / Grade_correction_flag | A correction request (old and new grade and remark, reason, who asked, who decided, status) and the flags raised for later work that relied on the old result. |

**Database-level guards:** all 69 foreign keys; unique keys `uq_account_email`, `uq_subject_code`, `uq_curriculum_program_name`, `uq_term_year_semester`, `uq_section_program_year_name`, `uq_class_term_section_subject`, `uq_room_code`, `uq_room_type_name` and `uq_tag_name`, plus the structural ones (unique username, unique token hash, unique student ID number, one enrollment per student per term, one enrollment per class per enrollment); and three CHECK constraints, `chk_enrolled_subject_grade`, `chk_transferee_credit_grade` and `chk_grade_correction_grades` (1.00 to 5.00). **The CHECK constraints are unconfirmed on the live database**: MariaDB 11.4 accepts them, but phpMyAdmin 4.9 omits CHECKs from exports and its constraint listing came back empty. To confirm, run `SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_TYPE = 'CHECK' AND TABLE_SCHEMA = DATABASE();` (expect 3 rows once Batch 12 is applied, 2 before). The 4-year cap on year levels is enforced in the application only. The `must_change_password` column still defaults to 1 in the schema, although every insert writes 0 explicitly.

## 5. Non-Functional Requirements

| **Area** | **Requirement** |
| --- | --- |
| Security | config/, includes/, src/ and sql/ are blocked from direct web access via .htaccess; only public/ is served. Every role folder under public/ has its own index.php that checks the session and redirects. Passwords are always stored hashed (bcrypt); no password is ever displayed or emailed. Link secrets are stored only as SHA-256 hashes. The reset page sends no-referrer and no-store headers. **CSRF protection** covers every POST form, and logout is POST-only. Removed pages (`register.php`, `shift-add-subjects.php`, `enrollment.php`, `diag-reset.php` and `prerequisites.php`) must return not found; the first four are absent from the live server. |
| Session integrity | 24-hour explicit session lifetime with httponly cookies (Secure over HTTPS); session id regenerated at login and password change; every protected page calls a role guard before rendering and checks the account's `session_version` and `is_active`; a pending forced password change blocks every other page. |
| Data integrity | Every table must be InnoDB with utf8mb4 (a MyISAM table silently drops foreign keys and transactions). All 69 foreign keys are enforced, grade columns carry CHECK constraints, and the unique keys in Section 4 backstop the application rules. Named PDO placeholders must be unique per query, even across UNION branches (real prepared statements), enforced by convention, not the database. Multi-row writes (curriculum subjects, roster changes, placement, term close, grade corrections, generator save, password reset, email change) run in transactions, and a rollback is only attempted when a transaction is open. |
| Maintainability | All CSS lives in external stylesheets under public/assets/css/; colors and spacing are tokens in `tokens.css` and `app.css` must not use raw colors. A single shared navbar file carries the sidebar and global scripts to every logged-in page. Shared helpers load through `includes/session.php` or are required where used: `ui_helper`, `flash_helper`, `academic_helper`, `picker_helper`, `schedule_helper`, `password_helper`, `reset_token_helper`, `invite_helper`, `mail_helper`, `account_email_helper`, `username_helper`, `instructor_helper`, `tag_helper`, `generator_helper`, `audit_helper` and `correction_helper`, plus `includes/subject_picker.php` and `includes/instructors_page.php`. The generator preview has its own stylesheet, `generator.css`, built on the same tokens. |
| Portability | Runs on a standard XAMPP stack locally (PHP 8.2, MariaDB 10.4) and on InfinityFree's free tier in production (PHP 8.4, MariaDB 11.4, phpMyAdmin 4.9). Production table names are case-sensitive, so every file and query uses the capitalized names of `schema.sql`. Windows XAMPP accepts either case, so the same SQL files run locally and live. The free plan does not allow `CREATE VIEW`, so the schema has no views, and databases are rebuilt from `schema.sql` instead of migrated. `BASE_URL` is `/public` in production and `/enrollment-system/public` locally. InfinityFree has no cron, so expired link rows are swept on each new link request. The repo's `config/app.php` says development; the server copy says production and must never be overwritten. `config/database.php` and `config/mail.php` are gitignored and never uploaded from the repo. |
| Email delivery | Transactional email (invite, reset link, email-changed notice to the old address) is sent through the Brevo API with a 10-second timeout and never throws. A failed or missing email must not block account creation or placement; the staff member or registrar sees a warning and uses **Resend invite**. There is no on-screen fallback. Test emails use Gmail plus-addressing (`inbox+tag@gmail.com`) so one inbox serves every account. The free Brevo plan has a daily send limit. |
| Performance | Auto-generate runs inside a 10 second budget on the free host and is limited to 5 sections per run, partly because the preview form must stay under the host's `max_input_vars`. A hidden end marker detects a form that was cut off. The weekly grid is drawn in the browser from the form fields. The preview shows how long a run took, so the limit can be revisited with real numbers. |
| Auditability | Notable actions are written to `Audit_log` (3.18), and a failed log write never blocks the action. Grades change after a term closes only through an approved correction. |
| Error handling | No raw exception text is shown to users. Errors are logged with a reference code through `error_log()`. The free hosting plan exposes no log viewer, so diagnosis of a silent failure needs a temporary on-screen diagnostic page (delete it afterwards) or an error-log table. |

## 6. Known Gaps & Deferred Items

### 6.1 Acknowledged gaps

These are acknowledged gaps against full scope, tracked here so they are not rediscovered as surprises. Items marked **(open issue)** came out of the project handoff and are flagged, not confirmed fixed.

**Accounts and security**

- Event-driven notifications (enrollment approved, grade posted, correction decided) are not built; only the transactional emails in Section 5 exist.
- **Changing a password while logged in does not ask for the current password**, so anyone at an unlocked, logged-in browser can change it. The change-password screen always says "You're using a temporary password", including when opened voluntarily from Profile.
- Password rules are minimal (8 or more characters, matches confirmation, not the username). There is no complexity rule, no common-password check and **no login attempt throttling**; only link requests are throttled. Signing in by email gives an attacker one more thing to try. The owner accepted this gap for now (Section 9.4).
- If an invite email cannot be sent there is no on-screen fallback. Someone must use Resend invite once the address is correct. The throttle is shared, so Resend within 60 seconds of the automatic invite is refused.
- The admin's own Profile email change is a plain update (it does not cancel open links, notify the old address or write an audit row). A deactivated account can still finish a reset that was issued before it was deactivated, but cannot log in.
- Typing the email twice on the public application form is not built.

**Data and structure**

- The applicant information form (about 30 fields) is hand-duplicated across the public application form, the admission review screen, and the registrar's placement screen; a schema change to applicant fields means editing all three. The email rules in 3.12 live in all three.
- There is **no screen to manage departments or programs**. They exist only through SQL (`seed_test.sql` loads the six colleges and six programs in Section 1), so registrars and teachers cannot be registered and the application form has no programs until they are seeded.
- **No SQL file installs only the structure, the departments, programs, room types and the Admin account without test data.** `enrollment_import.sql` did that and was removed (it is in git history). A production install needs one.
- Shift approval deliberately leaves an empty enrollment until the student picks subjects. A rejected irregular enrollment keeps its subject rows until the student presses Start Over.
- The 4-year cap applies to every program (the owner chose one cap for all; two-year programs such as ACT are not modelled separately).
- **(open issue)** `uq_section_program_year_name` is unique on (program, year level, name), so a second curriculum cannot have its own Year 1 "A". Sections are named per batch (for example `A 2025`) to avoid this, or the key would need `curriculum_id`.
- A section's year level is its intake year and students keep their section as they move up, so it drifts from their current year. Labels no longer present it as the current year (3.5), and a student's own year comes from their enrollment.
- `Department.max_units_per_term` exists in the schema but nothing reads it.

**Curricula and rooms**

- Removing a subject from a curriculum also removes its prerequisites in that curriculum. The confirm dialog and the result message say how many.
- Room merges and capacity changes are checked against the ongoing term only. Overlaps in closed terms are history and do not block them.

**Classes and the generator**

- The manual Class Offerings form shows its tag, room type and hours warnings after saving, not before, and its Teacher dropdown shows no tag match or load next to each name. Its table has no enrolled count.
- **Try a different arrangement** discards manual edits to the draft (the confirm dialog says so). Drafts live in the session and do not survive logout.
- The generator has no teacher availability, no section shifts (morning or afternoon), and plans for sections only. Cost-based placement packs classes tightly, which can leave a few days with a single class and days of up to 7 hours.
- The capacity check on the start screen is an estimate: a teacher with several tags counts in every group they match.

**Corrections**

- No one is notified when a correction is requested or decided.
- Class History does not show the Corrected marker.

**Interface and operations**

- UI gaps: no shared page header component (each page carries its own `<head>` and Bootstrap link), no browser-history back action on logged-in pages, tables do not collapse to cards on phones, a few pages have nested cards with heavy shadows, and `app.css` makes `.table-responsive` scroll inside the page, which causes nested scrolling on long tables unless overridden.
- The free hosting plan has no error-log viewer, so the `[MIST XXXXXX]` references cannot be looked up there.
- Cosmetic: the live `README.md` still says "capstone" and the live `config/app.php` header comment still mentions `register.php`.

### 6.2 Status and open items (October 2026)

Resolved since v2.4 (built on the development copy and checked in the sandbox, **not yet run end to end by the owner**; the live site has the October 13 build):

1. **One weekly meeting per class** (the model gap): classes now have zero or more meetings (3.14).
2. **Prerequisites and year standing per curriculum**, and several open curricula per program (3.13).
3. **Free-text rooms:** rooms and room types managed by the admin (3.15).
4. **Phase 1 changes** (unique email for every account, sign in by username or email, subject year level) are built (3.1, 3.5, 3.12).
5. **Grade correction after a term closes**, and an **audit trail** for grade, placement, approval, status and deactivation events (3.18).
6. **Generator quality** (cost-based placement, teacher rest rule, capacity check, preview redesign and weekly grids) (3.17).
7. **October 24 to 25 fixes (v2.7):** Undo and hand-edited classes, section labels, curriculum removal, room double-booking checks, busy teachers, the start screen, the manual class form and the shared standing rule.

Still open:

- **The localhost test pass is not done.** The live site runs the October 13 build on a database rebuilt from `schema.sql` (34 tables, no migrations needed). The Week tab, the weekly view window, `seed_generator.sql`, `seed_all_years.sql` and the v2.7 changes are local. Update the live site again only after `Test_Run_v5.md` passes.
- **Test run.** `Test_Run_v5.md` covers the small seed (Phase A, Rounds 0 to 21) and class generation at scale (Phase B, Rounds 22 to 30) and the all-years seed with the v2.7 fixes (Phase C, Rounds 31 to 41). Every account has its username and password listed. `Test_Run_v3.md` stops before Batch 3.
- **CHECK constraints** are unconfirmed on live (Section 4 gives the query).
- **Secrets:** `notes/notes.md` held real credentials (mail API key, hosting and database passwords, admin and test logins) and was inside a zip shared in chat. The owner plans to rotate them. Keep that file out of any zip, and out of git.
- `.gitignore` excludes real database dumps (for example `/sql/enrollment_system.sql`, `*.zip` and `/notes/`) so they are not committed, but dumps committed earlier remain in git history.
- Whether the latest working tree has been committed and pushed was not confirmed.
- Dropdown arrows: `app.css` already used `background-color`. The remaining cause was `.neu-select` in `components.css` (login, apply and forgot-password pages), fixed in v2.7. Confirm in the browser.
- The local "headers already sent" warning could not be reproduced: the config files have no BOM or trailing whitespace and the Apache error log has no such entry. Closed unless it returns.
- The live database holds the `seed_test.sql` test data (rebuilt on October 13). The development database holds the same, plus `seed_generator.sql` and `seed_all_years.sql` when they are imported (Section 8).
- Stale documents: the Word plan (`MIST_Planned_Changes_v2.docx`) and `Test_Run_Phase1.md` stop before Batch 3.

## 7. Out of Scope (This Version)

- Payment/billing, financial aid, or tuition assessment.
- Mobile app or offline access. The system is a web application only.
- Integration with any external LMS, SMS, or government reporting system.
- Event-driven notifications (email or SMS), see Section 6.1. Transactional email for invites, reset links and email-change notices is implemented and in scope.
- Combined classes, lab-group splits and team teaching in the class model (3.14). Co-requisites, "A or B" prerequisites and "consent of the dean" rules (3.13).

## 8. Data Operations (SQL Scripts)

All SQL lives in `sql/` and there are four files. Run them in phpMyAdmin's Import tab **with the database selected first**: with no database selected, `DATABASE()` is empty and unqualified table names fail with "Unknown table ... in information_schema". phpMyAdmin 4.9 may underline `CHECK`, `IF NOT EXISTS` or `AUTO_INCREMENT` in red; that is only its old syntax checker, and the server runs the statement. **Rebuild databases from `schema.sql`, never from a phpMyAdmin export**, because an export drops the CHECK constraints.

Every file uses the capitalized table names. Windows XAMPP accepts either case and the live host is case-sensitive, so the same files run in both places. The development database is `enrollment_system`; the live one is `if0_43013716_enrollment_system`. **Views cannot be created on InfinityFree's free plan, so none are used.**

| **File** | **Purpose** |
| --- | --- |
| `schema.sql` | Structure only: 34 tables, keys, 69 foreign keys, 3 CHECK constraints, no views. Import into an **empty** database. Current through Batch 12. |
| `seed_test.sql` | Test data, imported **after** `schema.sql`. It wipes every table first, so importing it again is the **reset**. Loads the 6 departments and 6 programs (Section 1), the 17 room types, the Admin account (`Admin`) and test data for two colleges: the staff accounts, the ongoing term 2027-2028 Semester 1, 13 subjects (5 BSIS test subjects and 8 BSM subjects), two curricula, 7 sections, 8 rooms, 4 tags, 5 BSIS students in the section `TEST-A` with 3 classes, and one pending application. Every seeded account uses the password `mist2027`; `alcantara_ramon` and `2026-00001` must change it at first login. Seeded emails end in `@mist.test`. |
| `seed_generator.sql` | Extra data for testing Auto-generate Classes, imported **once**, right after `seed_test.sql`. A second import stops with `Duplicate entry '2'`; import `seed_test.sql` again first. Adds the school's own **BSIS 2023** curriculum (49 subjects, 17 prerequisites, year standing on 10 subjects), 10 more teachers with tags and unit limits, 12 more tags, 5 more rooms (13 in all), 5 sections (`A 2027`, `B 2027`, `A 2026`, `A 2025`, `A 2024`), a closed term 2026-2027 Semester 2 and 82 students whose last enrollment is in it. Its last query shows 12 BSIS teachers, 13 rooms, 49 curriculum subjects and 32, 28 and 22 students. |
| `seed_all_years.sql` | Extra data for a four-year generator test, imported **once**, right after `seed_generator.sql`. A second import stops with a duplicate key error; import `seed_test.sql`, `seed_generator.sql` and this file again. Adds 8 BSIS teachers with tags (`lim_paolo`, `navarro_marco`, `domingo_ruth`, `pascual_ian`, `tan_victor`, `cruz_maricel`, `garcia_noel`, `lopez_dina`), 5 sections (`C 2027`, `B 2026`, `C 2026`, `B 2025`, `B 2024`), 107 students (34, 26, 27 and 20 in the new sections of years 2 to 4, usernames `2026-00301` to `2024-00407`), and 40 validated freshman applicants waiting for placement. Its last query shows 20 BSIS teachers, 11 BSIS sections (with `TEST-A`), 189 students in sections 10 to 17 and 40 unplaced applicants. |

The all-years seed keeps those traps and adds one rule of thumb: the 10 BSIS sections need 2 runs, and about 7 of the 68 classes (`PolSci` three times, and 4 of the 6 PE classes) need a teacher by hand. It should not be mixed with Phase B of the test run, because it changes the teacher list and the expected counts. The generator seed holds deliberate traps: one PE teacher with a 4 unit cap for 3 PE classes (one gets no teacher), a subject with no tags (`PolSci`, no teacher can be matched), a teacher with no tags, a deactivated teacher, two subjects with no hours (`CAP 102`, `PRAC 101`, so TBA classes), an inactive room, and two rooms that seat 30 while some sections are larger. With these five sections the generator should draft 34 classes, place all of them, and leave exactly 2 without a teacher (`PolSci` and one PE class).

To reset a database: import `seed_test.sql` (and `seed_generator.sql`, then `seed_all_years.sql`, once each after it). To rebuild the live database: drop every table (Structure tab, check all, Drop), import `schema.sql`, then a seed, then upload the code. Never overwrite the server's `config/app.php`, root `.htaccess`, `config/database.php` or `config/mail.php`.

`migrations/`, `enrollment_import.sql`, the demo seeds (`MIST_Seed_Demo.sql`, `MIST_Seed_Demo_History.sql`) and the reset scripts of earlier versions were removed from the project. They remain in git history and were never checked against 34 tables.

The seed files were loaded into a stand-in database (SQLite) with foreign keys on, and their values were checked against MySQL's column limits, but they have not been run on MariaDB by the author. Testing: `Test_Run_v5.md` is the checklist (Phase A on `seed_test.sql` alone, Phase B on both seeds), `MIST_Sandbox_Test_Report.md` records what the sandbox checks covered, and `MIST_Test_Accounts.md` holds the test credentials and must stay out of git.

## 9. Plan Status and What Remains

This section replaces the v2.4 plan. Everything that plan promised for Phases 1 to 3 is built, and so are Batch 12 and part of Batch 11b. **Built means written and checked in a sandbox without PHP (the SQL on a stand-in database, the PHP structure, the weekly view script in a browser, a port of the generator), not yet run end to end by the owner on localhost.**

### 9.1 Plan at a glance

| # | Change | Described in | Status |
| --- | --- | --- | --- |
| 1 | Unique email for every account | 3.1, 3.12 | Built |
| 2 | Sign in and recover by username or email | 3.1 | Built |
| 3 | Subject year level | 3.5 | Built |
| 4 | Teacher Class History | 3.19 | Built |
| 5 | Several open curricula, chosen at placement | 3.13, 3.3 | Built |
| 6 | Prerequisites per curriculum | 3.13 | Built |
| 7 | Year standing | 3.13 | Built |
| 8 | My Curriculum (student) | 3.19 | Built |
| 9 | Classes and meetings | 3.14 | Built |
| 10 | Rooms and room types (admin) | 3.15 | Built |
| 11 | Subject requirements (hours per meeting and preferred room type) | 3.15 | Built |
| 12 | Tags | 3.15 | Built |
| 13 | Teacher load and the Instructors pages | 3.15, 3.16 | Built |
| 14 | Auto-generate class offerings with manual override | 3.17 | Built |
| 15 | Generator quality (Batch 11b), steps 1 to 3 and most of 4, plus the weekly view window | 3.17 | Built |
| 16 | Grade correction after a term closes, with an audit log (Batch 12) | 3.18 | Built |
| 17 | Busy teachers in the preview's Edit panel, start screen summary and Select next 5, run time (v2.7) | 3.17 | Built |
| 18 | The manual Class Offerings form shares the generator's warnings, curriculum-filtered subjects and lock (v2.7) | 3.14 | Built |
| 19 | Section intake year labels, room double-booking checks, shared standing rule (v2.7) | 3.5, 3.15, 3.6 | Built |

### 9.2 What remains, in order

1. **Test what is built.** Run `Test_Run_v5.md` on localhost (Phase B first for generation, then Phase C, then Phase A) and fix what it finds before anything more goes to the live site. Write down the run times from the preview, they decide whether the 5 section limit can be raised.
2. **Batch 11b, the rest:**
   - Preview extras still missing: a teacher load table (units and hours against the caps) and bulk assign for classes without a teacher. The quality summary, the "show only problems" switch, the sticky action bar and the weekly view window are done. Marking busy teachers in the Edit panel is done (3.17).
   - **Locks and partial save:** pin a row (or its teacher) so a new arrangement moves only the unlocked rows, and "Save clean rows only" so one conflict does not block the rest.
   - **Reproducibility:** store the seed, options, years and quality numbers on `Generation_run` (needs a migration in both versions). Undo already skips classes edited by hand.
3. **Batch 11c, realism and manual-form parity:**
   - Teacher availability (blocked days and times), the biggest gap between the model and a real school, especially for part-time teachers.
   - Section shifts (morning, afternoon or any) so sections can share scarce rooms.
   - A run settings panel (school days, hours, lunch, daily caps, compact versus evenly spread days).
   - Drafts saved in the database so they survive logout, and a per-row "why this teacher and time" explanation.
   - Bring the generator's checks to the manual Class Offerings form by reusing the same validation: tag warning, school hours, room type, teacher load and match in the Teacher dropdown, and an enrolled count in its table. The tag, room type and hours warnings, the curriculum-filtered Subject list and the lock are done (3.14); the warnings still appear after saving, not before.
4. **Backlog:**
   - A shared `head` include and page header component (47 pages each carry their own).
   - Split the registrar's Academic Setup into Curriculum (Subjects, Curriculum, Sections, Terms) and Scheduling (Class Offerings, Schedule, Instructors), make sub-pages highlight their parent, and show pending-count badges for shifts, irregular enrollments and applications.
   - A "Term readiness" card on the registrar dashboard.
   - An enrollment-open flag separate from "ongoing", so classes can be prepared before students enroll and frozen after.
   - Accessibility: more aria labels, icon plus text for status, empty states on `transferee-credit.php` and `my-curriculum.php`, and a button or breadcrumb in place of inline "Back to Class Offerings" links.
   - Revisit login throttling now that email sign-in exists.
   - Notifications for correction requests and decisions.

### 9.3 Measured, so do not build

A repair step that ejects placed classes to fit unplaced ones, "keep the same teacher for a subject across sections", and load smoothing showed no useful gain in a Python simulation of the generator on the demo data. The two classes that stayed unplaced in the tightest test were a physical limit (one lab and 3 hour blocks), not an algorithm weakness.

For the cost-based placement, the same simulation (4 sections, 31 classes, best of 5 arrangements, averaged over 20 runs, PHP not run) gave these results against the earlier first-free-slot placement:

| Measure | First free slot | Cost-based |
| --- | --- | --- |
| Idle minutes between a section's classes | about 1,490 | about 800 |
| Waits of 90 minutes or more, per run | 5.5 | 0.25 |
| Longest wait (minutes) | 190 | 86 |
| Teacher stretches of 3 or more classes in a row | 0.55 | 0 |
| Unplaced classes, and classes without a teacher | 0 and 1 | 0 and 1 |

### 9.4 Decisions

Decided:

- Every account's email is unique across all roles. The admin's email may be empty. Sign in and recovery accept a username or an email.
- Subject year level is stored (1 to 4), required, and a guide only.
- Several curricula per program may be open, and the registrar chooses at placement. **Students are never moved to another curriculum.** A section follows one curriculum. "Standing" means every subject of the earlier years is completed.
- Rooms, room types and tags are managed by the admin (registrars and admin can add tags). Teacher load limits are set when the admin registers a teacher.
- Hours are per meeting, at most 5, in half hours. The generator calendar is Monday to Friday, 7:30 AM to 5:30 PM, with no classes 11:30 AM to 12:30 PM, on 30 minute starts.
- A class with no matching teacher is drafted with an empty teacher and a warning, and the registrar must assign one before Save. A teacher with no shared tag is never assigned automatically.
- Hard rules (a teacher, section or room in two places, one teacher per subject per section per term) cannot be overridden. Soft rules can, with a visible warning.
- A teacher gets at most 2 classes in a row. Classes are packed so students wait little. These are generator rules, and edits that break them only warn.
- The generator plans for sections, not retakes. Existing classes are never overwritten, and a class with students is never touched. Section names are per batch, for example `A 2025`.
- A grade changes after a term closes only through a correction approved by the registrar of the class's department. Corrections flag later work for review and never undo it. Closed terms stay locked for direct edits.
- No failed-login limit for now (an accepted gap).
- A class changed by hand leaves its generator run, so Undo never removes it.
- A section's year level is its intake year, and labels do not claim a current year.
- Room merges and capacity changes are refused when they would double-book a room in the ongoing term.
- The manual Class Offerings form uses the generator's warnings and constants, and the same lock for saving.

Still open (suggested answer in brackets):

1. Optional weekly hours cap default for teachers. (30, may be blank.)
2. Offer "allow teachers with no matching tag as a last resort"? (Yes, off by default. Not built.)
3. Raise the 5 section limit? (Decide from the run times in Phase C, and the host's `max_input_vars`.)
4. Show the manual form's warnings before saving, and tag match and load next to each teacher? (Yes, in Batch 11c.)
