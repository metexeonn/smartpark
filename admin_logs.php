<?php
require_once 'config/config.php';
global $db;

error_reporting(0);
ini_set('display_errors', 0);

$adminName = $_SESSION['admin_username'] ?? 'admin';

try {
    $sqlAllLogs = "
        SELECT 
            CASE 
                WHEN r.name = 'Manuel Giriş' OR r.email = 'manuel@smartpark.com' THEN CONVERT('Manuel Giriş / AI' USING utf8mb4)
                ELSE CONVERT('Rezervasyon' USING utf8mb4)
            END as islem_tipi, 
            CONVERT(r.plate USING utf8mb4) as plate, 
            CONVERT(r.slot_name USING utf8mb4) as slot_name,
            r.start_time as start_time, 
            r.end_time as end_time, 
            r.fee as fee, 
            CONVERT(r.status USING utf8mb4) as status, 
            CONVERT(r.park_status USING utf8mb4) as park_status, 
            CONVERT(p.name USING utf8mb4) as parking_name, 
            r.created_at as created_at
        FROM reservations r
        LEFT JOIN parking_lots p ON r.parking_id = p.id
        
        UNION ALL
        
        SELECT 
            CONVERT('Manuel Giriş / AI' USING utf8mb4) as islem_tipi, 
            CONVERT(v.plate USING utf8mb4) as plate, 
            CONVERT(v.slot_name USING utf8mb4) as slot_name,
            v.entry_time as start_time, 
            v.exit_time as end_time, 
            v.price as fee, 
            CONVERT('Tamamlandı' USING utf8mb4) as status, 
            CONVERT('Çıkış Yaptı' USING utf8mb4) as park_status, 
            CONVERT(p.name USING utf8mb4) as parking_name, 
            v.entry_time as created_at
        FROM vehicles v
        LEFT JOIN parking_lots p ON v.parking_id = p.id
        ORDER BY created_at DESC
    ";
    
    $stmt = $db->query($sqlAllLogs);
    $raw_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs = [];
    $seen = []; 

    foreach ($raw_logs as $log) {
        $plate = trim($log['plate']);
        $startTime = $log['start_time'];
        $uniqueKey = $plate . '_' . $startTime; 

        if (!isset($seen[$uniqueKey])) {
            $seen[$uniqueKey] = count($logs);
            $logs[] = $log;
        } else {
            $existingIndex = $seen[$uniqueKey];
            
            if ($log['islem_tipi'] == 'Rezervasyon') {
                $displayEndTime = !empty($log['end_time']) ? $log['end_time'] : $logs[$existingIndex]['end_time'];
                $log['end_time'] = $displayEndTime;
                $logs[$existingIndex] = $log;
            } else {
                if (!empty($log['end_time']) && $log['end_time'] != '0000-00-00 00:00:00') {
                    $logs[$existingIndex]['end_time'] = $log['end_time'];
                }
            }
        }
    }

    $realExitTimes = [];
    foreach ($logs as $l) {
        if ($l['islem_tipi'] == 'Manuel Giriş / AI' && !empty($l['end_time']) && $l['end_time'] != '0000-00-00 00:00:00') {
            if (!isset($realExitTimes[$l['plate']])) {
                $realExitTimes[$l['plate']] = $l['end_time'];
            }
        }
    }
} catch (Exception $e) {
    $error_msg = "Loglar çekilirken hata oluştu: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark İzmir - Gelişmiş Sistem Logları</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --success: #10b981;
            --text: #f3f4f6;
            --text-muted: #9ca3af;
            --bg-main: #0b0f17;
            --card-bg: rgba(22, 27, 34, 0.6);
            --border: rgba(255, 255, 255, 0.08);
        }
        
        body {
            background-color: var(--bg-main);
            color: var(--text);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }

        .admin-wrapper {
            max-width: 1400px;
            margin: 0 auto;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        .gradient-text {
            background: linear-gradient(45deg, #58a6ff, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-danger {
            background-color: #dc2626;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-danger:hover { background-color: #b91c1c; }

        .filter-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-box-container {
            position: relative;
            flex: 1;
            min-width: 280px;
        }

        .search-box-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .search-input {
            width: 100%;
            padding: 12px 12px 12px 42px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .search-input:focus { border-color: var(--primary); }

        .tab-container { display: flex; gap: 10px; }

        .tab-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
        }
        .tab-btn.active {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border-color: rgba(59, 130, 246, 0.4);
        }
        .tab-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
        }

        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th {
            padding: 16px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 14px; color: #e5e7eb; }

        .log-row { transition: background 0.2s; }
        .log-row:hover { background: rgba(255, 255, 255, 0.02); }

        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-ai { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-manual { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        
        .status-badge { color: var(--text-muted); font-size: 13px; }
        .status-badge.completed { color: var(--success); font-weight: 600; }

        .mobile-card {
            display: none;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 15px;
        }
        .mobile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .mobile-card-body .info-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; }
        .mobile-card-body .info-row .label { color: var(--text-muted); }
        .mobile-card-body .info-row .value { font-weight: 500; }

        @media (max-width: 992px) {
            body { padding: 10px; }
            table, thead, tbody { display: none; }
            .mobile-card { display: block; }
            .filter-wrapper { flex-direction: column; align-items: stretch; }
            .tab-container { justify-content: space-between; }
            .tab-btn { flex: 1; text-align: center; padding: 10px 5px; font-size: 11px; }
        }
    </style>
</head>
<body>

<div class="admin-wrapper">
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <i class="fa-solid fa-square-parking" style="font-size: 32px; color: var(--primary);"></i>
            <div>
                <h1 class="gradient-text" style="font-size: 22px; margin: 0; font-weight: 700;">SmartPark İzmir Paneli</h1>
                <p style="font-size: 12px; color: var(--text-muted); margin:0;">2026 Nesil Yönetim Merkezi</p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <a href="admin.php" style="color: #60a5fa; font-weight: bold; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-left"></i> Panel Ana Sayfa
            </a>
            <span style="font-size: 14px; color: var(--text-muted);">Hoş geldin, <span style="color:var(--text); font-weight:600;">@<?= htmlspecialchars($adminName) ?></span></span>
            <a href="logout.php" class="btn btn-danger" style="padding: 8px 16px; font-size:13px; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-power-off"></i> Çıkış
            </a>
        </div>
    </div>

    <div class="glass-card">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 25px;">
            <i class="fa-solid fa-list-check" style="font-size: 20px; color: var(--primary);"></i>
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: var(--text);">Tüm Sistem Giriş/Çıkış Logları</h3>
        </div>

        <div class="filter-wrapper">
            <div class="search-box-container">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="logSearch" class="search-input" placeholder="Plaka, Otopark veya Blok Ara...">
            </div>
            <div class="tab-container">
                <button class="tab-btn active" onclick="filterType('all', this)">Her Şey</button>
                <button class="tab-btn" onclick="filterType('Rezervasyon', this)">Rezervasyon</button>
                <button class="tab-btn" onclick="filterType('Manuel Giriş / AI', this)">Manuel Giriş / AI</button>
            </div>
        </div>

        <?php if (isset($error_msg)): ?>
            <div style="color: #ef4444; padding: 10px; background: rgba(239,68,68,0.1); border-radius: 8px;"><?= $error_msg ?></div>
        <?php else: ?>
            
            <div class="table-responsive">
                <table id="logTable">
                    <thead>
                        <tr>
                            <th>İşlem Tipi</th>
                            <th>Plaka</th>
                            <th>Otopark</th>
                            <th>Blok</th>
                            <th>Giriş / Başlangıç</th>
                            <th>Çıkış / Bitiş</th>
                            <th>Ücret</th>
                            <th>Rez. Durumu</th>
                            <th>Park Durumu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php 
                            $displayEndTime = $log['end_time'];
                            if (empty($displayEndTime) || $displayEndTime == '0000-00-00 00:00:00') {
                                if (isset($realExitTimes[$log['plate']])) {
                                    $displayEndTime = $realExitTimes[$log['plate']];
                                }
                            }
                            
                            $hasExit = (!empty($displayEndTime) && $displayEndTime != '0000-00-00 00:00:00');

                            if ($hasExit) {
                                $rezDurumu = 'Tamamlandı';
                                $parkDurumu = 'Çıkış Yaptı';
                            } else {
                                if (!empty($log['status'])) {
                                    $rezDurumu = ($log['status'] == 'approved' || $log['status'] == 'inside') ? 'Aktif' : $log['status'];
                                } else {
                                    $rezDurumu = ($log['islem_tipi'] == 'Rezervasyon') ? 'Tamamlandı' : 'Aktif';
                                }

                                if (!empty($log['park_status'])) {
                                    $parkDurumu = ($log['park_status'] == 'approved' || $log['park_status'] == 'inside') ? 'İçeride' : $log['park_status'];
                                } else {
                                    $parkDurumu = 'İçeride';
                                }
                            }
                            ?>
                            <tr class="log-row" data-type="<?= htmlspecialchars($log['islem_tipi']) ?>">
                                <td>
                                    <span class="badge <?= $log['islem_tipi'] == 'Rezervasyon' ? 'badge-ai' : 'badge-manual' ?>">
                                        <?= htmlspecialchars($log['islem_tipi']) ?>
                                    </span>
                                </td>
                                <td><strong style="color: #fff; letter-spacing: 0.5px;"><?= htmlspecialchars($log['plate']) ?></strong></td>
                                <td><?= htmlspecialchars($log['parking_name'] ?? '-') ?></td>
                                <td><span style="color: #38bdf8; font-weight: 600;"><?= htmlspecialchars($log['slot_name'] ?? '-') ?></span></td>
                                <td><?= htmlspecialchars($log['start_time'] ?? '-') ?></td>
                                <td>
                                    <?php if (!$hasExit): ?>
                                        <span style="color: #f59e0b; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-clock" style="font-size: 12px;"></i> İçeride
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #34d399; font-weight: 600;"><?= htmlspecialchars($displayEndTime) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: #34d399; font-weight: 600;"><?= number_format((float)($log['fee'] ?? 0), 2) ?> TL</td>
                                <td class="status-badge <?= ($rezDurumu == 'Tamamlandı' || $rezDurumu == 'Onaylandı') ? 'completed' : '' ?>">
                                    <?= htmlspecialchars($rezDurumu) ?>
                                </td>
                                <td class="status-badge <?= $parkDurumu == 'Çıkış Yaptı' ? 'completed' : '' ?>">
                                    <?= htmlspecialchars($parkDurumu) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="mobileCardsContainer">
                <?php foreach ($logs as $log): ?>
                    <?php 
                    $displayEndTime = $log['end_time'];
                    if (empty($displayEndTime) || $displayEndTime == '0000-00-00 00:00:00') {
                        if (isset($realExitTimes[$log['plate']])) {
                            $displayEndTime = $realExitTimes[$log['plate']];
                        }
                    }
                    
                    $hasExit = (!empty($displayEndTime) && $displayEndTime != '0000-00-00 00:00:00');

                    if ($hasExit) {
                        $rezDurumu = 'Tamamlandı';
                        $parkDurumu = 'Çıkış Yaptı';
                    } else {
                        if (!empty($log['status'])) {
                            $rezDurumu = ($log['status'] == 'approved' || $log['status'] == 'inside') ? 'Aktif' : $log['status'];
                        } else {
                            $rezDurumu = ($log['islem_tipi'] == 'Rezervasyon') ? 'Tamamlandı' : 'Aktif';
                        }

                        if (!empty($log['park_status'])) {
                            $parkDurumu = ($log['park_status'] == 'approved' || $log['park_status'] == 'inside') ? 'İçeride' : $log['park_status'];
                        } else {
                            $parkDurumu = 'İçeride';
                        }
                    }
                    ?>
                    <div class="mobile-card" data-type="<?= htmlspecialchars($log['islem_tipi']) ?>">
                        <div class="mobile-card-header">
                            <span class="badge <?= $log['islem_tipi'] == 'Rezervasyon' ? 'badge-ai' : 'badge-manual' ?>">
                                <?= htmlspecialchars($log['islem_tipi']) ?>
                            </span>
                            <strong style="color: #fff; font-size: 16px;"><?= htmlspecialchars($log['plate']) ?></strong>
                        </div>
                        <div class="mobile-card-body">
                            <div class="info-row"><span class="label">Otopark:</span><span class="value"><?= htmlspecialchars($log['parking_name'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="label">Blok:</span><span class="value" style="color: #38bdf8; font-weight:600;"><?= htmlspecialchars($log['slot_name'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="label">Giriş:</span><span class="value"><?= htmlspecialchars($log['start_time'] ?? '-') ?></span></div>
                            <div class="info-row">
                                <span class="label">Çıkış:</span>
                                <span class="value">
                                    <?php if (!$hasExit): ?>
                                        <span style="color: #f59e0b; font-weight: 600;"><i class="fa-solid fa-clock" style="font-size: 11px;"></i> İçeride</span>
                                    <?php else: ?>
                                        <span style="color: #34d399; font-weight: 600;"><?= htmlspecialchars($displayEndTime) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row"><span class="label">Ücret:</span><span class="value" style="color: #34d399; font-weight: 600;"><?= number_format((float)($log['fee'] ?? 0), 2) ?> TL</span></div>
                            <div class="info-row">
                                <span class="label">Rez. Durum:</span>
                                <span class="value" style="<?= $rezDurumu == 'Tamamlandı' ? 'color: var(--success); font-weight: 600;' : '' ?>">
                                    <?= htmlspecialchars($rezDurumu) ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="label">Park Durum:</span>
                                <span class="value" style="<?= $parkDurumu == 'Çıkış Yaptı' ? 'color: var(--success); font-weight: 600;' : 'color: #60a5fa;' ?>">
                                    <?= htmlspecialchars($parkDurumu) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
    let activeType = 'all';

    document.getElementById('logSearch').addEventListener('input', function(e) {
        let searchTerm = e.target.value.toUpperCase().trim();
        
        let rows = document.querySelectorAll('.log-row');
        rows.forEach(row => {
            let text = row.textContent.toUpperCase();
            let matchesSearch = text.includes(searchTerm);
            let matchesType = (activeType === 'all' || row.getAttribute('data-type') === activeType);
            
            if(matchesSearch && matchesType) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        let cards = document.querySelectorAll('.mobile-card');
        cards.forEach(card => {
            let text = card.textContent.toUpperCase();
            let matchesSearch = text.includes(searchTerm);
            let matchesType = (activeType === 'all' || card.getAttribute('data-type') === activeType);
            
            if(matchesSearch && matchesType) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });

    function filterType(type, buttonElement) {
        activeType = type;
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        buttonElement.classList.add('active');
        document.getElementById('logSearch').dispatchEvent(new Event('input'));
    }
</script>

</body>
</html>