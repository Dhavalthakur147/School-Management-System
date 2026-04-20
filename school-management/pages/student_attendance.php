<?php
$pdo = db();
$standards = school_standards();
$statuses = attendance_statuses();
$isTeacherView = teacher_is_logged_in();
$assignedStandard = normalize_standard(teacher_assigned_standard() ?? '') ?? $standards[0];
$selectedStandard = $isTeacherView
    ? $assignedStandard
    : (normalize_standard($_GET['standard'] ?? $_POST['standard'] ?? '') ?? $standards[0]);
$selectedDate = normalize_date_input($_GET['date'] ?? $_POST['attendance_date'] ?? '');
$flashMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    $validIds = $pdo->prepare('SELECT id FROM students WHERE grade = ?');
    $validIds->execute([$selectedStandard]);
    $allowedIds = array_map('intval', array_column($validIds->fetchAll(), 'id'));
    $legacyStatusInput = $_POST['status'] ?? [];
    $presentInput = $_POST['present'] ?? [];
    $absentInput = $_POST['absent'] ?? [];
    $remarksInput = $_POST['remarks'] ?? [];

    $stmt = $pdo->prepare(
        'INSERT INTO student_attendance (student_id, attendance_date, status, remarks)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            remarks = VALUES(remarks),
            updated_at = CURRENT_TIMESTAMP'
    );

    $pdo->beginTransaction();
    foreach ($allowedIds as $studentId) {
        $status = null;

        if (array_key_exists($studentId, $legacyStatusInput)) {
            $status = normalize_attendance_status((string) $legacyStatusInput[$studentId]);
            if (!in_array($status, ['Present', 'Absent'], true)) {
                $status = 'Present';
            }
        } else {
            $isPresent = (string) ($presentInput[$studentId] ?? '') === '1';
            $isAbsent = (string) ($absentInput[$studentId] ?? '') === '1';

            if (!$isPresent && !$isAbsent) {
                continue;
            }

            $status = $isAbsent ? 'Absent' : 'Present';
        }

        $remarks = trim((string) ($remarksInput[$studentId] ?? ''));
        $stmt->execute([$studentId, $selectedDate, $status, $remarks !== '' ? $remarks : null]);
    }
    $pdo->commit();

    header('Location: ' . page_url('student_attendance', [
        'standard' => $selectedStandard,
        'date' => $selectedDate,
        'saved' => '1',
    ]));
    exit;
}

if (($_GET['saved'] ?? '') === '1') {
    $flashMessage = 'Student attendance saved for ' . $selectedStandard . ' on ' . format_date($selectedDate) . '.';
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

$studentStmt = $pdo->prepare('SELECT
        s.id,
        s.first_name,
        s.last_name,
        s.dob,
        s.created_at,
        COALESCE(class_totals.class_count, 0) AS class_count,
        sa.status,
        sa.remarks
    FROM students s
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS class_count
        FROM enrollments
        GROUP BY student_id
    ) class_totals ON class_totals.student_id = s.id
    LEFT JOIN student_attendance sa
        ON sa.student_id = s.id
        AND sa.attendance_date = :attendance_date
    WHERE s.grade = :standard
    ORDER BY s.first_name ASC, s.last_name ASC');
$studentStmt->execute([
    'attendance_date' => $selectedDate,
    'standard' => $selectedStandard,
]);
$students = $studentStmt->fetchAll();

$statusCounts = array_fill_keys($statuses, 0);
$summaryStmt = $pdo->prepare('SELECT sa.status, COUNT(*) AS total
    FROM student_attendance sa
    INNER JOIN students s ON s.id = sa.student_id
    WHERE sa.attendance_date = ? AND s.grade = ?
    GROUP BY sa.status');
$summaryStmt->execute([$selectedDate, $selectedStandard]);
foreach ($summaryStmt->fetchAll() as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int) $row['total'];
    }
}

