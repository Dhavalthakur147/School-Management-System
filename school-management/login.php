<?php
require __DIR__ . '/db.php';

app_session_start();

if (current_auth_user() !== null) {
    header('Location: index.php');
    exit;
}

$assetVersion = '20260420-4';
$errorMessage = '';
$infoMessage = '';
$loginRole = $_POST['login_role'] ?? 'admin';
$loginIdValue = '';

if (!in_array($loginRole, ['admin', 'teacher'], true)) {
    $loginRole = 'admin';
}

if (($_GET['logged_out'] ?? '') === '1') {
    $infoMessage = 'You have been logged out successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $loginIdValue = $username;

    $loginOk = $loginRole === 'teacher'
        ? login_teacher($username, $password)
        : login_admin($username, $password);

    if ($loginOk) {
        header('Location: index.php');
        exit;
    }

    $errorMessage = $loginRole === 'teacher'
        ? 'Invalid teacher ID or password.'
        : 'Invalid admin username or password.';
}

$defaultAdmin = default_admin_credentials();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | M M Maheta High School</title>
    <link rel="icon" type="image/svg+xml" href="assets/mmmhs-icon.svg?v=<?php echo h($assetVersion); ?>">
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo h($assetVersion); ?>">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand">
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
                    <p class="eyebrow">School Access</p>
                    <h1>M M Maheta High School</h1>
                    <p class="login-copy">Admin can manage the full system. Teachers can sign in and view only their assigned standard details and attendance.</p>
                </div>
            </div>

            <?php if ($infoMessage !== ''): ?>
                <p class="badge"><?php echo h($infoMessage); ?></p>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <p class="login-alert"><?php echo h($errorMessage); ?></p>
            <?php endif; ?>

            <form method="post" class="login-form">
                <div class="role-switch" role="radiogroup" aria-label="Login role">
                    <label class="role-option" for="login-role-admin">
                        <input id="login-role-admin" type="radio" name="login_role" value="admin" <?php echo $loginRole === 'admin' ? 'checked' : ''; ?>>
                        <span>Admin Login</span>
                    </label>
                    <label class="role-option" for="login-role-teacher">
                        <input id="login-role-teacher" type="radio" name="login_role" value="teacher" <?php echo $loginRole === 'teacher' ? 'checked' : ''; ?>>
                        <span>Teacher Login</span>
                    </label>
                </div>

                <label class="field-group">
                    <span><?php echo $loginRole === 'teacher' ? 'Teacher Login ID' : 'Username'; ?></span>
                    <input
                        name="username"
                        value="<?php echo h($loginIdValue); ?>"
                        placeholder="<?php echo $loginRole === 'teacher' ? 'Enter admin provided teacher ID' : 'Enter username'; ?>"
                        required
                    >
                </label>

                <label class="field-group">
                    <span>Password</span>
                    <input name="password" type="password" placeholder="Enter password" required>
                </label>

                <button type="submit">Sign In</button>
            </form>

            <div class="login-note">
                <p class="eyebrow">Fresh Setup</p>
                <p>Default admin login:</p>
                <p><strong>Username:</strong> <?php echo h($defaultAdmin['username']); ?></p>
                <p><strong>Password:</strong> <?php echo h($defaultAdmin['password']); ?></p>
                <p>Teacher Login ID, password, and assigned standard are created by admin from the Teachers page.</p>
            </div>
        </section>
    </main>
</body>
</html>
