<?php
/**
 * Chatzio — API key encryption at rest
 * Uses AUTH_KEY from wp-config.php so the secret is not stored in the DB in plain text.
 * If AUTH_KEY is missing or encryption fails, the key is stored/returned as plain text (backward compatible).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_API_Key_Crypto {

    const PREFIX = 'scenc:';
    const CIPHER = 'aes-256-cbc';

    /**
     * Encrypt a plain-text API key for storage.
     *
     * @param string $plain_key
     * @return string Encrypted string with prefix, or original if encryption unavailable
     */
    public static function encrypt($plain_key) {
        if ($plain_key === '' || !is_string($plain_key)) {
            return $plain_key;
        }
        if (!function_exists('openssl_encrypt')) {
            return $plain_key;
        }
        $key = self::get_encryption_key();
        if ($key === false) {
            return $plain_key;
        }
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        if (!is_string($iv) || strlen($iv) !== 16) {
            return $plain_key;
        }
        $encrypted = openssl_encrypt($plain_key, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return $plain_key;
        }
        return self::PREFIX . base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt an API key from storage.
     *
     * @param string $stored
     * @return string Plain API key
     */
    public static function decrypt($stored) {
        if ($stored === '' || !is_string($stored)) {
            return $stored;
        }
        if (strpos($stored, self::PREFIX) !== 0) {
            return $stored; // Not encrypted (legacy or encryption unavailable)
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $key = self::get_encryption_key();
        if ($key === false) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 16) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Whether the stored value looks encrypted.
     *
     * @param string $stored
     * @return bool
     */
    public static function is_encrypted($stored) {
        return is_string($stored) && strpos($stored, self::PREFIX) === 0;
    }

    /**
     * Filter callback: decrypt openrouter_api_key when option is read.
     *
     * @param mixed $value Option value (array of settings)
     * @return mixed Same array with openrouter_api_key decrypted if present
     */
    public static function decrypt_api_key_in_settings($value) {
        if (!is_array($value) || !isset($value['openrouter_api_key'])) {
            return $value;
        }
        $value['openrouter_api_key'] = self::decrypt($value['openrouter_api_key']);
        return $value;
    }

    /**
     * @return string|false 32-byte key or false if unavailable
     */
    private static function get_encryption_key() {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            return false;
        }
        return hash('sha256', AUTH_KEY, true);
    }
}
