## tags: [mist, testing, checklist, v5, local]

# MIST Enrollment System: Test Run (v5, local)

Run top to bottom on your own computer (XAMPP). The database is loaded from seed files, so nobody types the setup data. Each round has checkboxes, then the exact inputs underneath.

> [!info] Two phases
> **Phase A (Rounds 0 to 21)** is the v4 run on the small seed (`seed_test.sql`, 5 students). **Phase B (Rounds 22 to 30)** is new: class generation at scale, on the real BSIS 2023 curriculum, with 87 students, 13 teachers and 13 rooms (`seed_test.sql` plus `seed_generator.sql`).
> The two phases are **independent**, each starts with its own reset. If class generation is what you care about, **run Phase B first**.

> [!info] Legend
> **P1** = must pass, run it | **P2** = should pass, run it if you have time | **P3** = optional, skip when rushed
> 🆕 = new since v3 | 🔴 = try to break it, the system must refuse | `code` = type exactly this
> If something fails, write it in the bug log at the bottom (round, what you did, what you expected, what happened) and keep going.

> [!tip] If you only have about an hour, run these and skip the rest
> Round 0, 2, 3.5, 5, 6, 9, 10, 11, 14, 17 (Phase A), then 22, 24, 25, 26, 27, 28 (Phase B). Everything else is P2 or P3.

---

## Accounts (already created, password for all of them is `mist2027`)

At the login page pick the **Role** first, then type the username and password.

| Role (login dropdown) | Username | Name | Notes |
| --------------------- | -------- | ---- | ----- |
| Admin | `Admin` | Admin | email is empty on purpose |
| Registrar | `alcantara_ramon` | Registrar A, BSIS (CTIS) | 🆕 **forced password change** on first login |
| Registrar | `alcantara_ramon2` | Registrar B, College of Medicine (BSM) | |
| Instructor | `bautista_carla` | Bautista, Carla (CTIS) | max 9 units, 20 hours a week |
| Instructor | `lim_daniel` | Lim, Daniel (CTIS) | |
| Instructor | `navarro_elena` | Navarro, Elena (BSM) | |
| Admission Staff | `ocampo_patricia` | Ocampo, Patricia | |
| Student | `2026-00001` | Juan Dela Cruz Jr. | 🆕 **forced password change** on first login |
| Student | `2026-00002` | Maria Reyes | |
| Student | `2026-00003` | Pedro Ramos | |
| Student | `2026-00004` | Ana Villanueva | transferee |
| Student | `2026-00005` | Luis Garcia | |

All seeded emails end in `@mist.test`, so no email ever reaches them. That is why Round 19 asks you to change an email to a real inbox first.

**Accounts you create during the run** (Rina, the extra registrar, the extra teacher) are created as **invited accounts with no password**. They cannot log in until someone uses the emailed link, and that is fine, you do not need to log in as them. Round 19 tests that link.

> [!note] Phase B accounts
> `seed_generator.sql` adds 10 teachers and 82 students, all with password `mist2027`. They are listed in the Phase B section (before Round 22). Phase A never needs them.

## What is already in the database

| Area | Seeded |
| ---- | ------ |
| Departments, programs, room types | all 6 departments, 6 programs, 17 room types |
| Term | `2027-2028` Semester 1, **ongoing** |
| BSIS test subjects | `TST101` to `TST105` (3 units each, tagged, `TST104` and `TST105` have weekly parts) |
| BSIS curriculum | `TEST BSIS Curriculum 2027` with those 5 subjects, `TST102` needs `TST101`, `TST104` needs `TST102` |
| BSM | 8 subjects (`GE 101`, `MID 101`, `HC 101`, `ANAT 101`, ...), curriculum `BSM 2026`, 6 sections |
| Sections | `TEST-A` (only **4** slots, holds **5** students on purpose) and the 6 BSM sections |
| Rooms and tags | 8 rooms (`T-101` to `T-104`, `T-201`, `M-201`, `M-202`, `T-LAB1`), 4 tags |
| Term 1 classes | `TST101` Mon, `TST102` Tue, `TST103` Wed, each 8:00 to 10:00 AM, Bautista, `TEST-A` |
| Students | Juan, Maria, Pedro, Ana (transferee, only on `TST103`, no credits yet), Luis, all enrolled in `TEST-A` |
| Applications | Rina Soriano, pending (waiting for admission staff) |

## What you add yourself (so every error path gets tested)

`TST106` (subject), `TEST-B` (section), one room, one tag, two public applications (Carlo, Reject Tester), the term 1 boundary and conflict classes, the term 2 classes, `TEST-C` (section, Round 17), plus one extra registrar and one extra teacher.

## Constants

| Name | Value |
| ---- | ----- |
| New password after a forced change | `Mist@Test2027` |
| Seeded password | `mist2027` |
| Term 2 | School year `2027-2028`, Semester `2` |
| Your inbox (Round 19 only) | `YOUR_INBOX@gmail.com` (use `YOUR_INBOX+admin@gmail.com`, `+bautista`, `+juan`, `+rina` so each is a different address) |

---

## Round 0: Setup (P1)

**Before you start**

- [ ] XAMPP Apache and MySQL are running
- [ ] The project folder is `htdocs/enrollment-system`, and `config/database.php` points to a database named `enrollment_system` (copy `config/database.example.php` if it does not exist)
- [ ] PHP has `mbstring`, `curl` and `pdo_mysql` on (XAMPP does by default)
- [ ] `config/mail.php` exists (copy `config/mail.example.php`). The Brevo key can stay fake until Round 19. Without a real key the app shows an "invite email could not be sent" warning, which is expected

**Load the database (phpMyAdmin)**

- [ ] Create a database `enrollment_system`, collation `utf8mb4_general_ci`
- [ ] Select it, **Import** `sql/schema.sql`, no error
- [ ] Import `sql/seed_test.sql`, no error. The last result shows this table:

| item | rows_loaded |
| ---- | ----------- |
| Accounts | `12` |
| Students | `5` |
| Applications (1 pending) | `6` |
| Subjects | `13` |
| Sections | `7` |
| Rooms | `8` |
| Class offerings | `3` |
| Enrolled subjects | `13` |

> [!tip] Reset at any time
> Import `seed_test.sql` again. It wipes every table first, so you are back to this exact starting point. Do this whenever a round leaves things in a mess.

> [!warning] Phase A and Phase B load different data
> The numbers in this Round 0 (12 accounts, 8 rooms, `13` subjects and so on) are for **`seed_test.sql` alone**, which is what Phase A expects. Phase B imports `seed_generator.sql` on top and has its own numbers (Round 22). Never import `seed_generator.sql` twice in a row: it stops with `Duplicate entry '2'`. To redo either phase, import `seed_test.sql` again first.

**Health checks** (run each in the SQL tab of `enrollment_system`)

- [ ] `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';` returns `34`
- [ ] `SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY';` returns `69`
- [ ] `SELECT COUNT(*) FROM Accounts WHERE password_hash LIKE '$2y$%';` returns `12`

**Smoke**

- [ ] `http://localhost/enrollment-system/public/login.php` loads with styling and the logos
- [ ] P2: these must show a "forbidden" or "not found" page and never any code: `/enrollment-system/config/database.php`, `/enrollment-system/includes/session.php`, `/enrollment-system/sql/schema.sql`

---

## Round 1: Admin sets up and checks people, rooms, tags (P1)

**Log in:** Role `Admin`, user `Admin`, password `mist2027`.

- [ ] Dashboard shows `2` Registrars, `3` Teachers, `1` Admission Staff and the current term `2027-2028 Sem 1`
- [ ] Staff Accounts lists those 6 people, searching `Alcantara` shows both registrars

**1.1 Admin profile email**

- [ ] The email box is empty. Try the invalid and the taken emails below, then save the good one

| Email | Expected |
| ----- | -------- |
| `not-an-email` | "Enter a valid email address." |
| `rega@mist.test` | refused, that address belongs to Registrar A (plain message, no SQL text) |
| `admin@mist.test` | saved |

**1.2 Duplicate staff warning** 🆕 (Register Registrar)

- [ ] Fill the form below. A yellow **Possible duplicate** box names the existing Alcantara, Ramon
- [ ] Press **Create Anyway**: the account is created. Note the username it gets (a number is added to it, for example `alcantara_ramon3`)
- [ ] With no real mail key you also see: "The account was created, but the invite email could not be sent. Open Staff Accounts and use Resend invite once the address is confirmed."
- [ ] Press F5 on the result page: no second account appears
- [ ] Dashboard now shows `3` Registrars

| Field | Value |
| ----- | ----- |
| Last name | `Alcantara` |
| First name | `Ramon` |
| Middle name | `Bautista` |
| Department | COLLEGE OF TECHNOLOGY AND INFORMATION SYSTEMS |
| Email | `rega3@mist.test` |

**1.3 Register a teacher** (this account is only for the destructive round)

| Field | Value |
| ----- | ----- |
| Last name | `Tester` |
| First name | `Extra` |
| Department | COLLEGE OF TECHNOLOGY AND INFORMATION SYSTEMS |
| Email | `extra@mist.test` |

- [ ] Created. A blank email, or an email already used (`lim@mist.test`), is refused

**1.4 Rooms** 🆕 (Admin, Rooms)

