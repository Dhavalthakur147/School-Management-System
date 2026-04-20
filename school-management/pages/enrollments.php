<?php
$pdo = db();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $classId = (int) ($_POST['class_id'] ?? 0);

        if ($studentId > 0 && $classId > 0) {
            try {
                $stmt = $pdo->prepare('INSERT INTO enrollments (student_id, class_id) VALUES (?, ?)');
                $stmt->execute([$studentId, $classId]);
            } catch (PDOException $e) {
                $error = 'This student is already enrolled in that class.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM enrollments WHERE id = ?');
            $stmt->execute([$id]);
        }
    }

    if ($action !== 'add' || $error === '') {
        header('Location: ' . page_url('enrollments'));
        exit;
    }
}

$students = $pdo->query('SELECT id, first_name, last_name FROM students ORDER BY last_name, first_name')->fetchAll();
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
$enrollments = $pdo->query('SELECT e.id, e.created_at, s.first_name, s.last_name, c.name AS class_name
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    JOIN classes c ON e.class_id = c.id
    ORDER BY e.id DESC')->fetchAll();

$totalEnrollments = (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();
$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$totalClasses = (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$studentsWaiting = (int) $pdo->query('SELECT COUNT(*)
    FROM students s
    LEFT JOIN enrollments e ON e.student_id = s.id
    WHERE e.id IS NULL')->fetchColumn();
$activeClasses = (int) $pdo->query('SELECT COUNT(DISTINCT class_id) FROM enrollments')->fetchColumn();

$topClass = $pdo->query('SELECT c.name, COUNT(e.id) AS total_students
    FROM classes c
    LEFT JOIN enrollments e ON e.class_id = c.id
    GROUP BY c.id, c.name
    ORDER BY total_students DESC, c.name ASC
    LIMIT 1')->fetch();
$recentEnrollment = $pdo->query('SELECT s.first_name, s.last_name, c.name AS class_name, e.created_at
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    JOIN classes c ON e.class_id = c.id
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT 1')->fetch();

$smartHeadline = 'Connect students to classes';
$smartMessage = 'Use enrollments to turn student and class records into a working academic structure.';
if ($totalEnrollments > 0) {
    if ($studentsWaiting > 0) {
        $smartHeadline = 'Some students still need placement';
        $smartMessage = $studentsWaiting . ' students are in the system but not enrolled in any class yet.';
    } else {
        $smartHeadline = 'Enrollment flow looks healthy';
        $smartMessage = 'Every student currently in the roster is linked to at least one class.';
    }
}
?>
<section class="hero">
    <div>
        <p class="eyebrow">Enrollment Desk</p>
        <h1>Student Enrollment Center</h1>
        <p class="hero-text">Link students to classes with a cleaner workflow that works well on desktop and mobile screens.</p>
        <div class="hero-actions">
            <a class="button-link" href="#enrollment-form">Enroll Student</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('students')); ?>">Open Students</a>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Enrollment update</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $totalEnrollments); ?> active enrollments</span>
            <span class="chip"><?php echo h((string) $activeClasses); ?> classes in use</span>
        </div>
    </div>
</section>

<div class="stats-grid">
    <article class="stat-card">
        <span class="stat-label">Enrollments</span>
        <strong><?php echo h((string) $totalEnrollments); ?></strong>
        <small class="muted">Student-to-class links currently active.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Students</span>
        <strong><?php echo h((string) $totalStudents); ?></strong>
        <small class="muted">Students available for assignment.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Classes</span>
        <strong><?php echo h((string) $totalClasses); ?></strong>
        <small class="muted">Classes available for enrollment mapping.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Waiting Placement</span>
        <strong><?php echo h((string) $studentsWaiting); ?></strong>
        <small class="muted">Students who are not yet linked to any class.</small>
    </article>
</div>

<div class="split-layout">
    <section class="card elevated" id="enrollment-form">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Enrollment Form</p>
                <h2>Assign a student to class</h2>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <p class="badge"><?php echo h($error); ?></p>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="add">

            <label class="field-group">
                <span>Select student</span>
                <select name="student_id" required>
                    <option value="">Choose student</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo h((string) $s['id']); ?>">
                            <?php echo h(full_name($s['first_name'], $s['last_name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field-group">
                <span>Select class</span>
                <select name="class_id" required>
                    <option value="">Choose class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo h((string) $c['id']); ?>"><?php echo h($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-group">
                <span>Available records</span>
                <div class="list-row">
                    <div>
                        <strong><?php echo h((string) $totalStudents); ?> students and <?php echo h((string) $totalClasses); ?> classes</strong>
                        <p>Make sure both student and class records exist before adding an enrollment.</p>
                    </div>
                </div>
            </div>
            <button type="submit">Save Enrollment</button>
        </form>
    </section>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Live Insights</p>
                <h2>Enrollment health</h2>
            </div>
        </div>

        <div class="insight-list">
            <div class="insight-item">
                <span class="mini-label">Recent enrollment</span>
                <strong><?php echo h($recentEnrollment ? full_name($recentEnrollment['first_name'], $recentEnrollment['last_name']) : 'No enrollment yet'); ?></strong>
                <p><?php echo h($recentEnrollment ? 'Joined ' . $recentEnrollment['class_name'] . ' on ' . format_date($recentEnrollment['created_at']) : 'Create the first enrollment to start tracking academic placement.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Top class</span>
                <strong><?php echo h($topClass['name'] ?? 'No class activity yet'); ?></strong>
                <p><?php echo h($topClass && (int) $topClass['total_students'] > 0 ? $topClass['total_students'] . ' students are currently assigned here.' : 'Class demand will appear once enrollments are added.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Coverage</span>
                <strong><?php echo h((string) percentage($totalStudents - $studentsWaiting, max($totalStudents, 1))); ?>% student placement</strong>
                <p><?php echo h($totalStudents > 0 ? 'This score shows how many student records are already linked to classes.' : 'Add students first to begin placement tracking.'); ?></p>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading stack-mobile">
        <div>
            <p class="eyebrow">Enrollment List</p>
            <h2>Current student placements</h2>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table table-modern">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($enrollments as $e): ?>
                <tr>
                    <td data-label="ID">#<?php echo h((string) $e['id']); ?></td>
                    <td data-label="Student"><?php echo h(full_name($e['first_name'], $e['last_name'])); ?></td>
                    <td data-label="Class"><?php echo h($e['class_name']); ?></td>
                    <td data-label="Created"><?php echo h(format_date($e['created_at'])); ?></td>
                    <td data-label="Actions" class="actions">
                        <form method="post" onsubmit="return confirm('Remove this enrollment?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo h((string) $e['id']); ?>">
                            <button type="submit" class="secondary">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($enrollments) === 0): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <strong>No enrollments yet.</strong>
                            <p>Assign students to classes to see placement records here.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
