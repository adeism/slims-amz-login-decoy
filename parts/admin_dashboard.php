<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

/**
 * Renders the Main Dashboard Layout for AMZ Login Decoy
 * 
 * @var string $secretUrl
 * @var array  $settings
 * @var array  $stats
 * @var array  $errors
 * @var string $status
 * @var bool   $schemaReady
 * @var string $recentAttemptsHtml
 * @var string $activeBlocksHtml
 * @var bool   $can_write
 * @var bool   $isMailConfigured
 */

// Resolve path relatif terhadap document root
$cssRelPath = str_replace(
  rtrim((string)(defined('SB') ? SB : $_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
  '',
  AMZLD_PLUGIN_DIR . '/assets/admin.css'
);
// Ensure we use forward slashes for URLs even on Windows
$cssRelPath = str_replace('\\', '/', $cssRelPath);
?>
<script>
  (function() {
    var cssUrl = '<?= amzldString(amzldBaseUrl() . $cssRelPath) ?>?v=<?= filemtime(AMZLD_PLUGIN_DIR . '/assets/admin.css') ?>';
    if (!document.querySelector('link[href^="' + cssUrl.split('?')[0] + '"]')) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = cssUrl;
      document.head.appendChild(link);
    }
  })();
</script>

<div class="menuBox">
  <div class="menuBoxInner amzld-admin">
    
    <!-- HEADER PANEL -->
    <div class="amzld-title">
      <div>
        <h2><i class="fa fa-shield"></i> AMZ Login Decoy</h2>
        <p>Sistem Keamanan Staf: Pintu Rahasia, Halaman Jebakan, dan Pemblokiran IP Otomatis.</p>
      </div>
      <div style="display: flex; gap: 8px; align-items: center;">
        <button type="button" id="btnAuditLog" class="btn btn-xs btn-default" style="font-weight: bold; padding: 5px 12px; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; background-color: rgba(255, 255, 255, 0.15); color: #fff; border: 1px solid rgba(255, 255, 255, 0.25); transition: background-color 0.15s ease-in-out;" onmouseover="this.style.backgroundColor='rgba(255, 255, 255, 0.25)'" onmouseout="this.style.backgroundColor='rgba(255, 255, 255, 0.15)'">
          <i class="fa fa-history"></i> Log Aktivitas Admin
        </button>
        <span>v1.0.0</span>
      </div>
    </div>

    <!-- STATUSSES -->
    <?php if ($status === 'saved'): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle"></i> Pengaturan berhasil disimpan dengan sukses!</div>
    <?php elseif ($status === 'unblocked'): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle"></i> Alamat IP berhasil dibuka blokirnya!</div>
    <?php elseif ($status === 'cleared'): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle"></i> Semua log percobaan berhasil dihapus!</div>
    <?php elseif ($status === 'test_success'): ?>
      <div class="alert alert-success"><i class="fa fa-envelope"></i> Email uji coba berhasil dikirim ke alamat yang dikonfigurasi!</div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
      <div class="alert alert-danger"><i class="fa fa-times-circle"></i> <?= amzldString($error) ?></div>
    <?php endforeach; ?>

    <?php if (!$schemaReady): ?>
      <div class="alert alert-warning">
        <i class="fa fa-exclamation-circle"></i> Database plugin belum siap. Silakan buka menu <strong>System - Plugins</strong>, nonaktifkan lalu aktifkan kembali <strong>AMZ Login Decoy</strong> untuk menyelesaikan instalasi database.
      </div>
    <?php endif; ?>

    <!-- PANDUAN CARA KERJA SISTEM -->
    <details class="tutorial-card" open style="margin-bottom: 16px;">
      <summary>
        <span><i class="fa fa-info-circle"></i> Panduan Alur Keamanan (Bagaimana Cara Masuk?)</span>
      </summary>
      <div class="tutorial-steps" onclick="event.stopPropagation();">
        <div class="step-item">
          <div class="step-header">
            <div class="step-number">1</div>
            <div class="step-title">Gunakan Link Rahasia</div>
          </div>
          <div class="step-desc">
            Staf perpustakaan wajib masuk ke SLiMS menggunakan alamat khusus berikut. Simpan link ini baik-baik:
            <div class="copy-link-container">
              <div style="position: relative; display: flex; align-items: center; flex: 1;">
                <input type="password" id="secretUrlInput" class="copy-link-input" value="<?= amzldString($secretUrl) ?>" readonly style="padding-right: 32px !important; width: 100% !important; box-sizing: border-box !important;">
                <span onclick="togglePasswordVisibility('secretUrlInput', 'eyeIconUrl')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; z-index: 5; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; font-size: 14px;">
                  <i id="eyeIconUrl" class="fa fa-eye-slash"></i>
                </span>
              </div>
              <button type="button" class="btn-copy" onclick="copySecretUrl()">
                <i class="fa fa-copy"></i> <span id="copyBtnText">Salin Link</span>
              </button>
            </div>
          </div>
        </div>
        <div class="step-item">
          <div class="step-header">
            <div class="step-number">2</div>
            <div class="step-title">Izin Masuk Aktif</div>
          </div>
          <div class="step-desc">
            Setelah mengklik link rahasia di atas, pintu masuk login admin akan terbuka selama <strong><?= amzldString($settings['session_ttl_minutes']) ?> menit</strong>. Anda dapat login secara normal.
          </div>
        </div>
        <div class="step-item">
          <div class="step-header">
            <div class="step-number">3</div>
            <div class="step-title">Jebakan Honeypot</div>
          </div>
          <div class="step-desc">
            Jika ada orang lain/hacker mencoba membuka halaman login biasa tanpa mengklik link rahasia, mereka akan melihat halaman login palsu (jebakan) dan komputernya akan otomatis diblokir.
          </div>
        </div>
      </div>
    </details>

    <script>
    function copySecretUrl() {
      var copyText = document.getElementById("secretUrlInput");
      copyText.select();
      copyText.setSelectionRange(0, 99999);
      navigator.clipboard.writeText(copyText.value).then(function() {
        var btnText = document.getElementById("copyBtnText");
        btnText.textContent = "Tersalin!";
        var btn = document.querySelector(".btn-copy");
        btn.style.backgroundColor = "var(--emerald-50)";
        btn.style.color = "var(--emerald-700)";
        btn.style.borderColor = "var(--emerald-100)";
        setTimeout(function() {
          btnText.textContent = "Salin Link";
          btn.style.backgroundColor = "#fff";
          btn.style.color = "var(--slate-700)";
          btn.style.borderColor = "var(--slate-200)";
        }, 2000);
      }).catch(function() {
        try {
          document.execCommand('copy');
          var btnText = document.getElementById("copyBtnText");
          btnText.textContent = "Tersalin!";
          setTimeout(function() {
            btnText.textContent = "Salin Link";
          }, 2000);
        } catch(e) {}
      });
    }

    function togglePasswordVisibility(inputId, iconId) {
      var input = document.getElementById(inputId);
      var icon = document.getElementById(iconId);
      if (input.type === "password") {
        input.type = "text";
        icon.className = "fa fa-eye";
      } else {
        input.type = "password";
        icon.className = "fa fa-eye-slash";
      }
    }
    </script>

    <!-- STATUS UTAMA SISTEM -->
    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; background-color: var(--slate-50); border: 1px solid var(--slate-200); padding: 12px 16px; border-radius: 8px; align-items: center;">
      <span style="font-weight: bold; font-size: 11px; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.5px;"><i class="fa fa-cog"></i> Status Proteksi:</span>
      <span class="label <?= ($settings['secret_token'] !== '') ? 'label-success' : 'label-default' ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Pintu Rahasia: <?= ($settings['secret_token'] !== '') ? 'Aktif' : 'Nonaktif' ?></span>
      <span class="label <?= amzldBoolSetting($settings, 'honeypot_enabled') ? 'label-success' : 'label-default' ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Honeypot Decoy: <?= amzldBoolSetting($settings, 'honeypot_enabled') ? 'Aktif' : 'Nonaktif' ?></span>
      <span class="label <?= amzldBoolSetting($settings, 'auto_block_enabled') ? 'label-success' : 'label-default' ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Auto-Block IP: <?= amzldBoolSetting($settings, 'auto_block_enabled') ? 'Aktif' : 'Nonaktif' ?></span>
      <span class="label <?= amzldBoolSetting($settings, 'htaccess_block_enabled') ? 'label-success' : 'label-default' ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Proteksi .htaccess: <?= amzldBoolSetting($settings, 'htaccess_block_enabled') ? 'Aktif' : 'Nonaktif' ?></span>
      <span class="label <?= amzldBoolSetting($settings, 'whitelist_bypass_enabled') ? 'label-success' : 'label-default' ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Bypass IP Whitelist: <?= amzldBoolSetting($settings, 'whitelist_bypass_enabled') ? 'Aktif' : 'Nonaktif' ?></span>
      <span class="label <?= ($settings['alert_email'] !== '' && $isMailConfigured) ? 'label-success' : (($settings['alert_email'] !== '') ? 'label-warning' : 'label-default') ?>" style="font-size: 10px; padding: 4px 8px; border-radius: 4px;">Laporan Email: <?= ($settings['alert_email'] !== '' && $isMailConfigured) ? 'Aktif' : (($settings['alert_email'] !== '') ? 'Butuh Setting Surel' : 'Nonaktif') ?></span>
    </div>

    <!-- STATISTIK KINERJA KEAMANAN -->
    <div class="amzld-stats">
      <div>
        <strong><?= (int) $stats['today_attempts'] ?></strong>
        <span>Percobaan Hari Ini</span>
      </div>
      <div>
        <strong><?= (int) $stats['last_hour_attempts'] ?></strong>
        <span>1 Jam Terakhir</span>
      </div>
      <div>
        <strong><?= (int) $stats['unique_ips_24h'] ?></strong>
        <span>IP Unik (24 Jam)</span>
      </div>
      <div>
        <strong><?= (int) $stats['active_blocks'] ?></strong>
        <span>Diblokir Aktif</span>
      </div>
      <div>
        <strong><?= (int) $stats['total_attempts'] ?></strong>
        <span>Total Percobaan</span>
      </div>
      <div>
        <strong><?= (int) $stats['total_incidents'] ?></strong>
        <span>Total Kasus Unik</span>
      </div>
    </div>

    <!-- FORM SETTINGS -->
    <form method="post" action="<?= amzldString(amzldAdminUrl([], true)) ?>">
      <input type="hidden" name="csrf_token" value="<?= amzldString(amzldGetCsrfToken()) ?>">
      <input type="hidden" name="saveSettings" value="1">

      <div class="amzld-form-layout">
        <!-- KOLOM KIRI -->
        <div class="amzld-form-col">
          
          <!-- BAGIAN 1: PINTU MASUK RAHASIA -->
          <div class="amzld-panel section-card card-blue">
            <div class="section-header">
              <span class="section-badge badge-blue">1</span>
              <h4>Pintu Masuk Rahasia (Staff Entrance)</h4>
            </div>
            <p class="section-intro">Membuat link pintu masuk tersembunyi khusus staf.</p>
            
            <div class="amzld-inline-group">
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Token Rahasia:</span>
                <div style="position: relative; display: inline-flex; align-items: center; width: 140px;">
                  <input type="password" id="secretTokenInput" name="secret_token" class="amzld-input-text" value="<?= amzldString($settings['secret_token']) ?>" maxlength="80" required <?= $can_write ? '' : 'disabled' ?> style="padding-right: 32px !important; width: 100% !important; box-sizing: border-box !important;">
                  <span onclick="togglePasswordVisibility('secretTokenInput', 'eyeIconToken')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; z-index: 5; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; font-size: 14px;">
                    <i id="eyeIconToken" class="fa fa-eye-slash"></i>
                  </span>
                </div>
                <small class="amzld-help-inline">Hanya berupa huruf dan angka tanpa spasi.</small>
              </div>
              <div class="amzld-inline-divider"></div>
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Durasi Akses:</span>
                <input type="number" name="session_ttl_minutes" class="amzld-input-number" min="1" max="1440" value="<?= amzldString($settings['session_ttl_minutes']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">menit</span>
                <small class="amzld-help-inline">Waktu untuk login setelah mengklik link rahasia.</small>
              </div>
            </div>
          </div>

          <!-- BAGIAN 2: JEBAKAN HONEYPOT -->
          <div class="amzld-panel section-card card-orange">
            <div class="section-header">
              <span class="section-badge badge-orange">2</span>
              <h4>Umpan Palsu (Honeypot Decoy)</h4>
            </div>
            <p class="section-intro">Jebakan form login palsu untuk menipu robot/hacker.</p>
            
            <div class="amzld-inline-group">
              <div class="amzld-inline-item">
                <label class="amzld-checkbox-label">
                  <span class="amzld-switch">
                    <input type="checkbox" name="honeypot_enabled" value="1" <?= amzldBoolSetting($settings, 'honeypot_enabled') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                    <span class="amzld-slider"></span>
                  </span>
                  <strong>Aktifkan Halaman Jebakan</strong>
                </label>
              </div>
              <div class="amzld-inline-divider"></div>
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Jeda Respon:</span>
                <input type="number" name="honeypot_delay_seconds" class="amzld-input-number" min="0" max="10" value="<?= amzldString($settings['honeypot_delay_seconds']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">detik</span>
                <small class="amzld-help-inline">Memperlambat proses login jebakan untuk bot/hacker.</small>
              </div>
            </div>
          </div>

          <!-- BAGIAN 4: SISTEM PEMBLOKIRAN IP -->
          <div class="amzld-panel section-card card-red">
            <div class="section-header">
              <span class="section-badge badge-red">4</span>
              <h4>Keamanan & Pemblokiran IP</h4>
            </div>
            <p class="section-intro">Memblokir akses bagi komputer penyerang yang brute force.</p>
            
            <!-- Baris 1: Status Aktivasi -->
            <div class="amzld-inline-item" style="margin-bottom: 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="auto_block_enabled" value="1" <?= amzldBoolSetting($settings, 'auto_block_enabled') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Aktifkan Blokir IP Otomatis</strong>
              </label>
              <span class="checkbox-desc">Blokir komputer yang salah memasukkan login berkali-kali.</span>
            </div>
            
            <div class="amzld-inline-item" style="margin-bottom: 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="htaccess_block_enabled" value="1" <?= amzldBoolSetting($settings, 'htaccess_block_enabled') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Aktifkan Proteksi File <code>.htaccess</code></strong>
              </label>
              <span class="checkbox-desc">Memblokir otomatis penyerang di level web server (lebih hemat resource server).</span>
            </div>

            <div class="amzld-inline-item" style="margin-bottom: 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="trust_cf_header" value="1" <?= amzldBoolSetting($settings, 'trust_cf_header') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Percayai Header Cloudflare (Cloudflare IP)</strong>
              </label>
              <span class="checkbox-desc">Aktifkan jika website Anda berada di belakang proxy Cloudflare. Ini mencegah pemalsuan IP client (IP spoofing).</span>
            </div>
            
            <div class="amzld-inline-item" style="margin-bottom: 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <div class="amzld-warning-box">
                <strong><i class="fa fa-exclamation-triangle"></i> Informasi Sistem Pemblokiran:</strong>
                <ul>
                  <li><strong>Dua Lapis Proteksi:</strong> Plugin ini memiliki 2 lapis keamanan. Lapis pertama adalah <strong>Kode PHP (Aplikasi)</strong> yang aktif otomatis di semua server. Lapis kedua adalah <strong>Web Server</strong> menggunakan file <code>.htaccess</code>.</li>
                  <li><strong>Rekomendasi Konfigurasi:</strong>
                    <ul>
                      <li>Jika menggunakan <strong>Apache</strong>: Aktifkan opsi ini (pastikan <code>AllowOverride</code> di server aktif) untuk memblokir di level server sebelum PHP berjalan (sangat hemat resource).</li>
                      <li>Jika menggunakan <strong>Nginx</strong>: Anda boleh menonaktifkan opsi ini karena pemblokiran sudah ditangani sepenuhnya oleh Kode PHP.</li>
                    </ul>
                  </li>
                </ul>
              </div>
            </div>
            
            <!-- Baris 2: Aturan Parameter -->
            <div class="amzld-inline-group">
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Batas Salah:</span>
                <input type="number" name="block_threshold" class="amzld-input-number" min="1" max="100" value="<?= amzldString($settings['block_threshold']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">kali / jam</span>
              </div>
              <div class="amzld-inline-divider"></div>
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Durasi Blokir:</span>
                <input type="number" name="block_duration_minutes" class="amzld-input-number" min="5" max="525600" value="<?= amzldString($settings['block_duration_minutes']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">menit</span>
              </div>
            </div>
          </div>
        </div>

        <!-- KOLOM KANAN -->
        <div class="amzld-form-col">
          
          <!-- BAGIAN 3: NOTIFIKASI LAPORAN EMAIL -->
          <div class="amzld-panel section-card card-teal">
            <div class="section-header">
              <span class="section-badge badge-teal">3</span>
              <h4>Laporan Serangan (Email Alert)</h4>
            </div>
            <p class="section-intro">Kirim email otomatis saat terdeteksi serangan brute force.</p>

            <?php if (!$isMailConfigured): ?>
              <div class="amzld-warning-box" style="margin-bottom: 12px !important;">
                <strong><i class="fa fa-exclamation-triangle"></i> Penting: Surel Sistem Belum Dikonfigurasi</strong>
                Anda harus melakukan pengaturan surel di sistem > pengaturan surel sebelum sistem bisa mengirim email laporan.
              </div>
            <?php endif; ?>
            
            <div class="amzld-inline-group">
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Email Laporan:</span>
                <input type="email" name="alert_email" class="amzld-input-email" value="<?= amzldString($settings['alert_email']) ?>" placeholder="Email Admin SLiMS" <?= $can_write ? '' : 'disabled' ?>>
                <?php if ($can_write && $isMailConfigured): ?>
                  <button type="submit" name="testEmail" value="1" class="btn btn-xs btn-default" style="font-weight: bold; padding: 4px 8px; border-radius: 4px;">
                    <i class="fa fa-envelope"></i> Uji Kirim
                  </button>
                <?php endif; ?>
              </div>
              <div class="amzld-inline-divider"></div>
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Kirim setelah:</span>
                <input type="number" name="email_threshold" class="amzld-input-number" min="1" max="100" value="<?= amzldString($settings['email_threshold']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">salah login</span>
              </div>
              <div class="amzld-inline-divider"></div>
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Jeda Laporan:</span>
                <input type="number" name="email_cooldown_minutes" class="amzld-input-number" min="5" max="1440" value="<?= amzldString($settings['email_cooldown_minutes']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">menit</span>
              </div>
            </div>
          </div>

          <!-- BAGIAN 5: DAFTAR KOMPUTER AMAN -->
          <div class="amzld-panel section-card card-gray">
            <div class="section-header">
              <span class="section-badge badge-gray">5</span>
              <h4>Komputer Aman (IP Whitelist)</h4>
            </div>
            <p class="section-intro">Daftar komputer yang tidak akan pernah diblokir.</p>

            <div class="amzld-inline-item" style="margin-bottom: 14px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="whitelist_bypass_enabled" value="1" <?= amzldBoolSetting($settings, 'whitelist_bypass_enabled') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Aktifkan Akses Langsung IP Whitelist (Bypass Token)</strong>
              </label>
              <span class="checkbox-desc">Izinkan komputer dalam daftar whitelist untuk langsung membuka halaman login tanpa memerlukan link rahasia.</span>
            </div>
            
            <div class="amzld-inline-group">
              <div class="amzld-inline-item" style="flex: 1; align-items: flex-start; flex-direction: column;">
                <span class="amzld-inline-label" style="margin-bottom: 2px;">Daftar IP Whitelist:</span>
                <small style="color: var(--slate-500); font-size: 11px; margin-bottom: 4px; line-height: 1.4;">IP kebal dari blokir (tulis satu alamat per baris). Mendukung format IP Tunggal (<code>192.168.1.1</code>), CIDR/Segmen (<code>192.168.1.0/24</code>), Range/Rentang IP (<code>192.168.1.10-50</code> atau <code>192.168.1.10-192.168.1.50</code>), dan Wildcard (<code>192.168.1.*</code>).</small>
              </div>
              <div class="amzld-inline-item" style="width: 100%;">
                <textarea name="whitelist_ips" class="amzld-input-textarea" style="height: 90px !important; min-height: 90px !important;" placeholder="Contoh penulisan:&#10;192.168.1.100 (Single IP)&#10;192.168.1.0/24 (CIDR/Segmen)&#10;192.168.1.10-50 (IP Range)&#10;192.168.1.* (Wildcard)" <?= $can_write ? '' : 'disabled' ?>><?= amzldString($settings['whitelist_ips']) ?></textarea>
              </div>
            </div>
          </div>

          <!-- BAGIAN 6: OPTIMALISASI & PEMBERSIHAN LOG -->
          <div class="amzld-panel section-card card-purple" style="margin-top: 16px;">
            <div class="section-header">
              <span class="section-badge badge-purple">6</span>
              <h4>Optimalisasi & Pembersihan Log</h4>
            </div>
            <p class="section-intro">Mengurangi beban database dari penumpukan data serangan brute force.</p>

            <div class="amzld-inline-item" style="margin-bottom: 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="log_honeypot_views" value="1" <?= amzldBoolSetting($settings, 'log_honeypot_views') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Catat Log Kunjungan Honeypot (View)</strong>
              </label>
              <span class="checkbox-desc">Menyimpan log setiap kali bot sekadar memuat halaman login palsu. Nonaktifkan ini untuk menghemat ukuran database secara signifikan.</span>
            </div>

            <div class="amzld-inline-item" style="margin-bottom: 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 100%;">
              <label class="amzld-checkbox-label">
                <span class="amzld-switch">
                  <input type="checkbox" name="log_blocked_denials" value="1" <?= amzldBoolSetting($settings, 'log_blocked_denials') ? 'checked' : '' ?> <?= $can_write ? '' : 'disabled' ?>>
                  <span class="amzld-slider"></span>
                </span>
                <strong>Catat Log Penolakan IP yang Diblokir</strong>
              </label>
              <span class="checkbox-desc">Menyimpan log sistem SLiMS setiap kali IP yang diblokir mencoba memuat situs. Nonaktifkan agar beban database tetap ringan ketika diserang spam.</span>
            </div>

            <div class="amzld-inline-group">
              <div class="amzld-inline-item">
                <span class="amzld-inline-label">Bersihkan Log Otomatis:</span>
                <input type="number" name="log_prune_days" class="amzld-input-number" min="0" max="365" value="<?= amzldString($settings['log_prune_days']) ?>" <?= $can_write ? '' : 'disabled' ?>>
                <span class="amzld-inline-suffix">hari sekali</span>
                <small class="amzld-help-inline">Hapus otomatis log percobaan yang lebih tua dari batas hari ini. Setel 0 untuk mematikan.</small>
              </div>
            </div>
          </div>

          <!-- ACTIONS -->
          <div class="amzld-actions" style="margin-top: 8px; text-align: right;">
            <button type="submit" class="btn btn-primary" <?= $can_write && $schemaReady ? '' : 'disabled' ?>>
              <i class="fa fa-save"></i> Simpan Pengaturan
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- TABLES SECTION -->
    <div class="amzld-columns">
      
      <!-- TABEL LOG PERCOBAAN DENGAN PAGINATION SIMBIO -->
      <section class="amzld-panel" style="border-top: 4px solid var(--indigo-600);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
          <h3 style="margin: 0;"><i class="fa fa-list"></i> Catatan Percobaan Akses Terbaru</h3>
          <div style="display: flex; gap: 8px; align-items: center;">
            <a href="<?= amzldString(amzldAdminUrl(['export_csv' => '1'])) ?>" class="btn btn-xs btn-success notAJAX" style="font-weight: bold; padding: 4px 10px; border-radius: 4px; font-size: 11px; color: #fff !important;">
              <i class="fa fa-download"></i> Export CSV
            </a>
            <a href="<?= amzldString(amzldAdminUrl(['export_excel' => '1'])) ?>" class="btn btn-xs btn-success notAJAX" style="font-weight: bold; padding: 4px 10px; border-radius: 4px; font-size: 11px; color: #fff !important; background-color: #107c41; border-color: #107c41;">
              <i class="fa fa-download"></i> Export Excel
            </a>
            <?php if ($can_write && $schemaReady): ?>
              <form method="post" action="<?= amzldString(amzldAdminUrl([], true)) ?>" style="margin: 0;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus semua log percobaan?');">
                <input type="hidden" name="csrf_token" value="<?= amzldString(amzldGetCsrfToken()) ?>">
                <button type="submit" name="clearLogs" class="btn btn-xs btn-danger" style="font-size: 11px; padding: 4px 10px; border-radius: 4px; font-weight: bold;">
                  <i class="fa fa-trash"></i> Hapus Semua Log
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <p style="color: var(--slate-500); font-size: 12px; margin-bottom: 14px;">Mencatat semua upaya akses mencurigakan ke login SLiMS Anda.</p>
        <div class="table-responsive simbio-grid-container">
          <?= $recentAttemptsHtml ?>
        </div>
      </section>

      <!-- TABEL DAFTAR BLOKIR IP -->
      <section class="amzld-panel" style="border-top: 4px solid var(--rose-600);">
        <h3><i class="fa fa-ban"></i> Daftar Komputer (IP) yang Sedang Diblokir</h3>
        <p style="color: var(--slate-500); font-size: 12px; margin-bottom: 14px;">Komputer yang saat ini dilarang mengakses halaman SLiMS karena dicurigai melakukan serangan.</p>
        <div class="table-responsive simbio-grid-container">
          <?= $activeBlocksHtml ?>
        </div>
      </section>
      
    </div>
  </div>
</div>
