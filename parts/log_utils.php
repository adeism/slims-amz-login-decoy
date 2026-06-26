<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

if (!function_exists('amzldSystemLog')) {
    function amzldSystemLog(string $message, string $action = 'Block'): void
    {
        global $dbs;

        if (!$dbs instanceof mysqli || !class_exists('utility')) {
            return;
        }

        $ip = amzldClientIp();
        $safeMessage = '[AMZ Login Decoy] IP ' . $ip . ' - ' . $message;

        utility::writeLogs(
            $dbs,
            'system',
            'amz_login_decoy',
            'Decoy Login',
            $safeMessage,
            'Login Guard',
            $action
        );
    }
}

if (!function_exists('amzldAttemptCountLastHour')) {
    function amzldAttemptCountLastHour(mysqli $dbs, string $ip): int
    {
        if (!amzldSchemaReady($dbs)) {
            return 0;
        }

        $statement = $dbs->prepare(
            'SELECT COUNT(*) FROM amzld_attempts WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );

        if (!$statement) {
            return 0;
        }

        $statement->bind_param('s', $ip);
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        return (int) $count;
    }
}

if (!function_exists('amzldAcquireAlertLock')) {
    function amzldAcquireAlertLock(mysqli $dbs, string $lockKey, int $cooldownMinutes): bool
    {
        if (!amzldSchemaReady($dbs)) {
            return false;
        }

        $lastSent = '';
        $statement = $dbs->prepare('SELECT last_sent_at FROM amzld_alert_locks WHERE lock_key = ?');
        if (!$statement) {
            return false;
        }

        $statement->bind_param('s', $lockKey);
        $statement->execute();
        $statement->bind_result($lastSent);
        $found = $statement->fetch();
        $statement->close();

        if ($found && $lastSent !== '') {
            $cooldownSeconds = max(300, $cooldownMinutes * 60);
            if (strtotime($lastSent) !== false && (time() - strtotime($lastSent)) < $cooldownSeconds) {
                return false;
            }
        }

        $statement = $dbs->prepare(
            'INSERT INTO amzld_alert_locks (lock_key, last_sent_at)
             VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_sent_at = NOW()'
        );

        if (!$statement) {
            return false;
        }

        $statement->bind_param('s', $lockKey);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }
}

if (!function_exists('amzldResolveRecipientEmail')) {
    function amzldResolveRecipientEmail(mysqli $dbs, array $settings, array $sysconf = []): string
    {
        $email = trim((string) ($settings['alert_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $statement = $dbs->prepare('SELECT email FROM user WHERE user_id = 1 LIMIT 1');
        if ($statement) {
            $statement->execute();
            $statement->bind_result($email);
            if ($statement->fetch() && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $statement->close();
                return (string) $email;
            }
            $statement->close();
        }

        $fallback = $sysconf['OAI']['Identify']['adminEmail'] ?? '';
        if (is_array($fallback)) {
            $fallback = reset($fallback);
        }

        return filter_var($fallback, FILTER_VALIDATE_EMAIL) ? (string) $fallback : '';
    }
}

if (!function_exists('amzldSendEmailAlert')) {
    function amzldSendEmailAlert(mysqli $dbs, array $settings, int $count): bool
    {
        global $sysconf;

        $recipient = amzldResolveRecipientEmail($dbs, $settings, is_array($sysconf ?? null) ? $sysconf : []);
        if ($recipient === '' || !class_exists('\SLiMS\Mail')) {
            return false;
        }

        $mailConfig = function_exists('config') ? config('mail') : null;
        if (!is_array($mailConfig) || empty($mailConfig['server'])) {
            return false;
        }

        $ip = amzldClientIp();
        $message = "AMZ Login Decoy detected repeated access to protected login URLs.\n\n"
            . 'IP Address: ' . $ip . "\n"
            . 'Attempts in the last hour: ' . $count . "\n"
            . 'Target URI: ' . amzldSafeRequestUri() . "\n"
            . 'User Agent: ' . substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) . "\n"
            . 'Time: ' . date('Y-m-d H:i:s') . "\n";

        $oldMode = \SLiMS\Mail::$mode;

        try {
            \SLiMS\Mail::$mode = 'new';
            $mail = \SLiMS\Mail::to($recipient, 'SLiMS Administrator');
            $mail->subject('AMZ Login Decoy security alert');
            $mail->message($message);
            return (bool) $mail->send();
        } catch (Throwable $exception) {
            error_log('AMZ Login Decoy email alert failed: ' . $exception->getMessage());
            return false;
        } finally {
            \SLiMS\Mail::$mode = $oldMode;
        }
    }
}

if (!function_exists('amzldSendTestEmail')) {
    function amzldSendTestEmail(mysqli $dbs, string $recipient): bool
    {
        global $sysconf;

        if ($recipient === '' || !class_exists('\SLiMS\Mail')) {
            return false;
        }

        $mailConfig = function_exists('config') ? config('mail') : null;
        if (!is_array($mailConfig) || empty($mailConfig['server'])) {
            return false;
        }

        $message = "Ini adalah email uji coba dari AMZ Login Decoy.\n\n"
            . "Jika Anda menerima email ini, berarti sistem surel Anda sudah terkonfigurasi dengan benar.\n"
            . "Waktu Uji: " . date('Y-m-d H:i:s') . "\n";

        $oldMode = \SLiMS\Mail::$mode;

        try {
            \SLiMS\Mail::$mode = 'new';
            $mail = \SLiMS\Mail::to($recipient, 'SLiMS Administrator');
            $mail->subject('AMZ Login Decoy - Uji Coba Surel');
            $mail->message($message);
            return (bool) $mail->send();
        } catch (Throwable $exception) {
            error_log('AMZ Login Decoy test email failed: ' . $exception->getMessage());
            return false;
        } finally {
            \SLiMS\Mail::$mode = $oldMode;
        }
    }
}

if (!function_exists('amzldIsIpBlocked')) {
    function amzldIsIpBlocked(?string $ip = null): bool
    {
        global $dbs;

        $ip = $ip ?: amzldClientIp();
        if (!$dbs instanceof mysqli || !amzldSchemaReady($dbs)) {
            return false;
        }

        $statement = $dbs->prepare(
            'SELECT block_id FROM amzld_blocked_ips
             WHERE ip_address = ? AND (blocked_until IS NULL OR blocked_until > NOW())
             LIMIT 1'
        );

        if (!$statement) {
            return false;
        }

        $statement->bind_param('s', $ip);
        $statement->execute();
        $statement->store_result();
        $blocked = $statement->num_rows > 0;
        $statement->close();

        return $blocked;
    }
}

if (!function_exists('amzldApplyHtaccessBlocks')) {
    function amzldApplyHtaccessBlocks(mysqli $dbs, array $settings): bool
    {
        if (!defined('SB')) {
            return false;
        }

        $path = SB . '.htaccess';
        $markerStart = '# BEGIN AMZ Login Decoy';
        $markerEnd = '# END AMZ Login Decoy';

        $exists = file_exists($path);
        if (($exists && !is_writable($path)) || (!$exists && !is_writable(SB))) {
            return false;
        }

        if (!amzldBoolSetting($settings, 'htaccess_block_enabled')) {
            if (!$exists) {
                return true;
            }
            $fp = fopen($path, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $content = '';
                while (!feof($fp)) {
                    $content .= fread($fp, 8192);
                }
                $pattern = '/' . preg_quote($markerStart, '/') . '.*?' . preg_quote($markerEnd, '/') . "\n?/s";
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, '', $content);
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, $content);
                    fflush($fp);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
                return true;
            }
            return false;
        }

        $ips = [];
        $result = $dbs->query(
            'SELECT ip_address, updated_at FROM amzld_blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW() ORDER BY ip_address ASC'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (filter_var($row['ip_address'], FILTER_VALIDATE_IP)) {
                    $ips[] = [
                        'ip' => $row['ip_address'],
                        'time' => $row['updated_at']
                    ];
                }
            }
            $result->free();
        }

        $block = $markerStart . "\n"
            . "<IfModule mod_authz_core.c>\n"
            . "<RequireAll>\n"
            . "Require all granted\n";

        foreach ($ips as $item) {
            $block .= 'Require not ip ' . $item['ip'] . ' # Blocked at ' . $item['time'] . "\n";
        }

        $block .= "</RequireAll>\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "Order Allow,Deny\n"
            . "Allow from all\n";

        foreach ($ips as $item) {
            $block .= 'Deny from ' . $item['ip'] . ' # Blocked at ' . $item['time'] . "\n";
        }

        $block .= "</IfModule>\n" . $markerEnd . "\n";

        $fp = fopen($path, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }
            $pattern = '/' . preg_quote($markerStart, '/') . '.*?' . preg_quote($markerEnd, '/') . "\n?/s";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $block, $content);
            } else {
                $content = rtrim($content) . "\n\n" . $block;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $content);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        }

        return false;
    }
}

