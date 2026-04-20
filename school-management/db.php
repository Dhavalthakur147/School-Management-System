<?php
// MySQL-backed storage.
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = '127.0.0.1';
        $db   = 'school_management';
        $user = 'root';
        $pass = '';

        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        ensure_runtime_schema($pdo);
    }
    return $pdo;
}

function ensure_runtime_schema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            last_login_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS teacher_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL UNIQUE,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            assigned_standard VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_teacher_accounts_teacher
                FOREIGN KEY (teacher_id) REFERENCES teachers(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS student_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM("Present","Absent") NOT NULL DEFAULT "Present",
            remarks VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_attendance (student_id, attendance_date),
            CONSTRAINT fk_student_attendance_student
                FOREIGN KEY (student_id) REFERENCES students(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS teacher_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM("Present","Absent") NOT NULL DEFAULT "Present",
            remarks VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_teacher_attendance (teacher_id, attendance_date),
            CONSTRAINT fk_teacher_attendance_teacher
                FOREIGN KEY (teacher_id) REFERENCES teachers(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    ensure_attendance_status_schema($pdo);
    ensure_default_admin($pdo);
    $initialized = true;
}

function ensure_attendance_status_schema(PDO $pdo): void {
    migrate_attendance_status_column($pdo, 'student_attendance');
    migrate_attendance_status_column($pdo, 'teacher_attendance');
}

function migrate_attendance_status_column(PDO $pdo, string $tableName): void {
    if (!in_array($tableName, ['student_attendance', 'teacher_attendance'], true)) {
        return;
    }

    $columnStmt = $pdo->query("SHOW COLUMNS FROM {$tableName} LIKE 'status'");
    $column = $columnStmt ? $columnStmt->fetch() : null;
    if (!$column) {
        return;
    }

    $type = strtolower((string) ($column['Type'] ?? ''));
    if (str_contains($type, "enum('present','absent')")) {
        return;
    }

    $pdo->exec("UPDATE {$tableName}
        SET status = 'Absent'
        WHERE status IS NULL OR status NOT IN ('Present', 'Absent')");
    $pdo->exec("ALTER TABLE {$tableName}
        MODIFY COLUMN status ENUM('Present','Absent') NOT NULL DEFAULT 'Present'");
}

function ensure_default_admin(PDO $pdo): void {
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($adminCount > 0) {
        return;
    }

    $credentials = default_admin_credentials();
    $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name) VALUES (?, ?, ?)');
    $stmt->execute([
        $credentials['username'],
        password_hash($credentials['password'], PASSWORD_DEFAULT),
        'School Administrator',
    ]);
}

function default_admin_credentials(): array {
    return [
        'username' => 'admin',
        'password' => 'admin123',
    ];
}

function app_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('mmmhs_auth');
    session_start([
        'cookie_httponly' => true,
        'use_strict_mode' => true,
    ]);
}

function current_auth_user(): ?array {
    app_session_start();
    $user = $_SESSION['auth_user'] ?? null;

    return is_array($user) ? $user : null;
}

function current_auth_role(): ?string {
    $user = current_auth_user();
    return $user['role'] ?? null;
}

function admin_is_logged_in(): bool {
    return current_auth_role() === 'admin';
}

function teacher_is_logged_in(): bool {
    return current_auth_role() === 'teacher';
}

function current_admin_user(): ?array {
    return admin_is_logged_in() ? current_auth_user() : null;
}

function current_teacher_user(): ?array {
    return teacher_is_logged_in() ? current_auth_user() : null;
}

function require_login(): void {
    if (current_auth_user() !== null) {
        return;
    }

    header('Location: login.php');
    exit;
}

function require_admin_role(): void {
    if (admin_is_logged_in()) {
        return;
    }

    header('Location: index.php');
    exit;
}

function login_admin(string $username, string $password): bool {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT id, username, full_name, password_hash FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    app_session_start();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'role' => 'admin',
        'id' => (int) $admin['id'],
        'username' => $admin['username'],
        'full_name' => $admin['full_name'],
    ];

    $update = db()->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([(int) $admin['id']]);

    return true;
}

function login_teacher(string $username, string $password): bool {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT
            ta.id,
            ta.teacher_id,
            ta.username,
            ta.password_hash,
            ta.assigned_standard,
            ta.is_active,
            t.first_name,
            t.last_name
        FROM teacher_accounts ta
        INNER JOIN teachers t ON t.id = ta.teacher_id
        WHERE ta.username = ?
        LIMIT 1');
    $stmt->execute([$username]);
    $teacher = $stmt->fetch();

    if (
        !$teacher ||
        (int) $teacher['is_active'] !== 1 ||
        !password_verify($password, $teacher['password_hash'])
    ) {
        return false;
    }

    app_session_start();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'role' => 'teacher',
        'id' => (int) $teacher['teacher_id'],
        'account_id' => (int) $teacher['id'],
        'username' => $teacher['username'],
        'full_name' => full_name($teacher['first_name'], $teacher['last_name']),
        'assigned_standard' => $teacher['assigned_standard'],
    ];

    $update = db()->prepare('UPDATE teacher_accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([(int) $teacher['id']]);

    return true;
}

function logout_user(): void {
    app_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function allowed_pages_for_current_user(): array {
    return teacher_is_logged_in()
        ? ['dashboard', 'students', 'student_attendance']
        : ['dashboard', 'students', 'teachers', 'classes', 'enrollments', 'student_attendance', 'teacher_attendance'];
}

function teacher_assigned_standard(): ?string {
    $teacher = current_teacher_user();
    return $teacher['assigned_standard'] ?? null;
}

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function full_name(?string $firstName, ?string $lastName): string {
    return trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
}

function like_value(string $value): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function page_url(string $page, array $params = []): string {
    $query = ['page' => $page];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $query[$key] = $value;
    }

    return '?' . http_build_query($query);
}

function format_date(?string $value, string $fallback = '-'): string {
    if ($value === null || $value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y', $timestamp);
}

function percentage(int $value, int $total): int {
    if ($total <= 0) {
        return 0;
    }

    return (int) round(($value / $total) * 100);
}

function school_standards(): array {
    return [
        '1th',
        '2th',
        '3th',
        '4th',
        '5th',
        '6th',
        '7th',
        '8th',
        '9th',
        '10th',
        '11th',
        '12th',
    ];
}

function normalize_standard(?string $value): ?string {
    $standard = trim((string) $value);
    if ($standard === '') {
        return null;
    }

    return in_array($standard, school_standards(), true) ? $standard : null;
}

function attendance_statuses(): array {
    return ['Present', 'Absent'];
}

function normalize_attendance_status(?string $value): string {
    $status = trim((string) $value);
    return in_array($status, attendance_statuses(), true) ? $status : 'Present';
}

function normalize_date_input(?string $value): string {
    $date = trim((string) $value);
    if ($date === '') {
        return date('Y-m-d');
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return date('Y-m-d');
    }

    return date('Y-m-d', $timestamp);
}

function attendance_status_class(string $status): string {
    return match ($status) {
        'Present' => 'is-present',
        'Absent' => 'is-absent',
        default => 'neutral',
    };
}
?>
