<?php
require_once 'config/config.php';

$error = '';

if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    header("Location: admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "CSRF Güvenlik hatası algılandı!";
    } else {
        $usernameOrEmail = clean($_POST['login_input']);
        $password = $_POST['password'];

        $stmt = $db->prepare("SELECT * FROM admins WHERE username = :user_param OR email = :email_param LIMIT 1");
        $stmt->execute([
            'user_param' => $usernameOrEmail,
            'email_param' => $usernameOrEmail
        ]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            
            $up = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
            $up->execute([$admin['id']]);

            header("Location: admin.php");
            exit;
        } else {
            $error = "Hatalı kullanıcı adı, e-posta veya şifre!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Panel - SmartPark İzmir</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-body">

<div class="login-wrapper">
    <div class="glass-card text-center" style="position: relative;">
        <div style="margin-bottom: 24px; text-align: center;">
            <i class="fa-solid fa-square-parking" style="font-size: 48px; color: var(--primary);"></i>
            <h2 class="gradient-text" style="font-size: 24px; margin-top: 12px; font-weight:700;">SmartPark İzmir</h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top:4px;">Yönetim Paneli Girişi</p>
        </div>

        <?php if(!empty($error)): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid var(--danger); color: var(--danger); padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-group" style="text-align: left;">
                <label>Kullanıcı Adı veya E-posta</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-user" style="position: absolute; left: 14px; top: 15px; color: var(--text-muted);"></i>
                    <input type="text" name="login_input" class="form-control" style="padding-left: 40px;" required placeholder="Kullanıcı adınızı yazın...">
                </div>
            </div>

            <div class="form-group" style="text-align: left;">
                <label>Şifre</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-lock" style="position: absolute; left: 14px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" id="passwordField" name="password" class="form-control" style="padding-left: 40px; padding-right: 40px;" required placeholder="••••••••">
                    <i class="fa-solid fa-eye" id="togglePassword" style="position: absolute; right: 14px; top: 15px; color: var(--text-muted); cursor: pointer;"></i>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; font-size: 13px;">
                <label style="display: flex; align-items: center; gap: 6px; color: var(--text-muted); cursor: pointer;">
                    <input type="checkbox" style="accent-color: var(--primary);"> Beni Hatırla
                </label>
                <a href="#" onclick="alert('Lütfen sistem yöneticisi ile iletişime geçiniz: admin@smartpark.com');" style="color: var(--secondary); text-decoration: none;">Şifremi Unuttum</a>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                Sisteme Giriş Yap <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center;">
            <a href="index.php" style="color: var(--text-muted); text-decoration: none; font-size: 13px;">
                <i class="fa-solid fa-arrow-left"></i> Ziyaretçi Sayfasına Dön
            </a>
        </div>
    </div>
</div>

<script>
    const togglePassword = document.querySelector('#togglePassword');
    const password = document.querySelector('#passwordField');
    togglePassword.addEventListener('click', function () {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye-slash');
    });
</script>
</body>
</html>