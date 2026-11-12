<?php
/**
 * SIM BISOLPIN ACADEMY - Global Footer & Script Integrator
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/helpers.php';
require_once dirname(__DIR__) . '/core/flash.php';
?>
    </main>

    <!-- Global App Footer -->
    <footer class="px-8 py-5 border-t border-slate-200/80 bg-white/60 text-xs text-slate-500 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div>
            &copy; <?= date('Y') ?> <strong><?= APP_NAME ?></strong>. PT Solusi Pintar Indonesia. All rights reserved.
        </div>
        <div class="flex items-center gap-4 text-[11px] text-slate-400">
            <span>SIAKAD & Kemitraan Offline V<?= APP_VERSION ?></span>
            <span>•</span>
            <a href="https://www.bisolpin.com" target="_blank" class="hover:text-brand-600 transition-colors">Portal Utama bisolpin.com</a>
        </div>
    </footer>
</div><!-- /main-content-wrapper -->
</div><!-- /app-container -->

<!-- jQuery CDN -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

<!-- DataTables JS CDN & Responsive Extension -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script>
    // Matikan alert modal native browser yang mengganggu (alihkan ke silent mode)
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
        window.jQuery.fn.dataTable.ext.errMode = 'none';
    }
</script>

<!-- Chart.js CDN for Analytics & Progress Graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- SweetAlert2 JS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Custom App JS (dengan auto cache busting) -->
<script src="<?= asset_url('js/app.js') ?>?v=<?= file_exists(dirname(__DIR__) . '/assets/js/app.js') ? filemtime(dirname(__DIR__) . '/assets/js/app.js') : time() ?>"></script>

<!-- Otomatis Render Flash Notification jika ada di sesi -->
<?= render_flash() ?>

</body>
</html>

