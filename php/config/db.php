<?php
/* ================================================================
   CAMPUS TRADE — db.php
   Database connection singleton + query helpers
   ================================================================ */

require_once __DIR__ . '/config.php';

class Database {
    private static ?Database $instance = null;
    private mysqli $conn;

    private function __construct() {
        $this->conn = new mysqli(
            DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT
        );

        if ($this->conn->connect_error) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Database connection failed.',
                'detail'  => $this->conn->connect_error
            ]);
            exit();
        }

        $this->conn->set_charset(DB_CHARSET);
    }

    // Singleton — one connection per request
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): mysqli {
        return $this->conn;
    }

    /* ── QUERY HELPERS ── */

    /** Run a prepared statement, return mysqli_stmt */
    public function prepare(string $sql): mysqli_stmt|false {
        return $this->conn->prepare($sql);
    }

    /** Execute a SELECT, return all rows as assoc array */
    public function fetchAll(string $sql, string $types = '', ...$params): array {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** Execute a SELECT, return one row */
    public function fetchOne(string $sql, string $types = '', ...$params): ?array {
        $rows = $this->fetchAll($sql, $types, ...$params);
        return $rows[0] ?? null;
    }

    /** Execute INSERT/UPDATE/DELETE, return affected rows */
    public function execute(string $sql, string $types = '', ...$params): int {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /** Execute INSERT, return last insert ID */
    public function insert(string $sql, string $types = '', ...$params): int {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $id = (int) $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** Escape a value (use prepared statements instead where possible) */
    public function escape(string $value): string {
        return $this->conn->real_escape_string($value);
    }

    /** Begin a transaction */
    public function beginTransaction(): void {
        $this->conn->begin_transaction();
    }

    /** Commit a transaction */
    public function commit(): void {
        $this->conn->commit();
    }

    /** Roll back a transaction */
    public function rollback(): void {
        $this->conn->rollback();
    }

    public function __destruct() {
        if (isset($this->conn)) {
            $this->conn->close();
        }
    }
}

/* ── RESPONSE HELPERS ── */

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

function success(array $data = [], string $message = 'OK'): void {
    respond(['success' => true, 'message' => $message, 'data' => $data]);
}

function error(string $message, int $status = 400): void {
    respond(['success' => false, 'error' => $message], $status);
}

/* ── SESSION HELPERS ── */

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(SESSION_LIFETIME);
        session_start();
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) error('Not authenticated. Please log in.', 401);
    return $user;
}

function requireAdmin(): array {
    $user = requireLogin();
    if ($user['role'] !== 'admin') error('Admin access required.', 403);
    return $user;
}

/* ── VALIDATION HELPERS ── */

function isStudentEmail(string $email): bool {
    return str_ends_with(strtolower(trim($email)), STUDENT_DOMAIN);
}

function isAdminEmail(string $email): bool {
    return str_ends_with(strtolower(trim($email)), ADMIN_DOMAIN);
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) &&
           (isStudentEmail($email) || isAdminEmail($email));
}

function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function getDB(): Database {
    return Database::getInstance();
}
