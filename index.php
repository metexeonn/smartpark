<?php
require_once 'config/config.php';
global $db;

error_reporting(0);
ini_set('display_errors', 0);

if (isset($_GET['get_data'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $db->query("
            SELECT p.*, 
            (SELECT COUNT(*) FROM vehicles v WHERE v.parking_id = p.id AND v.status = 'Inside') as active_vehicles 
            FROM parking_lots p
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Veri çekilemedi.']);
    }
    exit;
}

if (isset($_GET['get_inside_vehicles'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $db->query("
            SELECT v.plate, p.name as parking_name, v.entry_time as start_time, COALESCE(v.slot_name, '-') as slot_name 
            FROM vehicles v
            LEFT JOIN parking_lots p ON v.parking_id = p.id
            WHERE v.status = 'Inside'
            ORDER BY v.entry_time DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['check_reservation'])) {
    header('Content-Type: application/json');
    $inputPlate = trim($_GET['plate'] ?? '');
    $cleanInput = str_replace(' ', '', $inputPlate);
    if (empty($inputPlate)) {
        echo json_encode(['status' => 'error', 'message' => 'Lütfen bir plaka giriniz.']);
        exit;
    }

    try {
        $sqlActive = "SELECT r.*, p.name as parking_name FROM reservations r
                      LEFT JOIN parking_lots p ON r.parking_id = p.id
                      WHERE REPLACE(r.plate, ' ', '') = ? 
                      AND r.status IN ('pending', 'approved') 
                      AND (r.park_status NOT IN ('Çıkış Yaptı', 'İptal', 'Tamamlandı') OR r.park_status IS NULL)
                      ORDER BY r.created_at DESC LIMIT 1";
        $stmtActive = $db->prepare($sqlActive);
        $stmtActive->execute([$cleanInput]);
        $activeRes = $stmtActive->fetch(PDO::FETCH_ASSOC);

        $sqlHistory = "
            SELECT start_time, end_time, fee, status, park_status, parking_name, created_at FROM (
                SELECT r.start_time, r.end_time, r.fee, r.status, r.park_status, p.name as parking_name, r.created_at 
                FROM reservations r
                LEFT JOIN parking_lots p ON r.parking_id = p.id
                WHERE REPLACE(r.plate, ' ', '') = ?
                
                UNION ALL
                
                SELECT v.entry_time as start_time, COALESCE(v.exit_time, '-') as end_time, v.price as fee, 'Tamamlandı' as status, 'Çıkış Yaptı' as park_status, p.name as parking_name, v.entry_time as created_at
                FROM vehicles v
                LEFT JOIN parking_lots p ON v.parking_id = p.id
                WHERE REPLACE(v.plate, ' ', '') = ?
            ) AS combined_history
            ORDER BY created_at DESC LIMIT 10
        ";
        $stmtHistory = $db->prepare($sqlHistory);
        $stmtHistory->execute([$cleanInput, $cleanInput]);
        $historyResRaw = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
        
        $historyRes = [];
        $seen = [];
        foreach($historyResRaw as $row) {
            $key = date('Y-m-d H', strtotime($row['start_time'])) . '_' . $row['parking_name'];
            if(!isset($seen[$key])) {
                $seen[$key] = true;
                $historyRes[] = $row;
            }
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $activeRes ? $activeRes : null, 
            'history' => $historyRes ? $historyRes : []
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

$stmt = $db->query("
    SELECT p.*, 
    (SELECT COUNT(*) FROM vehicles v WHERE v.parking_id = p.id AND v.status = 'Inside') as active_vehicles 
    FROM parking_lots p
");
$parking_lots = $stmt->fetchAll();

$total_cap = 0;
$total_full = 0;

$chart_labels = [];
$chart_rates = [];

foreach ($parking_lots as $lot) {
    $total_cap += $lot['total_capacity'];
    $total_full += $lot['active_vehicles'];
    
    $lot_rate = ($lot['total_capacity'] > 0) ? round(($lot['active_vehicles'] / $lot['total_capacity']) * 100) : 0;
    $chart_labels[] = $lot['name'];
    $chart_rates[] = $lot_rate;
}
$total_empty = $total_cap - $total_full;
$total_rate = ($total_cap > 0) ? round(($total_full / $total_cap) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark İzmir - Canlı İzleme & Rezervasyon</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>

.btn-base {
    border: none;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-inactive {
    background: rgba(255,255,255,0.05);
    color: #94a3b8;
    border: 1px solid rgba(255,255,255,0.1);
}

.btn-active-state {
    background: var(--primary) !important;
    color: white !important;
    border: 1px solid var(--primary) !important;
}
        .active-btn-style {
        background: var(--primary) !important;
        color: white !important;
    }
    
    .matrix-layout-wrapper button {
        transition: all 0.4s ease-in-out !important;
    }
        
        .glass-search-input { background: rgba(15, 23, 42, 0.8) !important;
border: 1px solid rgba(255, 255, 255, 0.1) !important; color: white !important; padding: 12px 20px; border-radius: 10px; outline: none;
transition: all 0.3s ease; width: 300px; font-size: 14px; }
        .glass-search-input:focus { border-color: var(--primary) !important;
box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2); }
        .stats-container { display: grid;
grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: rgba(255,255,255,0.05);
padding: 20px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); text-align: center;
}
        .stat-val { font-size: 24px; font-weight: 700; margin-top: 5px; display: block;
}
        .stat-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;
}
        
        #resModal { display:none; position:fixed;
top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); backdrop-filter:blur(8px); z-index:9999; justify-content:center; align-items:center; }
        
        .check-card { background: rgba(15,23,42,0.6);
border: 1px solid rgba(59,130,246,0.3); border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;
}
        .check-input-wrapper { display: flex; gap: 10px; flex: 1; min-width: 280px; max-width: 500px;
}
        .check-input-wrapper input { flex: 1; padding: 12px 20px; border-radius: 8px;
border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); color: white; outline: none; font-size: 16px; text-transform: uppercase;
}
        .check-input-wrapper input:focus { border-color: var(--primary);
}
        .check-btn { background: var(--primary); color: white; border: none; padding: 12px 24px;
border-radius: 8px; cursor: pointer; font-weight: bold; transition: background 0.3s; white-space: nowrap;
}
        .check-btn:hover { background: #2563eb;
}
        
        .history-btn { background: #475569;
color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: background 0.3s; white-space: nowrap;
}
        .history-btn:hover { background: #334155;
}

        #checkResult, #historyResult { width: 100%; padding: 12px 15px; border-radius: 8px; font-weight: 500;
display: none; margin-top: 10px; font-size: 14px; }
        #checkResult { text-align: center;
}
        
        .btn-pay { background: #10b981;
color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; margin-top: 10px; font-weight: bold; text-decoration: none; display: inline-block;
}
        
        .faq-section { margin-top: 40px;
margin-bottom: 20px; }
        .faq-section { margin-top: 40px;
margin-bottom: 20px; }
        .faq-item { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px;
margin-bottom: 10px; overflow: hidden; transition: all 0.3s ease; }
        .faq-item summary { padding: 18px 20px;
font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none; outline: none; user-select: none;
}
        .faq-item summary::-webkit-details-marker { display: none;
}
        .faq-item summary:after { content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
color: var(--primary); transition: transform 0.3s; }
        .faq-item[open] summary:after { transform: rotate(180deg);
}
        .faq-item[open] { background: rgba(255,255,255,0.05); border-color: rgba(59,130,246,0.3);
}
        .faq-content { padding: 0 20px 18px 20px; color: var(--text-muted); font-size: 14px;
line-height: 1.6; }
        
        @media (max-width: 768px) {
            .check-card { flex-direction: column;
align-items: flex-start; }
            .check-input-wrapper { width: 100%; max-width: 100%;
flex-direction: column; }
            .check-btn, .history-btn { width: 100%;
}
            .matrix-layout-wrapper { grid-template-columns: 1fr !important; }
        }

        #modal_block_select option { background-color: #0f172a !important;
color: #ffffff !important; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 10px;
font-size: 13px; text-align: left; }
        .history-table th { background: rgba(255,255,255,0.08); padding: 10px;
color: var(--primary); font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .history-table td { padding: 10px;
border-bottom: 1px solid rgba(255,255,255,0.05); color: #cbd5e1; }

        /* Yeni Eklenen Alanların Orijinal Temaya Uygun CSS Kodları */
        .live-badge { background: #10b981; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; animation: livePulse 2s infinite; display: inline-flex; align-items: center; gap: 6px; }
        @keyframes livePulse { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
        .matrix-layout-wrapper { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }
        .chart-container-card { background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 12px; padding: 24px; display: flex; flex-direction: column; justify-content: center; }
        
        /* Mesafe Etiketi İçin Neon Stil Yapısı kanka */
        .tbl-distance-badge { background: rgba(16, 185, 129, 0.12); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.25); padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    </style>
</head>
<body>

<div id="resModal">
    <div style="background: rgba(15, 23, 42, 0.95); padding:30px; border-radius:15px; width:400px; border:1px solid rgba(255,255,255,0.1); color:white; position:relative; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);">
        <h3 style="margin-top:0; margin-bottom:20px; color: var(--primary);">Rezervasyon Oluştur</h3>
        <span onclick="closeModal()" style="position:absolute; top:15px; right:20px; cursor:pointer; font-size:24px;">&times;</span>
        
        <form id="resForm" style="display:flex; flex-direction:column; gap:15px;">
            <input type="hidden" name="parking_id" id="modal_parking_id">
        
        <input type="text" name="name" placeholder="Adınız" required style="padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
            <input type="text" name="surname" placeholder="Soyadınız" required style="padding:12px; border-radius:8px;
border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
            <input type="text" name="plate" placeholder="Araç Plakası (Örn: 35 ABC 123)" required style="padding:12px;
border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white; text-transform: uppercase;">
            <input type="email" name="email" placeholder="E-posta Adresiniz" required style="padding:12px;
border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
            
            <select name="slot_name" id="modal_block_select" required style="padding:12px;
border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
                <option value="" disabled selected>Park Edeceğiniz Bloğu Seçiniz</option>
            </select>

            <div style="display:flex;
gap:10px;">
                <input type="datetime-local" name="start_time" title="Giriş Saati" required style="flex:1;
padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
                <input type="datetime-local" name="end_time" title="Çıkış Saati" required style="flex:1;
padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:white;">
            </div>
            <button type="button" id="submitResBtn" style="padding:12px;
border-radius:8px; border:none; background:var(--primary); color:white; cursor:pointer; font-weight:bold; margin-top:10px;">Rezervasyonu Tamamla</button>
        </form>
    </div>
</div>

<div style="padding: 20px 40px;
border-bottom: 1px solid var(--card-border); display: flex; justify-content: space-between; align-items: center; background: rgba(15,23,42,0.6); backdrop-filter: blur(10px);">
    <div style="display: flex;
align-items: center; gap: 12px;">
        <i class="fa-solid fa-square-parking" style="font-size: 32px;
color: var(--primary);"></i>
        <div>
            <h1 style="font-size: 20px;
font-weight: 700;" class="gradient-text">SmartPark İzmir</h1>
            <p style="font-size: 11px;
color: var(--text-muted);">“İzmir’in Akıllı Otopark Takip Sistemi”</p>
        </div>
    </div>
    <div style="display: flex;
align-items: center; gap: 20px;">
        <span id="liveClock" style="font-size: 14px; font-weight: 500;
color: var(--secondary);"></span>
        <a href="login.php" class="btn btn-primary" style="padding: 8px 16px;
font-size:13px;">Yönetim Paneli <i class="fa-solid fa-lock"></i></a>
    </div>
</div>

<div class="main-content" style="margin-left: 0;
padding: 40px max(20px, 4%);">
    
    <div class="check-card">
        <div>
            <h3 style="margin:0 0 5px 0;
color:var(--primary); font-size: 18px;"><i class="fa-solid fa-magnifying-glass-chart"></i> Aktif Durum Sorgulama</h3>
            <p style="margin:0;
font-size:13px; color:var(--text-muted);">Plakanızı girerek aktif rezervasyon ve park durumunuzu anında görüntüleyin.</p>
        </div>
        <div style="flex:1;
display:flex; flex-direction:column; align-items:flex-end;">
            <div class="check-input-wrapper">
                <input type="text" id="checkPlateInput" placeholder="Örn: 35 ABC 123" onkeyup="this.value = this.value.toUpperCase();">
                <button class="check-btn" onclick="checkReservation()">Sorgula <i class="fa-solid fa-arrow-right"></i></button>
            </div>
            <div id="checkResult"></div>
        </div>
    </div>

  
  <div class="check-card" style="border-color: rgba(148, 163, 184, 0.3);">
        <div>
            <h3 style="margin:0 0 5px 0;
color:#cbd5e1; font-size: 18px;"><i class="fa-solid fa-clock-rotate-left"></i> Geçmiş Kayıt Sorgulama</h3>
            <p style="margin:0;
font-size:13px; color:var(--text-muted);">Plakanıza ait tamamlanmış eski tüm giriş-çıkış hareketlerini listeleyin.</p>
        </div>
        <div style="flex:1;
display:flex; flex-direction:column; align-items:flex-end;">
            <div class="check-input-wrapper">
                <input type="text" id="historyPlateInput" placeholder="Örn: 35 XYZ 789" onkeyup="this.value = this.value.toUpperCase();">
                <button class="history-btn" onclick="checkHistory()">Geçmişi Listele <i class="fa-solid fa-list"></i></button>
            </div>
        </div>
        <div id="historyResult" style="width: 100%;
background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255,255,255,0.1); max-height: 300px;
overflow-y: auto;"></div>
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <span class="stat-label">Toplam Kapasite</span>
            <span id="stat-total" class="stat-val"><?= $total_cap ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Aktif Araç</span>
            <span id="stat-full" class="stat-val" style="color: var(--danger);"><?= $total_full ?></span>
        </div>
         <div class="stat-card">
            <span class="stat-label">Boş Yer</span>
            <span id="stat-empty" class="stat-val" style="color: var(--success);"><?= $total_empty ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Doluluk Oranı</span>
            <span id="stat-rate" class="stat-val">%<?= $total_rate ?></span>
   </div>
    </div>

    <div class="glass-card" style="padding: 12px;
margin-bottom: 32px;">
        <div id="map" style="width: 100%; height: 480px;
border-radius: 12px;"></div>
    </div>

    <div class="glass-card" style="margin-bottom: 32px; border: 1px solid rgba(16, 185, 129, 0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #10b981;"><i class="fa-solid fa-car-tunnel"></i> Şu An Otoparktaki Araçlar (Kulübe Takip Paneli)</h3>
                <p style="margin: 5px 0 0 0; font-size: 13px; color: var(--text-muted);">İçeride bulunan araçların listesi ve yukarı doğru saniye saniye işleyen canlı kalış süreleri.</p>
            </div>
            <div class="live-badge"><i class="fa-solid fa-circle fa-fade"></i> ANLIK TAKİP</div>
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Araç Plakası</th>
                        <th>Bulunduğu Otopark</th>
                        <th>Park Bloğu</th>
                        <th>Giriş Saati</th>
                        <th>İçeride Geçen Süre (Canlı)</th>
                    </tr>
                </thead>
                <tbody id="inside-vehicles-body">
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Aktif araç verileri senkronize ediliyor...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="matrix-layout-wrapper">
        <div class="glass-card" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; width: 100%;">
                <div>
                    <h3 style="margin: 0 0 10px 0; font-size: 18px;">📊 Otopark Durum Matrisi</h3>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button onclick="filterMatrix('all')" id="btn-filter-all" style="background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">Tümü</button>
                        <button onclick="filterMatrix('available')" id="btn-filter-available" style="background: rgba(255,255,255,0.05); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;"><i class="fa-solid fa-circle-check"></i> Boş Yeri Olanlar</button>
                        <button onclick="filterMatrix('full')" id="btn-filter-full" style="background: rgba(255,255,255,0.05); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;"><i class="fa-solid fa-circle-xmark"></i> Tamamen Dolular</button>
                    </div>
                </div>
                <input type="text" id="searchInput" placeholder="🔍 Otopark adı veya lokasyon ara..." onkeyup="filterParking()" class="glass-search-input">
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Otopark Adı</th>
                            <th>Lokasyon Bilgisi</th>
                            <th>Mesafe</th>
                            <th>Toplam</th>
                            <th>Dolu</th>
                            <th>Boş</th>
                            <th>Doluluk Oranı</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                   <tbody id="parking-table-body">
                        <?php foreach($parking_lots as $lot): 
                            $full = $lot['active_vehicles'];
                            $total = $lot['total_capacity'];
                        
                            $empty = $total - $full;
                            $rate = ($total > 0) ?
round(($full / $total) * 100) : 0;
                        ?>
                        <tr id="lot-row-<?= $lot['id'] ?>" 
                            class="parking-lot-row"
                            data-lat="<?= htmlspecialchars($lot['latitude'] ?? '0') ?>" 
                            data-lng="<?= htmlspecialchars($lot['longitude'] ?? '0') ?>">
                            
                            <td style="font-weight: 600;"><i class="fa-solid fa-warehouse text-muted" style="margin-right: 8px;"></i> <?= $lot['name'] ?></td>
                            <td style="color: var(--text-muted);"><?= $lot['location'] ?></td>
                            
                            <td class="col-distance">
                                <span class="tbl-distance-badge" style="display: none;">
                                    <i class="fa-solid fa-location-dot"></i> <span>-</span> km
                                </span>
                                <small class="dist-loading" style="color: var(--text-muted); font-size: 11px;">Hesaplanıyor...</small>
                            </td>
                        
                            <td><strong><?= $total ?></strong></td>
                            <td class="col-full" style="color: var(--danger); font-weight: 600;"><?= $full ?></td>
                            <td class="col-empty" style="color: var(--success); font-weight: 600;"><?= $empty ?></td>
                        
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; background: rgba(255,255,255,0.05); height: 6px; border-radius: 4px; width: 100px; overflow:hidden;">
                                   <div class="progress-bar" style="width: <?= $rate ?>%; background: <?php if($rate < 50) echo '#10B981';
elseif($rate < 80) echo '#F59E0B'; else echo '#EF4444'; ?>; height: 100%;"></div>
                                    </div>
                                    <span class="badge col-badge"><?= $rate ?>%</span>
                                </div>
                            </td>
                            <td style="text-align:center; display: flex; gap: 6px; justify-content: center; align-items: center;">
                                <button onclick="openModal(<?= $lot['id'] ?>)" style="background:var(--primary);
border:none; padding:6px 12px; border-radius:6px; color:white; cursor:pointer; font-size:12px;">Rezervasyon</button>
                                <button onclick="gitGoogleMaps(<?= $lot['id'] ?>)" title="Haritada Gör" style="background:#1e293b; border:1px solid rgba(255,255,255,0.1); padding:6px 10px; border-radius:6px; color:#34d399; cursor:pointer; font-size:12px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-solid fa-map-location-dot"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="chart-container-card">
            <h3 style="margin: 0 0 20px 0; font-size: 16px; text-align: center; color: var(--secondary);">
                <i class="fa-solid fa-chart-pie"></i> Canlı Doluluk Grafiği
            </h3>
            <div style="position: relative; width: 100%; height: 280px;">
                <canvas id="liveOccupancyChart"></canvas>
            </div>
        </div>
    </div>

    <div class="faq-section">
        <h3 style="margin-bottom: 20px;
font-size: 18px;"><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Sıkça Sorulan Sorular</h3>
        <details class="faq-item">
            <summary>Rezervasyonumu nasıl iptal edebilirim?</summary>
            <div class="faq-content">Rezervasyon iptali veya değişiklik işlemleri için giriş saatinizden en az 1 saat önce iletişim numaralarımızdan veya e-posta üzerinden bize ulaşmanız yeterlidir.</div>
        </details>
        <details class="faq-item">
            <summary>Rezervasyon onay ne kadar sürede belli olur?</summary>
           <div class="faq-content">Sistemimize düşen talepler, adminlerimiz tarafından otopark yoğunluğuna göre incelenir og ortalama 15-30 dakika içerisinde onaylanır. Durumu yukarıdaki "Rezervasyon Sorgulama" panelinden takip edebilirsiniz.</div>
        </details>
        <details class="faq-item">
            <summary>Ödemeyi nasıl yapacağım?</summary>
            <div class="faq-content">Otopark ücretlerinizi çıkışta gişelerimizden nakit veya kredi kartı ile ödeyebileceğiniz gibi, SmartPark Online Ödeme altyapımız sayesinde 3D Secure güvencesiyle web sitemiz üzerinden hızlıca da gerçekleştirebilirsiniz.</div>
        </details>
        <details class="faq-item">
            <summary>Plakamı hatalı girdim, ne yapmalıyım?</summary>
            <div class="faq-content">Rezervasyon sırasında girdiğiniz plaka ile araç plakası uyuşmazsa giriş esnasında sorun yaşayabilirsiniz.
Lütfen vakit kaybetmeden doğru plakanız ile yeni bir kayıt oluşturun ve eskisinin iptali için bize bildirin.</div>
        </details>
    </div>

        <div class="safety-features">
        <div class="feature">
            <i class="fa-solid fa-video"></i>
            <span>7/24 Kamera Takibi</span>
        </div>
        <div class="feature">
            <i class="fa-solid fa-shield-halved"></i> 
            <span>Sigortalı Park Alanı</span>
        </div>
        <div class="feature">
            <i class="fa-solid fa-credit-card"></i> 
            <span>Güvenli Ödeme</span>
        </div>
        <div class="feature">
            <i class="fa-solid fa-headset"></i> 
            <span>7/24 Canlı Destek</span>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    var map = L.map('map').setView([38.4190, 27.1287], 11);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);
   
    var markers = {};
    var initialLocations = <?= json_encode($parking_lots) ?>;
    
    var userLocation = { lat: 38.4190, lon: 27.1287 };
    let lastTotalFullCount = null;
    
    let currentFilterStatus = 'all';

    function playNotificationSound() {
        try {
            let context = new (window.AudioContext || window.webkitAudioContext)();
            let osc = context.createOscillator();
            let gain = context.createGain();
           
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, context.currentTime);
            osc.frequency.setValueAtTime(880, context.currentTime + 0.1);
           
            gain.gain.setValueAtTime(0.3, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, context.currentTime + 0.4);
           
            osc.connect(gain);
            gain.connect(context.destination);
            osc.start();
            osc.stop(context.currentTime + 0.4);
        } catch (e) {
            console.log("Ses çalma tarayıcı tarafından engellendi. Sayfaya bir kez tıklamanız gerekebilir.");
        }
    }

    function generateBlocks(totalCapacity) {
        let select = document.getElementById('modal_block_select');
        select.innerHTML = '<option value="" disabled selected>Park Edeceğiniz Bloğu Seçiniz</option>';
       
        const blockNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'V', 'Y', 'Z'];
        let remaining = totalCapacity;
        let blockIndex = 0;
       
        while (remaining > 0 && blockIndex < blockNames.length) {
            let currentBlockCount = Math.min(remaining, 20);
            let char = blockNames[blockIndex];
           
            for (let i = 1; i <= currentBlockCount; i++) {
                let val = char + i;
                let opt = document.createElement('option');
                opt.value = val;
                opt.innerHTML = val;
                select.appendChild(opt);
            }
            remaining -= 20;
            blockIndex++;
        }
    }

    function openModal(id) {
        document.getElementById('modal_parking_id').value = id;
        let lot = initialLocations.find(l => l.id == id);
        if(lot) {
            generateBlocks(lot.total_capacity);
        }

        const startTimeInput = document.querySelector('input[name="start_time"]');
        const endTimeInput = document.querySelector('input[name="end_time"]');
        if (startTimeInput && endTimeInput) {
            function formatToDateTimeLocal(date) {
                const pad = (num) => String(num).padStart(2, '0');
                return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
            }
            let anlikSaat = new Date();
            startTimeInput.min = formatToDateTimeLocal(anlikSaat);
            startTimeInput.value = formatToDateTimeLocal(anlikSaat);
           
            let enErkenCikis = new Date(anlikSaat.getTime() + (60 * 60 * 1000));
            endTimeInput.min = formatToDateTimeLocal(enErkenCikis);
            endTimeInput.value = formatToDateTimeLocal(enErkenCikis);
        }
        document.getElementById('resModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('resModal').style.display = 'none';
    }

    document.getElementById('submitResBtn').addEventListener('click', function() {
        const btn = this;
        const startTimeInput = document.querySelector('input[name="start_time"]');
        const endTimeInput = document.querySelector('input[name="end_time"]');
        if (startTimeInput && endTimeInput) {
            let simdi = new Date();
            let toleransZamani = new Date(simdi.getTime() - (5 * 60 * 1000));
            let secilenGirar = new Date(startTimeInput.value);
            let secilenCikar = new Date(endTimeInput.value);
           
            if (secilenGirar < toleransZamani) {
                alert('Hata: Giriş tarihi geçmiş bir zaman olamaz!');
                return;
            }
           
            if (secilenCikar < new Date(secilenGirar.getTime() + (60 * 60 * 1000))) {
                alert('Hata: Çıkış tarihi, giriş tarihinden en az 1 saat sonra olmalıdır!');
                return;
            }
        }
       
        btn.disabled = true;
        let formData = new FormData(document.getElementById('resForm'));
        fetch('save_reservation.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.status === 'success') {
                alert('Başarılı! ' + data.message);
                closeModal();
                document.getElementById('resForm').reset();
                updateLiveStats();
            } else {
                alert('Hata: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Hata:', error);
            alert('Bir bağlantı hatası oluştu.');
        })
        .finally(() => {
            btn.disabled = false;
        });
    });

    function checkReservation() {
        const plateInput = document.getElementById('checkPlateInput').value.trim();
        const resultDiv = document.getElementById('checkResult');
       
        if(!plateInput) {
            resultDiv.style.display = 'block';
            resultDiv.style.background = 'rgba(239, 68, 68, 0.2)';
            resultDiv.style.color = '#ef4444';
            resultDiv.style.border = '1px solid #ef4444';
            resultDiv.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Lütfen sorgulamak için bir plaka giriniz!';
            return;
        }
       
        resultDiv.style.display = 'block';
        resultDiv.style.background = 'rgba(255,255,255,0.05)';
        resultDiv.style.color = '#fff';
        resultDiv.style.border = '1px solid rgba(255,255,255,0.1)';
        resultDiv.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sistemde aranıyor...';
        
        fetch('index.php?check_reservation=1&plate=' + encodeURIComponent(plateInput))
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                try {
                    let res = data.data;
                    let html = '';
                    let color = '#3b82f6';
                   
                    if(res) {
                        if(res.status === 'pending') {
                            html = `<b>ONAY BEKLİYOR</b> <i class="fa-solid fa-clock"></i><br><span style="font-size:12px; color:#cbd5e1;">Plaka: ${res.plate} - Otopark: ${res.parking_name || 'Belirtilmedi'} - Yönetici onayı bekleniyor.</span>`;
                            color = '#f59e0b';
                        } else if(res.status === 'approved' && (res.park_status === 'Bekliyor' || !res.park_status)) {
                            html = `<b>REZERVASYON ONAYLANDI!</b> <i class="fa-solid fa-circle-check"></i><br> <span style="font-size:12px; color:#cbd5e1;">Plaka: ${res.plate} - Otopark: ${res.parking_name || 'Belirtilmedi'}. Otoparka giriş yaptığınızda butona basarak sürenizi başlatın.</span><br> <button id="btnParkEtClick" onclick="parkEt('${res.plate}')" style="background:#3b82f6; color:white; border:none; padding:10px 20px; border-radius:6px; margin-top:10px; cursor:pointer; font-weight:bold;">📍 Park Ettim (Süreyi Başlat)</button>`;
                            color = '#3b82f6';
                        } else if(res.status === 'approved' && res.park_status === 'Park Halinde') {
                            html = `<b>ARAÇ OTOPARKTA</b> <i class="fa-solid fa-square-parking"></i><br> <span style="font-size:12px; color:#cbd5e1;">Plaka: ${res.plate} - Otopark: ${res.parking_name || 'Belirtilmedi'} - Şu an içeridesiniz.</span><br> <button onclick="cikisHesapla('${plateInput}')" style="background:#ef4444; color:white; border:none; padding:10px 20px; border-radius:6px; margin-top:10px; cursor:pointer; font-weight:bold;">🚗 Çıkış Yap ve Ücret Hesapla</button>`;
                            color = '#10b981';
                        } else if(res.status === 'approved' && res.park_status === 'Çıkış Bekliyor' && res.payment_status === 'Nakit Bekliyor') {
                            html = `<b>NAKİT ÖDEME BEKLENİYOR</b> <i class="fa-solid fa-cash-register"></i><br><span style="font-size:13px; color:#fff;">Ödenecek Tutar: <b>${res.fee || 0} TL</b></span><br><span style="font-size:12px; color:#cbd5e1;">Plaka: ${res.plate} - Otopark: ${res.parking_name || 'Belirtilmedi'}<br>Lütfen ödemenizi gişeye yapınız. Yönetici onayı bekleniyor...</span>`;
                            color = '#f59e0b';
                        } else {
                            html = `<b>DURUM: ${res.park_status ? res.park_status.toUpperCase() : 'İŞLEMDE'}</b><br><span style="font-size:12px; color:#cbd5e1;">Plaka: ${res.plate} - Otopark: ${res.parking_name || 'Belirtilmedi'}</span>`;
                            color = '#f59e0b';
                        }
                    } else {
                        html = `<b>AKTİF KAYIT BULUNAMADI</b> <i class="fa-solid fa-circle-xmark"></i><br><span style="font-size:12px; color:#cbd5e1;">Bu plakaya ait aktif bir rezervasyon veya otopark içi araç kaydı bulunamadı.</span>`;
                        color = '#ef4444';
                    }
                   
                    resultDiv.style.background = color + '1A';
                    resultDiv.style.borderColor = color;
                    resultDiv.style.color = color;
                    resultDiv.innerHTML = html;
                } catch(e) {
                    console.error(e);
                    resultDiv.innerHTML = 'Veri işleme hatası oluştu.';
                }
            } else {
                resultDiv.innerHTML = data.message;
            }
        });
    }

    function parkEt(plate) {
        let btn = document.getElementById('btnParkEtClick');
        if(btn) btn.disabled = true;

        let formData = new FormData();
        formData.append('action', 'park_et');
        formData.append('plate', plate);
        
        fetch('park_islemleri.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                Swal.fire('Başarılı', data.message, 'success');
                checkReservation();
                updateLiveStats();
            } else {
                Swal.fire('Hata', data.message, 'error');
                if(btn) btn.disabled = false;
            }
        })
        .catch(() => {
            if(btn) btn.disabled = false;
        });
    }

    function cikisHesapla(sorgulananPlaka) {
        if (!sorgulananPlaka) {
            Swal.fire('Hata', 'Lütfen plaka giriniz.', 'error');
            return;
        }

        let formData = new FormData();
        formData.append('action', 'cikis_hesapla');
        formData.append('plate', sorgulananPlaka);
        
        fetch('park_islemleri.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                let resId = data.id;
                let resMinutes = data.minutes;
                let resFee = data.fee;

                Swal.fire({
                    title: 'Ödeme ve Çıkış İşlemi',
                    html: `
                        <div style="text-align: left; font-size: 15px;">
                            <p><strong>Plaka:</strong> ${sorgulananPlaka.toUpperCase()}</p>
                            <p><strong>İçeride Kalınan Süre:</strong> ${resMinutes} Dakika</p>
                            <p style="font-size: 18px; color: #10b981;"><strong>Toplam Ücret:</strong> ${resFee} TL</p>
                            <hr style="border-color: rgba(255,255,255,0.1)">
                            <p style="font-size: 13px; color: #94a3b8;">Lütfen ödemeyi online kart ile veya otopark görevlisine nakit olarak yapınız.</p>
                        </div>
                    `,
                    icon: 'info',
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonColor: '#10b981',
                    denyButtonColor: '#f59e0b',
                    cancelButtonColor: '#ef4444',
                    confirmButtonText: '<i class="fa-solid fa-credit-card"></i> Kart ile Öde',
                    denyButtonText: '<i class="fa-solid fa-money-bill-wave"></i> Nakit Öde',
                    cancelButtonText: 'İptal',
                    background: '#1e293b',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        kartlaOde(resId, resFee);
                    } else if (result.isDenied) {
                        nakitTalepEt(resId, resFee);
                    }
                });
            } else {
                Swal.fire('Hata', data.message || 'Bir sorun oluştu.', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Hata', 'Sunucu ile iletişim kurulamadı.', 'error');
        });
    }

    function nakitTalepEt(id, fee) {
        let formData = new FormData();
        formData.append('action', 'nakit_talep_et');
        formData.append('id', id);
        formData.append('fee', fee);
       
        fetch('park_islemleri.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                Swal.fire('Talep Alındı', data.message, 'success');
                checkReservation();
                updateLiveStats();
            } else {
                Swal.fire('Hata', data.message, 'error');
            }
        });
    }

