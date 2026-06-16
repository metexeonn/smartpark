<?php
require_once 'config/config.php';

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Yetkisiz erişim denemesi!']);
    exit;
}

header('Content-Type: application/json');

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $_POST = clean($_POST);

    if ($action === 'vehicle_entry') {
        $plate = strtoupper(str_replace(' ', '', $_POST['plate']));
        $owner_name = $_POST['owner_name'];
        $phone = $_POST['phone'];
        $parking_id = intval($_POST['parking_id']);
        $entry_time = $_POST['entry_time'];
        $planned_exit_time = $_POST['planned_exit_time'];

        if (empty($plate) || empty($owner_name) || empty($parking_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Lütfen zorunlu alanları doldurun.']);
            exit;
        }

        $pStmt = $db->prepare("SELECT total_capacity, name FROM parking_lots WHERE id = ?");
        $pStmt->execute([$parking_id]);
        $lot = $pStmt->fetch();

        $vStmt = $db->prepare("
            SELECT (
                (SELECT COUNT(*) FROM vehicles WHERE parking_id = ? AND status = 'Inside') + 
                (SELECT COUNT(*) FROM reservations WHERE parking_id = ? AND status IN ('pending', 'approved') AND plate NOT IN (SELECT plate FROM vehicles WHERE status = 'Inside'))
            ) as current_count
        ");
        $vStmt->execute([$parking_id, $parking_id]);
        $current = $vStmt->fetch();

        if ($current['current_count'] >= $lot['total_capacity']) {
            echo json_encode(['status' => 'error', 'message' => 'Bu otopark tamamen doludur veya ayrılmış rezervasyonlar vardır! Yeni araç kabul edilemez.']);
            exit;
        }

        $ins = $db->prepare("INSERT INTO vehicles (plate, owner_name, phone, parking_id, entry_time, planned_exit_time, status) VALUES (?, ?, ?, ?, ?, ?, 'Inside')");
        $ins->execute([$plate, $owner_name, $phone, $parking_id, $entry_time, $planned_exit_time]);
        $vehicle_id = $db->lastInsertId();

        $log = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, plate, parking_name, action) VALUES (?, ?, ?, 'Entry')");
        $log->execute([$vehicle_id, $plate, $lot['name']]);

        $new_count = $current['current_count'] + 1;
        $occupancy_rate = ($new_count / $lot['total_capacity']) * 100;

        $notiTitle = "Araç Girişi: " . $plate;
        $notiMsg = "{$plate} plakalı araç, {$lot['name']} alanına giriş yaptı.";
        
        $not = $db->prepare("INSERT INTO notifications (title, message) VALUES (?, ?)");
        $not->execute([$notiTitle, $notiMsg]);

        if ($occupancy_rate >= 100) {
            $db->prepare("INSERT INTO notifications (title, message) VALUES (?, ?)")
               ->execute(["🚨 Otopark Tamamen Doldu!", "{$lot['name']} maksimum kapasiteye (%100) ulaştı!"]);
        } elseif ($occupancy_rate >= 90) {
            $db->prepare("INSERT INTO notifications (title, message) VALUES (?, ?)")
               ->execute(["⚠️ Kritik Kapasite Uyarısı", "{$lot['name']} kapasitesi %90 seviyesinin üzerine çıktı!"]);
        }

        echo json_encode(['status' => 'success', 'message' => 'Araç otoparka başarıyla kaydedildi.']);
        exit;
    }

    if ($action === 'vehicle_exit') {
        $vehicle_id = intval($_POST['id']);

        $v = $db->prepare("SELECT v.*, p.name as parking_name FROM vehicles v JOIN parking_lots p ON v.parking_id = p.id WHERE v.id = ? AND v.status = 'Inside'");
        $v->execute([$vehicle_id]);
        $vehicle = $v->fetch();

        if (!$vehicle) {
            echo json_encode(['status' => 'error', 'message' => 'Araç kaydı bulunamadı veya zaten çıkış yapmış.']);
            exit;
        }

        $up = $db->prepare("UPDATE vehicles SET status = 'Exited' WHERE id = ?");
        $up->execute([$vehicle_id]);

        $log = $db->prepare("INSERT INTO vehicle_logs (vehicle_id, plate, parking_name, action) VALUES (?, ?, ?, 'Exit')");
        $log->execute([$vehicle_id, $vehicle['plate'], $vehicle['parking_name']]);

        $notiTitle = "Araç Çıkışı: " . $vehicle['plate'];
        $notiMsg = "{$vehicle['plate']} plakalı araç, {$vehicle['parking_name']} alanından ayrıldı.";
        $db->prepare("INSERT INTO notifications (title, message) VALUES (?, ?)")->execute([$notiTitle, $notiMsg]);

        echo json_encode(['status' => 'success', 'message' => 'Araç çıkış işlemi başarıyla onaylandı.']);
        exit;
    }
}

if ($action === 'export_report') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=SmartPark_Izmir_Raporu_'.date('Y-m-d').'.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['SmartPark İzmir - 2026 Nesil Sistem Raporu']);
    fputcsv($output, ['Tarih:', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    fputcsv($output, ['Otopark Adı', 'Kapasite', 'Aktif Araç Sayısı']);
    
    $lots = $db->query("
        SELECT p.name, p.total_capacity, 
        (
            (SELECT COUNT(*) FROM vehicles v WHERE v.parking_id = p.id AND v.status = 'Inside') + 
            (SELECT COUNT(*) FROM reservations r WHERE r.parking_id = p.id AND r.status IN ('pending', 'approved') AND r.plate NOT IN (SELECT plate FROM vehicles WHERE status = 'Inside'))
        ) as active 
        FROM parking_lots p
    ")->fetchAll();
    
    foreach($lots as $l) {
        fputcsv($output, [$l['name'], $l['total_capacity'], $l['active']]);
    }
    exit;
}
?>