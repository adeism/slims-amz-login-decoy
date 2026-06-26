<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

global $dbs, $sysconf;

require_once LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-system');

require_once SB . 'admin/default/session.inc.php';
require_once SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/helper.php';

// Simbio GUI components
require_once SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require_once SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require_once SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';

$can_read = utility::havePrivilege('system', 'r');
$can_write = utility::havePrivilege('system', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You do not have access!') . '</div>');
}

// 1. Handle GET Exports (CSV / Excel)
$schemaReady = $dbs instanceof mysqli && amzldSchemaReady($dbs);

if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=amzld_attempts_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Waktu Mulai', 'Waktu Terakhir', 'Jumlah Hit', 'IP Address', 'Event Type', 'Target', 'Nama Akun / Username', 'Panjang Password', 'User Agent', 'URI Request', 'Status']);
    
    if ($schemaReady) {
        $result = $dbs->query('SELECT created_at, updated_at, attempt_count, ip_address, event_type, target, username, password_length, user_agent, request_uri, is_blocked FROM amzld_attempts ORDER BY updated_at DESC');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['created_at'],
                    $row['updated_at'],
                    $row['attempt_count'],
                    $row['ip_address'],
                    $row['event_type'],
                    $row['target'],
                    $row['username'],
                    $row['password_length'],
                    $row['user_agent'],
                    $row['request_uri'],
                    $row['is_blocked'] ? 'Blocked' : 'Allowed'
                ]);
            }
            $result->free();
        }
    }
    fclose($output);
    exit;
}

if (isset($_GET['export_excel']) && $_GET['export_excel'] == '1') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=amzld_attempts_' . date('Ymd_His') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $sep = "\t";
    echo implode($sep, ['Waktu Mulai', 'Waktu Terakhir', 'Jumlah Hit', 'IP Address', 'Event Type', 'Target', 'Nama Akun / Username', 'Panjang Password', 'User Agent', 'URI Request', 'Status']) . "\n";
    
    if ($schemaReady) {
        $result = $dbs->query('SELECT created_at, updated_at, attempt_count, ip_address, event_type, target, username, password_length, user_agent, request_uri, is_blocked FROM amzld_attempts ORDER BY updated_at DESC');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $statusVal = $row['is_blocked'] ? 'Blocked' : 'Allowed';
                $line = [
                    $row['created_at'],
                    $row['updated_at'],
                    $row['attempt_count'],
                    $row['ip_address'],
                    $row['event_type'],
                    $row['target'],
                    $row['username'],
                    $row['password_length'],
                    $row['user_agent'],
                    $row['request_uri'],
                    $statusVal
                ];
                foreach ($line as &$val) {
                    $val = str_replace($sep, ' ', (string) $val);
                    $val = preg_replace("/\r\n|\n\r|\n|\r/", ' ', $val);
                }
                echo implode($sep, $line) . "\n";
            }
            $result->free();
        }
    }
    exit;
}

// 2. Perform background logging cleanups
if ($schemaReady) {
    amzldPruneLogs($dbs);
}
$settings = $schemaReady ? amzldLoadSettings($dbs) : amzldDefaults();
$errors = [];

// 3. Log main access to plugin
if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['status']) && !isset($_GET['page']) && !isset($_GET['sort'])) {
    amzldRecordAudit($dbs, 'Akses Plugin', 'Membuka halaman dashboard pengaturan.');
}