function kartlaOde(id, fee) {
        let formData = new FormData();
        formData.append('action', 'kart_ile_ode');
        formData.append('id', id);
        formData.append('fee', fee);
        
        fetch('park_islemleri.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                window.location.href = "odeme.php?id=" + id;
            } else {
                Swal.fire('Hata', data.message, 'error');
            }
        });
    }

    function gitGoogleMaps(parkingId) {
        let lot = initialLocations.find(l => l.id == parkingId);
        if (!lot || !lot.latitude || !lot.longitude) {
            Swal.fire('Hata', 'Otoparkın koordinat bilgileri bulunamadı!', 'error');
            return;
        }

        let destLat = lot.latitude;
        let destLon = lot.longitude;
        let url = `https://www.google.com/maps/dir/?api=1&destination=${destLat},${destLon}&travelmode=driving`;
        window.open(url, '_blank');
    }
   

    function checkHistory() {
        const plateInput = document.getElementById('historyPlateInput').value.trim();
        const resultDiv = document.getElementById('historyResult');
       
        if(!plateInput) {
            Swal.fire('Hata', 'Lütfen geçmiş sorgulaması için bir plaka giriniz!', 'warning');
            return;
        }
       
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="padding:15px; text-align:center; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Geçmiş hareketler yükleniyor...</div>';
        
        fetch('index.php?check_reservation=1&plate=' + encodeURIComponent(plateInput))
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success' && data.history && data.history.length > 0) {
                let html = '<table class="history-table"><thead><tr><th>Otopark</th><th>Giriş Zamanı</th><th>Çıkış Zamanı</th><th>Ücret</th><th>Durum</th></tr></thead><tbody>';
                data.history.forEach(item => {
                    let feeText = item.fee ? item.fee + ' TL' : '-';
                    let statusText = item.park_status ? item.park_status : item.status;
                    let badgeColor = '#475569';
                   
                    if(statusText === 'Çıkış Yaptı' || statusText === 'Tamamlandı') badgeColor = '#10b981';
                    if(statusText === 'İptal' || statusText === 'rejected') badgeColor = '#ef4444';
                   
                    let cikisZamani = item.end_time ? item.end_time : 'İçeride';

                    html += `<tr>
                        <td><b>${item.parking_name || 'Bilinmiyor'}</b></td>
                        <td>${item.start_time}</td>
                        <td>${cikisZamani}</td>
                        <td style="color:#10b981; font-weight:600;">${feeText}</td>
                        <td><span style="background:${badgeColor}2b; color:${badgeColor}; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; border:1px solid ${badgeColor}4d;">${statusText}</span></td>
                    </tr>`;
                });
                html += '</tbody></table>';
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8;"><i class="fa-solid fa-folder-open"></i> Bu plakaya ait geçmiş bir hareket bulunamadı.</div>';
            }
        })
        .catch(err => {
            console.error(err);
            resultDiv.innerHTML = '<div style="padding:20px; text-align:center; color:#ef4444;">Veri yüklenirken hata oluştu.</div>';
        });
    }

    function initMarkers() {
        initialLocations.forEach(function(lot) {
            var markerColor = '#10b981';
            var rate = (lot.total_capacity > 0) ? (lot.active_vehicles / lot.total_capacity) * 100 : 0;
            if(rate >= 80) markerColor = '#ef4444';
            else if(rate >= 50) markerColor = '#f59e0b';
         
            var customIcon = L.divIcon({
                className: 'custom-marker',
                html: `<div style="background-color: ${markerColor}; width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 8px ${markerColor};"></div>`,
                iconSize: [14, 14],
                iconAnchor: [7, 7]
            });
           
            var m = L.marker([lot.latitude, lot.longitude], {icon: customIcon}).addTo(map);
            var empty = lot.total_capacity - lot.active_vehicles;
            m.bindPopup(`<b>${lot.name}</b><br>${lot.location}<br><br>Kapasite: ${lot.total_capacity}<br>Dolu: ${lot.active_vehicles} | Boş: ${empty}<br><br><button onclick="showDistancesFrom('${lot.id}', '${lot.name}', ${lot.latitude}, ${lot.longitude})" style="background:#3b82f6; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px; font-weight:bold; width:100%;">📏 Diğer Otoparklara Mesafesi</button>`);
            markers[lot.id] = m;
        });
        
        getUserLiveLocation();
    }
   
    initMarkers();

    function updateLiveStats() {
        fetch('index.php?get_data=1')
        .then(res => res.json())
        .then(data => {
            if (Array.isArray(data)) {
                let totalCap = 0;
                let totalFull = 0;
               
                data.forEach(lot => {
                    totalCap += parseInt(lot.total_capacity) || 0;
                    totalFull += parseInt(lot.active_vehicles) || 0;
           
                    let row = document.getElementById('lot-row-' + lot.id);
                    if (row) {
                        let empty = lot.total_capacity - lot.active_vehicles;
                        let rate = (lot.total_capacity > 0) ? Math.round((lot.active_vehicles / lot.total_capacity) * 100) : 0;
                       
                        row.querySelector('.col-full').innerText = lot.active_vehicles;
                        row.querySelector('.col-empty').innerText = empty;
                        row.querySelector('.col-badge').innerText = rate + '%';
                        
                        let distanceCell = row.querySelector('.col-distance');
                        if (distanceCell && userLocation) {
                            let calculatedDist = calculateDistance(userLocation.lat, userLocation.lon, lot.latitude, lot.longitude);
                            distanceCell.innerText = calculatedDist + ' KM';
                        }
                       
                        let bar = row.querySelector('.progress-bar');
                        if (bar) {
                            bar.style.width = rate + '%';
                            if (rate < 50) bar.style.backgroundColor = '#10B981';
                            else if (rate < 80) bar.style.backgroundColor = '#F59E0B';
                            else bar.style.backgroundColor = '#EF4444';
                        }
                    }
                   
                    if (markers[lot.id]) {
                        let empty = lot.total_capacity - lot.active_vehicles;
                        markers[lot.id].setPopupContent(`<b>${lot.name}</b><br>${lot.location}<br><br>Kapasite: ${lot.total_capacity}<br>Dolu: ${lot.active_vehicles} | Boş: ${empty}<br><br><button onclick="showDistancesFrom('${lot.id}', '${lot.name}', ${lot.latitude}, ${lot.longitude})" style="background:#3b82f6; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px; font-weight:bold; width:100%;">📏 Diğer Otoparklara Mesafesi</button>`);
                    }
                });

                let totalEmpty = totalCap - totalFull;
                let totalRate = (totalCap > 0) ? Math.round((totalFull / totalCap) * 100) : 0;
                document.getElementById('stat-total').innerText = totalCap;
                document.getElementById('stat-full').innerText = totalFull;
                document.getElementById('stat-empty').innerText = totalEmpty;
                document.getElementById('stat-rate').innerText = '%' + totalRate;
               
                if (typeof updateChart === "function") {
                    updateChart(data);
                }
               
                if (lastTotalFullCount !== null && totalFull > lastTotalFullCount) {
                    playNotificationSound();
                }
                lastTotalFullCount = totalFull;
                initialLocations = data;

                filterParking();
            }
        })
        .catch(err => {
            console.error('Canlı veri senkronizasyon hatası:', err);
        });
    }
   
    setInterval(updateLiveStats, 5000);


