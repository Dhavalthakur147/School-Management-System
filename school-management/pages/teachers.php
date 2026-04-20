<?php
require_admin_role();

$pdo = db();
$standards = school_standards();
$search = trim($_GET['q'] ?? '');
$editId = (int) ($_GET['edit'] ?? 0);
$errorMessage = '';
$submittedData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $accountEnabled = ($_POST['account_enabled'] ?? '') === '1';
        $assignedStandard = normalize_standard($_POST['assigned_standard'] ?? '');
        $loginUsername = trim($_POST['login_username'] ?? '');
        $loginPassword = (string) ($_POST['login_password'] ?? '');

        $submittedData = [
            'id' => $id,
            'account_id' => $accountId,
            'first_name' => $first,
            'last_name' => $last,
            'subject' => $subject,
            'email' => $email,
            'account_enabled' => $accountEnabled ? '1' : '0',
            'assigned_standard' => $assignedStandard ?? '',
            'login_username' => $loginUsername,
        ];

        if ($first === '' || $last === '') {
            $errorMessage = 'First name and last name are required.';
        } elseif ($accountEnabled && ($assignedStandard === null || $loginUsername === '')) {
            $errorMessage = 'To enable teacher login, choose the assigned standard and login ID.';
        } elseif ($accountEnabled && $action === 'add' && $loginPassword === '') {
            $errorMessage = 'Set a password for the new teacher login.';
        } elseif ($accountEnabled && $action === 'update' && $accountId === 0 && $loginPassword === '') {
            $errorMessage = 'Set a password before creating a teacher login for this profile.';
        }

        if ($errorMessage === '') {
            try {
                $pdo->beginTransaction();

                if ($action === 'add') {
                    $stmt = $pdo->prepare('INSERT INTO teachers (first_name, last_name, subject, email) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$first, $last, $subject !== '' ? $subject : null, $email !== '' ? $email : null]);
                    $teacherId = (int) $pdo->lastInsertId();
                } else {
                    $teacherId = $id;
                    $stmt = $pdo->prepare('UPDATE teachers SET first_name = ?, last_name = ?, subject = ?, email = ? WHERE id = ?');
                    $stmt->execute([$first, $last, $subject !== '' ? $subject : null, $email !== '' ? $email : null, $teacherId]);
                }

                if ($accountEnabled) {
                    if ($accountId > 0) {
                        if ($loginPassword !== '') {
                            $stmt = $pdo->prepare('UPDATE teacher_accounts
                                SET username = ?, assigned_standard = ?, password_hash = ?, is_active = 1
                                WHERE id = ? AND teacher_id = ?');
                            $stmt->execute([
                                $loginUsername,
                                $assignedStandard,
                                password_hash($loginPassword, PASSWORD_DEFAULT),
                                $accountId,
                                $teacherId,
                            ]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE teacher_accounts
                                SET username = ?, assigned_standard = ?, is_active = 1
                                WHERE id = ? AND teacher_id = ?');
                            $stmt->execute([
                                $loginUsername,
                                $assignedStandard,
                                $accountId,
                                $teacherId,
                            ]);
                        }
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO teacher_accounts (teacher_id, username, password_hash, assigned_standard, is_active) VALUES (?, ?, ?, ?, 1)');
                        $stmt->execute([
                            $teacherId,
                            $loginUsername,
                            password_hash($loginPassword, PASSWORD_DEFAULT),
                            $assignedStandard,
                        ]);
                    }
                } elseif ($accountId > 0) {
                    $stmt = $pdo->prepare('DELETE FROM teacher_accounts WHERE id = ? AND teacher_id = ?');
                    $stmt->execute([$accountId, $teacherId]);
                }

                $pdo->commit();
                header('Location: ' . page_url('teachers'));
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errorMessage = str_contains($exception->getMessage(), '1062')
                    ? 'That teacher login ID is already in use. Please choose another one.'
                    : 'The teacher profile could not be saved right now. Please try again.';
                $editId = $action === 'update' ? $id : 0;
            }
        } else {
            $editId = $action === 'update' ? $id : 0;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM teachers WHERE id = ?');
            $stmt->execute([$id]);
        }

        header('Location: ' . page_url('teachers'));
        exit;
    }
}

$editTeacher = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT
            t.*,
            ta.id AS account_id,
            ta.username AS login_username,
            ta.assigned_standard,
            ta.is_active
        FROM teachers t
        LEFT JOIN teacher_accounts ta ON ta.teacher_id = t.id
        WHERE t.id = ?');
    $stmt->execute([$editId]);
    $editTeacher = $stmt->fetch() ?: null;
}