if (!function_exists('amzldMaybeBlockIp')) {
    function amzldMaybeBlockIp(mysqli $dbs, array $settings, string $ip, int $count): void
    {
        if (!amzldBoolSetting($settings, 'auto_block_enabled')) {
            return;
        }

        $threshold = max(1, (int) ($settings['block_threshold'] ?? 10));
        if ($count < $threshold || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        $duration = max(5, min(525600, (int) ($settings['block_duration_minutes'] ?? 1440)));
        $blockedUntil = date('Y-m-d H:i:s', time() + ($duration * 60));
        $reason = 'Exceeded AMZ Login Decoy threshold: ' . $count . ' attempts in one hour';

        $statement = $dbs->prepare(
            'INSERT INTO amzld_blocked_ips (ip_address, reason, blocked_until, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_until = VALUES(blocked_until), updated_at = NOW()'
        );

        if (!$statement) {
            return;
        }

        $statement->bind_param('sss', $ip, $reason, $blockedUntil);
        $statement->execute();
        $statement->close();

        amzldApplyHtaccessBlocks($dbs, $settings);
    }
}

if (!function_exists('amzldAfterAttempt')) {
    function amzldAfterAttempt(mysqli $dbs, array $settings, string $ip): void
    {
        if (amzldIsWhitelistedIp($ip, $settings)) {
            return;
        }

        $count = amzldAttemptCountLastHour($dbs, $ip);
        $emailThreshold = max(1, (int) ($settings['email_threshold'] ?? 5));
        $cooldown = max(5, (int) ($settings['email_cooldown_minutes'] ?? 60));

        if ($count >= $emailThreshold && amzldAcquireAlertLock($dbs, 'ip_' . sha1($ip), $cooldown)) {
            amzldSendEmailAlert($dbs, $settings, $count);
        }

        amzldMaybeBlockIp($dbs, $settings, $ip, $count);
    }
}

if (!function_exists('amzldPruneLogs')) {
    function amzldPruneLogs(mysqli $dbs): void
    {
        $days = (int) amzldGetSetting('log_prune_days', '30');
        if ($days <= 0) {
            return;
        }

        // Delete attempts older than X days
        $statement = $dbs->prepare(
            'DELETE FROM amzld_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($statement) {
            $statement->bind_param('i', $days);
            $statement->execute();
            $statement->close();
        }

        // Delete audit logs older than X days
        $statement = $dbs->prepare(
            'DELETE FROM amzld_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($statement) {
            $statement->bind_param('i', $days);
            $statement->execute();
            $statement->close();
        }
    }
}

if (!function_exists('amzldRecordAttempt')) {
    function amzldRecordAttempt(
        string $eventType,
        string $target,
        string $username = '',
        int $passwordLength = 0,
        bool $blocked = true
    ): void {
        global $dbs;

        $ip = amzldClientIp();
        $message = $eventType . ' targeting ' . $target . ' at ' . amzldSafeRequestUri();
        if ($username !== '') {
            $message .= ' username=' . $username;
        }
        amzldSystemLog($message, $blocked ? 'Block' : 'Allow');

        if (!$dbs instanceof mysqli || !amzldSchemaReady($dbs)) {
            return;
        }

        $settings = amzldLoadSettings($dbs);

        // Run garbage collection with 1% probability
        if (mt_rand(1, 100) === 1) {
            amzldPruneLogs($dbs);
        }

        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $uri = amzldSafeRequestUri();
        $username = function_exists('mb_substr') ? mb_substr($username, 0, 100) : substr($username, 0, 100);
        $target = function_exists('mb_substr') ? mb_substr($target, 0, 120) : substr($target, 0, 120);
        $eventType = function_exists('mb_substr') ? mb_substr($eventType, 0, 50) : substr($eventType, 0, 50);
        $passwordLength = (int) $passwordLength;
        $blockedFlag = $blocked ? 1 : 0;

        // Check if an attempt log already exists for this combination
        $existingId = 0;
        $existingUsernames = '';
        $existingPassLengths = '';
        $checkStmt = $dbs->prepare(
            'SELECT attempt_id, username, password_length FROM amzld_attempts 
             WHERE ip_address = ? AND event_type = ? AND target = ? 
             LIMIT 1'
        );
        if ($checkStmt) {
            $checkStmt->bind_param('sss', $ip, $eventType, $target);
            $checkStmt->execute();
            $checkStmt->bind_result($existingId, $existingUsernames, $existingPassLengths);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if ($existingId > 0) {
            // Deduplicated username list appending
            $usernameList = [];
            if ($existingUsernames !== null && trim((string)$existingUsernames) !== '') {
                $usernameList = array_map('trim', explode(',', (string)$existingUsernames));
            }
            if (trim($username) !== '' && !in_array($username, $usernameList)) {
                $usernameList[] = $username;
            }
            // limit username length to fit VARCHAR(255)
            $newUsernameField = implode(', ', $usernameList);
            if (strlen($newUsernameField) > 255) {
                $newUsernameField = substr($newUsernameField, 0, 252) . '...';
            }

            // Deduplicated password length list appending
            $passLengthList = [];
            if ($existingPassLengths !== null && trim((string)$existingPassLengths) !== '') {
                $passLengthList = array_map('trim', explode(',', (string)$existingPassLengths));
            }
            $strPasswordLength = (string) $passwordLength;
            if ($strPasswordLength !== '' && !in_array($strPasswordLength, $passLengthList)) {
                $passLengthList[] = $strPasswordLength;
            }
            $newPassLengthField = implode(', ', $passLengthList);
            if (strlen($newPassLengthField) > 255) {
                $newPassLengthField = substr($newPassLengthField, 0, 252) . '...';
            }

            // Update existing log row
            $statement = $dbs->prepare(
                'UPDATE amzld_attempts 
                 SET username = ?, user_agent = ?, request_uri = ?, password_length = ?, is_blocked = ?, updated_at = NOW(), attempt_count = attempt_count + 1 
                 WHERE attempt_id = ?'
            );
            if ($statement) {
                $statement->bind_param(
                    'ssssii',
                    $newUsernameField,
                    $ua,
                    $uri,
                    $newPassLengthField,
                    $blockedFlag,
                    $existingId
                );
                $statement->execute();
                $statement->close();
            }
        } else {
            // Insert new log row
            $statement = $dbs->prepare(
                'INSERT INTO amzld_attempts
                 (ip_address, event_type, target, username, user_agent, request_uri, password_length, is_blocked, created_at, updated_at, attempt_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)'
            );
            if ($statement) {
                $passwordLengthStr = (string) $passwordLength;
                $statement->bind_param(
                    'sssssssi',
                    $ip,
                    $eventType,
                    $target,
                    $username,
                    $ua,
                    $uri,
                    $passwordLengthStr,
                    $blockedFlag
                );
                $statement->execute();
                $statement->close();
            }
        }

        amzldAfterAttempt($dbs, $settings, $ip);
    }
}

if (!function_exists('amzldRecordAudit')) {
    function amzldRecordAudit(mysqli $dbs, string $action, string $details = ''): bool
    {
        if (!amzldSchemaReady($dbs)) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $userId = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
        $username = isset($_SESSION['uname']) ? (string)$_SESSION['uname'] : 'system';
        $realname = isset($_SESSION['realname']) ? (string)$_SESSION['realname'] : 'System';
        $ip = amzldClientIp();

        $statement = $dbs->prepare(
            'INSERT INTO amzld_audit_logs (user_id, username, realname, ip_address, action, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );

        if (!$statement) {
            return false;
        }

        $statement->bind_param('isssss', $userId, $username, $realname, $ip, $action, $details);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }
}

if (!function_exists('amzldGetStats')) {
    function amzldGetStats(mysqli $dbs): array
    {
        $stats = [
            'today_attempts' => 0,
            'last_hour_attempts' => 0,
            'unique_ips_24h' => 0,
            'active_blocks' => 0,
            'total_attempts' => 0,
            'total_incidents' => 0,
        ];

        if (!amzldSchemaReady($dbs)) {
            return $stats;
        }

        $queries = [
            'today_attempts' => "SELECT COALESCE(SUM(attempt_count), 0) FROM amzld_attempts WHERE DATE(updated_at) = CURDATE()",
            'last_hour_attempts' => "SELECT COALESCE(SUM(attempt_count), 0) FROM amzld_attempts WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            'unique_ips_24h' => "SELECT COUNT(DISTINCT ip_address) FROM amzld_attempts WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            'active_blocks' => "SELECT COUNT(*) FROM amzld_blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW()",
            'total_attempts' => "SELECT COALESCE(SUM(attempt_count), 0) FROM amzld_attempts",
            'total_incidents' => "SELECT COUNT(*) FROM amzld_attempts",
        ];

        foreach ($queries as $key => $sql) {
            $result = $dbs->query($sql);
            if ($result) {
                $row = $result->fetch_row();
                $stats[$key] = (int) ($row[0] ?? 0);
                $result->free();
            }
        }

        return $stats;
    }
}

if (!function_exists('amzldActiveBlocks')) {
    function amzldActiveBlocks(mysqli $dbs, int $limit = 20): array
    {
        if (!amzldSchemaReady($dbs)) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $statement = $dbs->prepare(
            'SELECT ip_address, reason, blocked_until, updated_at
             FROM amzld_blocked_ips
             WHERE blocked_until IS NULL OR blocked_until > NOW()
             ORDER BY updated_at DESC
             LIMIT ?'
        );

        if (!$statement) {
            return [];
        }

        $statement->bind_param('i', $limit);
        $statement->execute();
        $statement->bind_result($ip_address, $reason, $blocked_until, $updated_at);

        $rows = [];
        while ($statement->fetch()) {
            $rows[] = [
                'ip_address' => $ip_address,
                'reason' => $reason,
                'blocked_until' => $blocked_until,
                'updated_at' => $updated_at,
            ];
        }
        $statement->close();

        return $rows;
    }
}
