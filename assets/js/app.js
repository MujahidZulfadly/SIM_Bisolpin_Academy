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
        $.fn.dataTable.ext.errMode = 'console';

        $('.datatable').each(function () {
            const $tbl = $(this);

            // Skip initialization if table has no tbody rows or contains an empty-state colspan row
            if ($tbl.find('tbody tr').length === 0 || $tbl.find('tbody tr:first td[colspan]').length > 0) {
                return;
            }

            // Prevent duplicate initialization
            if ($.fn.DataTable.isDataTable(this)) {
                return;
            }

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
                responsive: true,
                pageLength: 10
            });
        });

        // Recalculate column widths when tabs change
        $('button[onclick*="switch"], a[data-tab]').on('click', function () {
            setTimeout(function () {
                if ($.fn.DataTable.isDataTable) {
                    $('.datatable').each(function () {
                        if ($.fn.DataTable.isDataTable(this)) {
                            $(this).DataTable().columns.adjust().responsive.recalc();
                        }
                    });
                }
            }, 100);
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

