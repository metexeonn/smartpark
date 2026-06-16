<?php
require_once 'config/config.php';
global $db;

header('Content-Type: application/json');

error_reporting(0);
ini_set('display_errors', 0);

$action = $_POST['action'] ?? '';
$plate = trim($_POST['plate'] ?? '');
$cleanPlate = str_replace(' ', '', $plate);

if (empty($action)) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz işlem talebi.']);
    exit;
}

if ($action === 'park_et') {
    if (empty($cleanPlate)) {
        echo json_encode(['status' => 'error', 'message' => 'Plaka boş olamaz.']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM reservations 
            WHERE REPLACE(plate, ' ', '') = ? 
            AND status = 'approved' 
            AND park_status = 'Bekliyor' 
            AND end_time >= NOW() 
            LIMIT 1
        ");
        $stmt->execute([$cleanPlate]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            echo json_encode(['status' => 'error', 'message' => 'Bu plakaya ait süresi geçmemiş onaylı bir rezervasyon bulunamadı.']);
            exit;
        }

        $db->beginTransaction();

        $update = $db->prepare("UPDATE reservations SET park_status = 'Park Halinde' WHERE id = ?");
        $update->execute([$res['id']]);

        $insertVehicle = $db->prepare("INSERT INTO vehicles (plate, owner_name, parking_id, slot_name, entry_time, status) VALUES (?, ?, ?, ?, NOW(), 'Inside')");
        $insertVehicle->execute([
            $res['plate'],
            $res['name'] . ' ' . $res['surname'],
            $res['parking_id'],
            $res['slot_name'] ?? 'A1'
        ]);

        $log = $db->prepare("INSERT INTO system_logs (action_type, description) VALUES ('Giriş', ?)");
        $log->execute([$res['plate'] . " plakalı araç otoparka giriş yaptı ve süresi başladı."]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Aracınız başarıyla park edildi, süre başlatıldı!']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'cikis_hesapla') {
    if (empty($cleanPlate)) {
        echo json_encode(['status' => 'error', 'message' => 'Plaka bilgiisi gelmedi.']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM reservations WHERE REPLACE(plate, ' ', '') = ? AND park_status = 'Park Halinde' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cleanPlate]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            echo json_encode(['status' => 'error', 'message' => 'Otopark içinde bu plakaya ait aktif bir araç kaydı bulunamadı.']);
            exit;
        }

        $vStmt = $db->prepare("SELECT entry_time FROM vehicles WHERE REPLACE(plate, ' ', '') = ? AND status = 'Inside' ORDER BY id DESC LIMIT 1");
        $vStmt->execute([$cleanPlate]);
        $vTime = $vStmt->fetchColumn();

        if ($vTime) {
            $girisZamani = strtotime($vTime);
        } elseif (!empty($res['start_time']) && $res['start_time'] !== '0000-00-00 00:00:00') {
            $girisZamani = strtotime($res['start_time']);
        } else {
            $girisZamani = time() - 60;
        }

        $suAn = time();
        $farkSaniye = $suAn - $girisZamani;
        $dakika = ceil($farkSaniye / 60);
        if ($dakika <= 0) $dakika = 1;

        $ucret = 50;
        if ($dakika > 60) {
            $ekstraSaat = ceil(($dakika - 60) / 60);
            $ucret += $ekstraSaat * 15;
        }

        $updateFee = $db->prepare("UPDATE reservations SET fee = ? WHERE id = ?");
        $updateFee->execute([$ucret, $res['id']]);

        echo json_encode([
            'status' => 'success',
            'id' => $res['id'],
            'minutes' => $dakika,
            'fee' => $ucret
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Ücret hesaplanırken hata oluştu: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'nakit_talep_et') {
    $resId = intval($_POST['id'] ?? 0);
    $fee = floatval($_POST['fee'] ?? 0);

    if ($resId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz rezervasyon ID\'si.']);
        exit;
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$resId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            echo json_encode(['status' => 'error', 'message' => 'Rezervasyon kaydı bulunamadı.']);
            exit;
        }

        $updateRes = $db->prepare("UPDATE reservations SET park_status = 'Nakit Onayı Bekliyor', fee = ? WHERE id = ?");
        $updateRes->execute([$fee, $resId]);

        $noti = $db->prepare("INSERT INTO notifications (title, message, is_read) VALUES ('Nakit Ödeme Bildirimi', ?, 0)");
        $noti->execute([$res['plate'] . " plakalı araç çıkış talebi yaptı. Tahsil edilen: " . $fee . " TL"]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Çıkış talebi iletildi.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'kart_ile_ode') {
    $resId = intval($_POST['id'] ?? 0);
    $fee = floatval($_POST['fee'] ?? 0);

    if ($resId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz rezervasyon ID\'si.']);
        exit;
    }

    try {
        $updateRes = $db->prepare("UPDATE reservations SET park_status = 'Ödeme Bekliyor' WHERE id = ?");
        $updateRes->execute([$resId]);

        echo json_encode(['status' => 'success', 'message' => 'Ödeme sayfası hazırlanıyor...']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
?>