

function exitVehicle(vehicleId, plate) {
    fetch('check_status.php?plate=' + encodeURIComponent(plate))
    .then(response => response.json())
    .then(data => {
        
        if (data.status === 'Normal_Giris') {
            Swal.fire({
                title: 'Ödeme Talebi Oluştur',
                text: plate + ' plakalı araç için nakit ödeme talebi oluşturulsun mu?',
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
                text: plate + ' plakalı araç zaten nakit onay listesinde bekliyor. Lütfen önce sayfa altındaki listeden onay verin.',
                icon: 'warning',
                background: '#0f172a',
                color: '#fff',
                confirmButtonColor: '#3b82f6'
            });
        }
        
        else if (data.status === 'Odedi_Cikis_Yapabilir') {
            Swal.fire({
                title: 'Araç Çıkışı',
                text: plate + ' plakalı araç çıkış yapacak. Onaylıyor musunuz?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Evet, Çıkış Yap',
                cancelButtonText: 'Hayır',
                background: '#0f172a',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'admin.php?final_exit_id=' + vehicleId + '&plate=' + encodeURIComponent(plate);
                }
            });
        }
    })
    .catch(err => {
        console.error("Durum kontrolü yapılırken hata oluştu:", err);
    });
}

function validateForm() {
    return true;
}
