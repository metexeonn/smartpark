<?php
require_once 'config/config.php';

$stmtToday = $db->query("SELECT COUNT(*) FROM reservations WHERE DATE(start_time) = CURDATE()");
$todayCount = $stmtToday->fetchColumn();

$stmtPending = $db->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
$pendingCount = $stmtPending->fetchColumn();

$stmtApproved = $db->query("SELECT COUNT(*) FROM reservations WHERE status = 'approved'");
$approvedCount = $stmtApproved->fetchColumn();

$stmt = $db->query("SELECT r.*, p.name as parking_name FROM reservations r 
                    JOIN parking_lots p ON r.parking_id = p.id 
                    ORDER BY r.created_at DESC");
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Rezervasyon Yönetimi - SmartPark İzmir</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #0f172a; color: white; margin: 0; font-family: sans-serif; }
        .admin-wrapper { padding: 30px; max-width: 1200px; margin: 0 auto; }
        
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .dash-card { background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px; display: flex; align-items: center; gap: 15px; transition: transform 0.3s ease; border: 1px solid rgba(255,255,255,0.02); }
        .dash-card:hover { transform: translateY(-5px); background: rgba(255,255,255,0.08); }
        .dash-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .dash-info h3 { margin: 0; font-size: 28px; color: white; font-weight: bold; }
        .dash-info p { margin: 5px 0 0 0; color: #94a3b8; font-size: 14px; }

        .filter-tabs { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .filter-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; padding: 10px 20px; border-radius: 8px; cursor: pointer; transition: 0.3s; font-weight: 600; white-space: nowrap; outline: none; }
        .filter-btn:hover { background: rgba(255,255,255,0.1); color: white; }
        .filter-btn.active[data-filter="all"] { background: rgba(255,255,255,0.15); color: white; border-color: rgba(255,255,255,0.3); }
        .filter-btn.active[data-filter="pending"] { background: rgba(234, 179, 8, 0.2); color: #eab308; border-color: #eab308; }
        .filter-btn.active[data-filter="approved"] { background: rgba(16, 185, 129, 0.2); color: #10b981; border-color: #10b981; }
        .filter-btn.active[data-filter="rejected"] { background: rgba(239, 68, 68, 0.2); color: #ef4444; border-color: #ef4444; }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; white-space: nowrap; }
        .bg-pending { background: rgba(234, 179, 8, 0.2); color: #eab308; border: 1px solid #eab308; }
        .bg-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; }
        .bg-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; }
        .table-container { overflow-x: auto; background: rgba(255,255,255,0.03); padding: 20px; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { text-align: left; color: #94a3b8; font-size: 14px; padding: 15px; border-bottom: 2px solid rgba(255,255,255,0.1); }
        td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: rgba(255,255,255,0.05); border-radius: 12px; }
        .action-btns button { padding: 8px 12px; border: none; border-radius: 6px; cursor: pointer; color: white; margin-right: 5px; transition: opacity 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .action-btns button:hover { opacity: 0.8; }

        @media (max-width: 768px) {
            .admin-wrapper { padding: 15px; }
            .header-flex { flex-direction: column; gap: 15px; text-align: center; }
            .dash-card { padding: 15px; }
            .dash-icon { width: 50px; height: 50px; font-size: 20px; }
            .dash-info h3 { font-size: 22px; }
            th, td { padding: 10px; font-size: 13px; }
            .action-btns .btn-text { display: none; }
        }
    </style>
</head>
<body>

<div class="admin-wrapper">
    <div class="header-flex">
        <h2 style="margin: 0; font-size: 22px;"><i class="fa-solid fa-calendar-check" style="color: #3b82f6;"></i> Rezervasyon Yönetim Paneli</h2>
        <a href="admin.php" style="background: rgba(255,255,255,0.1); padding: 10px 18px; text-decoration: none; color: white; font-size: 14px; border-radius: 6px; transition: background 0.3s;"><i class="fa-solid fa-arrow-left"></i> Panele Dön</a>
    </div>

    <div class="dashboard-grid">
        <div class="dash-card">
            <div class="dash-icon" style="background: rgba(59, 130, 246, 0.2); color: #3b82f6;"><i class="fa-solid fa-car-side"></i></div>
            <div class="dash-info">
                <h3><?= $todayCount ?></h3>
                <p>Bugünkü Rezervasyonlar</p>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-icon" style="background: rgba(234, 179, 8, 0.2); color: #eab308;"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="dash-info">
                <h3><?= $pendingCount ?></h3>
                <p>Bekleyen Onaylar</p>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-icon" style="background: rgba(16, 185, 129, 0.2); color: #10b981;"><i class="fa-solid fa-circle-check"></i></div>
            <div class="dash-info">
                <h3><?= $approvedCount ?></h3>
                <p>Onaylanmış Aktif Kayıtlar</p>
            </div>
        </div>
    </div>

    <div class="filter-tabs">
        <button class="filter-btn active" data-filter="all" onclick="filterTable('all')"><i class="fa-solid fa-list"></i> Tüm Rezervasyonlar</button>
        <button class="filter-btn" data-filter="pending" onclick="filterTable('pending')"><i class="fa-solid fa-hourglass-half"></i> Bekleyenler</button>
        <button class="filter-btn" data-filter="approved" onclick="filterTable('approved')"><i class="fa-solid fa-check"></i> Onaylananlar</button>
        <button class="filter-btn" data-filter="rejected" onclick="filterTable('rejected')"><i class="fa-solid fa-xmark"></i> Reddedilenler</button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Müşteri</th>
                    <th>Plaka</th>
                    <th>Otopark</th>
                    <th>Slot</th>
                    <th>Tarih</th>
                    <th>Durum</th>
                    <th>İşlem</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($reservations as $res): 
                    $statusClass = ($res['status'] == 'pending') ? 'bg-pending' : (($res['status'] == 'approved') ? 'bg-approved' : 'bg-rejected');
                ?>
                <tr class="res-row" data-status="<?= htmlspecialchars($res['status']) ?>">
                    <td><?= htmlspecialchars($res['name'] . ' ' . $res['surname']) ?></td>
                    <td><strong><?= htmlspecialchars($res['plate']) ?></strong></td>
                    <td><?= htmlspecialchars($res['parking_name']) ?></td>
                    <td style="color: #60a5fa; font-weight: bold;"><?= htmlspecialchars($res['slot_name'] ?? '-') ?></td>
                    <td><?= date('d.m.Y H:i', strtotime($res['start_time'])) ?></td>
                    <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($res['status']) ?></span></td>
                    <td class="action-btns">
                        <?php if($res['status'] == 'pending'): ?>
                            <button onclick="updateStatus(<?= $res['id'] ?>, 'approved')" style="background:#10b981;" title="Onayla"><i class="fa-solid fa-check"></i></button>
                            <button onclick="updateStatus(<?= $res['id'] ?>, 'rejected')" style="background:#ef4444;" title="Reddet"><i class="fa-solid fa-xmark"></i></button>
                        <?php else: ?>
                            <button onclick="updateStatus(<?= $res['id'] ?>, 'pending')" style="background:#f59e0b;" title="Geri Al"><i class="fa-solid fa-rotate-left"></i> <span class="btn-text">Geri Al</span></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterTable(status) {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-filter') === status) {
            btn.classList.add('active');
        }
    });

    document.querySelectorAll('.res-row').forEach(row => {
        if (status === 'all' || row.getAttribute('data-status') === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function updateStatus(id, status) {
    let statusTr = (status === 'approved') ? 'onaylanacak' : (status === 'rejected' ? 'reddedilecek' : 'beklemeye alınacak');
    
    Swal.fire({
        title: 'Emin misin?',
        text: "Rezervasyon durumu " + statusTr + ".",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Evet, Güncelle',
        cancelButtonText: 'İptal',
        background: '#1e293b',
        color: '#fff'
    }).then((result) => {
        if (result.isConfirmed) {
            let formData = new FormData();
            formData.append('id', id);
            formData.append('status', status);

            fetch('update_status.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    Swal.fire({
                        title: 'Başarılı', 
                        text: 'İşlem tamamlandı.', 
                        icon: 'success',
                        background: '#1e293b',
                        color: '#fff',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Hata', 'Bir sorun oluştu: ' + (data.message || ''), 'error');
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Hata', 'Sunucuya ulaşılamadı.', 'error');
            });
        }
    });
}
</script>
</body>
</html>