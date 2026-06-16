<?php
require_once 'config/config.php';
check_admin();


function addLog($db, $action, $description) {
    try {
        $stmt = $db->prepare("INSERT INTO system_logs (action_type, description, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$action, $description]);
    } catch(Exception $e) {}
}

if (isset($_GET['request_cash_id']) && isset($_GET['plate'])) {
    $plate = $_GET['plate'];
    $clean_plate = strtoupper(str_replace(' ', '', trim($plate)));
    
    $check = $db->prepare("SELECT id FROM reservations WHERE REPLACE(REPLACE(plate, ' ', ''), '-', '') = ? AND park_status = 'Park Halinde' ORDER BY id DESC LIMIT 1");
    $check->execute([$clean_plate]);
    $res_id = $check->fetchColumn();

    if ($res_id) {
        $update = $db->prepare("UPDATE reservations SET park_status = 'Nakit Onayı Bekliyor' WHERE id = ?");
        $update->execute([$res_id]);
        addLog($db, 'Nakit_Talep', 'Plaka: ' . $plate . ' için nakit ödeme talebi oluşturuldu.');
    } else {
        $insert = $db->prepare("INSERT INTO reservations (parking_id, plate, fee, price, park_status, payment_status, created_at, status) VALUES (1, ?, 50.00, 50.00, 'Nakit Onayı Bekliyor', 'Odenmedi', NOW(), 'approved')");
        $insert->execute([$plate]);
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET['final_exit_id']) && isset($_GET['plate'])) {
    $vehicle_id = (int)$_GET['final_exit_id'];
    $plate = $_GET['plate'];
    $clean_plate = strtoupper(str_replace(' ', '', trim($plate)));

    $stmt = $db->prepare("UPDATE vehicles SET status = 'Exited', exit_time = NOW(), price = 50 WHERE id = ?");
    $stmt->execute([$vehicle_id]);

    $stmt2 = $db->prepare("UPDATE reservations SET park_status = 'Çıkış Yaptı', status = 'Tamamlandı' WHERE REPLACE(REPLACE(plate, ' ', ''), '-', '') = ? AND payment_status IN ('Ödendi', '3D Ödendi', 'Odenmedi') ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$clean_plate]);

    addLog($db, 'Cikis_Onayi', 'Plaka: ' . $plate . ' çıkışı onaylandı.');
    header("Location: admin.php?success=2");
    exit;
}

if (isset($_GET['get_cash_requests'])) {
    $requests = $db->query("SELECT * FROM reservations WHERE park_status = 'Nakit Onayı Bekliyor' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($requests)) {
        echo '<p style="color:#64748b; font-size:13px; padding:10px;">Bekleyen nakit işlemi yok.</p>';
    } else {
        foreach ($requests as $req) {
            echo '<div class="request-item" style="padding:10px; border-bottom:1px solid #1e293b;">
                    <span style="color:white;">'.$req['plate'].'</span>
                    <button onclick="approveCash('.$req['id'].')" style="background:#22c55e; border:none; color:white; padding:5px 10px; border-radius:4px; float:right; cursor:pointer;">Onayla</button>
                  </div>';
        }
    }
    exit;
}

if (isset($_GET['approve_cash_id'])) {
    $id = (int)$_GET['approve_cash_id'];
    $db->prepare("UPDATE reservations SET park_status = 'Park Halinde', payment_status = 'Ödendi' WHERE id = ?")->execute([$id]);
    addLog($db, 'Nakit_Onay', 'Rezervasyon ID: ' . $id . ' için nakit ödeme onaylandı.');
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['request_cash_id']) && isset($_GET['plate'])) {
    $plate = $_GET['plate'];
    $clean_plate = strtoupper(str_replace(' ', '', trim($plate)));
    
    $check = $db->prepare("SELECT id FROM reservations WHERE REPLACE(REPLACE(plate, ' ', ''), '-', '') = ? AND park_status = 'Park Halinde' ORDER BY id DESC LIMIT 1");
    $check->execute([$clean_plate]);
    $res_id = $check->fetchColumn();

    if ($res_id) {
        $update = $db->prepare("UPDATE reservations SET park_status = 'Nakit Onayı Bekliyor' WHERE id = ?");
        $update->execute([$res_id]);
    } else {
        $insert = $db->prepare("INSERT INTO reservations (parking_id, plate, fee, price, park_status, payment_status, created_at, status) VALUES (1, ?, 50.00, 50.00, 'Nakit Onayı Bekliyor', 'Odenmedi', NOW(), 'approved')");
        $insert->execute([$plate]);
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET['final_exit_id']) && isset($_GET['plate'])) {
    $vehicle_id = (int)$_GET['final_exit_id'];
    $plate = $_GET['plate'];
    $clean_plate = strtoupper(str_replace(' ', '', trim($plate)));

    $stmt = $db->prepare("UPDATE vehicles SET status = 'Exited', exit_time = NOW(), price = 50 WHERE id = ?");
    $stmt->execute([$vehicle_id]);

    $stmt2 = $db->prepare("UPDATE reservations SET park_status = 'Çıkış Yaptı', status = 'Tamamlandı' WHERE REPLACE(REPLACE(plate, ' ', ''), '-', '') = ? AND payment_status IN ('Ödendi', '3D Ödendi') ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$clean_plate]);

    header("Location: admin.php?success=2");
    exit;
}

if (isset($_GET['get_stats'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    try {
        $parking_id = isset($_REQUEST['parking_id']) ? intval($_REQUEST['parking_id']) : 0;
        if ($parking_id > 0) {
            $pStmt = $db->prepare("SELECT total_capacity FROM parking_lots WHERE id = ?");
            $pStmt->execute([$parking_id]);
            $total_capacity = $pStmt->fetchColumn() ?: 0;
        } else {
            $total_capacity = $db->query("SELECT SUM(total_capacity) FROM parking_lots")->fetchColumn() ?: 0;
        }

        if ($parking_id > 0) {
            $count_v = $db->prepare("SELECT COUNT(*) FROM vehicles WHERE parking_id = ? AND status = 'Inside'");
            $count_v->execute([$parking_id]);
            $v_sonuc = $count_v->fetchColumn() ?: 0;

            $r_sonuc = 0; 
        } else {
            $v_sonuc = $db->query("SELECT COUNT(*) FROM vehicles WHERE status = 'Inside'")->fetchColumn() ?: 0;
            $r_sonuc = 0;
        }
        
        $active_vehicles = $v_sonuc;
        $free_slots = $total_capacity - $active_vehicles;
        if ($free_slots < 0) $free_slots = 0;
        
        $efficiency = $total_capacity > 0 ?
        round(($active_vehicles / $total_capacity) * 100) : 0;

        $occupied_slots = [];
        try {
            if ($parking_id > 0) {
                $slot_stmt = $db->prepare("SELECT slot_name FROM reservations WHERE parking_id = ? AND status IN ('pending', 'approved') AND (park_status != 'Tamamlandı' OR park_status IS NULL) AND park_status != 'Çıkış Yaptı' AND slot_name IS NOT NULL AND slot_name != ''");
                $slot_stmt->execute([$parking_id]);
                $occupied_slots = $slot_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } else {
                $occupied_slots = $db->query("SELECT slot_name FROM reservations WHERE status IN ('pending', 'approved') AND (park_status != 'Tamamlandı' OR park_status IS NULL) AND park_status != 'Çıkış Yaptı' AND slot_name IS NOT NULL AND slot_name != ''")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
        } catch (Exception $slot_error) {
            $occupied_slots = [];
        }

        $earnings_v = $db->query("SELECT IFNULL(SUM(price), 0) FROM vehicles WHERE status = 'Exited'")->fetchColumn() ?: 0;
        $earnings_r = 0;
        try {
            $earnings_r = $db->query("SELECT IFNULL(SUM(fee), 0) FROM reservations WHERE payment_status IN ('Ödendi', '3D Ödendi')")->fetchColumn() ?: 0;
        } catch(Exception $e) {
            try { $earnings_r = $db->query("SELECT IFNULL(SUM(price), 0) FROM reservations WHERE payment_status IN ('Ödendi', '3D Ödendi')")->fetchColumn() ?: 0;
            } catch(Exception $ex) { $earnings_r = 0; }
        }
$total_earnings = $earnings_r;
$estimated_revenue = 0;
try {
    $estimated_revenue = $db->query("SELECT IFNULL(SUM(fee), 0) FROM reservations WHERE payment_status = 'Odenmedi' AND park_status = 'Park Halinde'")->fetchColumn() ?: 0;
} catch(Exception $e) {
    $estimated_revenue = 0;
}

        echo json_encode([
            'active' => (int)$active_vehicles,
            'free' => (int)$free_slots,
            'earnings' => number_format($total_earnings, 2, '.', ','),
            'efficiency' => $efficiency . '%',
            'estimated_revenue' => number_format($estimated_revenue, 2, '.', ',') . " ₺",
        
           'occupied_slots' => array_values(array_unique(array_filter($occupied_slots)))
        ]);
        exit;
    } catch (Exception $global_error) {
        echo json_encode([
            'error' => true,
            'message' => $global_error->getMessage(),
            'active' => 0,
            'free' => 0,
          
   'earnings' => "0.00",
            'efficiency' => "0%",
            'estimated_revenue' => "0.00 ₺",
            'occupied_slots' => []
        ]);
        exit;
    }
}

$adminName = $_SESSION['admin_user'] ?? 'Yönetici';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vehicle_exit'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $stmt = $db->prepare("SELECT plate FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $veh_plate = $stmt->fetchColumn();

    $price = 50;
    if ($veh_plate) {
        $res_check = $db->prepare("SELECT id FROM reservations WHERE plate = ? AND status IN ('pending', 'approved')");
        $res_check->execute([$veh_plate]);
        if ($res_check->fetchColumn()) {
            $price = 0;
        }
    }

    $stmt = $db->prepare("UPDATE vehicles SET status = 'Exited', exit_time = NOW(), price = ? WHERE id = ?");
    $stmt->execute([$price, $vehicle_id]);
    
    if ($veh_plate) {
        try {
            $db->prepare("UPDATE reservations SET park_status = 'Çıkış Yaptı', status = 'Tamamlandı' WHERE REPLACE(plate, ' ', '') = REPLACE(?, ' ', '') AND status = 'approved' AND park_status = 'Park Halinde'")->execute([$veh_plate]);
        } catch(PDOException $e) {}
    }

    header("Location: admin.php?success=2&price=$price");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_entry'])) {
    $plate = clean($_POST['plate']);
    $owner = clean($_POST['owner_name']);
    $parking_id = (int)$_POST['parking_id'];
    $slot_name = trim(clean($_POST['slot_name'])); 

    $stmt = $db->prepare("INSERT INTO vehicles (plate, owner_name, parking_id, slot_name, status, entry_time) VALUES (?, ?, ?, ?, 'Inside', NOW())");
    if ($stmt->execute([$plate, $owner, $parking_id, $slot_name])) {
        
        try {
            $cleanPlate = str_replace(' ', '', $plate);
            
            $clearOld = $db->prepare("UPDATE reservations SET status = 'Tamamlandı', park_status = 'İptal' WHERE REPLACE(plate, ' ', '') = ? AND status IN ('pending', 'approved') AND park_status NOT IN ('Çıkış Yaptı', 'İptal', 'Tamamlandı')");
            $clearOld->execute([$cleanPlate]);

            $parts = explode(' ', $owner);
            $name = $parts[0] ? $parts[0] : 'Manuel';
            $surname = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : 'Giriş';

            $stmt_res = $db->prepare("INSERT INTO reservations 
                (user_id, plate, name, surname, email, parking_id, slot_name, status, park_status, payment_status, fee, price, start_time, end_time, created_at) 
                VALUES 
                (0, ?, ?, ?, 'manuel@smartpark.com', ?, ?, 'approved', 'Park Halinde', 'Odenmedi', 50.00, 50.00, NOW(), DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW())");
            
            $stmt_res->execute([$plate, $name, $surname, $parking_id, $slot_name]);
        } catch (Exception $res_err) {
            try {
                $stmt_res_alt = $db->prepare("INSERT INTO reservations 
                    (plate, name, surname, email, parking_id, slot_name, status, park_status, payment_status, start_time, created_at) 
                    VALUES (?, ?, ?, 'manuel@smartpark.com', ?, ?, 'approved', 'Park Halinde', 'Odenmedi', NOW(), NOW())");
                $stmt_res_alt->execute([$plate, $name, $surname, $parking_id, $slot_name]);
            } catch(Exception $e) {}
        }

        header("Location: admin.php?success=1");
        exit;
    }
}

$total_capacity = $db->query("SELECT SUM(total_capacity) FROM parking_lots")->fetchColumn() ?: 0;
$active_v = $db->query("SELECT COUNT(*) FROM vehicles WHERE status = 'Inside'")->fetchColumn() ?: 0;
$active_r = 0; 

$active_vehicles = $active_v;

$total_free = $total_capacity - $active_vehicles;
$estimated_revenue = $active_vehicles * 50;
$earnings_v = $db->query("SELECT IFNULL(SUM(price), 0) FROM vehicles WHERE status = 'Exited'")->fetchColumn() ?: 0;
$earnings_r = 0;
try {
    $earnings_r = $db->query("SELECT IFNULL(SUM(fee), 0) FROM reservations WHERE payment_status IN ('Ödendi', '3D Ödendi')")->fetchColumn() ?: 0;
} catch(PDOException $e) {}
$total_earnings = $earnings_v + $earnings_r;

$current_efficiency = ($total_capacity > 0) ?
round(($active_vehicles / $total_capacity) * 100, 1) : 0;

$history_logs = $db->query("SELECT * FROM vehicles WHERE status = 'Exited' ORDER BY exit_time DESC")->fetchAll(PDO::FETCH_ASSOC);
$lots = $db->query("SELECT * FROM parking_lots")->fetchAll(PDO::FETCH_ASSOC);

$active_vehicles_query = $db->query("SELECT * FROM vehicles WHERE status = 'Inside' ORDER BY entry_time ASC");
$vehicles_by_slot = [];
while($v = $active_vehicles_query->fetch(PDO::FETCH_ASSOC)) {
    $lot_id = $v['parking_id'];
    $s_name = strtoupper(str_replace(' ', '', trim($v['slot_name'])));
    if(!isset($vehicles_by_slot[$lot_id])) { $vehicles_by_slot[$lot_id] = []; }
    $vehicles_by_slot[$lot_id][$s_name] = $v;
}

$reservations_by_slot = [];
try {
    $res_query = $db->query("
        SELECT * FROM reservations 
        WHERE status IN ('pending', 'approved')
        AND park_status != 'Çıkış Yaptı'
        AND plate NOT IN (SELECT plate FROM vehicles WHERE status = 'Inside')
    ");
    while($r = $res_query->fetch(PDO::FETCH_ASSOC)) {
        $lot_id = $r['parking_id'] ?? 1;
        $s_name = strtoupper(str_replace(' ', '', trim($r['slot_name'] ?? '')));
        
        if(!empty($s_name)) {
            if(!isset($reservations_by_slot[$lot_id])) { $reservations_by_slot[$lot_id] = [];
            }
            $reservations_by_slot[$lot_id][$s_name] = $r;
        }
    }
} catch(PDOException $e) {}

$nakit_bekleyenler = [];
try {

    $nakit_bekleyenler = $db->query("
        SELECT * FROM reservations 
        WHERE park_status = 'Nakit Onayı Bekliyor' 
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Paneli - SmartPark İzmir</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="assets/js/config.js"></script>
    <script src="assets/js/app.js"></script>

    <style>
        .admin-wrapper { padding: 30px;
        max-width: 1400px; margin: 0 auto; }
        .map-container { max-height: 450px; overflow-y: auto;
        padding-right: 10px; margin-top: 15px; }
        .map-container::-webkit-scrollbar { width: 8px;
        }
        .map-container::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 10px;
        }
        .map-container::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px;
        }
        .parking-map { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px;
        }
        .slot { background: rgba(255, 255, 255, 0.05);
        border: 2px dashed rgba(255, 255, 255, 0.2); border-radius: 8px; padding: 15px 5px; text-align: center; cursor: pointer; transition: all 0.3s ease;
        position: relative; backdrop-filter: blur(5px); }
        .slot.empty { border-color: var(--success); color: var(--success);
        background: rgba(16, 185, 129, 0.05); }
        .slot.empty:hover { transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }
        .slot.occupied { background: rgba(239, 68, 68, 0.1);
        border-color: var(--danger); border-style: solid; color: var(--danger); }
        .slot.occupied:hover { transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3); }
        .slot.reserved { background: rgba(245, 158, 11, 0.1);
        border-color: var(--warning); border-style: solid; color: var(--warning); cursor: not-allowed; }
        .slot.reserved:hover { transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3); }
        .slot.selected-slot { border-color: var(--primary) !important;
        border-style: solid; background: rgba(37, 99, 235, 0.2) !important; color: var(--primary) !important; transform: translateY(-5px);
        box-shadow: 0 0 20px rgba(37, 99, 235, 0.5); }
        .slot-name { font-weight: 700;
        font-size: 16px; margin-bottom: 5px;}
        .slot-plate { font-size: 12px; color: var(--text); font-weight:600;
        }
        .camera-box { width: 100%; height: 180px; background: #000; border-radius: 12px; position: relative;
        overflow: hidden; display: none; margin-bottom: 20px; border: 2px solid var(--primary); box-shadow: inset 0 0 20px rgba(0,0,0,0.8);
        }
        .camera-box.active { display: flex; align-items: center; justify-content: center;
        }
        .camera-text { color: var(--text-muted); font-size: 13px; z-index: 2;
        font-weight: 500;}
        .laser-line { position: absolute; top: 0; left: 0; width: 100%;
        height: 3px; background: var(--success); box-shadow: 0 0 15px var(--success), 0 0 30px var(--success); animation: scan 2s infinite linear; z-index: 3;
        }
        @keyframes scan { 0% { top: 0; } 50% { top: 100%;
        } 100% { top: 0; } }
        .dark-input { width: 100%;
        background: rgba(15, 23, 42, 0.6) !important; border: 1px solid var(--card-border) !important; padding: 12px 16px; border-radius: 10px; color: #ffffff !important;
        font-size: 14px; }
        .dark-input:focus { outline: none; border-color: var(--primary) !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2); }
        select.dark-input option { background: #0f172a;
        color: #fff; }
        @media (max-width: 768px) { .main-grid { grid-template-columns: 1fr !important;
        } }
    </style>
</head>
<body>

<div class="admin-wrapper">
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <i class="fa-solid fa-square-parking" style="font-size: 32px; color: var(--primary);"></i>
            <div>
                <h1 class="gradient-text" style="font-size: 22px; margin: 0; font-weight: 700;">SmartPark İzmir Paneli</h1>
                <p style="font-size: 12px; color: var(--text-muted); margin:0;">2026 Nesil Yönetim Merkezi</p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 20px;">
            <a href="admin_logs.php" style="color: #34d399; font-weight: bold; text-decoration: none; margin-right: 10px;"><i class="fa-solid fa-list-ol"></i> Tüm Loglar</a>

            <a href="admin_reservations.php" style="color: #60a5fa; font-weight: bold; text-decoration: none; margin-right: 10px;"><i class="fa-solid fa-calendar-check"></i> Rezervasyonlar</a>
            
            <span style="font-size: 14px; color: var(--text-muted);">Hoş geldin, <span style="color:var(--text); font-weight:600;">@<?= htmlspecialchars($adminName) ?></span></span>
            
            <a href="index.php" style="padding: 8px 16px; font-size:13px; text-decoration: none; background: rgba(255,255,255,0.1); color: #fff; border-radius: 6px;"><i class="fa-solid fa-house"></i> Ana Sayfa</a>
            
            <a href="logout.php" class="btn btn-danger" style="padding: 8px 16px; font-size:13px;"><i class="fa-solid fa-power-off"></i> Çıkış</a>
        </div>
    </div>


    <div style="display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="glass-card stat-card" style="display: flex;
        justify-content: space-between; align-items: center;">
            <div class="stat-info">
                <h3 style="font-size: 14px;
        color: var(--text-muted); margin-bottom: 8px;">Aktif Araçlar</h3>
                <p id="stat-active" style="font-size: 32px;
        font-weight: 700; color: var(--text);"><?= $active_vehicles ?></p>
            </div>
            <div style="width: 50px;
        height: 50px; border-radius: 12px; background: rgba(37,99,235,0.15); color: var(--primary); display: flex; align-items: center; justify-content: center;
        font-size: 24px;"><i class="fa-solid fa-car"></i></div>
        </div>
        <div class="glass-card stat-card" style="display: flex;
        justify-content: space-between; align-items: center;">
            <div class="stat-info">
                <h3 style="font-size: 14px;
        color: var(--text-muted); margin-bottom: 8px;">Boş Kapasite</h3>
                <p id="stat-free" style="font-size: 32px;
        font-weight: 700; color: var(--text);"><?= $total_free ?></p>
            </div>
            <div style="width: 50px;
        height: 50px; border-radius: 12px; background: rgba(16,185,129,0.15); color: var(--success); display: flex; align-items: center; justify-content: center;
        font-size: 24px;"><i class="fa-solid fa-square-parking"></i></div>
        </div>
        <div class="glass-card stat-card" style="display: flex;
        justify-content: space-between; align-items: center;">
            <div class="stat-info">
                <h3 style="font-size: 14px;
        color: var(--text-muted); margin-bottom: 8px;">Toplam Kazanç</h3>
                <p id="stat-earnings" style="font-size: 32px;
        font-weight: 700; color: var(--text);"><?= number_format($total_earnings, 2) ?> ₺</p>
            </div>
            <div style="width: 50px;
        height: 50px; border-radius: 12px; background: rgba(234,179,8,0.15); color: #eab308; display: flex; align-items: center; justify-content: center;
        font-size: 24px;"><i class="fa-solid fa-wallet"></i></div>
        </div>
        <div class="glass-card stat-card" style="display: flex;
        justify-content: space-between; align-items: center;">
            <div class="stat-info">
                <h3 style="font-size: 14px;
        color: var(--text-muted); margin-bottom: 8px;">Tahmini Gelir</h3>
                <p id="stat-revenue" style="font-size: 32px;
        font-weight: 700; color: var(--text);"><?= $estimated_revenue ?> ₺</p>
            </div>
            <div style="width: 50px;
        height: 50px; border-radius: 12px; background: rgba(16,185,129,0.15); color: var(--success); display: flex; align-items: center; justify-content: center;
        font-size: 24px;"><i class="fa-solid fa-coins"></i></div>
        </div>
        <div class="glass-card stat-card" style="display: flex;
        justify-content: space-between; align-items: center;">
            <div class="stat-info">
                <h3 style="font-size: 14px;
        color: var(--text-muted); margin-bottom: 8px;">Verimlilik</h3>
                <p id="stat-efficiency" style="font-size: 32px;
        font-weight: 700; color: var(--text);"><?= $current_efficiency ?>%</p>
            </div>
            <div style="width: 50px;
        height: 50px; border-radius: 12px; background: rgba(249,115,22,0.15); color: var(--warning); display: flex; align-items: center; justify-content: center;
        font-size: 24px;"><i class="fa-solid fa-chart-line"></i></div>
        </div>
    </div>

    <div class="main-grid" style="display: grid;
    grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <div class="glass-card" style="margin-bottom: 24px;">
                <div style="display: flex;
                justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <h3 style="font-size: 18px;
                margin-bottom: 5px;"><i class="fa-solid fa-map-location-dot" style="color: var(--secondary);"></i> Canlı Sensör Haritası</h3>
                        <p style="font-size: 13px;
                color: var(--text-muted);">Araç eklemek için <b>Boş (Yeşil)</b>, çıkış için <b>Dolu (Kırmızı)</b> alana tıklayın.</p>
                    </div>
                    <select id="mapSelector" class="dark-input" style="width: 250px;" onchange="changeMap(this.value)">
                        <?php foreach($lots as $l): ?>
                
                            <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?> (<?= $l['total_capacity'] ?> Kapasite)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div 
                class="map-container">
                    <?php 
                    foreach($lots as $index => $lot): 
                        $l_id = $lot['id'];
                        $cap = $lot['total_capacity'];
  
                        $display = ($index === 0) ? 'block' : 'none';
                    ?>
                        <div id="map_lot_<?= $l_id ?>" class="lot-map-wrapper" style="display: <?= $display ?>;">
                  
                            <div class="parking-map">
                                <?php 
                                for ($i = 1;
                                $i <= $cap; $i++): 
                                    $block_letter = chr(65 + floor(($i - 1) / 20));
                                $slot_num = (($i - 1) % 20) + 1;
                                    $slot_name_raw = $block_letter . $slot_num;
                                    $slot_name_clean = strtoupper(str_replace(' ', '', trim($slot_name_raw)));
                                    if (isset($vehicles_by_slot[$l_id][$slot_name_clean])): 
                                        $v_data = $vehicles_by_slot[$l_id][$slot_name_clean];
                                $v_plate = htmlspecialchars($v_data['plate']);
                                ?>
                                    <div class="slot occupied" onclick="exitVehicle(<?= $v_data['id'] ?>, '<?= $v_plate ?>')">
                                        <div class="slot-name"><?= $slot_name_raw ?></div>
          
                                              <div class="slot-plate"><?= $v_plate ?></div>
                                    </div>
                               
                                     
                                <?php 
                   
                                  elseif (isset($reservations_by_slot[$l_id][$slot_name_clean])): 
                                        $r_data = $reservations_by_slot[$l_id][$slot_name_clean];
                                $r_plate = htmlspecialchars($r_data['plate'] ?? 'GİZLİ');
                                ?>
                                    <div class="slot reserved" onclick="Swal.fire({title:'Rezerve Alan', text:'Bu alan <?= $r_plate ?> plakalı araç için rezerve edilmiştir.', icon:'info', background: '#0f172a', color: '#fff'})">
                                        
                                <div class="slot-name"><?= $slot_name_raw ?></div>
                                        <div class="slot-plate"><i class="fa-solid fa-lock"></i> <?= $r_plate ?></div>
                                    </div>
               
                                     
                                <?php 
     
                                else: 
                                ?>
                                    
                                <div class="slot empty" onclick="selectSlot('<?= $slot_name_raw ?>', <?= $l_id ?>, this)">
                                        <div class="slot-name"><?= $slot_name_raw ?></div>
                                        <div class="slot-plate">BOŞ</div>
       
                                     </div>
                                <?php 
                                    endif;
                                endfor; 
                                ?>
                            </div>
                        </div>
                    <?php endforeach;
                    ?>
                </div>
            </div>

            <div class="glass-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="font-size: 18px;"><i class="fa-solid fa-clock-rotate-left" style="color: var(--secondary);"></i> Tüm Çıkış Kayıtları</h3>
      
                    <a href="export_csv.php" class="btn btn-primary" style="padding: 8px 15px; font-size: 13px; text-decoration: none;">CSV İndir</a>
                </div>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table style="width: 100%; border-collapse: collapse; color: white;">
             
                        <thead>
                            <tr style="border-bottom: 1px solid #334155; text-align: left; color: var(--text-muted);">
                                <th style="padding:10px;">Plaka</th>
                    
                            <th style="padding:10px;">Çıkış</th>
                                <th style="padding:10px;">Ücret</th>
                            </tr>
                        </thead>
  
                        <tbody>
                            <?php foreach($history_logs as $log): ?>
                            <tr style="border-bottom: 1px solid #1e293b;">
              
                                  <td style="padding:10px;"><?= htmlspecialchars($log['plate']) ?></td>
                                <td style="padding:10px;"><?= date('H:i', strtotime($log['exit_time'])) ?></td>
                                <td style="padding:10px;
                                color: var(--success); font-weight: bold;"><?= number_format($log['price'], 2) ?> ₺</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
           
                        </table>
                </div>
            </div>

            <div class="glass-card" style="margin-top: 24px;">
                <div style="display: flex;
                justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="font-size: 18px;"><i class="fa-solid fa-hand-holding-dollar" style="color: var(--success);"></i> Nakit Bekleyen İşlemler</h3>
                </div>
                <div style="max-height: 300px;
                overflow-y: auto;">
                    <table style="width: 100%;
                border-collapse: collapse; color: white;">
                        <thead>
                            <tr style="border-bottom: 1px solid #334155;
                        text-align: left; color: var(--text-muted);">
                                <th style="padding:10px;">Plaka</th>
                                <th style="padding:10px;">Durum</th>
                               
                                <th style="padding:10px;">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                      
                            <?php if(count($nakit_bekleyenler) > 0): ?>
                                <?php foreach($nakit_bekleyenler as $rez): ?>
                                <tr style="border-bottom: 1px solid #1e293b;">
                  
                                    <td style="padding:10px;
                                    font-weight:bold;"><?= htmlspecialchars($rez['plate']) ?></td>
                                    <td style="padding:10px;
                                    color: var(--warning);">Nakit Bekliyor</td>
                                    <td style="padding:10px;">
                                        <a href="admin_nakit_onay.php?id=<?= $rez['id'] ?>" style="background: #22c55e;
                                    color: white; padding: 6px 12px; text-decoration: none; border-radius: 5px; font-size:13px; font-weight:bold;
                                    display: inline-block;"><i class="fa-solid fa-check"></i> Nakit Alındı - Onayla</a>
                                    </td>
                                </tr>
                         
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                             
                                    <td colspan="3" style="padding:15px; text-align:center;
                                    color: var(--text-muted);">Şu an nakit ödeme bekleyen müşteri bulunmuyor.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
       
                            </table>
                </div>
            </div>
            </div>

        <div>
            <div class="glass-card" style="position: sticky;
            top: 20px;">
                <h3 style="font-size: 18px;
                margin-bottom: 20px;"><i class="fa-solid fa-robot" style="color: var(--success);"></i> AI Hızlı Araç Girişi</h3>
                
                <div class="camera-box" id="cameraBox">
                    <div class="laser-line"></div>
                    <div class="camera-text"><i class="fa-solid fa-video"></i> Yapay Zeka Plakayı Tarıyor...</div>
         
                </div>

                <button type="button" class="btn" onclick="startOCR()" id="ocrBtn" style="width: 100%;
                margin-bottom: 20px; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff;">
                    <i class="fa-solid fa-camera"></i> Kameradan Oku (OCR)
                </button>

                <form action="admin.php" method="POST" autocomplete="off" onsubmit="return validateForm()">
                    <input type="hidden" name="ai_entry" value="1">
      
                    
                    <div class="form-group">
                        <label style="color: var(--text-muted);
                        font-size:13px; font-weight:600;">Tespit Edilen Plaka</label>
                        <input type="text" id="plateInput" name="plate" class="dark-input" required placeholder="Plakayı girin...">
                    </div>
                    
                    <div class="form-group">
     
                        <label style="color: var(--text-muted);
                        font-size:13px; font-weight:600;">Araç Sahibi (Zorunlu Değil)</label>
                        <input type="text" id="ownerInput" name="owner_name" class="dark-input" placeholder="Ziyaretçi Müşteri">
                    </div>

                    <div class="form-group">
                        <label 
                        style="color: var(--text-muted); font-size:13px; font-weight:600;">Hedef Otopark & Alan (Haritadan Seçin)</label>
                        <div style="display: flex;
                        gap: 10px;">
                            <select name="parking_id" id="slotInput" class="dark-input" style="flex: 2;
                            pointer-events: none; opacity: 0.7;" tabindex="-1" required>
                                <option value="">Otopark...</option>
                                <?php foreach($lots as $l): ?>
                          
                                    <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                       
                             
                            <input type="text" id="slotNameInput" name="slot_name" class="dark-input" required placeholder="Alan Seç" style="flex: 1;
                            text-align: center; font-weight: bold; color: var(--warning) !important; cursor: not-allowed;" readonly>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;
                    margin-top: 10px;">
                        <i class="fa-solid fa-floppy-disk"></i> Seçili Alana Kaydet
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let statsInterval;

function updateStats() {
    const currentParkingId = document.getElementById('mapSelector')?.value || 0;

    fetch('admin.php?get_stats=1&parking_id=' + currentParkingId)
        .then(response => response.json())
        .then(data => {
            if(document.getElementById('stat-active')) document.getElementById('stat-active').innerText = data.active;
            if(document.getElementById('stat-free')) document.getElementById('stat-free').innerText = data.free;
            if(document.getElementById('stat-earnings')) document.getElementById('stat-earnings').innerText = data.earnings + ' ₺';
            if(document.getElementById('stat-revenue')) document.getElementById('stat-revenue').innerText = data.estimated_revenue;
            if(document.getElementById('stat-efficiency')) document.getElementById('stat-efficiency').innerText = data.efficiency;

            if (data.occupied_slots) {
                const currentWrapper = document.getElementById('map_lot_' + currentParkingId);
                if (currentWrapper) {
                    currentWrapper.querySelectorAll('.slot').forEach(el => {
                        const onclickStr = el.getAttribute('onclick') || '';
                        const match = onclickStr.match(/selectSlot\s*\(\s*['"]([^'"]+)['"]\s*,\s*(\d+)/);
                        if (match) {
                            const sName = match[1].toUpperCase().replace(/\s+/g, '');
                            if (data.occupied_slots.includes(sName)) {
                                if (!el.classList.contains('occupied')) {
                                    el.classList.remove('empty');
                                    el.classList.add('reserved');
                                    const plateDiv = el.querySelector('.slot-plate');
                                    if (plateDiv && !plateDiv.innerHTML.includes('fa-lock')) {
                                        plateDiv.innerHTML = '<i class="fa-solid fa-lock"></i> REZERVE';
                                    }
                                }
                            } else {
                                if (!el.classList.contains('occupied')) {
                                    el.classList.remove('reserved');
                                    el.classList.add('empty');
                                    const plateDiv = el.querySelector('.slot-plate');
                                    if (plateDiv) plateDiv.innerText = 'BOŞ';
                                }
                            }
                        }
                    });
                }
            }
        }).catch(err => console.error("Stats/Map hatası:", err));

    fetch('admin.php?get_cash_requests=1')
        .then(response => response.text())
        .then(html => {
            const listDiv = document.getElementById('cash-request-list');
            if (listDiv) {
                listDiv.innerHTML = html;
            }
        })
        .catch(err => console.error("Nakit listesi hatası:", err));
}

statsInterval = setInterval(updateStats, 5000);
    
    document.addEventListener("DOMContentLoaded", function() {
        updateStats(); 
        const mapSelector = document.getElementById('mapSelector');
        if (mapSelector) {
            document.getElementById('slotInput').value = mapSelector.value;
        }
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
        let title = 'Başarılı!';
        let text = 'İşlem tamamlandı.';
        if (urlParams.get('success') === '2') {
            title = 'Çıkış Yapıldı';
            let price = urlParams.get('price') || 0;
            text = (price == 0) ? 'Araç çıkışı başarılı. Ücret rezervasyon ile ödendi.' : 'Araç çıkışı başarılı. Tahsil edilen: ' + price + ' TL';
        }
        Swal.fire({ icon: 'success', title: title, text: text, background: '#0f172a', color: '#fff' });
        window.history.replaceState(null, null, window.location.pathname);
    }

    function changeMap(lotId) {
        document.querySelectorAll('.lot-map-wrapper').forEach(function(map) { map.style.display = 'none'; });
        const targetMap = document.getElementById('map_lot_' + lotId);
        if(targetMap) targetMap.style.display = 'block';
        document.getElementById('slotInput').value = lotId;
        document.getElementById('slotNameInput').value = "";
        document.querySelectorAll('.slot').forEach(el => el.classList.remove('selected-slot'));
        updateStats();
    }

    function selectSlot(slotName, lotId, element) {
        if(element.classList.contains('reserved') || element.classList.contains('occupied')) return;
        document.querySelectorAll('.slot').forEach(el => el.classList.remove('selected-slot'));
        element.classList.add('selected-slot');
        document.getElementById('slotNameInput').value = slotName;
        document.getElementById('slotInput').value = lotId;
    }

function exitVehicle(vehicleId, plate) {
        fetch('check_status.php?plate=' + encodeURIComponent(plate))
        .then(response => response.json())
        .then(data => {
            
            if (data.status === 'Normal_Giris') {
                Swal.fire({
                    title: 'Ödeme İşlemi',
                    text: plate + ' plakalı araç çıkış yapmak istiyor. Nakit ödeme alındı mı?',
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: '#22c55e', 
                    cancelButtonColor: '#ef4444', 
                    confirmButtonText: 'Nakit ile Ödedi',
                    cancelButtonText: 'Hayır / İptal',
                    background: '#0f172a',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'admin.php?request_cash_id=' + vehicleId + '&plate=' + encodeURIComponent(plate);
                    }
                });
            } 
            
            else if (data.status === 'Nakit_Bekliyor') {
                Swal.fire({
                    title: 'Onay Bekleniyor',
                    text: plate + ' plakalı araç nakit onay listesinde bekliyor. Lütfen önce listenin en altından onay verin.',
                    icon: 'warning',
                    background: '#0f172a',
                    color: '#fff',
                    confirmButtonText: 'Tamam',
                    confirmButtonColor: '#3b82f6'
                });
            } 
            
            else if (data.status === 'Odedi_Cikis_Yapabilir') {
                Swal.fire({
                    background: '#0f172a',
                    color: '#fff',
                    title: 'Araç Çıkışı',
                    text: plate + ' plakalı aracın çıkış işlemini onaylıyor musunuz?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: '<i class="fa-solid fa-right-from-bracket"></i> Evet, Çıkış Yap',
                    cancelButtonText: 'İptal',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'admin.php?final_exit_id=' + vehicleId + '&plate=' + encodeURIComponent(plate);
                    }
                });
            }
        })
        .catch(err => {
            console.error("Durum kontrolü hatası:", err);
            Swal.fire({ icon: 'error', title: 'Hata', text: 'Sistemle iletişim kurulamadı.', background: '#0f172a', color: '#fff' });
        });
    }

    function validateForm() {
        if(document.getElementById('slotNameInput').value === "") {
            Swal.fire({ icon: 'warning', title: 'Alan Seçmediniz', text: 'Lütfen harita üzerinden yeşil renkli boş bir park yerine tıklayın.', background: '#0f172a', color: '#fff' });
            return false;
        }
        return true;
    }

    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || ('ontouchstart' in window);
    
    async function processFile(file) {
        const btn = document.getElementById('ocrBtn');
        const plateInput = document.getElementById('plateInput');
        if (!file) return;
        
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Yapay Zeka İnceliyor...';
        btn.disabled = true;
        try {
            const formData = new FormData(); 
            formData.append('upload', file);
            formData.append('regions', 'tr');
            
            let apiToken = typeof CONFIG !== 'undefined' ? CONFIG.PLATE_RECOGNIZER_TOKEN : '';

            const response = await fetch('https://api.platerecognizer.com/v1/plate-reader/', {
                method: 'POST',
                headers: { 'Authorization': 'Token ' + apiToken },
                body: formData
            });
            const data = await response.json();
            if (data.results && data.results.length > 0) {
                const result = data.results[0];
                let finalPlate = result.plate.toUpperCase().replace(/[^A-Z0-9]/g, '');
                plateInput.value = finalPlate;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Plaka Yakalandı!';
            } else {
                Swal.fire({ icon: 'info', title: 'Plaka Görünmüyor', text: 'Yapay zeka bu fotoğrafta plaka tespit edemedi.', background: '#0f172a', color: '#fff' });
                btn.innerHTML = '<i class="fa-solid fa-camera"></i> Yeni Plaka Oku';
            }
        } catch (err) {
            Swal.fire({ icon: 'warning', title: 'Bağlantı Sorunu', text: 'API Token\'ını kontrol et.', background: '#0f172a', color: '#fff' });
            btn.innerHTML = '<i class="fa-solid fa-camera"></i> Yeni Plaka Oku';
        } finally {
            btn.disabled = false;
        }
    }

    async function startOCR() {
        if (isMobile) {
            const result = await Swal.fire({
                title: 'Plaka Okuma',
                text: 'Plakayı nasıl girmek istersiniz?',
                icon: 'question',
                showCloseButton: true,
                showDenyButton: true,
                showConfirmButton: true,
                showCancelButton: false,
                confirmButtonText: '<i class="fa-solid fa-camera"></i> Kamera',
                denyButtonText: '<i class="fa-solid fa-image"></i> Dosya Seç',
                background: '#0f172a',
                color: '#fff',
                reverseButtons: true
            });
            if (result.isDismissed) return;

            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            if (result.isConfirmed) {
                input.setAttribute('capture', 'environment');
            }
            input.onchange = (e) => processFile(e.target.files[0]);
            input.click();
        } else {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = (e) => processFile(e.target.files[0]);
            input.click();
        }
    }

document.addEventListener('click', function(e) {
        let slotElement = e.target.closest('.slot.occupied');
        if (slotElement) {
            let onclickAttr = slotElement.getAttribute('onclick') || '';
            let matches = onclickAttr.match(/exitVehicle\s*\(\s*(\d+)\s*,\s*['"]([^'"]+)['"]/);
            
            if (matches && matches[1] && matches[2]) {
                e.preventDefault();
                e.stopPropagation();
                exitVehicle(matches[1], matches[2]);
            }
        }
    }, true);
</script>
</body>
</html>