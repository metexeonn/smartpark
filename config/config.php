<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0);
    session_start();
}

date_default_timezone_set('Europe/Istanbul');

define('DB_HOST', 'sql204.infinityfree.com');
define('DB_USER', 'if0_42191600');
define('DB_PASS', 'riLdAmd3hPOKtD');
define('DB_NAME', 'if0_42191600_smartpark');

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    $db->exec("SET time_zone = '+03:00'");
    
} catch (PDOException $e) {
    die("Veritabanı Bağlantı Hatası: " . $e->getMessage());
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

$timeout_duration = 900;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    
    if (isset($_GET['get_data']) || isset($_GET['check_reservation']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Oturum süreniz doldu, lütfen sayfayı yenileyin.']);
        exit;
    } else {
        header("Location: login.php?timeout=1");
        exit;
    }
}
if (!isset($_GET['get_data'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

function check_admin() {
    if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        header("Location: login.php");
        exit;
    }
}

function log_action($action, $desc) {
    global $db;
    $stmt = $db->prepare("INSERT INTO system_logs (action_type, description) VALUES (?, ?)");
    $stmt->execute([$action, $desc]);
}
?>
