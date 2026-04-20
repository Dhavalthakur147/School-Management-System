<?php
$pdo = db();
$standards = school_standards();
$search = trim($_GET['q'] ?? '');
$isTeacherView = teacher_is_logged_in();
$assignedStandard = normalize_standard(teacher_assigned_standard() ?? '') ?? $standards[0];
$editId = $isTeacherView ? 0 : (int) ($_GET['edit'] ?? 0);
$selectedStandard = $isTeacherView
    ? $assignedStandard
    : (normalize_standard($_GET['standard'] ?? '') ?? $standards[0]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isTeacherView) {
        header('Location: ' . page_url('students', ['standard' => $selectedStandard]));
        exit;
    }

    $action = $_POST['action'] ?? '';
    $returnStandard = normalize_standard($_POST['return_standard'] ?? '') ?? normalize_standard($_POST['grade'] ?? '') ?? $standards[0];

    if ($action === 'add' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $grade = normalize_standard($_POST['grade'] ?? '');

        if ($first !== '' && $last !== '' && $grade !== null) {
            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO students (first_name, last_name, dob, grade) VALUES (?, ?, ?, ?)');
                $stmt->execute([$first, $last, $dob !== '' ? $dob : null, $grade]);
            } elseif ($id > 0) {
                $stmt = $pdo->prepare('UPDATE students SET first_name = ?, last_name = ?, dob = ?, grade = ? WHERE id = ?');
                $stmt->execute([$first, $last, $dob !== '' ? $dob : null, $grade, $id]);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([$id]);
        }
    }

    header('Location: ' . page_url('students', ['standard' => $returnStandard]));
    exit;
}

$editStudent = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $stmt->execute([$editId]);
    $editStudent = $stmt->fetch() ?: null;

    if ($editStudent && normalize_standard($editStudent['grade'] ?? '') !== null && !isset($_GET['standard'])) {
        $selectedStandard = $editStudent['grade'];
    }
}

$countRows = $pdo->query('SELECT grade, COUNT(*) AS total
    FROM students
    WHERE grade IS NOT NULL AND grade <> ""
    GROUP BY grade')->fetchAll();
$standardCounts = array_fill_keys($standards, 0);
foreach ($countRows as $row) {
    if (isset($standardCounts[$row['grade']])) {
        $standardCounts[$row['grade']] = (int) $row['total'];
    }
}

$studentSql = 'SELECT s.*, COALESCE(enrollment_totals.class_count, 0) AS class_count
    FROM students s
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS class_count
        FROM enrollments
        GROUP BY student_id
    ) enrollment_totals ON enrollment_totals.student_id = s.id
    WHERE s.grade = :standard';
$studentParams = ['standard' => $selectedStandard];

if ($search !== '') {
    $studentSql .= ' AND (
        CONCAT_WS(" ", s.first_name, s.last_name) LIKE :term
        OR COALESCE(DATE_FORMAT(s.dob, "%Y-%m-%d"), "") LIKE :term
    )';
    $studentParams['term'] = like_value($search);
}

$studentSql .= ' ORDER BY s.id DESC';
$stmt = $pdo->prepare($studentSql);
$stmt->execute($studentParams);
$students = $stmt->fetchAll();

$schoolOverview = $pdo->query('SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN grade IS NOT NULL AND grade <> "" THEN 1 ELSE 0 END) AS with_standard
    FROM students')->fetch();
$totalStudents = (int) ($schoolOverview['total'] ?? 0);
$studentsWithStandard = (int) ($schoolOverview['with_standard'] ?? 0);

$selectedTotal = $standardCounts[$selectedStandard] ?? 0;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE grade = ? AND dob IS NOT NULL');
$stmt->execute([$selectedStandard]);
$selectedWithDob = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*)
    FROM students s
    LEFT JOIN enrollments e ON e.student_id = s.id
    WHERE s.grade = ? AND e.id IS NULL');
$stmt->execute([$selectedStandard]);
$selectedWithoutClasses = (int) $stmt->fetchColumn();
$selectedWithClasses = max($selectedTotal - $selectedWithoutClasses, 0);
$selectedCompletionScore = $selectedTotal > 0 ? percentage($selectedWithDob + $selectedWithClasses, $selectedTotal * 2) : 0;