$selectedTotal = $standardCounts[$selectedStandard] ?? 0;
$recordedCount = array_sum($statusCounts);
$pendingCount = max($selectedTotal - $recordedCount, 0);

$recentStmt = $pdo->prepare('SELECT
        sa.attendance_date,
        sa.status,
        s.first_name,
        s.last_name
    FROM student_attendance sa
    INNER JOIN students s ON s.id = sa.student_id
    WHERE s.grade = ?
    ORDER BY sa.attendance_date DESC, sa.id DESC
    LIMIT 5');
$recentStmt->execute([$selectedStandard]);
$recentEntries = $recentStmt->fetchAll();

$smartHeadline = $selectedStandard . ' attendance desk';
$smartMessage = 'Mark attendance standard-wise so each class section keeps its own clean daily record.';
if ($selectedTotal === 0) {
    $smartHeadline = 'No students in ' . $selectedStandard;
    $smartMessage = 'Add students to ' . $selectedStandard . ' first, then attendance can be marked here.';
} elseif ($pendingCount > 0) {
    $smartHeadline = 'Attendance still pending';
    $smartMessage = $pendingCount . ' students in ' . $selectedStandard . ' do not have attendance marked for ' . format_date($selectedDate) . '.';
} else {
    $smartHeadline = 'Attendance is fully marked';
    $smartMessage = 'Every student in ' . $selectedStandard . ' has an attendance status for ' . format_date($selectedDate) . '.';
}

if ($isTeacherView) {
    $smartHeadline = 'Assigned attendance sheet: ' . $selectedStandard;
    $smartMessage = 'Teacher login can open and save attendance only for the assigned standard.';
}
?>
<section class="hero">
    <div>
        <p class="eyebrow">Student Attendance</p>
        <h1><?php echo h($selectedStandard); ?> Attendance</h1>
        <p class="hero-text"><?php echo h($isTeacherView ? 'This teacher account is locked to one standard, so only that standard attendance sheet is available here.' : 'Record student attendance date-wise and standard-wise so every standard keeps its own separate daily attendance sheet.'); ?></p>
        <div class="hero-actions">
            <a class="button-link" href="#attendance-form">Mark Attendance</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('students', ['standard' => $selectedStandard])); ?>">Open Student List</a>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Daily briefing</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h(format_date($selectedDate)); ?></span>
            <span class="chip"><?php echo h((string) $selectedTotal); ?> students</span>
        </div>
    </div>
</section>

<?php if (!$isTeacherView): ?>
    <section class="card standard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Standard Switcher</p>
                <h2>Open attendance by standard</h2>
            </div>
        </div>
        <div class="standard-tabs">
            <?php foreach ($standards as $standard): ?>
                <a class="standard-tab <?php echo $selectedStandard === $standard ? 'active' : ''; ?>" href="<?php echo h(page_url('student_attendance', ['standard' => $standard, 'date' => $selectedDate])); ?>">
                    <strong><?php echo h($standard); ?></strong>
                    <small><?php echo h((string) ($standardCounts[$standard] ?? 0)); ?> students</small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="stats-grid">
    <article class="stat-card">
        <span class="stat-label">Present</span>
        <strong><?php echo h((string) $statusCounts['Present']); ?></strong>
        <small class="muted">Students marked present on the selected day.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Absent</span>
        <strong><?php echo h((string) $statusCounts['Absent']); ?></strong>
        <small class="muted">Students marked absent on the selected day.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Recorded</span>
        <strong><?php echo h((string) $recordedCount); ?></strong>
        <small class="muted">Students with attendance marked for this standard.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Pending</span>
        <strong><?php echo h((string) $pendingCount); ?></strong>
        <small class="muted">Students still waiting for attendance entry.</small>
    </article>
</div>