// 4. Handle POST form handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_write) {
        $errors[] = 'Anda tidak memiliki hak untuk mengubah pengaturan atau melakukan tindakan ini.';
    } elseif (!$schemaReady) {
        $errors[] = 'Database plugin belum siap. Nonaktifkan lalu aktifkan kembali plugin untuk menjalankan migration.';
    } elseif (!amzldValidateCsrf()) {
        $errors[] = 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        if (isset($_POST['saveSettings'])) {
            $secret = amzldInputString($_POST, 'secret_token', 80);
            $email = amzldInputString($_POST, 'alert_email', 150);

            if (!preg_match('/^[A-Za-z0-9._-]{5,80}$/', $secret)) {
                $errors[] = 'Secret token harus 5-80 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.';
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Alamat email alert tidak valid.';
            }

            if (!$errors) {
                $newSettings = [
                    'secret_token' => $secret,
                    'alert_email' => $email,
                    'whitelist_ips' => amzldNormalizeIpList((string) ($_POST['whitelist_ips'] ?? '')),
                    'honeypot_enabled' => isset($_POST['honeypot_enabled']) ? '1' : '0',
                    'honeypot_delay_seconds' => (string) max(0, min(3, (int) ($_POST['honeypot_delay_seconds'] ?? 3))),
                    'email_threshold' => (string) max(1, min(100, (int) ($_POST['email_threshold'] ?? 5))),
                    'email_cooldown_minutes' => (string) max(5, min(1440, (int) ($_POST['email_cooldown_minutes'] ?? 60))),
                    'session_ttl_minutes' => (string) max(1, min(1440, (int) ($_POST['session_ttl_minutes'] ?? 30))),
                    'auto_block_enabled' => isset($_POST['auto_block_enabled']) ? '1' : '0',
                    'block_threshold' => (string) max(1, min(100, (int) ($_POST['block_threshold'] ?? 10))),
                    'block_duration_minutes' => (string) max(5, min(525600, (int) ($_POST['block_duration_minutes'] ?? 1440))),
                    'htaccess_block_enabled' => isset($_POST['htaccess_block_enabled']) ? '1' : '0',
                    'trust_cf_header' => isset($_POST['trust_cf_header']) ? '1' : '0',
                    'whitelist_bypass_enabled' => isset($_POST['whitelist_bypass_enabled']) ? '1' : '0',
                    'log_honeypot_views' => isset($_POST['log_honeypot_views']) ? '1' : '0',
                    'log_blocked_denials' => isset($_POST['log_blocked_denials']) ? '1' : '0',
                    'log_prune_days' => (string) max(0, min(365, (int) ($_POST['log_prune_days'] ?? 30))),
                ];

                $changes = [];
                foreach ($newSettings as $key => $val) {
                    $oldVal = $settings[$key] ?? '';
                    if ((string)$oldVal !== (string)$val) {
                        if ($key === 'secret_token') {
                            $changes[] = "$key: [diubah]";
                        } else {
                            $changes[] = "$key: '$oldVal' -> '$val'";
                        }
                    }
                }

                foreach ($newSettings as $key => $value) {
                    amzldSaveSetting($dbs, $key, $value);
                }

                if (!empty($changes)) {
                    amzldRecordAudit($dbs, 'Ubah Pengaturan', 'Mengubah pengaturan: ' . implode(', ', $changes));
                } else {
                    amzldRecordAudit($dbs, 'Ubah Pengaturan', 'Menyimpan pengaturan tanpa perubahan.');
                }

                amzldApplyHtaccessBlocks($dbs, $newSettings);

                header('Location: ' . amzldAdminUrl(['status' => 'saved'], true));
                exit;
            }
        } elseif (isset($_POST['testEmail'])) {
            $secret = amzldInputString($_POST, 'secret_token', 80);
            $email = amzldInputString($_POST, 'alert_email', 150);

            if (!preg_match('/^[A-Za-z0-9._-]{5,80}$/', $secret)) {
                $errors[] = 'Secret token harus 5-80 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.';
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Alamat email alert tidak valid.';
            }

            if (!$errors) {
                $newSettings = [
                    'secret_token' => $secret,
                    'alert_email' => $email,
                    'whitelist_ips' => amzldNormalizeIpList((string) ($_POST['whitelist_ips'] ?? '')),
                    'honeypot_enabled' => isset($_POST['honeypot_enabled']) ? '1' : '0',
                    'honeypot_delay_seconds' => (string) max(0, min(3, (int) ($_POST['honeypot_delay_seconds'] ?? 3))),
                    'email_threshold' => (string) max(1, min(100, (int) ($_POST['email_threshold'] ?? 5))),
                    'email_cooldown_minutes' => (string) max(5, min(1440, (int) ($_POST['email_cooldown_minutes'] ?? 60))),
                    'session_ttl_minutes' => (string) max(1, min(1440, (int) ($_POST['session_ttl_minutes'] ?? 30))),
                    'auto_block_enabled' => isset($_POST['auto_block_enabled']) ? '1' : '0',
                    'block_threshold' => (string) max(1, min(100, (int) ($_POST['block_threshold'] ?? 10))),
                    'block_duration_minutes' => (string) max(5, min(525600, (int) ($_POST['block_duration_minutes'] ?? 1440))),
                    'htaccess_block_enabled' => isset($_POST['htaccess_block_enabled']) ? '1' : '0',
                    'trust_cf_header' => isset($_POST['trust_cf_header']) ? '1' : '0',
                    'whitelist_bypass_enabled' => isset($_POST['whitelist_bypass_enabled']) ? '1' : '0',
                ];

                $changes = [];
                foreach ($newSettings as $key => $val) {
                    $oldVal = $settings[$key] ?? '';
                    if ((string)$oldVal !== (string)$val) {
                        if ($key === 'secret_token') {
                            $changes[] = "$key: [diubah]";
                        } else {
                            $changes[] = "$key: '$oldVal' -> '$val'";
                        }
                    }
                }

                foreach ($newSettings as $key => $value) {
                    amzldSaveSetting($dbs, $key, $value);
                }

                if (!empty($changes)) {
                    amzldRecordAudit($dbs, 'Ubah Pengaturan', 'Mengubah pengaturan saat test email: ' . implode(', ', $changes));
                }

                amzldApplyHtaccessBlocks($dbs, $newSettings);

                if (amzldSendTestEmail($dbs, $email)) {
                    amzldRecordAudit($dbs, 'Kirim Email Uji Coba', 'Sukses mengirim email uji coba ke: ' . $email);
                    header('Location: ' . amzldAdminUrl(['status' => 'test_success'], true));
                    exit;
                } else {
                    amzldRecordAudit($dbs, 'Kirim Email Uji Coba', 'Gagal mengirim email uji coba ke: ' . $email);
                    $errors[] = 'Gagal mengirim email uji coba. Periksa konfigurasi email sistem SLiMS.';
                }
            }
        } elseif (isset($_POST['unblock_ip'])) {
            $ipToUnblock = amzldInputString($_POST, 'unblock_ip', 45);
            if (filter_var($ipToUnblock, FILTER_VALIDATE_IP)) {
                $statement = $dbs->prepare('DELETE FROM amzld_blocked_ips WHERE ip_address = ?');
                if ($statement) {
                    $statement->bind_param('s', $ipToUnblock);
                    $statement->execute();
                    $statement->close();
                    
                    amzldRecordAudit($dbs, 'Unblock IP', 'Membuka blokir alamat IP: ' . $ipToUnblock);
                    amzldApplyHtaccessBlocks($dbs, $settings);
                    
                    header('Location: ' . amzldAdminUrl(['status' => 'unblocked'], true));
                    exit;
                }
            }
            $errors[] = 'Gagal melakukan unblock IP.';
        } elseif (isset($_POST['clearLogs'])) {
            $ok = $dbs->query('TRUNCATE TABLE amzld_attempts');
            if ($ok) {
                amzldRecordAudit($dbs, 'Hapus Log Percobaan', 'Menghapus seluruh log percobaan login palsu (honeypot).');
                header('Location: ' . amzldAdminUrl(['status' => 'cleared'], true));
                exit;
            }
            $errors[] = 'Gagal menghapus log.';
        }
    }
}