- [ ] Add the room below: "Room added."
- [ ] Every bad row is refused with a friendly message
- [ ] Edit `T-202`, change capacity to `35`: "Room updated."
- [ ] Merge `T-202` into `T-201` (keep `T-201`): "Rooms merged. Every class that used the removed room now uses the other one."
- [ ] Merging a room with itself: "Pick two different rooms to merge."
- [ ] The Registrar cannot open `/admin/rooms.php` (redirected away)

| Room code | Type | Capacity | Expected |
| --------- | ---- | -------- | -------- |
| `T-202` | Lecture Room | `40` | added |
| `T-101` | Lecture Room | `40` | "A room with that code already exists." |
| (blank) | Lecture Room | `40` | refused |
| `T-203` | (no type) | `40` | "Pick a room type." |
| `T-203` | Lecture Room | `0` | "Capacity must be a whole number from 1 to 5000, or left empty." |
| `T-203` | Lecture Room | `5001` | same message |
| `T-203` | Lecture Room | `abc` | same message |

**1.5 Tags** 🆕 (Admin, Tags) P2

| Name | Expected |
| ---- | -------- |
| `Research` | "Tag added." |
| `research` | "A tag with that name already exists. Capitals do not make a new tag." |
| (blank) | "A tag name is required and can be at most 50 characters." |

- [ ] Rename `Research` to `Research Methods`: "Tag renamed everywhere it is used." Then delete it

**1.6 Instructors** 🆕 (Admin, Instructors) P2

- [ ] Bautista shows Max units `9`, Max hours per week `20`, tags `Programming`, `Database`. Lim has `24` and `30`
- [ ] Clearing Max hours per week and saving works ("Instructor profile saved."), put `20` back for Bautista

---

## Round 2: Registrar A, forced first login (P1)

**Log out. Log in:** Role `Registrar`, `alcantara_ramon`, `mist2027`.

- [ ] You land on **Change Password**, not the dashboard
- [ ] 🔴 Type `/enrollment-system/public/registrar/dashboard.php` in the address bar: you are sent back to Change Password
- [ ] Try each bad password below, all refused with a message (write the message down). Then set `Mist@Test2027`
- [ ] You reach the Registrar dashboard: `0 Awaiting Placement`, `5 Active Students`
- [ ] Log out and in again with `Mist@Test2027`. `mist2027` no longer works

| New password | Confirm | Expected |
| ------------ | ------- | -------- |
| `mist2027` | `mist2027` | refused or accepted, write down which (same as old) |
| `short` | `short` | refused, too short |
| `alcantara_ramon` | `alcantara_ramon` | refused, same as username |
| `Mist@Test2027` | `Mist@Test2028` | refused, does not match |
| `Mist@Test2027` | `Mist@Test2027` | accepted |

---

## Round 3: Registrar A adds to the academic records (P1)

**Session:** Registrar A (`alcantara_ramon`, `Mist@Test2027`). The base data exists, you add one of each thing and try to break it.

### 3.1 Subjects: add `TST106`

- [ ] Add the subject below: "Subject added." Leave **Tags** and **Weekly parts** empty on purpose (Round 17 needs it that way)
- [ ] Each bad row is refused with a plain message, no SQL text
- [ ] 🔴 Delete `TST101`: refused, "This subject is still in use: ..."

| Code | Name | Units | Year level | Expected |
| ---- | ---- | ----- | ---------- | -------- |
| `TST106` | `Test Elective` | `3` | `1` | "Subject added." |
| `TST101` | `Duplicate Code` | `3` | `1` | refused, code already exists |
| (blank) | `No Code` | `3` | `1` | "Subject code, name, and a numeric unit count are required." |
| `TST999` | `Bad Units` | `abc` | `1` | same message |
| `TST999` | `Bad Year` | `3` | DevTools: change the year option value to `5` | "Pick a year level from 1 to 4." |

### 3.2 Curriculum: `TEST BSIS Curriculum 2027`

- [ ] **Add Subjects to Curriculum**: adding with nothing ticked is refused. Tick `TST106`, Year `1`, Semester `2`, **Add Selected**
- [ ] 🆕 **Requirements** (prerequisites and year standing) on a subject. Try every row below
- [ ] Remove `TST106` from the curriculum (confirm dialog), then add it back the same way
- [ ] P3: Create Curriculum `ZZ CLONE TEST 2027` for BSIS, effective year `2027` ("Curriculum created"), retire it ("Curriculum retired. Students already on it are not affected."), reopen it, retire it again

| Subject | Set | Expected |
| ------- | --- | -------- |
| `TST105` | prerequisite `TST104` | "Requirements saved. They apply to future subject choices only." |
| `TST101` | prerequisite `TST102` | "A prerequisite must come in an earlier year or semester than the subject." |
| `TST104` | prerequisite `TST104` | "A subject cannot be its own prerequisite." |
| `TST105` | required year standing `3` | "A subject cannot ask for a higher year standing than the year it is placed in." |

### 3.3 Sections: add `TEST-B`

- [ ] The list shows `TEST-A` (4 slots). Add the section below: "Section added."
- [ ] 🔴 Add `TEST-B` again: refused with a plain duplicate message
- [ ] Blank name: "All fields are required."
- [ ] Edit `TEST-B`, change slots `30` to `31`, save, change back to `30`: no error appears in the modal (v3 bug check)
- [ ] **Roster** on `TEST-A` lists all 5 students (it is deliberately over its 4 slots). Write down what the header says about slots, see the known items at the end

| Field | Value |
| ----- | ----- |
| Section name | `TEST-B` |
| Program | BSIS |
| Year level | `1` |
| Curriculum | `TEST BSIS Curriculum 2027` |
| Max slots | `30` |

### 3.4 Terms (registrar view) 🆕

- [ ] Registrar, Terms: the page is **read-only**. It says terms are opened and closed by the administrator. There is no Open or Close button here

### 3.5 Class offerings (term 1) P1

**Session:** Registrar A, Class Offerings. Term 1 already has 3 classes. Add the rows below one by one, each class needs its **Meetings** row (day, start, end, room picked from the list).

- [ ] Row a is accepted. Rows b to f are all **refused**, and none of them is saved
- [ ] 🆕 **Edit** class a (table below): "Class updated." Saving it again unchanged must not conflict with itself
- [ ] Schedule, By Section for `TEST-A`, and By Teacher for Bautista and Lim, match what you entered
- [ ] 🆕 P2 Class Offerings has **List** and **Week** tabs. On **Week**, Show by **Section** `TEST-A`: 3 blocks, `TST101` Monday, `TST102` Tuesday, `TST103` Wednesday, each 8:00 to 10:00 AM. Show by **Room** `T-101`: `TST101` Monday 8:00 to 10:00 AM and, after the Edit below, `TST106` Thursday and Friday 10:00 AM to 12:00 PM. The grid always matches the List tab

| # | Subject | Teacher | Section | Day | Start | End | Room | Expected |
| - | ------- | ------- | ------- | --- | ----- | --- | ---- | -------- |
| a | `TST106` | Lim, Daniel | `TEST-B` | Monday | `10:00` | `12:00` | `T-101` | **saved** (starts exactly when `TST101` ends in the same room, no conflict) |
| b | `TST106` | Lim, Daniel | `TEST-B` | Thursday | `10:00` | `12:00` | `T-101` | "This section already has a class for that subject this term. Edit that class to add or change its meetings." |
| c | `TST104` | Bautista, Carla | `TEST-B` | Monday | `09:00` | `11:00` | `T-104` | refused with a schedule conflict message (Bautista teaches `TST101` Mon 8 to 10, and `TEST-B` has class a then) |
| d | `TST104` | Lim, Daniel | `TEST-B` | Tuesday | `08:30` | `09:30` | `T-102` | room conflict (`T-102` is busy with `TST102`, Lim is free) |
| e | `TST105` | Lim, Daniel | `TEST-A` | Monday | `08:30` | `09:30` | `T-104` | refused, `TEST-A` already has `TST101` then |
| f | `TST105` | Lim, Daniel | `TEST-B` | Thursday | `11:00` | `09:00` | `T-104` | refused, end before start |

**Edit class a**

| Change | Expected |
| ------ | -------- |
| move the meeting to Thursday `10:00` to `12:00`, room `T-101`, and add a second meeting Friday `10:00` to `12:00`, room `T-101` | "Class updated." and the schedule shows both meetings |

---

## Round 4: Public applicants (no login) (P1)

**Open a private window.** Go to `http://localhost/enrollment-system/public/apply.php` (not logged in).

- [ ] Loads without login, no file upload field, readable on a phone width
- [ ] Step 1 empty: **Next** is blocked and required fields are highlighted. Pressing Enter moves to the next step, it does not submit
- [ ] Submit the 2 applicants below. The application numbers are **`7`** and **`8`** (Rina is already `6`)
- [ ] 🔴 Tamper test (last table): a friendly generic error, no SQL text, the form reappears with your values kept, and **no 9th application** is created
- [ ] P2: an application using Rina's email `rina.soriano@mist.test` is refused (email already used)

**Applicant 7: Carlo (minimal, will be moved to another department)**

| Field | Value |
| ----- | ----- |
| Last Name | `Dizon` |
| First Name | `Carlo` |
| Birthdate | `2008-05-18` |
| Student Type | Freshman |
| Program | BSIS |

**Applicant 8: Reject Tester (minimal, will be rejected)**

| Field | Value |
| ----- | ----- |
| Last Name | `Tester` |
| First Name | `Reject` |
| Birthdate | `2008-01-01` |
| Student Type | Freshman |
| Program | BSIS |