<div class="split-layout">
    <section class="card elevated" id="attendance-form">
        <div class="section-heading stack-mobile">
            <div>
                <p class="eyebrow">Attendance Sheet</p>
                <h2>Mark attendance for <?php echo h($selectedStandard); ?></h2>
            </div>

            <form method="get" class="toolbar">
                <input type="hidden" name="page" value="student_attendance">
                <input type="hidden" name="standard" value="<?php echo h($selectedStandard); ?>">
                <input type="date" name="date" value="<?php echo h($selectedDate); ?>">
                <button type="submit">Load Date</button>
            </form>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <p class="badge"><?php echo h($flashMessage); ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="save_attendance">
            <input type="hidden" name="standard" value="<?php echo h($selectedStandard); ?>">
            <input type="hidden" name="attendance_date" value="<?php echo h($selectedDate); ?>">

            <div class="table-wrap">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>DOB</th>
                            <th>Classes</th>
                            <th>Attendance</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php $currentStatus = ($student['status'] ?? '') === 'Absent' ? 'Absent' : 'Present'; ?>
                        <tr>
                            <td data-label="Student">
                                <div class="person-cell">
                                    <strong><?php echo h(full_name($student['first_name'], $student['last_name'])); ?></strong>
                                    <small class="muted"><?php echo h($selectedStandard); ?></small>
                                </div>
                            </td>
                            <td data-label="DOB"><?php echo h(format_date($student['dob'])); ?></td>
                            <td data-label="Classes"><span class="table-pill"><?php echo h((string) $student['class_count']); ?> linked</span></td>
                            <td data-label="Attendance">
                                <div class="attendance-choice" data-attendance-choice>
                                    <label class="attendance-check">
                                        <input
                                            type="checkbox"
                                            name="present[<?php echo h((string) $student['id']); ?>]"
                                            value="1"
                                            data-attendance-option="present"
                                            <?php echo $currentStatus === 'Present' ? 'checked' : ''; ?>
                                        >
                                        <span>Present</span>
                                    </label>
                                    <label class="attendance-check">
                                        <input
                                            type="checkbox"
                                            name="absent[<?php echo h((string) $student['id']); ?>]"
                                            value="1"
                                            data-attendance-option="absent"
                                            <?php echo $currentStatus === 'Absent' ? 'checked' : ''; ?>
                                        >
                                        <span>Absent</span>
                                    </label>
                                </div>
                            </td>
                            <td data-label="Remarks">
                                <input name="remarks[<?php echo h((string) $student['id']); ?>]" value="<?php echo h($student['remarks'] ?? ''); ?>" placeholder="Optional note">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($students) === 0): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <strong>No students found in <?php echo h($selectedStandard); ?>.</strong>
                                    <p>Add students to this standard before marking attendance.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($students) > 0): ?>
                <button type="submit">Save Student Attendance</button>
            <?php endif; ?>
        </form>
    </section>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Recent Entries</p>
                <h2><?php echo h($selectedStandard); ?> history</h2>
            </div>
        </div>

        <div class="insight-list">
            <?php foreach ($recentEntries as $entry): ?>
                <div class="insight-item">
                    <span class="mini-label"><?php echo h(format_date($entry['attendance_date'])); ?></span>
                    <strong><?php echo h(full_name($entry['first_name'], $entry['last_name'])); ?></strong>
                    <p><?php echo h($entry['status']); ?> was recorded for this student.</p>
                </div>
            <?php endforeach; ?>
            <?php if (count($recentEntries) === 0): ?>
                <div class="insight-item">
                    <span class="mini-label">No history yet</span>
                    <strong>Attendance records will appear here.</strong>
                    <p>Save attendance for this standard to start building daily history.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
document.querySelectorAll('[data-attendance-choice]').forEach((choice) => {
    const options = Array.from(choice.querySelectorAll('[data-attendance-option]'));
    options.forEach((option) => {
        option.addEventListener('change', () => {
            if (option.checked) {
                options.forEach((otherOption) => {
                    if (otherOption !== option) {
                        otherOption.checked = false;
                    }
                });
                return;
            }

            const stillChecked = options.some((otherOption) => otherOption.checked);
            if (!stillChecked) {
                option.checked = true;
            }
        });
    });
});
</script>