$stmt = $pdo->prepare('SELECT first_name, last_name, created_at
    FROM students
    WHERE grade = ?
    ORDER BY created_at DESC, id DESC
    LIMIT 1');
$stmt->execute([$selectedStandard]);
$recentSelectedStudent = $stmt->fetch() ?: null;

$smartHeadline = $selectedStandard . ' roster is ready';
$smartMessage = 'This section shows only ' . $selectedStandard . ' students so each standard has its own separate list.';
if ($selectedTotal === 0) {
    $smartHeadline = 'Start the ' . $selectedStandard . ' list';
    $smartMessage = 'No students are added in ' . $selectedStandard . ' yet. Add students here to build a separate roster for this standard.';
} elseif ($selectedWithoutClasses > 0) {
    $smartHeadline = $selectedStandard . ' students need class mapping';
    $smartMessage = $selectedWithoutClasses . ' students in ' . $selectedStandard . ' are not linked to any class yet.';
} elseif ($selectedWithDob < $selectedTotal) {
    $smartHeadline = $selectedStandard . ' profiles need completion';
    $smartMessage = ($selectedTotal - $selectedWithDob) . ' students in ' . $selectedStandard . ' are missing date of birth details.';
}

if ($isTeacherView) {
    $smartHeadline = 'Assigned standard: ' . $selectedStandard;
    $smartMessage = 'Teacher login is locked to ' . $selectedStandard . '. Only this standard roster and attendance are visible in your account.';
}
?>
<section class="hero">
    <div>
        <p class="eyebrow">Student Operations</p>
        <h1><?php echo h($selectedStandard); ?> Student List</h1>
        <p class="hero-text"><?php echo h($isTeacherView ? 'This teacher account can view only the assigned standard roster and attendance records.' : 'Each standard has a separate student list. Switch between standards below and manage only that standard\'s students.'); ?></p>
        <div class="hero-actions">
            <?php if (!$isTeacherView): ?>
                <a class="button-link" href="#student-form"><?php echo $editStudent ? 'Update Profile' : 'Add Student'; ?></a>
            <?php endif; ?>
            <a class="button-secondary-link" href="<?php echo h(page_url('student_attendance', ['standard' => $selectedStandard])); ?>">Open Attendance</a>
            <?php if (!$isTeacherView): ?>
                <a class="button-secondary-link" href="#standard-switcher">Change Standard</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Standard briefing</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $selectedCompletionScore); ?>% list completion</span>
            <span class="chip"><?php echo h((string) $selectedTotal); ?> students in <?php echo h($selectedStandard); ?></span>
        </div>
    </div>
</section>

<?php if (!$isTeacherView): ?>
    <section class="card standard-section" id="standard-switcher">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Standard Switcher</p>
                <h2>Open a separate list for each standard</h2>
            </div>
            <a class="ghost-link" href="<?php echo h(page_url('student_attendance', ['standard' => $selectedStandard])); ?>">Attendance For <?php echo h($selectedStandard); ?></a>
        </div>

        <p class="standard-copy">Select a standard below. The student table will only show students from that selected standard.</p>

        <div class="standard-tabs">
            <?php foreach ($standards as $standard): ?>
                <a class="standard-tab <?php echo $selectedStandard === $standard ? 'active' : ''; ?>" href="<?php echo h(page_url('students', ['standard' => $standard])); ?>">
                    <strong><?php echo h($standard); ?></strong>
                    <small><?php echo h((string) ($standardCounts[$standard] ?? 0)); ?> students</small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="stats-grid">
    <?php if (!$isTeacherView): ?>
        <article class="stat-card">
            <span class="stat-label">School Total</span>
            <strong><?php echo h((string) $totalStudents); ?></strong>
            <small class="muted">All student profiles across every standard.</small>
        </article>
    <?php else: ?>
        <article class="stat-card">
            <span class="stat-label">Assigned Standard</span>
            <strong><?php echo h($selectedStandard); ?></strong>
            <small class="muted">This teacher account is locked to one standard only.</small>
        </article>
    <?php endif; ?>
    <article class="stat-card">
        <span class="stat-label"><?php echo h($selectedStandard); ?> Students</span>
        <strong><?php echo h((string) $selectedTotal); ?></strong>
        <small class="muted">Students currently saved in this separate standard list.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">In Classes</span>
        <strong><?php echo h((string) $selectedWithClasses); ?></strong>
        <small class="muted">Students in <?php echo h($selectedStandard); ?> already linked to classes.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">DOB Captured</span>
        <strong><?php echo h((string) $selectedWithDob); ?></strong>
        <small class="muted">Verified date of birth records for <?php echo h($selectedStandard); ?> students.</small>
    </article>
</div>

