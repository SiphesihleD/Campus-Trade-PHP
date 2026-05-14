<?php
/* ================================================================
   CAMPUS TRADE — api/auth.php
   Handles: register · login · logout · session check
   ================================================================ */

require_once __DIR__ . '/../config/db.php';

startSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match ($action) {
    'register' => handleRegister(),
    'login'    => handleLogin(),
    'logout'   => handleLogout(),
    'me'       => handleMe(),
    default    => error("Unknown action: $action", 404)
};

/* ── REGISTER ── */
function handleRegister(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);

    $body = getBody();
    $name   = sanitize($body['displayName'] ?? '');
    $email  = strtolower(trim($body['email']   ?? ''));
    $pw     = $body['password'] ?? '';
    $campus = sanitize($body['campus'] ?? '');
    $fbUid  = sanitize($body['firebaseUid'] ?? '');  // from Firebase Auth

    if (!$name)             error('Display name is required.');
    if (!isValidEmail($email)) error('Use a valid campus email (@edu.net or @adminIT.net).');
    if (!$campus)           error('Campus is required.');
    if (strlen($pw) < 8)   error('Password must be at least 8 characters.');

    $role = isAdminEmail($email) ? 'admin' : 'student';
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $db   = getDB();

    // Check duplicate
    $existing = $db->fetchOne('SELECT id FROM users WHERE email = ?', 's', $email);
    if ($existing) error('This email is already registered.');

    $id = $db->insert(
        'INSERT INTO users (firebase_uid, email, display_name, role, campus, password_hash, is_verified)
         VALUES (?, ?, ?, ?, ?, ?, 1)',
        'ssssss',
        $fbUid ?: uniqid('php_', true),
        $email, $name, $role, $campus, $hash
    );

    if (!$id) error('Registration failed. Please try again.');

    $user = $db->fetchOne('SELECT id, email, display_name, role, campus, rating, rating_count FROM users WHERE id = ?', 'i', $id);
    $_SESSION['user'] = $user;

    success($user, 'Account created successfully.');
}

/* ── LOGIN ── */
function handleLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);

    $body  = getBody();
    $email = strtolower(trim($body['email']    ?? ''));
    $pw    = $body['password'] ?? '';

    if (!$email) error('Email is required.');
    if (!$pw)    error('Password is required.');

    $db   = getDB();
    $user = $db->fetchOne(
        'SELECT id, email, display_name, role, campus, password_hash, rating, rating_count, is_banned
         FROM users WHERE email = ?', 's', $email
    );

    if (!$user)                                error('Invalid email or password.');
    if ($user['is_banned'])                    error('This account has been suspended.');
    if (!password_verify($pw, $user['password_hash'])) error('Invalid email or password.');

    // Remove sensitive field before sending
    unset($user['password_hash'], $user['is_banned']);
    $_SESSION['user'] = $user;

    // Update last seen
    $db->execute('UPDATE users SET updated_at = NOW() WHERE id = ?', 'i', $user['id']);

    success($user, 'Logged in successfully.');
}

/* ── LOGOUT ── */
function handleLogout(): void {
    session_destroy();
    success([], 'Logged out.');
}

/* ── GET CURRENT USER ── */
function handleMe(): void {
    $user = currentUser();
    if (!$user) error('Not authenticated.', 401);
    success($user);
}
