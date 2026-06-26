<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

if (!function_exists('amzldDefaults')) {
    function amzldDefaults(): array
    {
        static $defaults;
        if ($defaults === null) {
            $defaults = [
                'secret_token' => 'changeme_default_token',
                'alert_email' => '',
                'whitelist_ips' => "127.0.0.1\n::1\n10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16\nfe80::/10\nfc00::/7",
                'honeypot_enabled' => '1',
                'honeypot_delay_seconds' => '3',
                'email_threshold' => '5',
                'email_cooldown_minutes' => '60',
                'session_ttl_minutes' => '30',
                'auto_block_enabled' => '1',
                'block_threshold' => '10',
                'block_duration_minutes' => '1440',
                'htaccess_block_enabled' => '0',
                'trust_cf_header' => '0',
                'whitelist_bypass_enabled' => '1',
                'log_honeypot_views' => '0',
                'log_blocked_denials' => '0',
                'log_prune_days' => '30',
                'migrated_local_ips_whitelist' => '0',
            ];
        }
        return $defaults;
    }
}

if (!function_exists('amzldString')) {
    function amzldString($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('amzldInputString')) {
    function amzldInputString(array $source, string $key, int $maxLength = 255): string
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return '';
        }

        $value = trim((string) $source[$key]);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('amzldAdminUrl')) {
    function amzldAdminUrl(array $params = [], bool $reset = false): string
    {
        if (function_exists('pluginUrl')) {
            return pluginUrl($params, $reset);
        }

        $base = defined('AWB') ? AWB . 'plugin_container.php' : 'plugin_container.php';
        $query = [
            'mod' => amzldInputString($_GET, 'mod', 50) ?: 'system',
            'id' => amzldInputString($_GET, 'id', 100),
        ];

        if (!$reset) {
            foreach ($_GET as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $query[$key] = (string) $value;
                }
            }
        }

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } elseif (is_scalar($value)) {
                $query[$key] = (string) $value;
            }
        }

        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('amzldBaseUrl')) {
    function amzldBaseUrl(): string
    {
        return defined('SWB') ? SWB : '/';
    }
}

if (!function_exists('amzldGetCsrfToken')) {
    function amzldGetCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['amzld_csrf_token']) || !is_string($_SESSION['amzld_csrf_token'])) {
            $_SESSION['amzld_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['amzld_csrf_token'];
    }
}

if (!function_exists('amzldValidateCsrf')) {
    function amzldValidateCsrf(): bool
    {
        $token = amzldInputString($_POST, 'csrf_token', 128);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        return $token !== ''
            && isset($_SESSION['amzld_csrf_token'])
            && is_string($_SESSION['amzld_csrf_token'])
            && hash_equals($_SESSION['amzld_csrf_token'], $token);
    }
}

if (!function_exists('amzldTableExists')) {
    function amzldTableExists(mysqli $dbs, string $table): bool
    {
        $statement = $dbs->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );

        if (!$statement) {
            return false;
        }

        $statement->bind_param('s', $table);
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        return (int) $count > 0;
    }
}

