<?php
/**
 * Privacy-conscious audit logging for order verification events.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Audit_Logger {

    /**
     * Mask an email without exposing its local part.
     *
     * @param mixed $email Proposed email value.
     * @return string Masked value such as c***@example.com, or ***.
     */
    public static function mask_email($email) {
        if (!is_string($email)) {
            return '***';
        }

        $email = strtolower(trim($email));
        if (!preg_match('/\A([a-z0-9])[a-z0-9._%+\-]*@([a-z0-9.-]+\.[a-z]{2,})\z/iD', $email, $matches)) {
            return '***';
        }

        return strtolower($matches[1] . '***@' . $matches[2]);
    }

    /**
     * Recursively remove raw email addresses from audit context.
     *
     * @param mixed $context Untrusted audit context.
     * @return array Sanitized context suitable for persistent logs.
     */
    public static function sanitize_context($context) {
        if (!is_array($context)) {
            return array();
        }

        return self::sanitize_array($context, 0);
    }

    /**
     * Write an order-security audit event through the existing logger.
     *
     * @param string $event   Short event name; never include customer input.
     * @param array  $context Event metadata. Email values are always masked.
     * @param string $level   info, warning, or error.
     * @return bool Whether the logger accepted the event.
     */
    public static function log($event, $context = array(), $level = 'info') {
        if (!class_exists('Chatzio_Logger')) {
            return false;
        }

        $event = sanitize_text_field((string) $event);
        $event = substr($event, 0, 160);
        if ('' === $event) {
            return false;
        }

        $context = self::sanitize_context($context);
        $methods = array(
            'info'    => 'log_info',
            'warning' => 'log_warning',
            'error'   => 'log_error',
        );
        $method = isset($methods[$level]) ? $methods[$level] : $methods['info'];

        return (bool) call_user_func(array('Chatzio_Logger', $method), $event, $context);
    }

    /**
     * Convenience wrapper for informational events.
     *
     * @param string $event Event name.
     * @param array  $context Event context.
     * @return bool
     */
    public static function log_info($event, $context = array()) {
        return self::log($event, $context, 'info');
    }

    /**
     * Convenience wrapper for warning events.
     *
     * @param string $event Event name.
     * @param array  $context Event context.
     * @return bool
     */
    public static function log_warning($event, $context = array()) {
        return self::log($event, $context, 'warning');
    }

    /**
     * Sanitize one nested context array with a conservative depth limit.
     *
     * @param array $values Context values.
     * @param int   $depth  Current nesting depth.
     * @return array
     */
    private static function sanitize_array($values, $depth) {
        if ($depth > 3) {
            return array();
        }

        $sanitized = array();
        foreach ($values as $key => $value) {
            $safe_key = is_string($key) ? sanitize_key($key) : (int) $key;
            if ('' === $safe_key) {
                continue;
            }

            if (is_string($safe_key) && false !== strpos($safe_key, 'email')) {
                $sanitized[$safe_key] = self::mask_email($value);
                continue;
            }

            if (is_array($value)) {
                $sanitized[$safe_key] = self::sanitize_array($value, $depth + 1);
            } elseif (is_string($value)) {
                $sanitized[$safe_key] = self::mask_emails_in_text($value);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
                $sanitized[$safe_key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Mask email-like values even when embedded in a larger context string.
     *
     * @param string $value Context string.
     * @return string
     */
    private static function mask_emails_in_text($value) {
        $value = preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            static function ($matches) {
                return Chatzio_Order_Audit_Logger::mask_email($matches[0]);
            },
            $value
        );

        return substr(sanitize_text_field((string) $value), 0, 500);
    }
}
