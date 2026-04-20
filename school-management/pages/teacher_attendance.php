<?php
require_admin_role();

$pdo = db();
$statuses = attendance_statuses();
$selectedDate = normalize_date_input($_GET['date'] ?? $_POST['attendance_date'] ?? '');
$flashMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    $validIds = array_map('intval', $pdo->query('SELECT id FROM teachers')->fetchAll(PDO::FETCH_COLUMN));
    $allowedLookup = array_fill_keys($validIds, true);
    $statusInput = $_POST['status'] ?? [];
    $remarksInput = $_POST['remarks'] ?? [];

    $stmt = $pdo->prepare(
        'INSERT INTO teacher_attendance (teacher_id, attendance_date, status, remarks)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            remarks = VALUES(remarks),
            updated_at = CURRENT_TIMESTAMP'
    );

    $pdo->beginTransaction();
    foreach ($statusInput as $teacherId => $statusValue) {
        $teacherId = (int) $teacherId;
        if (!isset($allowedLookup[$teacherId])) {
            continue;
        }

        $status = normalize_attendance_status($statusValue);
        $remarks = trim((string) ($remarksInput[$teacherId] ?? ''));
        $stmt->execute([$teacherId, $selectedDate, $status, $remarks !== '' ? $remarks : null]);
    }
    $pdo->commit();

    header('Location: ' . page_url('teacher_attendance', [
        'date' => $selectedDate,
        'saved' => '1',
    ]));
    exit;
}

if (($_GET['saved'] ?? '') === '1') {
    $flashMessage = 'Teacher attendance saved on ' . format_date($selectedDate) . '.';
}

$teacherStmt = $pdo->prepare('SELECT
        t.id,
        t.first_name,
        t.last_name,
        t.subject,
        t.email,
        t.created_at,
        COALESCE(class_totals.class_count, 0) AS class_count,
        ta.status,
        ta.remarks
    FROM teachers t
    LEFT JOIN (
        SELECT teacher_id, COUNT(*) AS class_count
        FROM classes
        WHERE teacher_id IS NOT NULL
        GROUP BY teacher_id
    ) class_totals ON class_totals.teacher_id = t.id
    LEFT JOIN teacher_attendance ta
        ON ta.teacher_id = t.id
        AND ta.attendance_date = ?
    ORDER BY t.first_name ASC, t.last_name ASC');
$teacherStmt->execute([$selectedDate]);
$teachers = $teacherStmt->fetchAll();

$statusCounts = array_fill_keys($statuses, 0);
$summaryStmt = $pdo->prepare('SELECT status, COUNT(*) AS total
    FROM teacher_attendance
    WHERE attendance_date = ?
    GROUP BY status');
$summaryStmt->execute([$selectedDate]);
foreach ($summaryStmt->fetchAll() as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int) $row['total'];
    }
}

$totalTeachers = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$recordedCount = array_sum($statusCounts);
$pendingCount = max($totalTeachers - $recordedCount, 0);
$teachersWithClasses = (int) $pdo->query('SELECT COUNT(DISTINCT teacher_id) FROM classes WHERE teacher_id IS NOT NULL')->fetchColumn();

$recentEntries = $pdo->query('SELECT
        ta.attendance_date,
        ta.status,
        t.first_name,
        t.last_name
    FROM teacher_attendance ta
    INNER JOIN teachers t ON t.id = ta.teacher_id
    ORDER BY ta.attendance_date DESC, ta.id DESC
    LIMIT 5')->fetchAll();

$smartHeadline = 'Faculty attendance desk';
$smartMessage = 'Mark daily faculty attendance for all teachers from one sheet.';
if ($totalTeachers === 0) {
    $smartHeadline = 'No teachers added yet';
    $smartMessage = 'Add teacher profiles first, then attendance can be recorded here.';
} elseif ($pendingCount > 0) {
    $smartHeadline = 'Teacher attendance is pending';
    $smartMessage = $pendingCount . ' teachers still need attendance marking for ' . format_date($selectedDate) . '.';
} else {
    $smartHeadline = 'Teacher attendance is complete';
    $smartMessage = 'All teachers have attendance marked for ' . format_date($selectedDate) . '.';
}
?>
<section class="hero">
    <div>
        <p class="eyebrow">Teacher Attendance</p>
        <h1>Faculty Attendance Sheet</h1>
        <p class="hero-text">Record daily teacher attendance in one place and keep a clean faculty history for every working day.</p>
        <div class="hero-actions">
            <a class="button-link" href="#teacher-attendance-form">Mark Attendance</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('teachers')); ?>">Open Teachers</a>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Daily briefing</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h(format_date($selectedDate)); ?></span>
            <span class="chip"><?php echo h((string) $totalTeachers); ?> teachers</span>
        </div>
    </div>
