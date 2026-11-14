/**
 * SIM BISOLPIN ACADEMY - Main Client-Side JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Mobile Sidebar Toggle
    const mobileToggleBtn = document.getElementById('btn-toggle-sidebar');
    const sidebarWrapper = document.getElementById('sidebar-wrapper');
    const sidebarOverlay = document.getElementById('sidebar-overlay');

    if (mobileToggleBtn && sidebarWrapper) {
        mobileToggleBtn.addEventListener('click', function () {
            sidebarWrapper.classList.toggle('-translate-x-full');
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle('hidden');
            }
        });
    }

    if (sidebarOverlay && sidebarWrapper) {
        sidebarOverlay.addEventListener('click', function () {
            sidebarWrapper.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });
    }

    // 2. Auto-initialize DataTables if class .datatable is present and jQuery is loaded
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        // Prevent intrusive native browser alert modals for DataTables warnings; log to console instead
        $.fn.dataTable.ext.errMode = 'none';

        function safeInitDataTables() {
            $('.datatable').each(function () {
                const $tbl = $(this);

                // Already initialized
                if ($.fn.DataTable.isDataTable(this)) {
                    return;
                }

                // If table is inside hidden tab / hidden container, skip until made visible
                if ($tbl.is(':hidden') || $tbl.closest('.hidden').length > 0) {
                    return;
                }

                // Skip if no body rows or contains an empty-state colspan row
                const $bodyRows = $tbl.find('tbody tr');
                if ($bodyRows.length === 0 || $tbl.find('tbody td[colspan]').length > 0) {
                    return;
                }

                // Pre-check: Ensure thead th count matches tbody first tr td count to prevent tn/18
                const thCount = $tbl.find('thead tr:first th').length;
                const tdCount = $bodyRows.first().find('td').length;
                if (thCount > 0 && tdCount > 0 && thCount !== tdCount) {
                    return;
                }

                const hasResponsive = typeof $.fn.dataTable.Responsive !== 'undefined';

                $tbl.DataTable({
                    language: {
                        search: "Cari Data:",
                        lengthMenu: "Tampilkan _MENU_ baris",
                        info: "Menampilkan _START_ s/d _END_ dari _TOTAL_ data",
                        infoEmpty: "Data tidak tersedia",
                        infoFiltered: "(disaring dari _MAX_ total data)",
                        zeroRecords: "Tidak ada data yang cocok dengan pencarian",
                        paginate: {
                            first: "Awal",
                            last: "Akhir",
                            next: "Lanjut →",
                            previous: "← Kembali"
                        }
                    },
                    responsive: hasResponsive,
                    autoWidth: false,
                    pageLength: 10
                });
            });
        }

        // Initialize on visible tables at startup
        safeInitDataTables();

        // Recalculate column widths and initialize newly revealed tables when tabs change
        $(document).on('click', 'button[onclick*="switch"], a[data-tab], [data-tab-target]', function () {
            setTimeout(function () {
                safeInitDataTables();
                if ($.fn.DataTable.isDataTable) {
                    $('.datatable').each(function () {
                        if ($.fn.DataTable.isDataTable(this) && $(this).is(':visible')) {
                            const dt = $(this).DataTable();
                            dt.columns.adjust();
                            if (dt.responsive && typeof dt.responsive.recalc === 'function') {
                                dt.responsive.recalc();
                            }
                        }
                    });
                }
            }, 120);
        });
    }

    // 3. Image Upload Preview Helper
    const imageInputs = document.querySelectorAll('[data-preview-target]');
    imageInputs.forEach(input => {
        input.addEventListener('change', function () {
            const targetId = this.getAttribute('data-preview-target');
            const previewElem = document.getElementById(targetId);
            if (previewElem && this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    previewElem.src = e.target.result;
                    previewElem.classList.remove('hidden');
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });
});

/**
 * Global Modal Helpers (Open / Close Modal)
 */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

// Close active modal on ESC key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('[id^="modal"]:not(.hidden)');
        openModals.forEach(m => {
            m.classList.add('hidden');
        });
        document.body.classList.remove('overflow-hidden');
    }
});

/**
 * Global SweetAlert2 Delete Confirmation Helper
 */
function confirmAction(url, title = 'Konfirmasi Aksi', text = 'Apakah Anda yakin ingin melanjutkan tindakan ini?', confirmText = 'Ya, Lanjutkan') {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#64748B',
            confirmButtonText: confirmText,
            cancelButtonText: 'Batal',
            customClass: {
                popup: 'rounded-2xl'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    } else {
        if (confirm(text)) {
            window.location.href = url;
        }
    }
}

/**
 * Global Topbar Notification Bell & Dropdown Handler
 */
document.addEventListener('DOMContentLoaded', function () {
    const btnNotif = document.getElementById('btn-toggle-notif');
    const menuNotif = document.getElementById('notif-dropdown-menu');
    const containerNotif = document.getElementById('topbar-notif-container');

    if (btnNotif && menuNotif) {
        btnNotif.addEventListener('click', function (e) {
            e.stopPropagation();
            menuNotif.classList.toggle('hidden');
        });

        document.addEventListener('click', function (e) {
            if (containerNotif && !containerNotif.contains(e.target)) {
                menuNotif.classList.add('hidden');
            }
        });
    }
});

function markNotificationRead(notifId) {
    if (!notifId) return;
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('id', notifId);
    
    const ajaxUrl = window.location.pathname.includes('/SIM_bisolpin_academy/') 
        ? '/SIM_bisolpin_academy/core/ajax_notifikasi.php'
        : '/core/ajax_notifikasi.php';

    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    }).then(res => res.json()).then(data => {
        if (data.success && data.unread_count === 0) {
            const badge = document.getElementById('notif-badge-pill');
            if (badge) badge.remove();
        }
    }).catch(err => console.error('Gagal update notifikasi:', err));
}

function markAllNotificationsAsRead() {
    const formData = new FormData();
    formData.append('action', 'mark_all_read');

    const ajaxUrl = window.location.pathname.includes('/SIM_bisolpin_academy/') 
        ? '/SIM_bisolpin_academy/core/ajax_notifikasi.php'
        : '/core/ajax_notifikasi.php';

    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    }).then(res => res.json()).then(data => {
        if (data.success) {
            const badge = document.getElementById('notif-badge-pill');
            if (badge) badge.remove();
            const countBadge = document.getElementById('notif-count-badge');
            if (countBadge) countBadge.remove();
            document.querySelectorAll('#notif-items-list .bg-teal-50\\/20').forEach(el => el.classList.remove('bg-teal-50/20'));
            document.querySelectorAll('#notif-items-list .bg-brand-500').forEach(el => el.remove());
        }
    }).catch(err => console.error('Gagal mark all read:', err));
}