if (!function_exists('amzldSchemaReady')) {
    function amzldSchemaReady(mysqli $dbs): bool
    {
        static $ready;

        if ($ready === null) {
            $basicReady = amzldTableExists($dbs, 'amzld_settings')
                && amzldTableExists($dbs, 'amzld_attempts')
                && amzldTableExists($dbs, 'amzld_alert_locks')
                && amzldTableExists($dbs, 'amzld_blocked_ips');

            if ($basicReady) {
                // Ensure amzld_audit_logs table exists
                if (!amzldTableExists($dbs, 'amzld_audit_logs')) {
                    $dbs->query("CREATE TABLE IF NOT EXISTS `amzld_audit_logs` (
                        `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` INT UNSIGNED NOT NULL,
                        `username` VARCHAR(50) NOT NULL,
                        `realname` VARCHAR(100) NOT NULL,
                        `ip_address` VARCHAR(45) NOT NULL,
                        `action` VARCHAR(100) NOT NULL,
                        `details` TEXT DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`audit_id`),
                        KEY `idx_amzld_audit_created` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // Seamlessly upgrade amzld_attempts schema if new columns are missing
                $check = $dbs->query("SHOW COLUMNS FROM `amzld_attempts` LIKE 'attempt_count'");
                if ($check && $check->num_rows === 0) {
                    $dbs->query("ALTER TABLE `amzld_attempts` ADD COLUMN `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_blocked`");
                    $dbs->query("ALTER TABLE `amzld_attempts` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
                }
                // Increase username column size to VARCHAR(255) to support aggregated username lists
                $checkUser = $dbs->query("SHOW COLUMNS FROM `amzld_attempts` LIKE 'username'");
                if ($checkUser && $row = $checkUser->fetch_assoc()) {
                    if (strpos($row['Type'], '255') === false) {
                        $dbs->query("ALTER TABLE `amzld_attempts` MODIFY COLUMN `username` VARCHAR(255) DEFAULT NULL");
                    }
                }
                // Upgrade password_length column size to VARCHAR(255) to support aggregated password lengths list
                $checkPass = $dbs->query("SHOW COLUMNS FROM `amzld_attempts` LIKE 'password_length'");
                if ($checkPass && $row = $checkPass->fetch_assoc()) {
                    if (strpos($row['Type'], 'varchar') === false) {
                        $dbs->query("ALTER TABLE `amzld_attempts` MODIFY COLUMN `password_length` VARCHAR(255) DEFAULT NULL");
                    }
                }
            }

            $ready = $basicReady && amzldTableExists($dbs, 'amzld_audit_logs');
        }

        return $ready;
    }
}

if (!function_exists('amzldLoadSettings')) {
    function amzldLoadSettings(?mysqli $database = null, bool $forceRefresh = false): array
    {
        static $cached = null;

        global $dbs;
        $database = $database ?: $dbs;

        if ($cached !== null && $database === $dbs && !$forceRefresh) {
            return $cached;
        }

        $settings = amzldDefaults();

        if (!$database instanceof mysqli || !amzldSchemaReady($database)) {
            return $settings;
        }

        $result = $database->query('SELECT setting_key, setting_value FROM amzld_settings');
        if (!$result) {
            return $settings;
        }

        while ($row = $result->fetch_assoc()) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
        $result->free();

        // Auto-migrate whitelist_ips if it has not migrated local IPs
        if (!isset($settings['migrated_local_ips_whitelist']) || $settings['migrated_local_ips_whitelist'] !== '1') {
            $currentWhitelist = trim($settings['whitelist_ips']);
            $currentEntries = preg_split('/[\s,;]+/', $currentWhitelist, -1, PREG_SPLIT_NO_EMPTY);
            
            $localIPs = [
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                'fe80::/10',
                'fc00::/7'
            ];
            
            $added = false;
            foreach ($localIPs as $lip) {
                if (!in_array($lip, $currentEntries)) {
                    $currentEntries[] = $lip;
                    $added = true;
                }
            }
            
            if ($added) {
                $newWhitelist = implode("\n", $currentEntries);
                $stmt = $database->prepare('UPDATE amzld_settings SET setting_value = ? WHERE setting_key = ?');
                if ($stmt) {
                    $key = 'whitelist_ips';
                    $stmt->bind_param('ss', $newWhitelist, $key);
                    $stmt->execute();
                    $stmt->close();
                }
                $settings['whitelist_ips'] = $newWhitelist;
            }
            
            // Mark migration as done
            $stmt = $database->prepare('INSERT INTO amzld_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            if ($stmt) {
                $mkey = 'migrated_local_ips_whitelist';
                $mval = '1';
                $stmt->bind_param('ss', $mkey, $mval);
                $stmt->execute();
                $stmt->close();
            }
            $settings['migrated_local_ips_whitelist'] = '1';
        }

        if ($database === $dbs) {
            $cached = $settings;
        }

        return $settings;
    }
}

if (!function_exists('amzldGetSetting')) {
    function amzldGetSetting(string $key, ?string $default = null): string
    {
        $settings = amzldLoadSettings();
        $defaults = amzldDefaults();

        if (array_key_exists($key, $settings)) {
            return (string) $settings[$key];
        }

        if ($default !== null) {
            return $default;
        }

        return (string) ($defaults[$key] ?? '');
    }
}

if (!function_exists('amzldSaveSetting')) {
    function amzldSaveSetting(mysqli $dbs, string $key, string $value): bool
    {
        if (!array_key_exists($key, amzldDefaults()) || !amzldSchemaReady($dbs)) {
            return false;
        }

        $statement = $dbs->prepare(
            'INSERT INTO amzld_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        if (!$statement) {
            return false;
        }

        $statement->bind_param('ss', $key, $value);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }
}

if (!function_exists('amzldBoolSetting')) {
    function amzldBoolSetting(array $settings, string $key): bool
    {
        return isset($settings[$key]) && (string) $settings[$key] === '1';
    }
}
