<?php
$pdo = db();

if (teacher_is_logged_in()) {
    $teacherUser = current_teacher_user();
    $selectedStandard = normalize_standard(teacher_assigned_standard() ?? '') ?? school_standards()[0];
    $today = date('Y-m-d');

    $teacherProfileStmt = $pdo->prepare('SELECT
            t.first_name,
            t.last_name,
            t.subject,
            t.email,
            COALESCE(class_totals.total_classes, 0) AS total_classes
        FROM teachers t
        LEFT JOIN (
            SELECT teacher_id, COUNT(*) AS total_classes
            FROM classes
            WHERE teacher_id IS NOT NULL
            GROUP BY teacher_id
        ) class_totals ON class_totals.teacher_id = t.id
        WHERE t.id = ?
        LIMIT 1');
    $teacherProfileStmt->execute([(int) ($teacherUser['id'] ?? 0)]);
    $teacherProfile = $teacherProfileStmt->fetch() ?: [
        'first_name' => $teacherUser['full_name'] ?? 'Teacher',
        'last_name' => '',
        'subject' => null,
        'email' => null,
        'total_classes' => 0,
    ];

    $studentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE grade = ?');
    $studentCountStmt->execute([$selectedStandard]);
    $studentCount = (int) $studentCountStmt->fetchColumn();

    $studentsWithClassesStmt = $pdo->prepare('SELECT COUNT(DISTINCT s.id)
        FROM students s
        INNER JOIN enrollments e ON e.student_id = s.id
        WHERE s.grade = ?');
    $studentsWithClassesStmt->execute([$selectedStandard]);
    $studentsWithClasses = (int) $studentsWithClassesStmt->fetchColumn();

    $statusCounts = array_fill_keys(attendance_statuses(), 0);
    $attendanceSummaryStmt = $pdo->prepare('SELECT sa.status, COUNT(*) AS total
        FROM student_attendance sa
        INNER JOIN students s ON s.id = sa.student_id
        WHERE sa.attendance_date = ? AND s.grade = ?
        GROUP BY sa.status');
    $attendanceSummaryStmt->execute([$today, $selectedStandard]);
    foreach ($attendanceSummaryStmt->fetchAll() as $row) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int) $row['total'];
        }
    }

    $recordedToday = array_sum($statusCounts);
    $pendingToday = max($studentCount - $recordedToday, 0);

    $recentStudentsStmt = $pdo->prepare('SELECT first_name, last_name, dob, created_at
        FROM students
        WHERE grade = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 5');
    $recentStudentsStmt->execute([$selectedStandard]);
    $recentStudents = $recentStudentsStmt->fetchAll();

    $recentAttendanceStmt = $pdo->prepare('SELECT
            sa.attendance_date,
            sa.status,
            s.first_name,
            s.last_name
        FROM student_attendance sa
        INNER JOIN students s ON s.id = sa.student_id
        WHERE s.grade = ?
        ORDER BY sa.attendance_date DESC, sa.id DESC
        LIMIT 5');
    $recentAttendanceStmt->execute([$selectedStandard]);
    $recentAttendance = $recentAttendanceStmt->fetchAll();

    $briefingHeadline = 'Your ' . $selectedStandard . ' dashboard is ready';
    $briefingMessage = 'Teacher access is limited to one standard, so this dashboard only shows ' . $selectedStandard . ' students and attendance.';
    if ($studentCount === 0) {
        $briefingHeadline = 'No students found in ' . $selectedStandard;
        $briefingMessage = 'Ask the admin to add students to ' . $selectedStandard . ' so roster and attendance details appear here.';
    } elseif ($pendingToday > 0) {
        $briefingHeadline = 'Attendance needs review';
        $briefingMessage = $pendingToday . ' students in ' . $selectedStandard . ' still do not have attendance marked for ' . format_date($today) . '.';
    } else {
        $briefingHeadline = 'Attendance is complete for today';
        $briefingMessage = 'All visible students in ' . $selectedStandard . ' already have attendance recorded for ' . format_date($today) . '.';
    }
    ?>
    <section class="hero hero-dashboard">
        <div>
            <p class="eyebrow">Teacher Portal</p>
            <h1><?php echo h($selectedStandard); ?> Standard Dashboard</h1>
            <p class="hero-text">This teacher account can view only the assigned standard roster, student attendance, and class-readiness details for daily work.</p>
            <div class="hero-actions">
                <a class="button-link" href="<?php echo h(page_url('students', ['standard' => $selectedStandard])); ?>">Open Students</a>
                <a class="button-secondary-link" href="<?php echo h(page_url('student_attendance', ['standard' => $selectedStandard])); ?>">Open Attendance</a>
            </div>
        </div>
        <div class="hero-panel">
            <div class="school-badge">
                <img src="assets/mmmhs-icon.svg" alt="M M Maheta High School crest">
                <div>
                    <span class="mini-label">Teacher Profile</span>
                    <strong><?php echo h(full_name($teacherProfile['first_name'], $teacherProfile['last_name'])); ?></strong>
                    <p>
                        <?php echo h($teacherProfile['subject'] ?: 'Subject pending'); ?>
                        <?php if (!empty($teacherProfile['email'])): ?>
                            <?php echo ' | ' . h($teacherProfile['email']); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <span class="mini-label">Assigned scope</span>
            <strong><?php echo h($briefingHeadline); ?></strong>
            <p><?php echo h($briefingMessage); ?></p>
            <div class="chip-row">
                <span class="chip"><?php echo h($selectedStandard); ?> standard</span>
                <span class="chip"><?php echo h((string) $studentCount); ?> students</span>
                <span class="chip"><?php echo h((string) $teacherProfile['total_classes']); ?> classes linked</span>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Teacher Services</p>
                <h2>Your active modules</h2>
            </div>
        </div>

        <div class="service-grid">
            <a class="service-card" href="<?php echo h(page_url('students', ['standard' => $selectedStandard])); ?>">
                <span class="service-tag">Service 01</span>
                <strong><?php echo h($selectedStandard); ?> Student List</strong>
                <p>View the separate roster for your assigned standard only.</p>
            </a>
            <a class="service-card" href="<?php echo h(page_url('student_attendance', ['standard' => $selectedStandard])); ?>">
                <span class="service-tag">Service 02</span>
                <strong>Student Attendance</strong>
                <p>Mark and review attendance only for <?php echo h($selectedStandard); ?> students.</p>
            </a>
        </div>
    </section>

    <div class="stats-grid">
        <article class="stat-card">
            <span class="stat-label">Assigned Standard</span>
            <strong><?php echo h($selectedStandard); ?></strong>
            <small class="muted">Teacher access is locked to one standard.</small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Students</span>
            <strong><?php echo h((string) $studentCount); ?></strong>
            <small class="muted">Visible students in your assigned roster.</small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Present Today</span>
            <strong><?php echo h((string) $statusCounts['Present']); ?></strong>
            <small class="muted">Students marked present for <?php echo h(format_date($today)); ?>.</small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Pending Today</span>
            <strong><?php echo h((string) $pendingToday); ?></strong>
            <small class="muted">Students still waiting for attendance entry.</small>
        </article>
    </div>

    <div class="dashboard-grid">
        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Teacher Details</p>
                    <h2>Your profile summary</h2>
                </div>
            </div>

            <div class="data-list">
                <div class="list-row">
                    <div>
                        <strong><?php echo h(full_name($teacherProfile['first_name'], $teacherProfile['last_name'])); ?></strong>
                        <p><?php echo h($teacherProfile['subject'] ?: 'Subject pending'); ?></p>
                    </div>
                    <span class="table-pill"><?php echo h((string) $teacherProfile['total_classes']); ?> classes</span>
                </div>
                <div class="list-row">
                    <div>
                        <strong>Assigned standard</strong>
                        <p>Only this roster is visible in your account.</p>
                    </div>
                    <span class="table-pill neutral"><?php echo h($selectedStandard); ?></span>
                </div>
                <div class="list-row">
                    <div>
                        <strong>Contact email</strong>
                        <p>Saved in the faculty profile.</p>
                    </div>
                    <span class="table-pill neutral"><?php echo h($teacherProfile['email'] ?: 'No email'); ?></span>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Recent Students</p>
                    <h2><?php echo h($selectedStandard); ?> roster updates</h2>
                </div>
            </div>

            <div class="data-list">
                <?php foreach ($recentStudents as $student): ?>
                    <div class="list-row">
                        <div>
                            <strong><?php echo h(full_name($student['first_name'], $student['last_name'])); ?></strong>
                            <p><?php echo h($student['dob'] ? 'DOB ' . format_date($student['dob']) : 'DOB pending'); ?></p>
                        </div>
                        <span class="table-pill"><?php echo h(format_date($student['created_at'])); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (count($recentStudents) === 0): ?>
                    <div class="empty-state">
                        <strong>No students added yet.</strong>
                        <p>Students in your assigned standard will appear here once the admin adds them.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Attendance History</p>
                    <h2>Latest recorded entries</h2>
                </div>
            </div>

            <div class="data-list">
                <?php foreach ($recentAttendance as $entry): ?>
                    <div class="list-row">
                        <div>
                            <strong><?php echo h(full_name($entry['first_name'], $entry['last_name'])); ?></strong>
                            <p><?php echo h(format_date($entry['attendance_date'])); ?></p>
                        </div>
                        <span class="table-pill <?php echo h(attendance_status_class($entry['status'])); ?>"><?php echo h($entry['status']); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (count($recentAttendance) === 0): ?>
                    <div class="empty-state">
                        <strong>No attendance history yet.</strong>
                        <p>Saved attendance records for <?php echo h($selectedStandard); ?> will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card highlight-band">
        <div class="highlight-copy">
            <p class="eyebrow">Daily Focus</p>
            <h2>What needs attention today</h2>
            <p>This teacher view keeps the school-wide system hidden and shows only your current standard workload.</p>
        </div>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $studentsWithClasses); ?> students linked to classes</span>
            <span class="chip"><?php echo h((string) $statusCounts['Absent']); ?> absent today</span>
            <span class="chip"><?php echo h((string) $statusCounts['Present']); ?> present today</span>
            <span class="chip"><?php echo h((string) $pendingToday); ?> pending attendance</span>
        </div>
    </section>
    <?php
    return;
}

$counts = [
    'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'teachers' => (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn(),
    'classes' => (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
    'enrollments' => (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
];

$studentsWithoutClasses = (int) $pdo->query('SELECT COUNT(*)
    FROM students s
    LEFT JOIN enrollments e ON e.student_id = s.id
    WHERE e.id IS NULL')->fetchColumn();
$classesWithoutTeachers = (int) $pdo->query('SELECT COUNT(*)
    FROM classes
    WHERE teacher_id IS NULL')->fetchColumn();
$teachersWithoutClasses = (int) $pdo->query('SELECT COUNT(*)
    FROM teachers t
    LEFT JOIN classes c ON c.teacher_id = t.id
    WHERE c.id IS NULL')->fetchColumn();

$topGrade = $pdo->query('SELECT
        CASE
            WHEN grade IS NULL OR grade = "" THEN "Unassigned"
            ELSE grade
        END AS label,
        COUNT(*) AS total
    FROM students
    GROUP BY label
    ORDER BY total DESC, label ASC
    LIMIT 1')->fetch();
$busiestTeacher = $pdo->query('SELECT t.first_name, t.last_name, COUNT(c.id) AS total_classes
    FROM teachers t
    LEFT JOIN classes c ON c.teacher_id = t.id
    GROUP BY t.id, t.first_name, t.last_name
    ORDER BY total_classes DESC, t.last_name ASC, t.first_name ASC
    LIMIT 1')->fetch();

$recentStudents = $pdo->query('SELECT id, first_name, last_name, grade, created_at
    FROM students
    ORDER BY created_at DESC, id DESC
    LIMIT 5')->fetchAll();
$recentTeachers = $pdo->query('SELECT id, first_name, last_name, subject, email, created_at
    FROM teachers
    ORDER BY created_at DESC, id DESC
    LIMIT 5')->fetchAll();
$teacherLoad = $pdo->query('SELECT t.first_name, t.last_name, COUNT(c.id) AS total_classes
    FROM teachers t
    LEFT JOIN classes c ON c.teacher_id = t.id
    GROUP BY t.id, t.first_name, t.last_name
    ORDER BY total_classes DESC, t.last_name ASC, t.first_name ASC
    LIMIT 4')->fetchAll();
$classDemand = $pdo->query('SELECT c.name, c.room, COUNT(e.id) AS total_students
    FROM classes c
    LEFT JOIN enrollments e ON e.class_id = c.id
    GROUP BY c.id, c.name, c.room
    ORDER BY total_students DESC, c.name ASC
    LIMIT 4')->fetchAll();

$briefingHeadline = 'Your digital campus is ready';
$briefingMessage = 'Use the live modules below to manage M M Maheta High School students, teachers, classes, and enrollments from one dashboard.';
if ($counts['students'] === 0 && $counts['teachers'] === 0) {
    $briefingHeadline = 'Start building the school directory';
    $briefingMessage = 'Add student and teacher records for M M Maheta High School first, then connect them through classes and enrollments.';
} elseif ($studentsWithoutClasses > 0) {
    $briefingHeadline = 'Student enrollments need attention';
    $briefingMessage = $studentsWithoutClasses . ' students are active in the system but not assigned to classes yet.';
} elseif ($classesWithoutTeachers > 0) {
    $briefingHeadline = 'Some classes still need teachers';
    $briefingMessage = $classesWithoutTeachers . ' classes are open without teacher assignments.';
} elseif ($teachersWithoutClasses > 0) {
    $briefingHeadline = 'Teaching capacity is available';
    $briefingMessage = $teachersWithoutClasses . ' teachers are ready to be assigned across classes.';
}
?>
<section class="hero hero-dashboard">
    <div>
        <p class="eyebrow">M M Maheta High School</p>
        <h1>School Information Portal</h1>
        <p class="hero-text">Browse and manage school records through a cleaner public-portal style dashboard inspired by education directory websites.</p>
        <div class="hero-actions">
            <a class="button-link" href="<?php echo h(page_url('students')); ?>">Open Students</a>
            <a class="button-secondary-link" href="<?php echo h(page_url('teachers')); ?>">Open Teachers</a>
        </div>
    </div>
    <div class="hero-panel">
        <div class="school-badge">
            <img src="assets/mmmhs-icon.svg" alt="M M Maheta High School crest">
            <div>
                <span class="mini-label">School Identity</span>
                <strong>M M Maheta High School</strong>
                <p>Centralized academic administration for a cleaner digital campus workflow.</p>
            </div>
        </div>
        <span class="mini-label">Smart briefing</span>
        <strong><?php echo h($briefingHeadline); ?></strong>
        <p><?php echo h($briefingMessage); ?></p>
        <div class="chip-row">
            <span class="chip"><?php echo h((string) $counts['students']); ?> students</span>
            <span class="chip"><?php echo h((string) $counts['teachers']); ?> teachers</span>
            <span class="chip"><?php echo h((string) $counts['classes']); ?> classes</span>
        </div>
    </div>
</section>

<section class="card">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Portal Services</p>
            <h2>Search and explore modules</h2>
        </div>
    </div>

    <div class="service-grid">
        <a class="service-card" href="<?php echo h(page_url('students')); ?>">
            <span class="service-tag">Search 01</span>
            <strong>Student Directory</strong>
            <p>Review student profiles, standard details, and active class links in one place.</p>
        </a>
        <a class="service-card" href="<?php echo h(page_url('teachers')); ?>">
            <span class="service-tag">Search 02</span>
            <strong>Teacher Directory</strong>
            <p>Track faculty records, subjects, email details, and class assignments.</p>
        </a>
        <a class="service-card" href="<?php echo h(page_url('classes')); ?>">
            <span class="service-tag">Search 03</span>
            <strong>Class Lookup</strong>
            <p>View class rooms, teacher mapping, and the structure of academic groups.</p>
        </a>
        <a class="service-card" href="<?php echo h(page_url('enrollments')); ?>">
            <span class="service-tag">Search 04</span>
            <strong>Enrollment Records</strong>
            <p>Check which students are linked to classes and spot pending placement gaps.</p>
        </a>
        <a class="service-card" href="<?php echo h(page_url('student_attendance')); ?>">
            <span class="service-tag">Search 05</span>
            <strong>Student Attendance</strong>
            <p>Mark standard-wise student attendance and review daily attendance history.</p>
        </a>
        <a class="service-card" href="<?php echo h(page_url('teacher_attendance')); ?>">
            <span class="service-tag">Search 06</span>
            <strong>Teacher Attendance</strong>
            <p>Track faculty attendance day-wise from one combined attendance sheet.</p>
        </a>
    </div>
</section>

<div class="stats-grid">
    <a class="stat-card stat-link" href="<?php echo h(page_url('students')); ?>">
        <span class="stat-label">Students</span>
        <strong><?php echo h((string) $counts['students']); ?></strong>
        <small class="muted">Student profiles in the live roster.</small>
    </a>
    <a class="stat-card stat-link" href="<?php echo h(page_url('teachers')); ?>">
        <span class="stat-label">Teachers</span>
        <strong><?php echo h((string) $counts['teachers']); ?></strong>
        <small class="muted">Faculty members tracked in the directory.</small>
    </a>
    <a class="stat-card stat-link" href="<?php echo h(page_url('classes')); ?>">
        <span class="stat-label">Classes</span>
        <strong><?php echo h((string) $counts['classes']); ?></strong>
        <small class="muted">Academic groups with room and teacher mapping.</small>
    </a>
    <a class="stat-card stat-link" href="<?php echo h(page_url('enrollments')); ?>">
        <span class="stat-label">Enrollments</span>
        <strong><?php echo h((string) $counts['enrollments']); ?></strong>
        <small class="muted">Student-to-class links already active.</small>
    </a>
</div>

<div class="dashboard-grid">
    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Recent Students</p>
                <h2>Newest roster entries</h2>
            </div>
        </div>

        <div class="data-list">
            <?php foreach ($recentStudents as $student): ?>
                <div class="list-row">
                    <div>
                        <strong><?php echo h(full_name($student['first_name'], $student['last_name'])); ?></strong>
                        <p><?php echo h($student['grade'] ?: 'Standard pending'); ?></p>
                    </div>
                    <span class="table-pill"><?php echo h(format_date($student['created_at'])); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (count($recentStudents) === 0): ?>
                <div class="empty-state">
                    <strong>No students added yet.</strong>
                    <p>Create student records to see them appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Recent Teachers</p>
                <h2>Newest faculty entries</h2>
            </div>
        </div>

        <div class="data-list">
            <?php foreach ($recentTeachers as $teacher): ?>
                <div class="list-row">
                    <div>
                        <strong><?php echo h(full_name($teacher['first_name'], $teacher['last_name'])); ?></strong>
                        <p><?php echo h($teacher['subject'] ?: 'Subject pending'); ?></p>
                    </div>
                    <span class="table-pill neutral"><?php echo h($teacher['email'] ?: 'No email'); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (count($recentTeachers) === 0): ?>
                <div class="empty-state">
                    <strong>No teachers added yet.</strong>
                    <p>Create faculty records to see them appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Teacher Load</p>
                <h2>Current class allocation</h2>
            </div>
        </div>

        <div class="data-list">
            <?php foreach ($teacherLoad as $teacher): ?>
                <div class="list-row">
                    <div>
                        <strong><?php echo h(full_name($teacher['first_name'], $teacher['last_name'])); ?></strong>
                        <p>Faculty workload snapshot</p>
                    </div>
                    <span class="table-pill"><?php echo h((string) $teacher['total_classes']); ?> classes</span>
                </div>
            <?php endforeach; ?>
            <?php if (count($teacherLoad) === 0): ?>
                <div class="empty-state">
                    <strong>No teacher load data yet.</strong>
                    <p>Assign teachers to classes to monitor workload here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Class Demand</p>
                <h2>Highest enrollment activity</h2>
            </div>
        </div>

        <div class="data-list">
            <?php foreach ($classDemand as $class): ?>
                <div class="list-row">
                    <div>
                        <strong><?php echo h($class['name']); ?></strong>
                        <p><?php echo h($class['room'] ?: 'Room pending'); ?></p>
                    </div>
                    <span class="table-pill"><?php echo h((string) $class['total_students']); ?> students</span>
                </div>
            <?php endforeach; ?>
            <?php if (count($classDemand) === 0): ?>
                <div class="empty-state">
                    <strong>No class demand data yet.</strong>
                    <p>Create classes and enroll students to unlock this view.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card highlight-band">
    <div class="highlight-copy">
        <p class="eyebrow">Focus Areas</p>
        <h2>Where the system needs attention</h2>
        <p>These signals help you keep the platform structured even as the school data grows.</p>
    </div>
    <div class="chip-row">
        <span class="chip"><?php echo h((string) $studentsWithoutClasses); ?> students without classes</span>
        <span class="chip"><?php echo h((string) $classesWithoutTeachers); ?> classes without teachers</span>
        <span class="chip"><?php echo h((string) $teachersWithoutClasses); ?> teachers with free capacity</span>
        <span class="chip"><?php echo h(($topGrade['label'] ?? 'No standard data') . (($topGrade && (int) $topGrade['total'] > 0) ? ' top standard' : '')); ?></span>
        <span class="chip"><?php echo h($busiestTeacher ? full_name($busiestTeacher['first_name'], $busiestTeacher['last_name']) . ' busiest' : 'No teacher load yet'); ?></span>
    </div>
</section>
