# Plugin Ubah Status Buku 1.0.0

Pasang folder `ubah_status_buku` ke direktori `plugins/` SLiMS, lalu aktifkan melalui menu Sistem > Plugins. Buka Bibliografi > Ubah Status Buku.

Pilih status tujuan dari `mst_item_status`, scan barcode (`item.item_code`), lalu tekan Enter. Perubahan dilakukan pada `item.item_status_id`, `item.last_update`, dan `item.uid` dalam transaksi database yang juga mencatat audit pada `plugin_item_status_log`.

Halaman menampilkan 20 log per halaman, beserta judul, nomor eksemplar, tahun, status sebelum dan sesudah, waktu, dan pustakawan. Halaman Log & Laporan menyediakan filter tanggal, kata kunci, status baru, serta ekspor CSV.

Tabel `plugin_item_status_log` dibuat otomatis saat plugin dibuka oleh akun database yang memiliki izin CREATE TABLE. Backup database sebelum memasang plugin. Data audit tidak dihapus saat plugin dinonaktifkan.

Catatan: jika barcode dipindai menggunakan scanner, atur suffix Enter pada perangkat. Hak akses halaman mengikuti autentikasi admin SLiMS. Batasi menu plugin hanya kepada petugas berwenang melalui pengaturan akses admin SLiMS. Uji di lingkungan staging sebelum dipakai pada data produksi.

