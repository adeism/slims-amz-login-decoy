<?php

defined('INDEX_AUTH') OR die('Direct access not allowed');

if (!function_exists('amzldSafeRequestUri')) {
    function amzldSafeRequestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        if (strpos($query, 'p=ld') !== false) {
            $path = parse_url($uri, PHP_URL_PATH) ?: 'index.php';
            return $path . '?p=ld&[token-redacted]';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($uri, 0, 255);
        }

        return substr($uri, 0, 255);
    }
}

if (!function_exists('amzldDenyBlockedIp')) {
    function amzldDenyBlockedIp(): void
    {
        if (amzldGetSetting('log_blocked_denials', '0') === '1') {
            amzldSystemLog('Blocked IP denied by AMZ Login Decoy runtime guard', 'Deny');
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access denied.';
        exit;
    }
}

if (!function_exists('amzldRedirectHome')) {
    function amzldRedirectHome(): void
    {
        header('Location: ' . amzldBaseUrl() . 'index.php', true, 302);
        exit;
    }
}

if (!function_exists('amzldCanAccessStaffLogin')) {
    function amzldCanAccessStaffLogin(?array $settings = null): bool
    {
        $settings = $settings ?: amzldLoadSettings();

        if (empty($_SESSION['amzld_can_access_staff_login']) || $_SESSION['amzld_can_access_staff_login'] !== true) {
            return false;
        }

        $authorizedAt = (int) ($_SESSION['amzld_authorized_at'] ?? 0);
        $ttl = max(1, (int) ($settings['session_ttl_minutes'] ?? 30)) * 60;

        if ($authorizedAt < 1 || (time() - $authorizedAt) > $ttl) {
            unset($_SESSION['amzld_can_access_staff_login'], $_SESSION['amzld_authorized_at'], $_SESSION['amzld_client_fingerprint']);
            return false;
        }

        $currentFingerprint = sha1(amzldClientIp() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (empty($_SESSION['amzld_client_fingerprint']) || $_SESSION['amzld_client_fingerprint'] !== $currentFingerprint) {
            unset($_SESSION['amzld_can_access_staff_login'], $_SESSION['amzld_authorized_at'], $_SESSION['amzld_client_fingerprint']);
            return false;
        }

        return true;
    }
}

if (!function_exists('amzldAuthorizeStaffLogin')) {
    function amzldAuthorizeStaffLogin(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['amzld_can_access_staff_login'] = true;
        $_SESSION['amzld_authorized_at'] = time();
        $_SESSION['amzld_client_fingerprint'] = sha1(amzldClientIp() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}

if (!function_exists('amzldExtractSecretFromQuery')) {
    function amzldExtractSecretFromQuery(): string
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        foreach (explode('&', $query) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $parts = explode('=', $part, 2);
            $name = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : null;

            if ($name !== '' && $name !== 'p' && $value === null) {
                return $name;
            }
        }

        return '';
    }
}

if (!function_exists('amzldRenderHoneypot')) {
    function amzldRenderHoneypot(string $errorMessage = ''): void
    {
        global $sysconf, $opac, $path, $dbs;

        if (amzldIsAdminUri() && empty($_GET['p'])) {
            $_GET['p'] = 'login';
        }

        $path = $path ?: 'login';

        if (!empty($sysconf['template']['dir']) && !empty($sysconf['template']['theme'])) {
            $tinfoPath = SB . $sysconf['template']['dir'] . '/' . $sysconf['template']['theme'] . '/tinfo.inc.php';
            if (file_exists($tinfoPath)) {
                include_once $tinfoPath;
            }
        }

        if (!is_object($opac)) {
            global $localisation, $sanitizer;
            $langs = [];
            if (isset($localisation) && is_object($localisation)) {
                $langs = $localisation->getLanguages();
            } elseif (class_exists('\SLiMS\Localisation')) {
                $langs = \SLiMS\Localisation::getInstance()->getLanguages();
            }
            $opacVar = [
                'page_title' => ($sysconf['library_subname'] ?? '') . ' | ' . ($sysconf['library_name'] ?? 'SLiMS'),
                'info' => '',
                'total_pages' => 1,
                'header_info' => '',
                'metadata' => '',
                'js' => '',
                'searched_words_js_array' => '',
                'available_languages' => $langs,
                'sanitizer' => $sanitizer ?? null
            ];
            $opacClass = function_exists('config') ? config('custom_opac', \SLiMS\Opac::class) : \SLiMS\Opac::class;
            if (class_exists($opacClass)) {
                $opac = new $opacClass($opacVar, $sysconf, $dbs);
            }
        }

        if (is_object($opac)) {
            $available_languages = $opac->available_languages;
            $sanitizer = $opac->sanitizer;
            $page_title = $opac->page_title;
            $metadata = $opac->metadata;
            $js = $opac->js;
            $searched_words_js_array = $opac->searched_words_js_array;
            $info = $opac->info;
            $total_pages = $opac->total_pages;
            $header_info = $opac->header_info;
        }

        global $localisation, $sanitizer;

        $available_languages = $available_languages ?? (isset($localisation) && is_object($localisation) ? $localisation->getLanguages() : []);
        $sanitizer = $sanitizer ?? (isset($GLOBALS['sanitizer']) ? $GLOBALS['sanitizer'] : null);
        $metadata = $metadata ?? '';
        $notes = $notes ?? '';
        $subject = $subject ?? '';
        $image_src = $image_src ?? '';
        $imagesDisk = $imagesDisk ?? (\class_exists('\SLiMS\Filesystems\Storage') ? \SLiMS\Filesystems\Storage::images() : null);
        $colltype_list = $colltype_list ?? '';
        $location_list = $location_list ?? '';

        ob_start();
        ?>
        <div id="loginForm">
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" style="margin-bottom: 12px; padding: 10px; border-radius: 4px; background: #fee2e2; color: #991b1b; font-size: 13px; font-weight: bold; text-align: center;">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>
            
            <form action="index.php?p=<?= htmlspecialchars($path) ?>" method="post">
                <div class="heading1"><?= __('Username') ?></div>
                <div class="login_input"><input type="text" name="userName" id="userName" class="login_input" required /></div>
                <div class="heading1"><?= __('Password') ?></div>
                <div class="login_input"><input type="password" name="passWord" class="login_input" autocomplete="off" required /></div>
                
                <?php
                if (class_exists('\Volnix\CSRF\CSRF')) {
                    try {
                        echo \Volnix\CSRF\CSRF::getHiddenInputString();
                    } catch (\Throwable $e) {}
                }
                ?>
                
                <div class="marginTop">
                    <input type="submit" name="logMeIn" value="<?= __('Login') ?>" class="loginButton" />
                    <input type="button" value="Home" class="homeButton" onclick="javascript: location.href = 'index.php';" />
                </div>
            </form>
        </div>
        <script type="text/javascript">
            jQuery('#userName').focus();
        </script>
        <?php
        $main_content = ob_get_clean();

        $page_title = __('Library Automation Login') . ' | ' . $sysconf['library_name'];
        if (is_object($opac)) {
            $opac->page_title = $page_title;
        }

        if ($sysconf['template']['base'] == 'html') {
            require_once SIMBIO . 'simbio_GUI/template_parser/simbio_template_parser.inc.php';
            $template = new simbio_template_parser(SB . $sysconf['template']['dir'] . '/' . $sysconf['template']['theme'] . '/login_template.html');
            $template->assign('<!--PAGE_TITLE-->', $page_title);
            $template->assign('<!--CSS-->', $sysconf['template']['css']);
            $template->assign('<!--MAIN_CONTENT-->', $main_content);
            
            ob_start();
            $template->printOut();
            $rendered = ob_get_clean();
            
            if (amzldIsAdminUri()) {
                $baseTag = '<base href="' . amzldBaseUrl() . '">';
                $rendered = preg_replace('/<head>/i', '<head>' . $baseTag, $rendered, 1);
            }
            echo $rendered;
        } else if ($sysconf['template']['base'] == 'php') {
            $sysconf['page_title'] = $page_title;
            
            ob_start();
            require SB . $sysconf['template']['dir'] . '/' . $sysconf['template']['theme'] . '/login_template.inc.php';
            $rendered = ob_get_clean();
            
            if (amzldIsAdminUri()) {
                $baseTag = '<base href="' . amzldBaseUrl() . '">';
                $rendered = preg_replace('/<head>/i', '<head>' . $baseTag, $rendered, 1);
            }
            echo $rendered;
        }
        exit;
    }
}

if (!function_exists('amzldHandleOpacRequest')) {
    function amzldHandleOpacRequest(): void
    {
        $settings = amzldLoadSettings();
        $ip = amzldClientIp();

        if (amzldBoolSetting($settings, 'whitelist_bypass_enabled') && amzldIsWhitelistedIp($ip, $settings)) {
            return;
        }

        if (amzldIsIpBlocked($ip)) {
            amzldDenyBlockedIp();
        }

        $path = amzldInputString($_GET, 'p', 50);

        if ($path === 'ld') {
            return;
        }

        if ($path === 'login' || $path === 'loginstaf') {
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            if (strpos($referer, 'logout.php') !== false) {
                amzldRedirectHome();
            }

            if (amzldCanAccessStaffLogin($settings)) {
                return;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = amzldInputString($_POST, 'userName', 100);
                $passwordLen = isset($_POST['passWord']) && is_scalar($_POST['passWord']) ? strlen((string) $_POST['passWord']) : 0;
                amzldRecordAttempt('honeypot_submit', 'index.php?p=' . $path, $username, $passwordLen);

                if (amzldIsIpBlocked($ip)) {
                    amzldDenyBlockedIp();
                }

                if (amzldBoolSetting($settings, 'honeypot_enabled')) {
                    $delay = max(0, min(3, (int) ($settings['honeypot_delay_seconds'] ?? 3)));
                    if ($delay > 0) {
                        sleep($delay);
                    }
                    amzldRenderHoneypot('Wrong username or password.');
                }

                amzldRedirectHome();
            }

            if (amzldBoolSetting($settings, 'honeypot_enabled')) {
                if (amzldBoolSetting($settings, 'log_honeypot_views')) {
                    amzldRecordAttempt('honeypot_view', 'index.php?p=' . $path);
                }
                amzldRenderHoneypot();
            } else {
                amzldRecordAttempt('staff_login_without_secret', 'index.php?p=' . $path);
            }

            amzldRedirectHome();
        }
    }
}

if (!function_exists('amzldIsAdminUri')) {
    function amzldIsAdminUri(): bool
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return is_string($path) && preg_match('~/admin(?:/|$)~', $path) === 1;
    }
}

if (!function_exists('amzldHandleAdminRequest')) {
    function amzldHandleAdminRequest(): void
    {
        if (!amzldIsAdminUri()) {
            return;
        }

        $settings = amzldLoadSettings();
        $ip = amzldClientIp();

        if (amzldBoolSetting($settings, 'whitelist_bypass_enabled') && amzldIsWhitelistedIp($ip, $settings)) {
            return;
        }

        if (amzldIsIpBlocked($ip)) {
            amzldDenyBlockedIp();
        }

        if (empty($_SESSION['uid'])) {
            if (amzldCanAccessStaffLogin($settings)) {
                return;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = amzldInputString($_POST, 'userName', 100);
                $passwordLen = isset($_POST['passWord']) && is_scalar($_POST['passWord']) ? strlen((string) $_POST['passWord']) : 0;
                amzldRecordAttempt('honeypot_submit', '/admin', $username, $passwordLen);

                if (amzldIsIpBlocked($ip)) {
                    amzldDenyBlockedIp();
                }

                if (amzldBoolSetting($settings, 'honeypot_enabled')) {
                    $delay = max(0, min(3, (int) ($settings['honeypot_delay_seconds'] ?? 3)));
                    if ($delay > 0) {
                        sleep($delay);
                    }
                    amzldRenderHoneypot('Wrong username or password.');
                }
                amzldRedirectHome();
            }

            amzldRecordAttempt('admin_direct_without_session', '/admin');

            if (amzldBoolSetting($settings, 'honeypot_enabled')) {
                if (amzldBoolSetting($settings, 'log_honeypot_views')) {
                    amzldRecordAttempt('honeypot_view', '/admin');
                }
                amzldRenderHoneypot();
            }

            amzldRedirectHome();
        }
    }
}

if (!function_exists('amzldHandleSecretDoor')) {
    function amzldHandleSecretDoor(): void
    {
        $settings = amzldLoadSettings();
        $secret = (string) ($settings['secret_token'] ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }
        $token = amzldExtractSecretFromQuery();

        if (hash_equals($secret, $token)) {
            amzldAuthorizeStaffLogin();
            amzldSystemLog('Secret staff login door opened', 'Allow');
            header('Location: ' . amzldBaseUrl() . 'index.php?p=login', true, 302);
            exit;
        }

        if (!amzldIsWhitelistedIp(amzldClientIp(), $settings)) {
            amzldRecordAttempt('secret_door_invalid', 'index.php?p=ld');
        }

        amzldRedirectHome();
    }
}