// 5. Reload Settings, Stats and Data for View
$settings = $schemaReady ? amzldLoadSettings($dbs) : $settings;
$stats = $schemaReady ? amzldGetStats($dbs) : ['today_attempts' => 0, 'last_hour_attempts' => 0, 'unique_ips_24h' => 0, 'active_blocks' => 0, 'total_attempts' => 0, 'total_incidents' => 0];
$status = amzldInputString($_GET, 'status', 30);
$secretUrl = amzldBaseUrl() . 'index.php?p=ld&' . ($settings['secret_token'] ?? 'loginstaf123');
$mailConfig = function_exists('config') ? config('mail') : null;
$isMailConfigured = is_array($mailConfig) && !empty($mailConfig['server']);

// Load Admin Audit Logs
$auditLogs = [];
if ($schemaReady) {
    $auditResult = $dbs->query('SELECT created_at, username, realname, ip_address, action, details FROM amzld_audit_logs ORDER BY created_at DESC LIMIT 100');
    if ($auditResult) {
        while ($auditRow = $auditResult->fetch_assoc()) {
            $auditLogs[] = $auditRow;
        }
        $auditResult->free();
    }
}

// 6. Generate Simbio Datagrids
$activeBlocksHtml = '';
if ($schemaReady) {
    if (!function_exists('amzldFormatUnblockAction')) {
        function amzldFormatUnblockAction($db, $row, $index)
        {
            $ip = htmlspecialchars($row[$index] ?? '');
            $csrf = amzldString(amzldGetCsrfToken());
            $url = amzldString(amzldAdminUrl([], true));
            return '<form method="post" action="' . $url . '" style="margin: 0;" onsubmit="return confirm(\'Apakah Anda yakin ingin membuka blokir IP ini?\');">'
                 . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                 . '<input type="hidden" name="unblock_ip" value="' . $ip . '">'
                 . '<button type="submit" class="btn btn-xs btn-warning" style="font-size: 10px; padding: 4px 8px; border-radius: 4px; font-weight: bold;">'
                 . '<i class="fa fa-check"></i> Unblock'
                 . '</button>'
                 . '</form>';
        }
    }

    $bgBlock = new simbio_datagrid();
    $bgBlock->setSQLColumn(
        'ip_address AS \'' . __('Alamat IP') . '\'',
        'blocked_until AS \'' . __('Diblokir Sampai') . '\'',
        'reason AS \'' . __('Alasan Pemblokiran') . '\'',
        'ip_address AS \'' . __('Aksi') . '\''
    );
    $bgBlock->setSQLorder('updated_at DESC');
    $bgBlock->table_attr = 'id="blockedIpsGrid" class="s-table table table-striped table-condensed table-hover"';
    $bgBlock->table_header_attr = 'class="dataListHeader" style="font-weight: bold; background-color: #ffe4e6;"';
    
    $bgBlock->modifyColumnContent(3, 'callback{amzldFormatUnblockAction}');
    $activeBlocksHtml = $bgBlock->createDataGrid($dbs, 'amzld_blocked_ips', 10, 'blocked_until IS NULL OR blocked_until > NOW()', false);
} else {
    $activeBlocksHtml = '<div class="text-muted">Tidak ada komputer yang sedang diblokir aktif saat ini.</div>';
}

