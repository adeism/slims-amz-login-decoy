<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

/**
 * Renders the Audit Log Modal Popup for AMZ Login Decoy
 * 
 * @var array $auditLogs Loaded audit logs from database.
 */
?>
<!-- MODAL POPUP AUDIT LOG -->
<div id="amzldAuditModal" class="amz-modal-backdrop">
  <div class="amz-modal-content">
    <div class="amz-modal-header">
      <h3><i class="fa fa-history"></i> Log Aktivitas Administrator</h3>
      <button type="button" class="amz-modal-close" id="btnCloseAuditModal">&times;</button>
    </div>
    <div class="amz-modal-body">
      <p style="color: var(--slate-500); font-size: 12px; margin-top: 0; margin-bottom: 16px;">
        Mencatat 100 aktivitas administrasi terakhir yang dilakukan pada plugin AMZ Login Decoy.
      </p>
      <div class="table-responsive" style="max-height: 400px; overflow-y: auto; border: 1px solid var(--slate-200); border-radius: 8px; background: #fff; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
        <table class="s-table table table-striped table-condensed table-hover" style="margin: 0; font-size: 12px; width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background-color: var(--slate-100); font-weight: bold; position: sticky; top: 0; z-index: 10;">
              <th style="padding: 10px; border-bottom: 2px solid var(--slate-200); width: 140px; text-align: left;">Waktu</th>
              <th style="padding: 10px; border-bottom: 2px solid var(--slate-200); width: 160px; text-align: left;">Admin</th>
              <th style="padding: 10px; border-bottom: 2px solid var(--slate-200); width: 110px; text-align: left;">Alamat IP</th>
              <th style="padding: 10px; border-bottom: 2px solid var(--slate-200); width: 130px; text-align: left;">Aktivitas</th>
              <th style="padding: 10px; border-bottom: 2px solid var(--slate-200); text-align: left;">Rincian</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($auditLogs)): ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 20px; color: var(--slate-400);">Belum ada log aktivitas admin tercatat.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($auditLogs as $log): ?>
                <tr>
                  <td style="padding: 10px; border-bottom: 1px solid var(--slate-200); white-space: nowrap;"><?= htmlspecialchars($log['created_at']) ?></td>
                  <td style="padding: 10px; border-bottom: 1px solid var(--slate-200);">
                    <strong style="color: var(--slate-800);"><?= htmlspecialchars($log['realname']) ?></strong> 
                    <div style="color: var(--slate-500); font-size: 11px;">(<?= htmlspecialchars($log['username']) ?>)</div>
                  </td>
                  <td style="padding: 10px; border-bottom: 1px solid var(--slate-200);"><?= htmlspecialchars($log['ip_address']) ?></td>
                  <td style="padding: 10px; border-bottom: 1px solid var(--slate-200);">
                    <?php
                      $labelClass = 'label-default';
                      if ($log['action'] === 'Ubah Pengaturan') $labelClass = 'label-primary';
                      elseif ($log['action'] === 'Unblock IP') $labelClass = 'label-info';
                      elseif ($log['action'] === 'Hapus Log Percobaan') $labelClass = 'label-danger';
                      elseif ($log['action'] === 'Kirim Email Uji Coba') $labelClass = 'label-success';
                    ?>
                    <span class="label <?= $labelClass ?>" style="font-size: 10px; padding: 3px 6px; font-weight: bold; border-radius: 4px;"><?= htmlspecialchars($log['action']) ?></span>
                  </td>
                  <td style="padding: 10px; border-bottom: 1px solid var(--slate-200); font-size: 11px; color: var(--slate-600); word-wrap: break-word;"><?= htmlspecialchars($log['details']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="amz-modal-footer">
      <button type="button" class="btn btn-default btn-sm" id="btnCloseAuditFooter" style="font-weight: bold; border-radius: 4px; padding: 6px 16px;">Tutup</button>
    </div>
  </div>
</div>

<script>
  (function() {
    var modal = document.getElementById('amzldAuditModal');
    var btnOpen = document.getElementById('btnAuditLog');
    var btnClose = document.getElementById('btnCloseAuditModal');
    var btnCloseFooter = document.getElementById('btnCloseAuditFooter');

    if (modal) {
      // PREMIUM FIX: Relocate modal elements directly to <body> to avoid SLiMS relative layout/transform position inheritance.
      document.body.appendChild(modal);
    }

    if (btnOpen && modal) {
      btnOpen.addEventListener('click', function(e) {
        e.preventDefault();
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
      });
    }

    function closeModal() {
      if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
      }
    }

    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeModal);

    if (modal) {
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          closeModal();
        }
      });
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        closeModal();
      }
    });
  })();
</script>
