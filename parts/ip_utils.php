<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

if (!function_exists('amzldClientIp')) {
    function amzldClientIp(): string
    {
        static $resolving = false;
        if (!$resolving) {
            $resolving = true;
            $settings = amzldLoadSettings();
            $resolving = false;
            if (isset($settings['trust_cf_header']) && $settings['trust_cf_header'] === '1') {
                if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
                    return trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
                }
            }
        }
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }
}

if (!function_exists('amzldNormalizeIpList')) {
    function amzldNormalizeIpList(string $value): string
    {
        $entries = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];

        foreach ($entries ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            // 1. IP Range (e.g. 192.168.1.10-20 or 192.168.1.10-192.168.1.20)
            if (strpos($entry, '-') !== false) {
                [$start, $end] = array_pad(explode('-', $entry, 2), 2, '');
                $start = trim($start);
                $end = trim($end);
                
                if (filter_var($start, FILTER_VALIDATE_IP)) {
                    if (filter_var($end, FILTER_VALIDATE_IP)) {
                        $valid[] = $start . '-' . $end;
                    } elseif (preg_match('/^\d+$/', $end)) {
                        $lastDotPos = strrpos($start, '.');
                        if ($lastDotPos !== false) {
                            $endIp = substr($start, 0, $lastDotPos + 1) . $end;
                            if (filter_var($endIp, FILTER_VALIDATE_IP)) {
                                $valid[] = $start . '-' . $endIp;
                            }
                        }
                    }
                }
                continue;
            }

            // 2. Wildcard (e.g. 192.168.1.*)
            if (strpos($entry, '*') !== false) {
                if (preg_match('/^[0-9a-fA-F:.*]+$/', $entry)) {
                    $valid[] = $entry;
                }
                continue;
            }

            // 3. CIDR (e.g. 192.168.1.0/24)
            if (strpos($entry, '/') !== false) {
                [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, '');
                $packed = @inet_pton($ip);
                $mask = filter_var($mask, FILTER_VALIDATE_INT);
                if ($packed !== false && $mask !== false && $mask >= 0 && $mask <= strlen($packed) * 8) {
                    $valid[] = $ip . '/' . $mask;
                }
                continue;
            }

            // 4. Single IP (e.g. 192.168.1.100)
            if (filter_var($entry, FILTER_VALIDATE_IP)) {
                $valid[] = $entry;
            }
        }

        return implode("\n", array_values(array_unique($valid)));
    }
}

if (!function_exists('amzldIpMatchesCidr')) {
    function amzldIpMatchesCidr(string $ip, string $cidr): bool
    {
        [$range, $mask] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipBin = @inet_pton($ip);
        $rangeBin = @inet_pton($range);
        $mask = filter_var($mask, FILTER_VALIDATE_INT);

        if ($ipBin === false || $rangeBin === false || strlen($ipBin) !== strlen($rangeBin) || $mask === false) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        if ($mask < 0 || $mask > $bits) {
            return false;
        }

        $fullBytes = intdiv($mask, 8);
        $remainingBits = $mask % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($rangeBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $maskByte = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $maskByte) === (ord($rangeBin[$fullBytes]) & $maskByte);
    }
}

if (!function_exists('amzldIpMatchesRange')) {
    function amzldIpMatchesRange(string $ip, string $range): bool
    {
        [$start, $end] = array_pad(explode('-', $range, 2), 2, '');
        
        // IPv4 range matching
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            
            if ($ipLong !== false && $startLong !== false && $endLong !== false) {
                return $ipLong >= min($startLong, $endLong) && $ipLong <= max($startLong, $endLong);
            }
        }
        
        // IPv6 range matching (binary comparison)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = @inet_pton($ip);
            $startBin = @inet_pton($start);
            $endBin = @inet_pton($end);
            
            if ($ipBin !== false && $startBin !== false && $endBin !== false) {
                return strcmp($ipBin, $startBin) >= 0 && strcmp($ipBin, $endBin) <= 0;
            }
        }
        
        return false;
    }
}

if (!function_exists('amzldIsWhitelistedIp')) {
    function amzldIsWhitelistedIp(?string $ip = null, ?array $settings = null): bool
    {
        $ip = $ip ?: amzldClientIp();
        $settings = $settings ?: amzldLoadSettings();
        $entries = preg_split('/[\s,;]+/', (string) ($settings['whitelist_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($entries ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            // 1. IP Range matching
            if (strpos($entry, '-') !== false && amzldIpMatchesRange($ip, $entry)) {
                return true;
            }

            // 2. Wildcard matching
            if (strpos($entry, '*') !== false) {
                $pattern = str_replace(['.', '*'], ['\.', '\d+'], $entry);
                if (preg_match('/^' . $pattern . '$/', $ip)) {
                    return true;
                }
                continue;
            }

            // 3. CIDR matching
            if (strpos($entry, '/') !== false && amzldIpMatchesCidr($ip, $entry)) {
                return true;
            }

            // 4. Single IP matching
            if ($entry === $ip) {
                return true;
            }
        }

        return false;
    }
}