document.addEventListener("DOMContentLoaded", function() {
    window.currentFilterStatus = 'all';
    
    let allBtn = document.getElementById('btn-filter-all');
    if (allBtn) {
        allBtn.classList.add('active-btn-style');
    }
    
    filterParking();
});

window.filterMatrix = function(status) {
    window.currentFilterStatus = status;

    document.querySelectorAll('.matrix-layout-wrapper button').forEach(btn => {
        btn.classList.remove('btn-active-state');
        btn.classList.add('btn-inactive');
    });

    let activeBtn = document.getElementById('btn-filter-' + status);
    if (activeBtn) {
        activeBtn.classList.remove('btn-inactive');
        activeBtn.classList.add('btn-active-state');
    }

    filterParking();
};

document.addEventListener("DOMContentLoaded", function() {
    window.currentFilterStatus = 'all';
    let allBtn = document.getElementById('btn-filter-all');
    if (allBtn) {
        allBtn.classList.add('btn-active-state');
    }
    filterParking();
});

window.filterParking = function() {
    var input = document.getElementById('searchInput').value.toUpperCase();
    var table = document.getElementById('parking-table-body');
    var tr = table.getElementsByTagName('tr');
    
    for (var i = 0; i < tr.length; i++) {
        var emptyCol = tr[i].querySelector('.col-empty');
        var emptyCount = emptyCol ? parseInt(emptyCol.innerText) : 0;
        
        var rowText = tr[i].innerText.toUpperCase();
        var matchesSearch = (input === "" || rowText.indexOf(input) > -1);
        
        var matchesButton = false;
        if (window.currentFilterStatus === 'all') {
            matchesButton = true;
        } else if (window.currentFilterStatus === 'available') {
            matchesButton = (emptyCount > 0);
        } else if (window.currentFilterStatus === 'full') {
            matchesButton = (emptyCount === 0);
        }

        tr[i].style.display = (matchesSearch && matchesButton) ? "" : "none";
    }
};

    function updateClock() {
        const now = new Date();
        const days = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        const pad = (n) => String(n).padStart(2, '0');
        const timeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        const dateStr = `${pad(now.getDate())}.${pad(now.getMonth()+1)}.${now.getFullYear()} ${days[now.getDay()]}`;
       
        document.getElementById('liveClock').innerHTML = `<i class="fa-regular fa-calendar-days"></i> ${dateStr} | <i class="fa-regular fa-clock"></i> ${timeStr}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    const startTimeInput = document.querySelector('input[name="start_time"]');
    const endTimeInput = document.querySelector('input[name="end_time"]');
    if (startTimeInput && endTimeInput) {
        function formatToDateTimeLocal(date) {
            const pad = (num) => String(num).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
        }

        startTimeInput.addEventListener('change', function() {
            let simdi = new Date();
            let toleransZamani = new Date(simdi.getTime() - (5 * 60 * 1000));
            let secilenGiris = new Date(this.value);
           
            if (secilenGiris < toleransZamani) {
                this.value = formatToDateTimeLocal(simdi);
                secilenGiris = simdi;
            }
           
            let enErkenCikis = new Date(secilenGiris.getTime() + (60 * 60 * 1000));
            endTimeInput.min = formatToDateTimeLocal(enErkenCikis);
       
            if (!endTimeInput.value || new Date(endTimeInput.value) < enErkenCikis) {
                endTimeInput.value = formatToDateTimeLocal(enErkenCikis);
            }
        });

        endTimeInput.addEventListener('change', function() {
            let secilenGiris = new Date(startTimeInput.value);
            let enErkenCikis = new Date(secilenGiris.getTime() + (60 * 60 * 1000));
            let secilenCikis = new Date(this.value);
           
            if (secilenCikis < enErkenCikis) {
                this.value = formatToDateTimeLocal(enErkenCikis);
            }
        });
    }

    function updateInsideVehicles() {
        fetch('index.php?get_inside_vehicles=1')
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('inside-vehicles-body');
            if (!tbody) return;

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:20px;">Şu an içeride araç bulunmuyor.</td></tr>';
                return;
            }

            let html = '';
            data.forEach(car => {
                let giris = new Date(car.start_time);
                let simdi = new Date();
                let farkMs = simdi - giris;
               
                let sn = Math.floor((farkMs / 1000) % 60);
                let dk = Math.floor((farkMs / (1000 * 60)) % 60);
                let sa = Math.floor((farkMs / (1000 * 60 * 60)));
               
                let sureStr = `${String(sa).padStart(2,'0')}:${String(dk).padStart(2,'0')}:${String(sn).padStart(2,'0')}`;
                html += `<tr>
                    <td style="font-weight:600; color:#fff;">${car.plate}</td>
                    <td>${car.parking_name}</td>
                    <td>${car.slot_name || '-'}</td>
                    <td>${car.start_time}</td>
                    <td style="color:#10b981; font-family:monospace;">${sureStr}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        })
        .catch(err => console.error('Araç listesi çekilemedi:', err));
    }

    setInterval(updateInsideVehicles, 5000);
    updateInsideVehicles();

    const ctx = document.getElementById('liveOccupancyChart').getContext('2d');
    let occupancyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($parking_lots, 'name')) ?>,
            datasets: [{
                label: 'Doluluk Oranı (%)',
                data: <?= json_encode(array_map(function($lot) {
                    return ($lot['total_capacity'] > 0) ? round(($lot['active_vehicles'] / $lot['total_capacity']) * 100) : 0;
                }, $parking_lots)) ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.6)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.1)' } },
                x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            },
            plugins: { legend: { labels: { color: '#fff' } } }
        }
    });

    function updateChart(newData) {
        occupancyChart.data.datasets[0].data = newData.map(lot =>
            (lot.total_capacity > 0) ? Math.round((lot.active_vehicles / lot.total_capacity) * 100) : 0
        );
        occupancyChart.update();
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = 
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return (R * c).toFixed(2);
    }

    function getUserLiveLocation() {
        refreshDistanceTable();
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                userLocation = {
                    lat: position.coords.latitude,
                    lon: position.coords.longitude
                };
              
                refreshDistanceTable();
            }, function(error) {
                console.log("Konum izni reddedildi veya hata oluştu. Varsayılan merkez nokta baz alınarak mesafeler basılıyor.");
                refreshDistanceTable();
            });
        } else {
            console.log("Tarayıcı konum servislerini desteklemiyor.");
            refreshDistanceTable();
        }
    }

    function refreshDistanceTable() {
        if(!userLocation) return;
        initialLocations.forEach(lot => {
            let row = document.getElementById('lot-row-' + lot.id);
            if (row) {
                let distanceCell = row.querySelector('.col-distance');
                if (distanceCell) {
                    let dist = calculateDistance(userLocation.lat, userLocation.lon, lot.latitude, lot.longitude);
                    distanceCell.innerText = dist + ' KM';
                }
            }
        });
    }

    function showDistancesFrom(currentId, currentName, currentLat, currentLon) {
        let distanceHtml = `<div style="text-align:left; max-height:200px; overflow-y:auto; font-size:14px; color:#fff;">`;
        let foundOther = false;

        initialLocations.forEach(lot => {
            if (lot.id != currentId) {
                foundOther = true;
                let dist = calculateDistance(currentLat, currentLon, lot.latitude, lot.longitude);
                distanceHtml += `<p style="margin:8px 0; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:5px;">
                    <i class="fa-solid fa-location-dot" style="color:#3b82f6;"></i> <b>${lot.name}</b>: 
                    <span style="color:#10b981; float:right; font-weight:bold;">${dist} KM</span>
                </p>`;
            }
        });
        if (!foundOther) {
            distanceHtml += `<p style="color:#94a3b8; text-align:center;">Sistemde karşılaştırma yapılacak başka otopark bulunamadı.</p>`;
        }
        distanceHtml += `</div>`;

        Swal.fire({
            title: `${currentName} - Mesafe Analizi`,
            html: distanceHtml,
            icon: 'info',
            background: '#1e293b',
            color: '#fff',
            confirmButtonColor: '#3b82f6',
            confirmButtonText: 'Kapat'
        });
    }
</script>
</body>
</html>