$formData = $submittedData ?? [
    'id' => (int) ($editTeacher['id'] ?? 0),
    'account_id' => (int) ($editTeacher['account_id'] ?? 0),
    'first_name' => $editTeacher['first_name'] ?? '',
    'last_name' => $editTeacher['last_name'] ?? '',
    'subject' => $editTeacher['subject'] ?? '',
    'email' => $editTeacher['email'] ?? '',
    'account_enabled' => !empty($editTeacher['account_id']) ? '1' : '0',
    'assigned_standard' => $editTeacher['assigned_standard'] ?? '',
    'login_username' => $editTeacher['login_username'] ?? '',
];

$teacherSql = 'SELECT
        t.*,
        COALESCE(class_totals.class_count, 0) AS class_count,
        ta.username AS login_username,
        ta.assigned_standard,
        ta.is_active
    FROM teachers t
    LEFT JOIN (
        SELECT teacher_id, COUNT(*) AS class_count
        FROM classes
        WHERE teacher_id IS NOT NULL
        GROUP BY teacher_id
    ) class_totals ON class_totals.teacher_id = t.id
    LEFT JOIN teacher_accounts ta ON ta.teacher_id = t.id';
$teacherParams = [];

if ($search !== '') {
    $teacherSql .= ' WHERE CONCAT_WS(" ", t.first_name, t.last_name) LIKE :term
        OR COALESCE(t.subject, "") LIKE :term
        OR COALESCE(t.email, "") LIKE :term
        OR COALESCE(ta.username, "") LIKE :term
        OR COALESCE(ta.assigned_standard, "") LIKE :term';
    $teacherParams['term'] = like_value($search);
}

$teacherSql .= ' ORDER BY t.id DESC';
$stmt = $pdo->prepare($teacherSql);
$stmt->execute($teacherParams);
$teachers = $stmt->fetchAll();