<div class="split-layout">
    <?php if (!$isTeacherView): ?>
        <section class="card elevated" id="student-form">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Roster Form</p>
                    <h2><?php echo $editStudent ? 'Update student profile' : 'Add a new student'; ?></h2>
                </div>
                <?php if ($editStudent): ?>
                    <a class="ghost-link" href="<?php echo h(page_url('students', ['standard' => $selectedStandard, 'q' => $search])); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="<?php echo $editStudent ? 'update' : 'add'; ?>">
                <input type="hidden" name="return_standard" value="<?php echo h($selectedStandard); ?>">
                <?php if ($editStudent): ?>
                    <input type="hidden" name="id" value="<?php echo h((string) $editStudent['id']); ?>">
                <?php endif; ?>

                <label class="field-group">
                    <span>First name</span>
                    <input name="first_name" value="<?php echo h($editStudent['first_name'] ?? ''); ?>" placeholder="Enter first name" required>
                </label>
                <label class="field-group">
                    <span>Last name</span>
                    <input name="last_name" value="<?php echo h($editStudent['last_name'] ?? ''); ?>" placeholder="Enter last name" required>
                </label>
                <label class="field-group">
                    <span>Date of birth</span>
                    <input name="dob" type="date" value="<?php echo h($editStudent['dob'] ?? ''); ?>">
                </label>
                <label class="field-group">
                    <span>Standard</span>
                    <select name="grade" required>
                        <option value="">Select standard</option>
                        <?php foreach ($standards as $standard): ?>
                            <option value="<?php echo h($standard); ?>" <?php echo (($editStudent['grade'] ?? $selectedStandard) === $standard) ? 'selected' : ''; ?>>
                                <?php echo h($standard); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit"><?php echo $editStudent ? 'Save Changes' : 'Add Student'; ?></button>
            </form>
        </section>
    <?php else: ?>
        <section class="card elevated">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Teacher Scope</p>
                    <h2><?php echo h($selectedStandard); ?> roster access</h2>
                </div>
            </div>

            <div class="insight-list">
                <div class="insight-item">
                    <span class="mini-label">Assigned standard</span>
                    <strong><?php echo h($selectedStandard); ?></strong>
                    <p>You can view only this standard roster from your teacher account.</p>
                </div>
                <div class="insight-item">
                    <span class="mini-label">Attendance</span>
                    <strong>Student attendance is available</strong>
                    <p>Use the attendance page to mark or review daily attendance for <?php echo h($selectedStandard); ?> only.</p>
                </div>
                <div class="insight-item">
                    <span class="mini-label">Read-only roster</span>
                    <strong><?php echo h((string) count($students)); ?> visible records</strong>
                    <p>Student add, edit, and delete actions stay in the admin account.</p>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Standard Insights</p>
                <h2><?php echo h($selectedStandard); ?> overview</h2>
            </div>
        </div>

        <div class="insight-list">
            <div class="insight-item">
                <span class="mini-label">Recent profile</span>
                <strong><?php echo h($recentSelectedStudent ? full_name($recentSelectedStudent['first_name'], $recentSelectedStudent['last_name']) : 'No student yet'); ?></strong>
                <p><?php echo h($recentSelectedStudent ? 'Added on ' . format_date($recentSelectedStudent['created_at']) . ' in ' . $selectedStandard . '.' : 'Add the first student to start this standard-wise list.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Current list</span>
                <strong><?php echo h((string) count($students)); ?> visible records</strong>
                <p><?php echo h($search !== '' ? 'Search is active inside the ' . $selectedStandard . ' list only.' : 'Only ' . $selectedStandard . ' students are shown in this table.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label"><?php echo h($isTeacherView ? 'Teacher view' : 'School coverage'); ?></span>
                <strong><?php echo h($isTeacherView ? $selectedStandard . ' only' : (string) percentage($studentsWithStandard, max($totalStudents, 1)) . '% standards assigned'); ?></strong>
                <p><?php echo h($isTeacherView ? 'This teacher account is restricted to one assigned standard.' : 'The school currently has ' . $totalStudents . ' students spread across separate standard-wise lists.'); ?></p>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading stack-mobile">
        <div>
            <p class="eyebrow">Student List</p>
            <h2><?php echo h($selectedStandard); ?> roster</h2>
        </div>

        <form method="get" class="toolbar">
            <input type="hidden" name="page" value="students">
            <input type="hidden" name="standard" value="<?php echo h($selectedStandard); ?>">
            <input name="q" value="<?php echo h($search); ?>" placeholder="Search student name or DOB in <?php echo h($selectedStandard); ?>">
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="button-secondary-link" href="<?php echo h(page_url('students', ['standard' => $selectedStandard])); ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table class="table table-modern">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>DOB</th>
                    <th>Classes</th>
                    <th>Created</th>
                    <?php if (!$isTeacherView): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td data-label="ID">#<?php echo h((string) $s['id']); ?></td>
                    <td data-label="Student">
                        <div class="person-cell">
                            <strong><?php echo h(full_name($s['first_name'], $s['last_name'])); ?></strong>
                            <small class="muted"><?php echo h($selectedStandard); ?> roster</small>
                        </div>
                    </td>
                    <td data-label="DOB"><?php echo h(format_date($s['dob'])); ?></td>
                    <td data-label="Classes"><span class="table-pill"><?php echo h((string) $s['class_count']); ?> linked</span></td>
                    <td data-label="Created"><?php echo h(format_date($s['created_at'])); ?></td>
                    <?php if (!$isTeacherView): ?>
                        <td data-label="Actions" class="actions">
                            <a class="button-inline" href="<?php echo h(page_url('students', ['standard' => $selectedStandard, 'edit' => $s['id'], 'q' => $search])); ?>">Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this student?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo h((string) $s['id']); ?>">
                                <input type="hidden" name="return_standard" value="<?php echo h($selectedStandard); ?>">
                                <button type="submit" class="secondary">Delete</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (count($students) === 0): ?>
                <tr>
                    <td colspan="<?php echo $isTeacherView ? '5' : '6'; ?>">
                        <div class="empty-state">
                            <strong>No student records found in <?php echo h($selectedStandard); ?>.</strong>
                            <p><?php echo h($search !== '' ? 'Try a different search term or reset the search in this standard.' : 'Add students in ' . $selectedStandard . ' to build its separate roster.'); ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
