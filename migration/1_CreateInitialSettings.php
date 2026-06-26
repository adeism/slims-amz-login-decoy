<?php

use SLiMS\DB;
use SLiMS\Migration\Migration;

defined('INDEX_AUTH') OR die('Direct access not allowed');

class CreateInitialSettings extends Migration
{
    public function up(): void
    {
        $db = DB::getInstance();

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `amzld_settings` (
                `setting_key` VARCHAR(100) NOT NULL,
                `setting_value` TEXT DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `amzld_attempts` (
                `attempt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip_address` VARCHAR(45) NOT NULL,
                `event_type` VARCHAR(50) NOT NULL,
                `target` VARCHAR(120) NOT NULL,
                `username` VARCHAR(255) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `request_uri` VARCHAR(255) DEFAULT NULL,
                `password_length` VARCHAR(255) DEFAULT NULL,
                `is_blocked` TINYINT(1) NOT NULL DEFAULT 1,
                `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`attempt_id`),
                KEY `idx_amzld_ip_created` (`ip_address`, `created_at`),
                KEY `idx_amzld_event_created` (`event_type`, `created_at`),
                KEY `idx_amzld_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `amzld_alert_locks` (
                `lock_key` VARCHAR(120) NOT NULL,
                `last_sent_at` DATETIME NOT NULL,
                PRIMARY KEY (`lock_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `amzld_blocked_ips` (
                `block_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip_address` VARCHAR(45) NOT NULL,
                `reason` VARCHAR(255) DEFAULT NULL,
                `blocked_until` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`block_id`),
                UNIQUE KEY `uniq_amzld_ip` (`ip_address`),
                KEY `idx_amzld_block_until` (`blocked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `amzld_audit_logs` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $defaults = [
            'secret_token' => bin2hex(random_bytes(10)),
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
        ];

        $statement = $db->prepare(
            'INSERT IGNORE INTO amzld_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)'
        );

        foreach ($defaults as $key => $value) {
            $statement->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS `amzld_audit_logs`');
        $db->exec('DROP TABLE IF EXISTS `amzld_blocked_ips`');
        $db->exec('DROP TABLE IF EXISTS `amzld_alert_locks`');
        $db->exec('DROP TABLE IF EXISTS `amzld_attempts`');
        $db->exec('DROP TABLE IF EXISTS `amzld_settings`');
    }
}