$recentAttemptsHtml = '';
if ($schemaReady) {
    if (!function_exists('amzldFormatUsername')) {
        function amzldFormatUsername($db, $row, $index)
        {
            $val = trim($row[$index] ?? '');
            return $val !== '' ? htmlspecialchars($val) : '-';
        }
    }

    if (!function_exists('amzldFormatEventType')) {
        function amzldFormatEventType($db, $row, $index)
        {
            $val = trim($row[$index] ?? '');
            $map = [
                'secret_door_invalid' => '<span class="label label-danger">Token Salah / Akses Ilegal</span>',
                'staff_login_without_secret' => '<span class="label label-warning">Akses Tanpa Otorisasi</span>',
                'honeypot_view' => '<span class="label label-info">Melihat Login Palsu</span>',
                'honeypot_submit' => '<span class="label label-danger">Mencoba Login Palsu</span>',
                'admin_direct_without_session' => '<span class="label label-warning">Akses Admin Langsung</span>',
            ];
            return $map[$val] ?? htmlspecialchars($val);
        }
    }

    $datagrid = new simbio_datagrid();
    $datagrid->setSQLColumn(
        'created_at AS \'' . __('Waktu Mulai') . '\'',
        'updated_at AS \'' . __('Waktu Terakhir') . '\'',
        'attempt_count AS \'' . __('Jumlah') . '\'',
        'ip_address AS \'' . __('IP Address') . '\'',
        'event_type AS \'' . __('Event') . '\'',
        'target AS \'' . __('Target') . '\'',
        'username AS \'' . __('Username') . '\'',
        'password_length AS \'' . __('Panjang Password') . '\''
    );
    $datagrid->setSQLorder('updated_at DESC');
    $datagrid->table_attr = 'id="recentAttemptsGrid" class="s-table table table-striped table-condensed table-hover"';
    $datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold; background-color: #f8fafc;"';
    
    $datagrid->modifyColumnContent(6, 'callback{amzldFormatUsername}');
    $datagrid->modifyColumnContent(4, 'callback{amzldFormatEventType}');
    
    $recentAttemptsHtml = $datagrid->createDataGrid($dbs, 'amzld_attempts', 10, false);
} else {
    $recentAttemptsHtml = '<div class="text-muted">Belum ada data percobaan.</div>';
}

// 7. Load Visual Dashboard and Modal Templates
require_once AMZLD_PLUGIN_DIR . '/parts/admin_dashboard.php';
require_once AMZLD_PLUGIN_DIR . '/parts/admin_audit_modal.php';