**Tamper (use one more copy of Carlo, never submit the real one twice)**

| On the last step, DevTools > Elements, change the Program `<option value>` to |
| ----- |
| `9999` |

---

## Round 5: Admission staff reviews (P1)

**Log in:** Role `Admission Staff`, `ocampo_patricia`, `mist2027`.

- [ ] Dashboard shows `3` pending (Rina, Carlo, Tester) and `5` validated by you in total. Pending, Validated and Rejected tabs work
- [ ] Search `Soriano` finds Rina, search spans all statuses and shows a Status column
- [ ] The **Validated** tab shows the 5 seeded students as `Placed as 2026-0000N` with no Reopen button
- [ ] Open Rina. Correct her **Purok** (table 1), save, reload: the edit stayed
- [ ] Validate Rina with Evaluated Year Level `1`, and validate Carlo the same way
- [ ] Reject `Reject Tester`: a blank reason is refused first, then the reason in table 2 works
- [ ] Final counts: **7 validated, 1 rejected, 0 pending**
- [ ] The Rejected tab shows the **Reason** column, opening Tester is read-only and shows who rejected it, when, and why
- [ ] Open Carlo, **Reopen for Review** (confirm dialog), he is Pending again. Validate him again with year `1`. Counts are 7 and 1 again
- [ ] 🔴 Rejecting Tester a second time from an old tab: "no longer pending", nothing changes
- [ ] 🔴 Open `/enrollment-system/public/registrar/dashboard.php` as staff: you are sent away

**1. Correct Rina**

| Field | Change from | Change to |
| ----- | ----------- | --------- |
| Purok | `Purok 2` | `Purok 6` |

**2. Reject Tester**

| Field | Value |
| ----- | ----- |
| Rejection reason | (blank first, expect an error) then `Incomplete requirements. Test rejection.` |

---

## Round 6: Registrar A places the new student (P1) 🔴

**Log in:** Registrar A, Place Student.

> [!warning] Riskiest write path in the app. Place Rina exactly as in the table.

- [ ] Two tabs: **Awaiting Placement** (`2`: Rina, Carlo) and **Rejected** (read-only, `Tester, Reject` with the reason)
- [ ] Open Rina, press **Load Subjects** with the term, curriculum and section from the table
- [ ] The confirm screen shows the yellow warning "Heads up: TEST-A is at 5/4" saying the placement puts it over capacity, and you can still confirm
- [ ] Untick `TST101` and `TST102` so only `TST103` is ticked, then **Confirm**
- [ ] Result: "Student placed successfully", Student ID Number / Username **`2026-00006`**, Subjects Enrolled `1`
- [ ] With no real mail key you also see: "The student was placed, but the invite email could not be sent. Open the student's Account tab and use Resend invite once the address is confirmed."
- [ ] Double-clicking Confirm must **not** create two students (the next number would be `2026-00007`)
- [ ] The Students list shows 6 students, Rina is `regular`, Year `1`, Section `TEST-A`
- [ ] Log in as Ocampo in a second browser: Rina's application says `Placed as 2026-00006` with **no** Reopen button, Carlo still says "Awaiting placement" with Reopen
- [ ] **Carlo cross-department reassign:** open Carlo in Place Student, change Program to `BSM` (table 2), Save Changes. He disappears from Registrar A's list (Registrar B sees him in Round 12). **Do not place him**
- [ ] Rina's student record shows Purok `Purok 6` (your correction carried across)
- [ ] 🔴 Tamper on Rina's confirm screen before pressing Confirm (last table): rejected or ignored, nothing wrong is enrolled

**1. Placement input**

| Student | Term | Curriculum | Section | Offerings ticked | Documents |
| ------- | ---- | ---------- | ------- | ---------------- | --------- |
| Rina | `2027-2028 Semester 1` | `TEST BSIS Curriculum 2027` | `TEST-A` | **only `TST103`** | all 4 Verified |

**2. Carlo reassign**

| Field | Value |
| ----- | ----- |
| Program | `BSM` |
| Evaluated Year Level | `1` |

**Tamper on Rina's confirm screen**

| Replace one offering checkbox `value` with |
| ------------------------------------------ |
| the id of the term 1 `TST106` / `TEST-B` offering (Lim, Thursday and Friday after your edit) |

---

## Round 7: Registrar A credits Ana's transferee subjects (P1)

**Session:** Registrar A, Students, find Ana Villanueva, **transferee credit** (the button shows only for transferees).

- [ ] The page has two tabs: **Credits** and **Add Subjects**
- [ ] Credits shows the whole curriculum by year and semester with a progress meter
- [ ] Add Subjects: 🔴 `TST102` is **greyed out** ("Requires: TST101"), `TST103` is not listed (already on her roster)
- [ ] Add the credit in table 1: `TST101` now shows the CREDITED badge
- [ ] 🔴 Back in Add Subjects `TST102` is now **enabled** (the credit satisfies the prerequisite)
- [ ] Tick `TST102`, pick its class, the unit total updates, **Add Selected Subjects**: "1 subject added to the roster"
- [ ] The class dropdown offers classes from any section, not only `TEST-A`
- [ ] Every bad row in table 2 is rejected with a message
- [ ] Edit the credit and change the grade (table 3)

**1. Credit TST101**

| Field | Value |
| ----- | ----- |
| Subject (row) | `TST101` |
| Previous School | `Sample State College` |
| Previous Subject Description | `Introduction to Information Technology` |
| Previous Grade | `1.75` |

**Add Subjects (tick this, choose this class)**

| Subject | Class option |
| ------- | ------------ |
| `TST102` | `TEST-A – Tue 8:00 AM – 10:00 AM · T-102` |

**2. Bad inputs (each must fail)**

| Previous School | Grade | Expected |
| --------------- | ----- | -------- |
| (blank) | `1.75` | error, school required |
| `Sample State College` | (blank) | error, grade required |
| `Sample State College` | `0.50` | error, outside 1.00 to 5.00 |
| `Sample State College` | `5.50` | error, outside 1.00 to 5.00 |
| `Sample State College` | `abc` | error, not numeric |

**3. Edit that credit**

| Field | Value |
| ----- | ----- |
| Previous Grade | `2.00` |

---

## Round 8: Student Juan's first login (P2)

**Log in:** Role `Student`, `2026-00001`, `mist2027`.

- [ ] You land on **Change Password**. Set `Mist@Test2027`
- [ ] Dashboard shows regular standing, Section `TEST-A`, and the 3 term 1 classes
- [ ] Schedule shows Mon, Tue, Wed 8:00 to 10:00 AM, no overlaps
- [ ] The sidebar **Shift Request** is locked (he is enrolled in the open term)
- [ ] 🔴 Open `/enrollment-system/public/registrar/dashboard.php` as Juan: sent away

---

## Round 9: Teacher Bautista enters grades (P1)

**Log in:** Role `Instructor`, `bautista_carla`, `mist2027` (no forced change for her).

- [ ] Dashboard, My Subjects (3 classes), My Students, Schedule (calendar grid) all load
- [ ] Open each class's grade entry, jot down the `TST101` `offering_id` from the URL: `______`
- [ ] Progress meter starts at 0 graded
- [ ] Rosters: `TST101` has Juan, Maria, Pedro, Luis. `TST102` has Juan, Maria, Pedro, Luis, Ana. `TST103` has Juan, Maria, Pedro, Ana, Luis, **Rina**
- [ ] Invalid grades (table 1) are flagged and refused, other rows still save
- [ ] **Auto remarks:** typing a grade fills the remark (3.00 or better = Passed, above 3.00 = Failed), clearing the grade clears the remark
- [ ] 🔴 Hard rule on Luis's `TST103`, follow table 2. A Passed at 3.50 must be impossible
- [ ] Editing a box then trying to leave shows the unsaved-changes warning
- [ ] Enter the real grades (tables 3 to 5), leave every remark alone except where noted, save, reload: values persist
- [ ] Pedro's `TST103` stays `Incomplete` (pick it by hand) with a blank grade, Ana's `TST103` stays ungraded
- [ ] **My Students** (table 6): one row per student with a chip per class, chips open that grade sheet, search by name and ID works

**1. Invalid inputs (type into one grade box, then fix)**

| Grade | Expected |
| ----- | -------- |
| `0.99` | flagged, not saved |
| `5.01` | flagged, not saved |
| `abc` | flagged, not saved |
| (blank) | allowed, stays ungraded |

**2. Hard rule on Luis's TST103**

| Step | Expected |
| ---- | -------- |
| Type `3.50` | remark switches to **Failed** by itself |
| Change the remark to `Passed` by hand | snaps back to Failed |
| DevTools: set the remark `<option value>` to `Passed` while the grade is `3.50`, then Save | row refused with "remarks did not match the grade", other rows still save |
| Pick `Dropped`, then type any grade | stays `Dropped`. Clear it before moving on |
| Type `2.75` and leave the remark | auto **Passed**, saves |

**3. TST101 grades**

| Student | Grade | Remark (expected) |
| ------- | ----- | ----------------- |
| Juan | `1.25` | Passed |
| Maria | `1.50` | Passed |
| Pedro | `1.75` | Passed |
| Luis | `1.00` | Passed |

**4. TST102 grades**

| Student | Grade | Remark (expected) |
| ------- | ----- | ----------------- |
| Juan | `1.50` | Passed |
| Maria | `5.00` | Failed |
| Pedro | `2.00` | Passed |
| Luis | `3.00` | Passed (the 3.00 boundary) |
| Ana | `2.00` | Passed |

