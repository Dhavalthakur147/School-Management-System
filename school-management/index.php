<?php
require __DIR__ . '/db.php';

app_session_start();

if (($_GET['logout'] ?? '') === '1') {
    logout_user();
    header('Location: login.php?logged_out=1');
    exit;
}

require_login();

$currentUser = current_auth_user();
$currentRole = current_auth_role();
$page = $_GET['page'] ?? 'dashboard';
$allowedPages = allowed_pages_for_current_user();

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

$assetVersion = '20260420-4';
$brandSubline = teacher_is_logged_in()
    ? 'Teacher access for ' . (teacher_assigned_standard() ?? 'assigned') . ' students and attendance'
    : 'Smart student, teacher, class, and enrollment management';

$navItems = [
    'dashboard' => 'Dashboard',
    'students' => 'Students',
];

if (teacher_is_logged_in()) {
    $navItems['student_attendance'] = 'Attendance';
}

if (admin_is_logged_in()) {
    $navItems['teachers'] = 'Teachers';
    $navItems['classes'] = 'Classes';
    $navItems['enrollments'] = 'Enrollments';
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>M M Maheta High School</title>
    <link rel="icon" type="image/svg+xml" href="assets/mmmhs-icon.svg?v=<?php echo h($assetVersion); ?>">
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo h($assetVersion); ?>">
</head>
<body>
    <header class="topbar">
        <div class="brand-lockup">
            <div class="brand-mark">
                <img
                    src="assets/mmmhs-icon.svg?v=<?php echo h($assetVersion); ?>"
                    alt="M M Maheta High School icon"
                    width="34"
                    height="34"
                    style="display:block;width:34px;height:34px;"
                >
            </div>
            <div>
                <p class="eyebrow">Academic Records Portal</p>
                <div class="brand">M M Maheta High School</div>
                <p class="brand-subline"><?php echo h($brandSubline); ?></p>
            </div>
        </div>

        <nav class="nav">
            <?php foreach ($navItems as $navPage => $navLabel): ?>
                <a href="<?php echo h(page_url($navPage)); ?>" class="<?php echo $page === $navPage ? 'active' : ''; ?>"><?php echo h($navLabel); ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-actions">
            <div class="admin-badge">
                <span class="eyebrow"><?php echo h($currentRole === 'teacher' ? 'Teacher Access' : 'Admin Access'); ?></span>
                <strong><?php echo h($currentUser['full_name'] ?? 'User'); ?></strong>
                <?php if ($currentRole === 'teacher' && teacher_assigned_standard() !== null): ?>
                    <small><?php echo h(teacher_assigned_standard()); ?> standard</small>
                <?php endif; ?>
            </div>
            <a class="logout-link" href="index.php?logout=1">Logout</a>
        </div>
    </header>

    <main class="container">
        <?php include __DIR__ . '/pages/' . $page . '.php'; ?>
    </main>
</body>
</html>
