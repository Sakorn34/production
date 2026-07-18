<?php
/**
 * อ่านค่า `$_SESSION['profile']` แบบเดียวกับระบบ maintenance
 *
 * รองรับ object (stdClass จาก json_decode), array และ string
 * ใช้ร่วมกับ auth_guard หลัง session_start แล้ว
 */

// ─────────────────────────────────────────────────────────────────
// helpers (รูปแบบ maintenance)
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_new_session_profile')) {
    /**
     * คืนค่า profile จาก session ถ้ามีและเป็นชนิดที่อ่านได้
     *
     * @return object|array|string|null
     */
    function maintenance_new_session_profile()
    {
        if (!isset($_SESSION['profile'])) {
            return null;
        }
        return $_SESSION['profile'];
    }
}

if (!function_exists('maintenance_new_profile_login_name')) {
    /**
     * ดึง login_name จาก profile
     *
     * @return string|null
     */
    function maintenance_new_profile_login_name()
    {
        $p = maintenance_new_session_profile();
        if ($p === null) {
            return null;
        }
        if (is_object($p) && isset($p->login_name)) {
            return (string) $p->login_name;
        }
        if (is_array($p) && isset($p['login_name'])) {
            return (string) $p['login_name'];
        }
        return null;
    }
}

if (!function_exists('maintenance_new_profile_display_name')) {
    /**
     * ชื่อแสดงจาก profile ตามคีย์ที่พบบ่อยใน SSO
     *
     * @return string
     */
    function maintenance_new_profile_display_name()
    {
        $p = maintenance_new_session_profile();
        if ($p === null) {
            return '';
        }
        $keys = array('display_name', 'name', 'full_name', 'emp_name', 'nickname', 'login_name');
        foreach ($keys as $k) {
            if (is_object($p) && isset($p->{$k})) {
                $v = trim((string) $p->{$k});
                if ($v !== '') {
                    return $v;
                }
            }
            if (is_array($p) && isset($p[$k])) {
                $v = trim((string) $p[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        if (is_string($p)) {
            $plain = trim($p);
            if ($plain !== '' && $plain[0] !== '{' && $plain[0] !== '[') {
                return $plain;
            }
            if ($plain !== '' && ($plain[0] === '{' || $plain[0] === '[')) {
                $decoded = json_decode($plain, true);
                if (is_array($decoded)) {
                    foreach ($keys as $k) {
                        if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                            $v = trim($decoded[$k]);
                            if ($v !== '') {
                                return $v;
                            }
                        }
                    }
                }
            }
        }

        return '';
    }
}

// ─────────────────────────────────────────────────────────────────
// SaleSystem wrappers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('ss_session_has_profile')) {
    /**
     * ตรวจว่ามี profile ใน session (เกณฑ์เดียวกับ maintenance auth_guard)
     *
     * @return bool
     */
    function ss_session_has_profile()
    {
        if (!isset($_SESSION['profile']) || $_SESSION['profile'] === null || $_SESSION['profile'] === '') {
            return false;
        }
        if (is_object($_SESSION['profile']) || is_array($_SESSION['profile'])) {
            return true;
        }
        return trim((string) $_SESSION['profile']) !== '';
    }
}

if (!function_exists('ss_profile_employee_name')) {
    /**
     * ดึงชื่อพนักงานจาก profile/session สำหรับ audit log
     *
     * @return string|null
     */
    function ss_profile_employee_name()
    {
        if (function_exists('ss_auth_bootstrap')) {
            ss_auth_bootstrap();
        } elseif (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $keys = array('emp_name', 'Emp_Name', 'EMP_NAME', 'username', 'user_name', 'name', 'display_name');
        foreach ($keys as $key) {
            if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
                $value = trim($_SESSION[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $display = maintenance_new_profile_display_name();
        if ($display !== '') {
            return $display;
        }

        $login = maintenance_new_profile_login_name();
        if ($login !== null && $login !== '') {
            return $login;
        }

        return null;
    }
}

if (!function_exists('ss_sync_session_employee_from_profile')) {
    /**
     * คัดลอกชื่อพนักงานจาก profile ไป `$_SESSION['emp_name']` ถ้ายังไม่มี
     *
     * @return void
     */
    function ss_sync_session_employee_from_profile()
    {
        if (!empty($_SESSION['emp_name']) && is_string($_SESSION['emp_name'])) {
            return;
        }
        $name = ss_profile_employee_name();
        if ($name !== null && $name !== '') {
            $_SESSION['emp_name'] = $name;
        }
    }
}