$overview = $pdo->query('SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN subject IS NOT NULL AND subject <> "" THEN 1 ELSE 0 END) AS with_subject,
        SUM(CASE WHEN email IS NOT NULL AND email <> "" THEN 1 ELSE 0 END) AS with_email
    FROM teachers')->fetch();
$totalTeachers = (int) ($overview['total'] ?? 0);
$teachersWithSubject = (int) ($overview['with_subject'] ?? 0);
$teachersWithEmail = (int) ($overview['with_email'] ?? 0);
$teachersWithLogin = (int) $pdo->query('SELECT COUNT(*) FROM teacher_accounts WHERE is_active = 1')->fetchColumn();
$teachersWithoutClasses = (int) $pdo->query('SELECT COUNT(*)
    FROM teachers t
    LEFT JOIN classes c ON c.teacher_id = t.id
    WHERE c.id IS NULL')->fetchColumn();
$completionScore = $totalTeachers > 0 ? percentage($teachersWithSubject + $teachersWithEmail, $totalTeachers * 2) : 0;
$loginCoverage = $totalTeachers > 0 ? percentage($teachersWithLogin, $totalTeachers) : 0;

$busiestTeacher = $pdo->query('SELECT t.first_name, t.last_name, COUNT(c.id) AS total_classes
    FROM teachers t
    LEFT JOIN classes c ON c.teacher_id = t.id
    GROUP BY t.id, t.first_name, t.last_name
    ORDER BY total_classes DESC, t.last_name ASC, t.first_name ASC
    LIMIT 1')->fetch();
$subjectMix = $pdo->query('SELECT
        CASE
            WHEN subject IS NULL OR subject = "" THEN "Unassigned"
            ELSE subject
        END AS label,
        COUNT(*) AS total
    FROM teachers
    GROUP BY label
    ORDER BY total DESC, label ASC
    LIMIT 3')->fetchAll();

$smartHeadline = 'Build your faculty network';
$smartMessage = 'Add teacher profiles, enable teacher login, and assign one standard so each teacher sees only the right student details.';
if ($totalTeachers > 0) {
    if ($teachersWithLogin < $totalTeachers) {
        $smartHeadline = 'Teacher login setup is pending';
        $smartMessage = ($totalTeachers - $teachersWithLogin) . ' teachers do not have login access yet. Add login IDs and assigned standards to unlock teacher-only views.';
    } elseif ($teachersWithSubject < $totalTeachers) {
        $smartHeadline = 'Subject mapping needs attention';
        $smartMessage = ($totalTeachers - $teachersWithSubject) . ' teacher profiles are missing subject expertise. Completing subjects will improve scheduling clarity.';
    } elseif ($teachersWithEmail < $totalTeachers) {
        $smartHeadline = 'Contact data can be stronger';
        $smartMessage = ($totalTeachers - $teachersWithEmail) . ' teacher profiles do not have email contacts yet. Filling them in keeps communication ready.';
    } elseif ($teachersWithoutClasses > 0) {
        $smartHeadline = 'Teaching capacity is available';
        $smartMessage = $teachersWithoutClasses . ' teachers are not assigned to any class. You can distribute them across open class slots.';
    } else {
        $smartHeadline = 'Faculty data looks balanced';
        $smartMessage = 'Teacher profiles, standards, and class allocations are all present. Teachers can now sign in to view only their assigned standard.';
    }
}

$topSubjectLabel = $subjectMix[0]['label'] ?? 'No subject data yet';
$topSubjectCount = (int) ($subjectMix[0]['total'] ?? 0);
?>
<section class="hero">
    <div>
        <p class="eyebrow">Faculty Operations</p>
        <h1>Teacher Command Center</h1>
        <p class="hero-text">Maintain the faculty directory, create teacher login accounts, and assign one standard so each teacher sees only that standard after sign in.</p>
        <div class="hero-actions">
            <a class="button-link" href="#teacher-form"><?php echo $editTeacher ? 'Update Teacher' : 'Add Teacher'; ?></a>
            <a class="button-secondary-link" href="<?php echo h(page_url('teacher_attendance')); ?>">Open Attendance</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('classes')); ?>">Manage Classes</a>
        </div>
    </div>
    <div class="hero-panel">
        <span class="mini-label">Smart briefing</span>
        <strong><?php echo h($smartHeadline); ?></strong>
        <p><?php echo h($smartMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $completionScore); ?>% profile completion</span>
            <span class="chip"><?php echo h((string) $loginCoverage); ?>% login coverage</span>
            <span class="chip"><?php echo h($topSubjectLabel); ?> leads<?php echo $topSubjectCount > 0 ? ' with ' . h((string) $topSubjectCount) : ''; ?></span>
        </div>
    </div>
</section>

<div class="stats-grid">
    <article class="stat-card">
        <span class="stat-label">Teachers</span>
        <strong><?php echo h((string) $totalTeachers); ?></strong>
        <small class="muted">Faculty profiles available in the system.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Teacher Logins</span>
        <strong><?php echo h((string) $teachersWithLogin); ?></strong>
        <small class="muted"><?php echo h((string) $loginCoverage); ?>% of faculty profiles are ready for teacher sign-in.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Subjects Set</span>
        <strong><?php echo h((string) $teachersWithSubject); ?></strong>
        <small class="muted"><?php echo h((string) percentage($teachersWithSubject, max($totalTeachers, 1))); ?>% of teachers have subject expertise mapped.</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Available Capacity</span>
        <strong><?php echo h((string) $teachersWithoutClasses); ?></strong>
        <small class="muted">Teachers not yet assigned to classes.</small>
    </article>
</div>

<div class="split-layout">
    <section class="card elevated" id="teacher-form">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Faculty Form</p>
                <h2><?php echo $editTeacher ? 'Update teacher profile' : 'Add a new teacher'; ?></h2>
            </div>
            <?php if ($editTeacher): ?>
                <a class="ghost-link" href="<?php echo h(page_url('teachers', ['q' => $search])); ?>">Cancel edit</a>
            <?php endif; ?>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <p class="login-alert"><?php echo h($errorMessage); ?></p>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="<?php echo $editTeacher ? 'update' : 'add'; ?>">
            <input type="hidden" name="account_id" value="<?php echo h((string) ($formData['account_id'] ?? 0)); ?>">
            <?php if ($editTeacher): ?>
                <input type="hidden" name="id" value="<?php echo h((string) $editTeacher['id']); ?>">
            <?php endif; ?>

            <label class="field-group">
                <span>First name</span>
                <input name="first_name" value="<?php echo h($formData['first_name'] ?? ''); ?>" placeholder="Enter first name" required>
            </label>
            <label class="field-group">
                <span>Last name</span>
                <input name="last_name" value="<?php echo h($formData['last_name'] ?? ''); ?>" placeholder="Enter last name" required>
            </label>
            <label class="field-group">
                <span>Subject</span>
                <input name="subject" value="<?php echo h($formData['subject'] ?? ''); ?>" placeholder="Subject expertise">
            </label>
            <label class="field-group">
                <span>Email</span>
                <input name="email" type="email" value="<?php echo h($formData['email'] ?? ''); ?>" placeholder="teacher@example.com">
            </label>

            <label class="toggle-row field-span-full">
                <input type="checkbox" name="account_enabled" value="1" <?php echo ($formData['account_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable teacher login and lock this teacher to one standard.</span>
            </label>

            <label class="field-group">
                <span>Assigned standard</span>
                <select name="assigned_standard">
                    <option value="">Select standard</option>
                    <?php foreach ($standards as $standard): ?>
                        <option value="<?php echo h($standard); ?>" <?php echo (($formData['assigned_standard'] ?? '') === $standard) ? 'selected' : ''; ?>>
                            <?php echo h($standard); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field-group">
                <span>Teacher Login ID</span>
                <input name="login_username" value="<?php echo h($formData['login_username'] ?? ''); ?>" placeholder="Set teacher login ID">
            </label>
            <label class="field-group field-span-full">
                <span>Password</span>
                <input name="login_password" type="password" placeholder="<?php echo $editTeacher && !empty($formData['account_id']) ? 'Leave blank to keep the current password' : 'Set login password'; ?>">
            </label>

            <div class="login-note field-span-full">
                <p class="eyebrow">Teacher Scope</p>
                <p>If teacher login is enabled, that teacher will only see students and student attendance for the selected standard.</p>
                <p>Uncheck the toggle above and save to remove teacher login access.</p>
            </div>

            <button type="submit"><?php echo $editTeacher ? 'Save Changes' : 'Add Teacher'; ?></button>
        </form>
    </section>

    <section class="card insight-card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Smart Insights</p>
                <h2>Faculty health</h2>
            </div>
        </div>

        <div class="insight-list">
            <div class="insight-item">
                <span class="mini-label">Workload leader</span>
                <strong><?php echo h($busiestTeacher ? full_name($busiestTeacher['first_name'], $busiestTeacher['last_name']) : 'No teacher yet'); ?></strong>
                <p><?php echo h($busiestTeacher && (int) $busiestTeacher['total_classes'] > 0 ? $busiestTeacher['total_classes'] . ' classes are currently linked to this teacher.' : 'Teacher workload will appear here once classes are assigned.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Teacher portal</span>
                <strong><?php echo h((string) $teachersWithLogin); ?> login-ready profiles</strong>
                <p><?php echo h($teachersWithLogin > 0 ? 'Teachers with login can access only their assigned standard after sign in.' : 'Enable teacher login on a faculty profile to unlock standard-wise teacher access.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Top subject</span>
                <strong><?php echo h($topSubjectLabel); ?></strong>
                <p><?php echo h($topSubjectCount > 0 ? $topSubjectCount . ' teacher profiles currently align to this subject area.' : 'Subject distribution will appear here as soon as teachers are added.'); ?></p>
            </div>
            <div class="insight-item">
                <span class="mini-label">Live filter</span>
                <strong><?php echo h((string) count($teachers)); ?> visible records</strong>
                <p><?php echo h($search !== '' ? 'Filtered by "' . $search . '". Reset the search to view the complete directory.' : 'Search the teacher list by name, subject, email, login ID, or standard in one step.'); ?></p>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="section-heading stack-mobile">
        <div>
            <p class="eyebrow">Teacher List</p>
            <h2>Digital faculty directory</h2>
        </div>

        <a class="ghost-link" href="<?php echo h(page_url('teacher_attendance')); ?>">Teacher Attendance</a>

        <form method="get" class="toolbar">
            <input type="hidden" name="page" value="teachers">
            <input name="q" value="<?php echo h($search); ?>" placeholder="Search name, subject, email, login ID, or standard">
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="button-secondary-link" href="<?php echo h(page_url('teachers')); ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table class="table table-modern">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Teacher</th>
                    <th>Subject</th>
                    <th>Standard</th>
                    <th>Portal Access</th>
                    <th>Email</th>
                    <th>Classes</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
                <tr>
                    <td data-label="ID">#<?php echo h((string) $t['id']); ?></td>
                    <td data-label="Teacher">
                        <div class="person-cell">
                            <strong><?php echo h(full_name($t['first_name'], $t['last_name'])); ?></strong>
                            <small class="muted">Ready for class planning</small>
                        </div>
                    </td>
                    <td data-label="Subject"><span class="table-pill neutral"><?php echo h($t['subject'] ?: 'Pending'); ?></span></td>
                    <td data-label="Standard"><span class="table-pill"><?php echo h($t['assigned_standard'] ?: '-'); ?></span></td>
                    <td data-label="Portal Access">
                        <div class="person-cell">
                            <strong><?php echo h($t['login_username'] ?: 'Not enabled'); ?></strong>
                            <small class="muted"><?php echo h($t['login_username'] ? 'Teacher login active' : 'Admin-only profile'); ?></small>
                        </div>
                    </td>
                    <td data-label="Email"><?php echo h($t['email'] ?: '-'); ?></td>
                    <td data-label="Classes"><span class="table-pill"><?php echo h((string) $t['class_count']); ?> linked</span></td>
                    <td data-label="Created"><?php echo h(format_date($t['created_at'])); ?></td>
                    <td data-label="Actions" class="actions">
                        <a class="button-inline" href="<?php echo h(page_url('teachers', ['edit' => $t['id'], 'q' => $search])); ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this teacher?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo h((string) $t['id']); ?>">
                            <button type="submit" class="secondary">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($teachers) === 0): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <strong>No teacher records found.</strong>
                            <p><?php echo h($search !== '' ? 'Try a different search term or reset the filter.' : 'Add your first teacher to build the faculty directory.'); ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