**5. TST103 grades**

| Student | Grade | Remark |
| ------- | ----- | ------ |
| Juan | `1.75` | Passed |
| Maria | `2.00` | Passed |
| Pedro | (blank) | pick `Incomplete` by hand |
| Luis | `2.75` | Passed (from the hard-rule test) |
| Ana | (blank) | leave ungraded |
| Rina | `2.25` | Passed |

**6. My Students expectations (term 1)**

| Filter | Expected |
| ------ | -------- |
| none | 6 rows, each student once |
| Subject `TST101` | 4 rows, Ana is absent (credited, not enrolled) |
| Search `Garcia` | Luis only |

### Teacher Lim: ownership test (second browser)

**Log in:** Role `Instructor`, `lim_daniel`, `mist2027`.

- [ ] My Subjects shows only Lim's own class (`TST106` / `TEST-B` from Round 3.5), not Bautista's
- [ ] My Students shows nobody for Lim
- [ ] 🔴 Open `.../teacher/grade-entry.php?offering_id=<the TST101 offering_id>`: blocked, no roster visible

---

## Round 10: Admin closes term 1 (P1) 🆕

**Session:** Admin (Admin, Terms). Registrar pages cannot open or close terms.

- [ ] 🔴 While term 1 is ongoing, open a term `2027-2028` Semester `2`: refused, "Close the ongoing term (2027-2028, 1) before opening a new one."
- [ ] Next to **Close Term** the page says `1 ungraded subject · 0 pending enrollments` (that is Ana's `TST103`)
- [ ] Press **Close Term**: the confirm dialog says ungraded subjects will be marked Incomplete, every standing is recalculated and grade entry is locked, and it cannot be undone. Confirm
- [ ] Term 1 now shows **Closed**. Registrar A's Students list shows standings (table 1): **3 regular, 3 irregular**
- [ ] Ana's `TST103` is now `Incomplete`
- [ ] Bautista's grade sheet for term 1 is **locked and read-only**, with a **Request correction** button on every row (Round 18)
- [ ] 🔴 Press Close Term again from the old tab: "That term is not ongoing, so it cannot be closed."

**1. Standings expected after the close**

| Student | Standing | Why |
| ------- | -------- | --- |
| Juan, Luis, Rina | regular | passed everything they took |
| Maria | irregular | failed `TST102` |
| Pedro | irregular | `TST103` is Incomplete |
| Ana | irregular | `TST103` was ungraded |

---

## Round 11: Admin opens term 2, Registrar A schedules it (P1)

**Session:** Admin, Terms, **Open a Term**.

- [ ] Each row below behaves as shown. The last row opens the term: "Term opened."

| School year | Semester | Expected |
| ----------- | -------- | -------- |
| `2027-2028` | `1` | "A term for 2027-2028, semester 1 already exists." |
| `2027/2028` | `2` | "Use the school year format 2026-2027 and a semester of 1, 2 or Summer." |
| (blank) | `2` | "School year and semester are required." |
| `2027-2028` | `2` | "Term opened." |

**Session:** Registrar A, Class Offerings, Term = `2027-2028 Semester 2`.

- [ ] Add the 4 classes below, all saved. Bautista ends at 9 of 9 units
- [ ] Schedule, By Section for `TEST-A` and `TEST-B` shows them
- [ ] 🔴 One more row for Bautista on top of her 9 units (any subject, any free time): note whether the page warns, blocks or says nothing

| Subject | Teacher | Section | Day | Start | End | Room |
| ------- | ------- | ------- | --- | ----- | --- | ---- |
| `TST104` | Bautista, Carla | `TEST-A` | Monday | `08:00` | `10:00` | `T-101` |
| `TST105` | Bautista, Carla | `TEST-A` | Tuesday | `08:00` | `10:00` | `T-102` |
| `TST106` | Bautista, Carla | `TEST-A` | Wednesday | `08:00` | `10:00` | `T-103` |
| `TST106` | Lim, Daniel | `TEST-B` | Monday | `09:00` | `11:00` | `T-104` |

---

## Round 12: Registrar B, the other department (P1)

**Log in:** Role `Registrar`, `alcantara_ramon2`, `mist2027`.

- [ ] Profile shows COLLEGE OF MEDICINE
- [ ] Sections shows only the 6 BSM sections, with the **Intake year** column, and the modal has no Program dropdown
- [ ] Dropdowns only show BSM programs, curricula and sections, no BSIS test data. The Students list shows none of the BSIS students
- [ ] Place Student, Awaiting tab shows **Carlo** (reassigned to BSM), Rejected tab is empty. Do not place him
- [ ] Schedule, By Section lists only BSM sections
- [ ] Add the 2 valid BSM classes below
- [ ] 🔴 Add the conflict row, expected **rejected**: room `T-101` is used by a BSIS class at that time (rooms are shared across departments)
- [ ] 🔴 Open `place-student.php?id=1` (Juan's application, CTIS): blocked, not in your department
- [ ] Shift Requests is empty
- [ ] Back as Registrar A, open `place-student.php?id=7` (Carlo, now BSM): blocked

**Valid classes (Term = `2027-2028 Semester 2`, Section = `A (Year 1 intake)`)**

| Subject | Teacher | Day | Start | End | Room |
| ------- | ------- | --- | ----- | --- | ---- |
| `MID 101` | Navarro, Elena | Monday | `13:00` | `15:00` | `M-201` |
| `HC 101` | Navarro, Elena | Tuesday | `13:00` | `15:00` | `M-202` |

**Cross-department room conflict (expected rejected)**

| Subject | Teacher | Section | Day | Start | End | Room |
| ------- | ------- | ------- | --- | ----- | --- | ---- |
| `ANAT 101` | Navarro, Elena | `A (Year 1 intake)` | Monday | `09:00` | `10:00` | `T-101` |

---

## Round 13: Juan self-enrolls, the regular path (P1)

**Log in:** Juan (`2026-00001`, `Mist@Test2027`).

- [ ] Dashboard offers enrollment for the new term
- [ ] The button first shows a **preview**: term `2027-2028 Semester 2`, Year `1`, Section `TEST-A`, `TST104`, `TST105`, `TST106` with schedules, total units `9`, no "not scheduled" warning
- [ ] **Confirm Enroll** enrolls him. Result shows Year Level `1`, same section `TEST-A`
- [ ] Schedule shows the term 2 classes with no overlaps
- [ ] Opening the enroll page again is refused ("already enrolled"). Enrollment History shows two rows
- [ ] **Shift Request** is locked again
- [ ] Log in as Maria and open `/enrollment-system/public/student/enroll.php` directly: blocked with the irregular message

---

## Round 14: Irregular students (Maria, Pedro, Ana) and approvals (P1)

### Maria (`2026-00002`)

- [ ] Dashboard shows irregular standing, no one-click enroll
- [ ] The picker is a checkbox list grouped by Year and Semester with a unit total and a Select all box per group
- [ ] `TST104` and `TST105` are greyed "Requires: TST102" (she failed it), `TST106` is selectable with two classes in the dropdown
- [ ] `TST102` is not listed at all (a graded subject counts as taken). Known item, do not report
- [ ] Submit as pending. The dashboard shows an amber "waiting for registrar approval" alert

| Tick | Class option |
| ---- | ------------ |
| `TST106` | `TEST-A – Wed 8:00 AM – 10:00 AM · T-103` |

### Pedro (`2026-00003`)

- [ ] `TST104` is selectable (he passed `TST102`), `TST105` is greyed (needs `TST104`), `TST103` is greyed "Not offered this term"
- [ ] 🔴 Pick **both** classes in the first table and submit: the overlap is a **hard block**. You get "TST104 and TST106 overlap on Monday. Nothing was saved." (the server refuses it, it is not just a warning)
- [ ] Then pick only `TST104` (second table) and submit as pending

| Tick | Class option |
| ---- | ------------ |
| `TST104` | `TEST-A – Mon 8:00 AM – 10:00 AM · T-101` |
| `TST106` | `TEST-B – Mon 9:00 AM – 11:00 AM · T-104` |

| Tick (after the refusal) | Class option |
| ------------------------ | ------------ |
| `TST104` | `TEST-A – Mon 8:00 AM – 10:00 AM · T-101` |

### Ana (`2026-00004`)

- [ ] `TST101` and `TST102` are not listed (credited and taken), `TST104` is selectable (her credit and `TST102` pass satisfy it), `TST105` is greyed
- [ ] Submit `TST104` (the Mon 8:00 AM class) as pending. **Do not approve her later**, Round 15 withdraws it

### Registrar A approvals (Irregular Enrollments)

- [ ] All 3 pending requests are listed with their subjects
- [ ] **Approve** Maria (confirm dialog). Her Enrollment now shows `TST106`
- [ ] **Reject** Pedro (confirm dialog, give a reason if asked)
- [ ] Leave **Ana** pending
- [ ] 🔴 Replay: after approving Maria, open her request again, no approve or reject buttons remain
- [ ] Log in as Pedro: his dashboard shows the rejection. Log in as Maria: her dashboard shows the approved subject

---

## Round 15: Program shift, Luis and Ana (P1)

### 15.1 Luis and Ana request

- [ ] Luis's sidebar **Shift Request** is unlocked. The dropdown lists only other programs (BSIS is not offered), only the active curriculum `BSM 2026`
- [ ] After Luis submits, his dashboard says a shift is pending
- [ ] 🔴 Luis opening `/enrollment-system/public/student/enroll.php` is blocked with the "pending program shift request" message
- [ ] Ana (irregular) has a **Request Program Shift** button. Her shift page shows a pending subject selection and **Withdraw Pending Selection**. Press it (confirm), then the shift form appears and she submits
- [ ] 🔴 Tamper on Luis's form before submitting: change the program `<option value>` to a BSIS curriculum id: rejected "not available to shift into"

| Who | Program to shift into |
| --- | --------------------- |
| Luis | `BSM`, curriculum `BSM 2026` |
| Ana | `BSM`, curriculum `BSM 2026` |

### 15.2 Registrar A evaluates (Shift Requests)

- [ ] Pending, Approved, Rejected tabs with counts, both requests under Pending, only BSIS students appear
- [ ] **Approve is disabled** until the credit evaluation is saved. Save Luis's evaluation (table below), then Approve is enabled
- [ ] Section dropdown reads `A (Year 1 intake)`. Approve Luis (the dialog says the student chooses their own subjects)
- [ ] You land on the Approved tab, green message, no cross-department error. Luis's row says **Waiting on student**
- [ ] Reject Ana: a blank reason errors first, then `Test rejection. Incomplete requirements.` works

| Luis passed subject | Credit into BSM curriculum subject |
| ------------------- | ---------------------------------- |
| `TST101` | (leave blank) |
| `TST102` | (leave blank) |
| `TST103` | `GE 101` (Understanding the Self) |

| Luis target | Value |
| ----------- | ----- |
| Target Section | `A (Year 1 intake)` |
| Target Year Level | `1` |

### 15.3 Luis chooses his subjects

- [ ] Dashboard shows the green "Your program shift was approved" alert with **Choose Subjects**
- [ ] The picker lists the BSM subjects that have a class this term: `MID 101` and `HC 101`. `GE 101` (credited) is not listed, every other BSM subject is greyed "Not offered this term"
- [ ] Tick both and **Save My Subjects**. The dashboard shows irregular standing, the CREDITED badge and the two subjects
- [ ] 🔴 Refresh the result or reopen `/enrollment-system/public/student/enroll-irregular.php`: nothing is added twice
- [ ] Registrar A's Approved tab now says Luis has **2 chosen**. Ana's dashboard shows her shift was rejected and her picker works again

| Tick | Class option |
| ---- | ------------ |
| `MID 101` | `A (Year 1 intake) – Mon 1:00 PM – 3:00 PM · M-201` |
| `HC 101` | `A (Year 1 intake) – Tue 1:00 PM – 3:00 PM · M-202` |

---

## Round 16: Section roster, move and change classes (P2)

**Session:** Registrar A, Sections, **Roster** on `TEST-A` (term 2 is ongoing).

- [ ] The roster lists this term's students: **Juan** (regular) and **Maria** (irregular). Pedro (rejected), Ana and Luis are not listed
- [ ] Write down what the slot header says compared to the rows listed (known item, see the end)
- [ ] Juan has **Move section**, Maria has **Change classes**
- [ ] **Move Juan to `TEST-B`**: the options show occupancy like `(0/30)`. The result warns that `TST104` and `TST105` have no class in `TEST-B` and stayed on the old class. His dashboard shows `TST106` in `TEST-B`
- [ ] **Move Juan back to `TEST-A`**: all three subjects return to the `TEST-A` classes
- [ ] Log in as Bautista and grade Juan's term 2 `TST104` as `1.50` (auto Passed)
- [ ] 🔴 Back as Registrar A: **Move section** on Juan is now **disabled** ("grades already entered") and a forced POST is refused with the same message
- [ ] **Change classes for Maria** (table): swap, remove, add back. A graded row (Juan's `TST104`) can never be swapped or removed
- [ ] Registrar B, Sections, Roster on `A (Year 1 intake)` shows **Luis** (irregular, 2 subjects) and no BSIS student
- [ ] 🔴 As Registrar B open `section-roster.php?id=7` (`TEST-A`): "not found, or not in your department"

| Step | Do | Expected |
| ---- | -- | -------- |
| a | swap `TST106` to `TEST-B – Mon 9:00 AM – 11:00 AM` | "1 changed, 0 removed, 0 added" |
| b | tick **Remove** on `TST106` | "0 changed, 1 removed, 0 added" |
| c | in Add subjects tick `TST106` and the `TEST-A` Wednesday class | "0 changed, 0 removed, 1 added" |

---

## Round 17: Generate classes (P1) 🆕

> [!info] Needs term 2 ongoing with the 4 classes from Round 11, and `TST106` still **without tags** (Round 3.1). If you reset the database, redo Rounds 3 to 11 first, or this round will not show the same numbers.

**Session:** Registrar A.

- [ ] Sections: add `TEST-C` (BSIS, Year `1`, curriculum `TEST BSIS Curriculum 2027`, `40` slots)
- [ ] Class Offerings, press **Auto-generate** (once a draft exists the page offers **Continue draft**). The readiness box warns that **`TST106` has no tags** and no weekly parts
- [ ] `TEST-A` has nothing left to generate (all three of its term 2 subjects already have a class), so do not tick it. Tick **`TEST-B`** and **`TEST-C`**
- [ ] The capacity box appears: **Database** needs `6.0` units and has `0.0`, marked short. **Programming** is fine
- [ ] **Generate preview** shows **5 classes** (2 for `TEST-B`, 3 for `TEST-C`), **3 of them need a teacher**, and nothing is saved yet
- [ ] The 3 gaps say: both `TST105` classes "Every teacher with a matching tag would go over a limit: Bautista, Carla (would reach 12 of 9 units)", and `TST106` in `TEST-C` "This subject has no tags, so no teacher could be matched."
- [ ] **Save is disabled** while any class has no teacher
- [ ] 🔴 Edit a draft class so Lim is in two places at once, press **Check again**: a red error names Lim and the time, Save stays disabled
- [ ] Move one class to 6:00 PM: a yellow warning saying the time is "outside school days or hours (Monday to Friday, 7:30 AM to 5:30 PM, no classes 11:30 AM to 12:30 PM)", and Save is **not** blocked by it
- [ ] **Try a different arrangement** and **Discard** both ask for confirmation
- [ ] **Fix the gaps**, then generate again (table below). Result: **5 classes, 0 need a teacher**
- [ ] **Save**: a success message with the number saved. Class Offerings now lists **9** term 2 classes
- [ ] Schedule shows the new classes: nothing outside Mon to Fri 7:30 AM to 5:30 PM, nothing between 11:30 AM and 12:30 PM, no teacher or room used twice at the same time
- [ ] 🆕 P2 Before you save (do this on the draft), press **Week view** in the toolbar: a window with a weekly grid opens. Show by **Section**, then **Teacher** (Lim), then **Room**. Nothing overlaps and the lunch hour is empty. Close it, press the small **Week** button on one section's panel: it opens on that section. Click a block: the window closes and that class's Edit panel opens. Details are in Round 26
- [ ] "Earlier runs this term" lists the run. **Undo this run**: 5 classes removed, the 4 manual classes stay (Class Offerings shows 4)
- [ ] 🔴 Open the page in two browsers and Save in both: one saves, the other is told the draft changed or another registrar is saving, never a duplicate class

| Fix | Where | Value |
| --- | ----- | ----- |
| Raise Bautista's units | Instructors | Max units `9` to `15` |
| Give `TST106` a tag | Subjects, edit `TST106` | tag `Mathematics` |

---

## Round 18: Grade corrections (P1) 🆕

> [!info] Run after Round 16 on purpose. Approving a correction changes Maria's standing, which would break Rounds 14 to 16.

**Teacher Bautista**, Grades, the term 1 `TST102` class (locked, read-only).

- [ ] Each row has **Request correction**. Try the bad rows in the table, then the two valid ones
- [ ] 🔴 Requesting Maria's again while one is pending: "A correction for this student is already waiting for the registrar."
- [ ] 🔴 As Lim, open Bautista's grade page by URL: blocked

| Student, subject | New result | Reason | Expected |
| ---------------- | ---------- | ------ | -------- |
| Maria, `TST102` | `2.50` | `short` | refused, reason too short |
| Maria, `TST102` | `5.01` | `Quiz 4 was scored incorrectly, recomputed.` | refused, not 1.00 to 5.00 |
| Maria, `TST102` | `2.50` + remark `Dropped` | `Quiz 4 was scored incorrectly, recomputed.` | refused, a grade cannot be Dropped |
| Maria, `TST102` | `2.50` | `Quiz 4 was scored incorrectly, recomputed.` | "Correction sent to the registrar. The grade changes only if it is approved." |
| Pedro, `TST103` | `2.50` | `Late requirement was submitted and graded.` | same success message |

**Registrar A**, Grade Corrections (the dashboard also shows `2 Pending Grade Corrections`).

- [ ] Pending shows both requests with old grade, new grade and reason
- [ ] **Reject** Pedro's: a blank reason is refused ("A rejection reason is required."), then `The paper shows 3 items were left blank, so the grade stands.` gives "Correction rejected. The grade stays as it was." His grade did not change
- [ ] **Approve** Maria's: "Correction approved. 2027-2028 Semester 1 is now regular standing."
- [ ] Maria's `TST102` is now `2.50` Passed. Her term 1 standing is regular, her term 2 enrollment stays irregular. **Flags to review** is `0` (nothing later relied on that grade)
- [ ] 🔴 Press Approve again on the already decided request: nothing changes twice
- [ ] 🔴 As Registrar B open the Grade Corrections page: none of these requests are visible
- [ ] Maria sees the corrected grade on her Grades page

---

## Round 19: Reset a password after changing the email (P1, needs real mail) 🆕

> [!warning] Read this first: change the email before you try to reset
> Every seeded account has a fake `@mist.test` email, so a reset link sent to it goes nowhere and the test looks broken when nothing is wrong. **For each account you reset, first change its email to an inbox you can open, then request the reset.**
> This round also needs a real **Brevo API key and verified sender** in `config/mail.php`, and internet. Without them every email attempt shows "could not be sent". In that case write "not testable, no mail key" for this whole round and move on.
> Links in the email point at `localhost`, so open them on the **same computer** that runs XAMPP.

**19.1 Change the emails (do these first)**

| Who | Where | New email | Expected |
| --- | ----- | --------- | -------- |
| Admin | Admin, Profile | `YOUR_INBOX+admin@gmail.com` | saved |
| Bautista | Admin, Staff Accounts, open her | `YOUR_INBOX+bautista@gmail.com` | "Details saved for ..." |
| Bautista | same, try `lim@mist.test` | refused, address already in use |
| Juan | Registrar A, Students, Juan, **Account** tab, **Change email** (box "New email") | `YOUR_INBOX+juan@gmail.com` | saved, "Email on file" shows the new address |
| Rina | Registrar A, Students, Rina, **Account** tab, **Change email** | `YOUR_INBOX+rina@gmail.com` | saved |

**19.2 Forgot password** (log out first)

- [ ] On the login page use **Forgot password**, enter `bautista_carla`. You see a neutral confirmation, and an email arrives within a minute
- [ ] Open the link (valid for 30 minutes): the page says **Reset Your Password**. Set `Mist@Test2027`
- [ ] Log in as Instructor `bautista_carla` with `Mist@Test2027`. `mist2027` is refused
- [ ] 🔴 Open the same link again: it no longer works
- [ ] Repeat for `Admin`
- [ ] 🔴 Enter a username that does not exist: the same neutral message, nothing that reveals whether it exists. Request twice in a row and write down whether it is throttled

**19.3 Send reset link from the admin** 🆕

- [ ] Admin, Staff Accounts, Bautista, **Send reset link** (the dialog says the link is valid for 30 minutes). An email arrives. **Do not click it yet**
- [ ] Change Bautista's email again to `YOUR_INBOX+bautista2@gmail.com`
- [ ] 🔴 Now click the old link: it is refused, because changing the email cancels earlier links
- [ ] Send a new reset link to the new address and use it

**19.4 Invite link for a student who never had a password** 🆕

- [ ] Rina's Account tab shows Account status **Invite pending** and says she has not chosen a password yet. Press **Resend invite** (confirm dialog: a fresh 24-hour invite, any earlier one stops working): an email arrives
- [ ] The link page says **Set Your Password**. Set `Mist@Test2027`
- [ ] Log in as Student `2026-00006` with it. The dashboard shows `TST103` only
- [ ] Rina's status badge now reads **Active**. On Juan's Account tab the button reads **Send reset link** (he is already active) and works the same way

**19.5 Check the record**

- [ ] SQL: `SELECT account_id, old_email, new_email FROM Account_email_change;` has one row per email you changed in 19.1 and 19.3 for staff and students

---

## Round 20: Authorization quick checks (P2)

Use the address bar while logged in as the first role. Every row must send you away or show a not-allowed page, never data.

| Logged in as | Open | Expected |
| ------------ | ---- | -------- |
| Student (Maria) | `/enrollment-system/public/registrar/dashboard.php` | blocked |
| Student (Maria) | `/enrollment-system/public/admin/dashboard.php` | blocked |
| Instructor (Lim) | `/enrollment-system/public/registrar/students.php` | blocked |
| Registrar A | `/enrollment-system/public/admin/rooms.php` | blocked |
| Registrar A | `/enrollment-system/public/admin/audit-log.php` | blocked |
| Registrar A | `/enrollment-system/public/admin/terms.php` | blocked |
| Admission Staff | `/enrollment-system/public/registrar/place-student.php` | blocked |
| Registrar B | a `TEST-A` student's page (`student-view.php?id=1`) | not found or not in your department |
| Logged out | `/enrollment-system/public/student/dashboard.php` | sent to login |

---

## Round 21: Destructive tests, audit log, wrap-up (P2)

**Run last.** Use two browsers so you can watch the effect on a signed-in user.

- [ ] Browser 2 logged in as Lim. Admin, Staff Accounts, **Deactivate** Lim ("They are signed out right away and cannot log in until reactivated"): Lim's next click sends him to login, and logging in is refused. **Reactivate** Lim, he can log in again
- [ ] 🔴 Admin, Rooms: remove `T-102` (used by a class): "That room appears in a schedule, so it was deactivated instead of removed." The existing class keeps it
- [ ] 🔴 Registrar A, Sections: delete `TEST-A` (it has enrollments and classes): refused, the message says the section is still referenced by an enrollment or class offering
- [ ] Registrar A, Students, Juan, set status **Graduated**: refused, "Graduated is only allowed once every subject in the student's curriculum is completed." Set **On Leave**, then **Active** again
- [ ] 🆕 **Audit Log** (Admin): entries exist, with who, what and when, for placement, grade entry, term close and open, grade correction request and decision, staff deactivation. No password or reset token appears anywhere in the text
- [ ] The Registrar and the Teacher cannot open the Audit Log

**Integrity queries** (SQL tab, run each, results in the right-hand column)

| Query | Expected |
| ----- | -------- |
| `SELECT enrollment_id, offering_id, COUNT(*) c FROM Enrolled_subject GROUP BY enrollment_id, offering_id HAVING c > 1;` | 0 rows |
| `SELECT enrolled_subject_id, grade, remarks FROM Enrolled_subject WHERE (grade <= 3.00 AND remarks = 'Failed') OR (grade > 3.00 AND remarks = 'Passed');` | 0 rows |
| `SELECT cm.meeting_id FROM Class_Meeting cm LEFT JOIN Class_Offering co ON co.offering_id = cm.offering_id WHERE co.offering_id IS NULL;` | 0 rows |
| `SELECT username, activated_at FROM Accounts WHERE password_hash NOT LIKE '$2y$%';` | only people who never used their invite link (the extra registrar and teacher, plus Rina if you skipped 19.4) |
| `SELECT action, COUNT(*) FROM Audit_log GROUP BY action;` | several different actions, not empty |

```sql
SELECT 'teacher' AS kind, COUNT(*) AS overlaps FROM Class_Meeting a JOIN Class_Meeting b ON a.meeting_id < b.meeting_id JOIN Class_Offering oa ON oa.offering_id = a.offering_id JOIN Class_Offering ob ON ob.offering_id = b.offering_id WHERE oa.term_id = (SELECT term_id FROM School_term WHERE status = 'ongoing' LIMIT 1) AND ob.term_id = oa.term_id AND a.day_of_week = b.day_of_week AND a.start_time < b.end_time AND b.start_time < a.end_time AND oa.teacher_id = ob.teacher_id
UNION ALL
SELECT 'section', COUNT(*) FROM Class_Meeting a JOIN Class_Meeting b ON a.meeting_id < b.meeting_id JOIN Class_Offering oa ON oa.offering_id = a.offering_id JOIN Class_Offering ob ON ob.offering_id = b.offering_id WHERE oa.term_id = (SELECT term_id FROM School_term WHERE status = 'ongoing' LIMIT 1) AND ob.term_id = oa.term_id AND a.day_of_week = b.day_of_week AND a.start_time < b.end_time AND b.start_time < a.end_time AND oa.section_id = ob.section_id
UNION ALL
SELECT 'room', COUNT(*) FROM Class_Meeting a JOIN Class_Meeting b ON a.meeting_id < b.meeting_id JOIN Class_Offering oa ON oa.offering_id = a.offering_id JOIN Class_Offering ob ON ob.offering_id = b.offering_id JOIN Room r ON r.room_id = a.room_id JOIN Room_type rt ON rt.room_type_id = r.room_type_id WHERE oa.term_id = (SELECT term_id FROM School_term WHERE status = 'ongoing' LIMIT 1) AND ob.term_id = oa.term_id AND a.day_of_week = b.day_of_week AND a.start_time < b.end_time AND b.start_time < a.end_time AND a.room_id = b.room_id AND rt.is_unlimited = 0;
```

- [ ] The overlap query shows `0` for teacher, section and room

**Wrap-up**

- [ ] Fill in the bug log below and send it
- [ ] Reset the database by importing `seed_test.sql` again if you want to run a round twice
- [ ] Commit your work to GitHub before you change any code, so you can go back to this state

---

# Phase B: Class generation at scale

> [!info] What Phase B is
> Phase A tests the system with 5 students and tiny data. Phase B gives the generator a realistic job: the **real BSIS 2023 curriculum (49 subjects)**, 12 BSIS teachers, 12 active rooms and 5 sections that hold 82 students. **Class generation matters most, so give these rounds the most time.**
> It starts with its own reset: import `seed_test.sql`, then `seed_generator.sql`, once each.

## Phase B data

**Teachers** (all BSIS, Role `Instructor`, password `mist2027`)

| Username | Name | Tags | Max units | Note |
| -------- | ---- | ---- | --------- | ---- |
| `bautista_carla` | Bautista, Carla | Programming, Database | 9 | already at 9 of 9 with the 3 `TEST-A` classes |
| `lim_daniel` | Lim, Daniel | Programming, Mathematics | 24 | |
| `santos_mark` | Santos, Mark | Programming, Web Development | 24 | |
| `villanueva_grace` | Villanueva, Grace | Information Systems, Systems Analysis, Database | 24 | |
| `ramirez_joel` | Ramirez, Joel | Networking | 12 (18 h) | the only Networking teacher |
| `castillo_anna` | Castillo, Anna | Mathematics | 24 | |
| `mendoza_rico` | Mendoza, Rico | Social Science, English, Humanities | 24 | |
| `flores_liza` | Flores, Liza | Management, Research, Accounting | 24 | |
| `torres_nina` | Torres, Nina | Science and Technology, NSTP | 24 | |
| `aquino_ben` | Aquino, Ben | Physical Education | **4** (10 h) | low on purpose |
| `dizon_carlo` | Dizon, Carlo | none | 24 | no tags on purpose |
| `reyes_tess` | Reyes, Tess | Programming | 24 | **deactivated** on purpose, cannot log in |

**Sections** (all BSIS, all follow `BSIS 2023`)

| Section | Students | Teach year this term | Classes to generate |
| ------- | -------- | -------------------- | ------------------- |
| `A 2027` | 0 (35 slots) | 1 | 8 |
| `B 2027` | 0 (30 slots) | 1 | 8 |
| `A 2026` | 32 | **2** (they were 1st years last term) | 8 |
| `A 2025` | 28 | **3** | 6 |
| `A 2024` | 22 | **4** | 4 |

**Students:** `2026-00101` to `2026-00132` are in `A 2026`, `2025-00133` to `2025-00160` in `A 2025`, `2024-00161` to `2024-00182` in `A 2024`. Password `mist2027`, no forced change. Their last enrollment is in a closed term (`2026-2027` Semester 2), so none of them has enrolled in the ongoing term yet.

**Rooms added:** `T-LAB2` (computer lab, 40), `T-LAB3` (computer lab, 35), `NET-1` (networking lab, 30), `GYM-1` (gymnasium, 60), `T-301` (lecture, 40, **inactive**). `T-LAB1` (computer lab) seats 30.

**Deliberate traps** (each one should show up in the rounds below)

| Trap | Expected |
| ---- | -------- |
| Aquino's cap is 4 units and there are 3 PE classes | 2 PE classes get Aquino, **1 PE class has no teacher** |
| `PolSci` has no tags | no teacher: "This subject has no tags, so no teacher could be matched." |
| Dizon has no tags, Reyes is deactivated | neither is ever picked or offered |
| `CAP 102` has no hours | a class with **no meetings** (TBA) that still gets a teacher (Flores) |
| `T-301` is inactive | never used |
| `T-LAB1` and `NET-1` seat 30, `A 2027` has 35 slots and `A 2026` has 32 students | never used for those two sections without a warning. `A 2026`'s `IS 103` lab goes to a computer lab with a warning |
| `PROFEL 2` and `IS 107` each have two tags that no single teacher holds | the teacher shows a **partial** match |

> [!tip] Numbers marked about
> The arrangement is random (up to 8 are tried). Counts of classes, teachers and conflicts are fixed, but exact days, times and rooms differ every run. Where a number is marked **about**, write down what you got.

---

## Round 22: Load the generator seed and look around (P1) 🆕

> [!warning] Your phpMyAdmin export may still hold the old `BSIS 2027` curriculum
> An earlier version of `seed_generator.sql` had a made-up curriculum. If your database shows `BSIS 2027` (not `BSIS 2023`), reset it: import `seed_test.sql`, then the current `seed_generator.sql`.

- [ ] Select `enrollment_system`, import `sql/seed_test.sql`, no error. Then import `sql/seed_generator.sql`, no error (22 queries). The last result shows this table:

| item | n |
| ---- | - |
| Teachers in BSIS | `12` |
| Rooms | `13` |
| BSIS 2023 subjects (49) | `49` |
| Students in A 2026 (32) | `32` |
| Students in A 2025 (28) | `28` |
| Students in A 2024 (22) | `22` |

**Health checks** (SQL tab)

| Query | Expected |
| ----- | -------- |
| `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';` | `34` |
| `SELECT COUNT(*) FROM Accounts WHERE password_hash LIKE '$2y$%';` | `104` |
| `SELECT COUNT(*) FROM Student;` | `87` |
| `SELECT COUNT(*) FROM Subject;` | `62` |
| `SELECT COUNT(*) FROM Class_Offering;` | `3` |
| `SELECT COUNT(*) FROM Curriculum_prerequisite WHERE curriculum_id = 3;` | `17` |

**Session:** Registrar A (`alcantara_ramon`). The reset made the forced password change come back: use `Mist@Test2027` again.

- [ ] Class Offerings, **List**: still the 3 `TST` classes of `TEST-A`, taught by Bautista. Both **Schedule a Class** and **Auto-generate** are there
- [ ] **Week** tab, Show by **Section** `TEST-A`: 3 blocks, Monday, Tuesday and Wednesday, 8:00 to 10:00 AM
- [ ] Instructors lists the BSIS teachers above. `reyes_tess` shows as inactive, and she is **not** offered in the Teacher dropdown of Schedule a Class

---

## Round 23: The BSIS 2023 curriculum (P2) 🆕

**Session:** Registrar A, Curriculum.

- [ ] Two open BSIS curricula: **`BSIS 2023`** (follows: about `82` students) and `TEST BSIS Curriculum 2027` (`5`)
- [ ] Open `BSIS 2023`: 8 semesters, and the unit totals are `23`, `23`, `23`, `23`, `18`, `18`, `12`, `6` (49 subjects in all)
- [ ] The **Requires** column shows, for example: `CC 103` needs `CC 102`, `IS 101` needs `CC 101`, `PE 3` needs `PE 2`, `IS 107` needs `IS 106`
- [ ] Year standing shows on: `IS 104`, `PROFEL 1` (2nd year), `QUAMET`, `PROFEL 2`, `CAP 101`, `PROFEL 3` (3rd year), `CC 106`, `CAP 102`, `PROFEL 4`, `PRAC 101` (4th year)
- [ ] 🔴 Try each prerequisite below. All three must be refused with a clear message, and nothing is saved

| Subject | Add prerequisite | Expected |
| ------- | ---------------- | -------- |
| `CC 101` | `CC 101` | refused, a subject cannot require itself |
| `CC 101` | `IS 101` | refused, `IS 101` comes in a later semester (and would loop) |
| `CC 103` | `CC 102` | refused, already there |

---

## Round 24: Start screen, readiness, teach years, capacity (P1) 🆕

**Session:** Registrar A, Class Offerings, **Auto-generate**.

- [ ] The readiness box says `PolSci` has no tags and so gets no teacher, and `Dizon, Carlo` has no tags and is never chosen. It does **not** name Reyes, Tess. It does **not** warn about a missing room type. It may also list `TST101` to `TST103` as having no weekly parts (they belong to the test curriculum), that is fine
- [ ] The sections list shows `TEST-A`, `A 2027`, `B 2027`, `A 2026`, `A 2025`, `A 2024`. `TEST-A` has nothing left to generate and cannot be ticked
- [ ] Tick `A 2027`, `B 2027`, `A 2026`, `A 2025`, `A 2024`. Check each teach year (table below) and the student counts next to the names
- [ ] Try to tick a **sixth** section: not allowed (5 per run). Untick it
- [ ] The capacity box appears after you tick. **Physical Education** is marked **short** (3 classes need 6 units, Aquino has 4). The other groups are fine or tight, write down any other short line

| Section | Teach year | Note under the dropdown |
| ------- | ---------- | ----------------------- |
| `A 2027` | `1` | suggested from the section (no students) |
| `B 2027` | `1` | suggested from the section |
| `A 2026` | `2` | suggested from enrolled students |
| `A 2025` | `3` | suggested from enrolled students |
| `A 2024` | `4` | suggested from enrolled students |

---

## Round 25: Generate and read the preview (P1) 🆕

**Session:** Registrar A, the five sections still ticked, press **Generate preview** (it can take up to about 10 seconds).

- [ ] The top card says **34 classes** drafted, **2 need a teacher**, **0 with a conflict**, and **0 not placed**. A quality line shows the total waiting time. Nothing is saved yet
- [ ] The 2 gaps say: one `PE` class (an "every teacher ... would go over a limit" message that names Aquino, Ben) and `PolSci` ("This subject has no tags, so no teacher could be matched.")
- [ ] **Save is disabled** while any class has no teacher
- [ ] Press **Expand all**: each row shows subject, teacher and match level, the meetings as text (like `Wed 12:30 PM – 3:30 PM · T-LAB2`), a status chip and an **Edit** button
- [ ] The switch **Show only classes that need attention** leaves the 2 gap classes and any warnings. Switch it off again. **Collapse all** and **Expand all** both work

| Class | Expected in the preview |
| ----- | ----------------------- |
| `CC 102` | Santos, Mark or Lim, Daniel, a full match |
| `PROFEL 2` and `IS 107` | a **partial** match teacher (flagged) |
| `PolSci` | no teacher |
| `CAP 102` | **no meetings** (TBA) but a teacher: Flores, Liza |
| the 3 PE classes | in `GYM-1`, 2 with Aquino and 1 with no teacher |
| `NSTP 1` (two classes) | Torres, Nina |

---

## Round 26: Check the rules in the weekly view (P1) 🆕

**Session:** the same preview. Press **Week view** in the toolbar. A window opens with a weekly grid (Monday to Friday, half-hour rows, the lunch hour shaded).

- [ ] **Show by** has Section, Teacher and Room. Changing it resets the second dropdown. The small **Week** button on a section's panel opens the window on that section
- [ ] In **Section** view, dashed boxes show the waiting time between classes. A wait of 90 minutes or more is amber
- [ ] Each block shows the subject code, the time and two lines (room and teacher in Section view)
- [ ] **Click a block**: the window closes, that class's Edit panel opens, the page scrolls to it and it flashes briefly
- [ ] `CAP 102` is not in the grid (no meetings)

**Hard rules.** Step through every teacher, every room and every section.

- [ ] **Teachers:** no two blocks overlap and none is red
- [ ] **Rooms:** no two blocks overlap
- [ ] **Sections:** no two blocks overlap
- [ ] **Hours:** the 11:30 AM to 12:30 PM band is empty everywhere, nothing starts before 7:30 AM or ends after 5:30 PM, nothing on Saturday

**Soft rules**

| Check | Expected |
| ----- | -------- |
| Labs of `CC 101`, `CC 102`, `CC 104`, `CC 105`, `IS 105`, `PROFEL 2`, `CC 106` | in a computer lab (`T-LAB1`, `T-LAB2` or `T-LAB3`) |
| Labs of `IS 103` and `PROFEL 4` | in `NET-1`, or in a computer lab **with a warning** |
| `T-301` | never used |
| `T-LAB1` and `NET-1` (30 seats) | never used for `A 2027` or `A 2026` without a warning. In about 1 run in 100 the generator may put `CC 102` of `A 2027` in `T-LAB1`; then the row must carry the `seats 30, but the section has about 35 students` warning |
| Joel Ramirez | only Networking subjects |
| Ben Aquino | exactly 2 PE classes |
| Carlo Dizon, Tess Reyes | on no class |
| Carla Bautista | on no new class (already at 9 of 9) |
| Any teacher | never more than 2 classes in a row. If the quality line says otherwise, write the number |

---

## Round 27: Edit by hand and save (P1) 🆕

> [!info] Do the first step before you save
> Students must not be able to enroll until the classes exist.

- [ ] 🔴 **Before saving.** In a private window, log in as student `2026-00101` (role Student, password `mist2027`) and try to enroll. Refused with a message like "No classes are scheduled for you this term yet". Nothing is created
- [ ] Open the **PE** class that has no teacher, Edit, pick `Aquino, Ben`: a yellow warning that he goes over his limit, not a block
- [ ] Open `PolSci`, Edit, pick a teacher: a warning that the subject has no tags so the teacher cannot be checked, not a block
- [ ] 🔴 If **Check again** then says `The teacher is already teaching ... on <day and time>`, that is correct: `PolSci` was placed before any teacher was known, so the teacher you picked can be busy at that time (in testing, between 2 and 11 of the 11 teachers were free, usually about 7). Pick another teacher, or change the day and time in the Edit panel. Write down how many teachers you tried
- [ ] Press **Check again**: **0 need a teacher**, **0 conflicts**, and **Save is enabled**. The page keeps your scroll position
- [ ] 🔴 Before saving, break the draft on purpose. On `GE 4` of `A 2027`, press Edit, change one thing, press **Check again**, then put it back. **Conflicts** (a teacher, section or room in two places, a bad time, the same class twice) turn Save off. **Warnings** leave Save on

| Edit | Expected after Check again | Save |
| ---- | -------------------------- | ---- |
| Give `GE 4` the same day and time as `CC 102` of `A 2027` | red: `Section A 2027 already has CC 102 (draft) on ...` and `The teacher is already teaching CC 102 ...` | off |
| End time before start time | red: `Meeting 1 needs a day and a start time before its end time.` | off |
| Move it to **Saturday** 8:00 AM | amber: `... is outside school days or hours.` | on |
| Move it to **Friday 6:00 PM** | amber: same message | on |
| Friday 11:00 AM to 12:30 PM (over lunch) | amber: same message | on |
| Pick `Dizon, Carlo` (no tags) as its teacher | amber: `This teacher has none of the subject's tags.` | on |
| Set the room to `No room` | amber: `Meeting 1 has no room yet.` | on |
| Tick **Remove from draft** | the class count drops by 1 | on |

- [ ] Press **Save**: a success message with the number saved (**34**)
- [ ] Class Offerings, **List**, now shows **37** classes (34 new plus the 3 `TST` ones)
- [ ] **Week** tab, Show by **Section** `A 2027`: it looks the same as the preview did. Try **Teacher** and **Room** too
- [ ] "Earlier runs this term" lists this run with its date and **34** classes
- [ ] Press **Auto-generate** again and tick the same sections: they have nothing left to generate, and no existing class is changed or duplicated

---

## Round 28: Students and teachers see the new classes (P1) 🆕

**Student `2026-00101`** (`A 2026`), Dashboard, **Enroll**.

- [ ] The preview is for **Year 2** with **8 subjects**: `CC 104`, `IS 102`, `IS 103`, `DM 101`, `Lit 1`, `GE 6`, `PolSci`, `PE 3`, each with its schedule and room, **23 units** in all
- [ ] **Confirm Enroll**: enrolled at once (regular student). The **Schedule** page shows a weekly grid that matches the registrar's grid for `A 2026`

**Other years** (one student each, same steps)

| Student | Section | Preview |
| ------- | ------- | ------- |
| `2025-00133` | `A 2025` | **Year 3**, 6 subjects (`CC 105`, `IS 105`, `DM 103`, `QUAMET`, `PROFEL 2`, `Rizal`), `18` units |
| `2024-00161` | `A 2024` | **Year 4**, 4 subjects (`IS 107`, `CC 106`, `CAP 102`, `PROFEL 4`), `12` units, `CAP 102` shows as TBA |

**Teachers**

- [ ] `santos_mark` and `aquino_ben`: My Subjects and Schedule show exactly the classes the preview gave them. Aquino has **3 PE classes** (2 from the generator and the one you assigned), with nothing else
- [ ] A teacher's classes never overlap on the Schedule page
- [ ] Grade Entry for a class lists the students who enrolled in it

---

## Round 29: Undo a run and run again (P1) 🆕

**Session:** Registrar A, Auto-generate, "Earlier runs this term".

- [ ] The run shows **34** classes and a **With students** number
- [ ] **Undo this run**, confirm. The classes with enrolled students **stay**, the rest are removed. If you only enrolled the three students above, expect **16 removed and 18 kept** (the 16 classes of `A 2027` and `B 2027` go, the 8, 6 and 4 classes of `A 2026`, `A 2025` and `A 2024` stay)
- [ ] Class Offerings agrees with the message
- [ ] Auto-generate again: only `A 2027` and `B 2027` have classes to generate. The preview shows **16 classes**. Assign a teacher to any gap, **Check again**, **Save**

---

## Round 30: Edge cases (P2) 🆕

- [ ] **Another department.** Registrar B (`alcantara_ramon2`), Class Offerings, Auto-generate, tick a BSM section, generate. The readiness box warns that many subjects have no tags and no weekly parts. Classes are drafted as one lecture block each, many have **no teacher**, and Save stays disabled until you assign them. No BSIS data is visible
- [ ] **Draft survives a reload.** Leave the preview, open Auto-generate again: a banner offers **Continue editing it**. After you log out and back in, the draft is gone (known limit, see Known behaviours)
- [ ] **Edits and conflicts** (overlap, a 6:00 PM class, Try a different arrangement, Discard, saving from two browsers) are tested in Round 17. Run it on this data too if you have time

---

## Known behaviours (do not report these as new bugs)

| # | What you will see | Why |
| - | ----------------- | --- |
| 1 | `TEST-A` holds more students than its 4 slots, and the placement warning "Heads up: TEST-A is at 5/4" | on purpose, to test the warning |
| 2 | The roster's slot count does not match the rows listed (in v3 it said 4 of 4 with 2 rows) | likely cause: the count includes every active student whose **latest** enrollment is in the section, even from an older term, while the roster lists this term only. Write down both numbers, it is still open |
| 3 | A subject a student **failed** is not offered again in the irregular picker | known, needs a rule for retakes |
| 4 | Every placement or new staff account says the invite email could not be sent | no real Brevo key, expected until Round 19 |
| 5 | Accounts you create (Rina, the extra registrar and teacher) cannot log in | they are invite-only until the link is used |
| 6 | The Registrar cannot open or close a term | only the Admin can |
| 7 | Seeded emails never receive mail | they end in `@mist.test` |
| 8 | The start screen marks Physical Education as **short** (needs 6 units, Ben Aquino has 4) | on purpose, Phase B |
| 9 | A generated draft does not survive logging out | known limit, it lives in the session |
| 10 | The generator plans for sections only, never retakes | by design, add retake classes by hand |
| 11 | A few days can end up with a single class, and a few waits can be long | the cost-based placement is a trade-off, write down how many you see |
| 12 | The capacity check is an estimate | a teacher with several tags counts in every group they match |

---

## Bug log

| # | Round | Steps | Expected | Actual | Screenshot or note |
| - | ----- | ----- | -------- | ------ | ------------------ |
| 1 | | | | | |
| 2 | | | | | |
| 3 | | | | | |
| 4 | | | | | |
| 5 | | | | | |