</section>

<div class="stats-grid">
    <article class="stat-card">
        <span class="stat-label">Present</span>
        <strong><?php echo h((string) $statusCounts['Present']); ?></strong>
        <small class="muted">Teachers marked present on the selected day.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Absent</span>
        <strong><?php echo h((string) $statusCounts['Absent']); ?></strong>
        <small class="muted">Teachers marked absent on the selected day.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Recorded</span>
        <strong><?php echo h((string) $recordedCount); ?></strong>
        <small class="muted">Teachers with attendance marked on this day.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Pending</span>
        <strong><?php echo h((string) $pendingCount); ?></strong>
        <small class="muted">Teachers still waiting for attendance entry.</small>
    </article>
</div>

<div class="split-layout">
    <section class="card elevated" id="teacher-attendance-form">
        <div class="section-heading stack-mobile">
            <div>
                <p class="eyebrow">Attendance Sheet</p>
                <h2>Mark teacher attendance</h2>
            </div>

            <form method="get" class="toolbar">
                <input type="hidden" name="page" value="teacher_attendance">
                <input type="date" name="date" value="<?php echo h($selectedDate); ?>">
                <button type="submit">Load Date</button>
            </form>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <p class="badge"><?php echo h($flashMessage); ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="save_attendance">
            <input type="hidden" name="attendance_date" value="<?php echo h($selectedDate); ?>">

            <div class="table-wrap">
                <table class="table table-modern">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Subject</th>
                            <th>Classes</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td data-label="Teacher">
                                <div class="person-cell">
                                    <strong><?php echo h(full_name($teacher['first_name'], $teacher['last_name'])); ?></strong>
                                    <small class="muted"><?php echo h($teacher['email'] ?: 'No email'); ?></small>
                                </div>
                            </td>
                            <td data-label="Subject"><span class="table-pill neutral"><?php echo h($teacher['subject'] ?: 'Pending'); ?></span></td>
                            <td data-label="Classes"><span class="table-pill"><?php echo h((string) $teacher['class_count']); ?> linked</span></td>
                            <td data-label="Status">
                                <select name="status[<?php echo h((string) $teacher['id']); ?>]">
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo h($status); ?>" <?php echo (($teacher['status'] ?? 'Present') === $status) ? 'selected' : ''; ?>>
                                            <?php echo h($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td data-label="Remarks">
                                <input name="remarks[<?php echo h((string) $teacher['id']); ?>]" value="<?php echo h($teacher['remarks'] ?? ''); ?>" placeholder="Optional note">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($teachers) === 0): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <strong>No teacher records found.</strong>
                                    <p>Add teachers before marking daily attendance.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($teachers) > 0): ?>
                <button type="submit">Save Teacher Attendance</button>
            <?php endif; ?>
        </form>
    </section>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Recent Entries</p>
                <h2>Faculty history</h2>
            </div>
        </div>

        <div class="insight-list">
            <div class="insight-item">
                <span class="mini-label">Faculty capacity</span>
                <strong><?php echo h((string) $teachersWithClasses); ?> teachers linked to classes</strong>
                <p>Class-linked faculty members can be tracked alongside daily attendance.</p>
            </div>
            <?php foreach ($recentEntries as $entry): ?>
                <div class="insight-item">
                    <span class="mini-label"><?php echo h(format_date($entry['attendance_date'])); ?></span>
                    <strong><?php echo h(full_name($entry['first_name'], $entry['last_name'])); ?></strong>
                    <p><?php echo h($entry['status']); ?> was recorded for this teacher.</p>
                </div>
            <?php endforeach; ?>
            <?php if (count($recentEntries) === 0): ?>
                <div class="insight-item">
                    <span class="mini-label">No history yet</span>
                    <strong>Teacher attendance records will appear here.</strong>
                    <p>Save attendance for teachers to start building faculty history.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
