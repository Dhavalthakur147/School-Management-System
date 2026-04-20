<?php
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);

        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO classes (name, room, teacher_id) VALUES (?, ?, ?)');
            $stmt->execute([$name, $room !== '' ? $room : null, $teacherId > 0 ? $teacherId : null]);
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM classes WHERE id = ?');
            $stmt->execute([$id]);
        }
    }

    header('Location: ' . page_url('classes'));
    exit;
}

$teachers = $pdo->query('SELECT id, first_name, last_name FROM teachers ORDER BY last_name, first_name')->fetchAll();
$classes = $pdo->query('SELECT c.*, t.first_name AS t_first, t.last_name AS t_last
    FROM classes c
    LEFT JOIN teachers t ON c.teacher_id = t.id
    ORDER BY c.id DESC')->fetchAll();

$overview = $pdo->query('SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN teacher_id IS NOT NULL THEN 1 ELSE 0 END) AS with_teacher,
        SUM(CASE WHEN room IS NOT NULL AND room <> "" THEN 1 ELSE 0 END) AS with_room
    FROM classes')->fetch();
$totalClasses = (int) ($overview['total'] ?? 0);
$classesWithTeacher = (int) ($overview['with_teacher'] ?? 0);
$classesWithRoom = (int) ($overview['with_room'] ?? 0);
$openTeacherAssignments = $totalClasses - $classesWithTeacher;
$roomsPending = $totalClasses - $classesWithRoom;
$coverageScore = percentage($classesWithTeacher, max($totalClasses, 1));

$latestClass = $pdo->query('SELECT c.name, c.room, t.first_name, t.last_name, c.created_at
    FROM classes c
    LEFT JOIN teachers t ON c.teacher_id = t.id
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 1')->fetch();
$popularClass = $pdo->query('SELECT c.name, COUNT(e.id) AS student_total
    FROM classes c
    LEFT JOIN enrollments e ON e.class_id = c.id
    GROUP BY c.id, c.name
    ORDER BY student_total DESC, c.name ASC
    LIMIT 1')->fetch();

$smartHeadline = 'Plan your academic structure';
$smartMessage = 'Create classes with rooms and teacher assignments so student enrollment can flow smoothly.';
if ($totalClasses > 0) {
    if ($openTeacherAssignments > 0) {
        $smartHeadline = 'Some classes need teachers';
        $smartMessage = $openTeacherAssignments . ' classes are not linked to a teacher yet. Assigning teachers will improve the daily schedule view.';
    } elseif ($roomsPending > 0) {
        $smartHeadline = 'Room planning is still open';
        $smartMessage = $roomsPending . ' classes do not have room details yet. Adding rooms helps administrators stay organized.';
    } else {
        $smartHeadline = 'Class planning looks strong';
        $smartMessage = 'Class records, teacher assignments, and room details are in place. The next step is balancing enrollments.';
    }
}
?>
<section class="hero">
    <div>
        <p class="eyebrow">Academic Planning</p>
        <h1>Class Management Hub</h1>
        <p class="hero-text">Build a cleaner timetable by organizing classes, rooms, and teacher assignments in one easy dashboard.</p>
        <div class="hero-actions">
            <a class="button-link" href="#class-form">Add Class</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('teachers')); ?>">Open Teachers</a>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Planning update</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $coverageScore); ?>% teacher coverage</span>
            <span class="chip"><?php echo h((string) $classesWithRoom); ?> rooms added</span>
        </div>
    </div>
</section>

<div class="stats-grid">
    <article class="stat-card">
        <span class="stat-label">Classes</span>
        <strong><?php echo h((string) $totalClasses); ?></strong>
        <small class="muted">Active class records inside the system.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Teacher Assigned</span>
        <strong><?php echo h((string) $classesWithTeacher); ?></strong>
        <small class="muted">Classes already connected to a faculty member.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Room Ready</span>
        <strong><?php echo h((string) $classesWithRoom); ?></strong>
        <small class="muted">Class records that include room information.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Need Action</span>
        <strong><?php echo h((string) ($openTeacherAssignments + $roomsPending)); ?></strong>
        <small class="muted">Open teacher or room details still pending.</small>
    </article>
</div>

<div class="split-layout">
    <section class="card elevated" id="class-form">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Class Form</p>
                <h2>Create a new class</h2>
            </div>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="add">

            <label class="field-group">
                <span>Class name</span>
                <input name="name" placeholder="Enter class name" required>
            </label>
            <label class="field-group">
                <span>Room</span>
                <input name="room" placeholder="Enter room number">
            </label>
            <label class="field-group">
                <span>Assign teacher</span>
                <select name="teacher_id">
                    <option value="">Choose teacher (optional)</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?php echo h((string) $t['id']); ?>">
                            <?php echo h(full_name($t['first_name'], $t['last_name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-group">
                <span>Quick note</span>
                <div class="list-row">
                    <div>
                        <strong><?php echo h((string) count($teachers)); ?> teachers available</strong>
                        <p>Choose a teacher now or assign later when scheduling is ready.</p>
                    </div>
                </div>
            </div>
            <button type="submit">Add Class</button>
        </form>
    </section>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Live Insights</p>
                <h2>Class overview</h2>
            </div>
        </div>

        <div class="insight-list">
            <div class="insight-item">
                <span class="mini-label">Latest class</span>
                <strong><?php echo h($latestClass['name'] ?? 'No class yet'); ?></strong>
                <p><?php echo h($latestClass ? (($latestClass['room'] ?: 'Room pending') . ' with ' . (full_name($latestClass['first_name'] ?? '', $latestClass['last_name'] ?? '') ?: 'teacher pending')) : 'Create the first class to begin academic planning.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Most active class</span>
                <strong><?php echo h($popularClass['name'] ?? 'No enrollment yet'); ?></strong>
                <p><?php echo h($popularClass && (int) $popularClass['student_total'] > 0 ? $popularClass['student_total'] . ' students are currently enrolled in this class.' : 'Enrollment demand will appear here once students are linked to classes.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Coverage</span>
                <strong><?php echo h((string) $coverageScore); ?>% assignment complete</strong>
                <p><?php echo h($totalClasses > 0 ? 'Teacher assignment is tracked automatically as classes are added.' : 'Class planning metrics will appear as soon as you create records.'); ?></p>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading stack-mobile">
        <div>
            <p class="eyebrow">Class List</p>
            <h2>Current academic groups</h2>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table table-modern">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Class</th>
                    <th>Room</th>
                    <th>Teacher</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($classes as $c): ?>
                <tr>
                    <td data-label="ID">#<?php echo h((string) $c['id']); ?></td>
                    <td data-label="Class"><?php echo h($c['name']); ?></td>
                    <td data-label="Room"><?php echo h($c['room'] ?: 'Pending'); ?></td>
                    <td data-label="Teacher"><?php echo h(full_name($c['t_first'] ?? '', $c['t_last'] ?? '') ?: 'Pending'); ?></td>
                    <td data-label="Created"><?php echo h(format_date($c['created_at'])); ?></td>
                    <td data-label="Actions" class="actions">
                        <form method="post" onsubmit="return confirm('Delete this class?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo h((string) $c['id']); ?>">
                            <button type="submit" class="secondary">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($classes) === 0): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <strong>No classes created yet.</strong>
                            <p>Add your first class to begin organizing rooms and teachers.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
