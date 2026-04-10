<?php
declare(strict_types=1);
/**
 * Encryption handler for sensitive data.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles encryption/decryption of sensitive plugin data using OpenSSL AES-256-GCM.
 * Uses WordPress security keys from wp-config.php.
 */
final class GPW_Encryption {
	/**
	 * Cipher algorithm.
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * IV length in bytes.
	 */
	private const IV_LENGTH = 12;

	/**
	 * Authentication tag length in bytes.
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Check if encryption is available/supported.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if (! extension_loaded('openssl')) {
			return false;
		}

		$ciphers = openssl_get_cipher_methods();
		return is_array($ciphers) && in_array(self::CIPHER, $ciphers, true);
	}

	/**
	 * Get a consistent encryption key from WordPress security constants.
	 *
	 * @return string 32-byte key for AES-256.
	 */
	private static function get_key(): string {
		// Use AUTH_KEY + SECURE_AUTH_KEY combined and hashed to 32 bytes.
		$key_material = (string) AUTH_KEY . (string) SECURE_AUTH_KEY;
		$key          = hash('sha256', $key_material, true);
		if (strlen($key) !== 32) {
			wp_die('Encryption key generation failed.');
		}
		return $key;
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plaintext The string to encrypt.
	 *
	 * @return string|WP_Error Base64-encoded ciphertext with IV and tag prepended, or WP_Error on failure.
	 */
	public static function encrypt(string $plaintext) {
		if ('' === $plaintext) {
			return '';
		}

		if (! self::is_available()) {
			return new WP_Error(
				'gpw_encryption_unavailable',
				__('OpenSSL encryption is not available on this server. PATs cannot be stored securely.', 'git-plugins-wordpress')
			);
		}

		$key = self::get_key();
		$iv  = openssl_random_pseudo_bytes(self::IV_LENGTH);

		if (false === $iv || strlen($iv) !== self::IV_LENGTH) {
			return new WP_Error(
				'gpw_encryption_iv_failed',
				__('Failed to generate a secure initialization vector. PAT was not saved.', 'git-plugins-wordpress')
			);
		}

		$tag       = '';
		$encrypted = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			''
		);

		if (false === $encrypted || false === $tag) {
			return new WP_Error(
				'gpw_encryption_failed',
				__('Encryption operation failed. PAT was not saved.', 'git-plugins-wordpress')
			);
		}

		// Prepend IV + tag to ciphertext, then base64 encode.
		$payload = $iv . $tag . $encrypted;
		return 'enc:' . base64_encode($payload);
	}

	/**
	 * Decrypt a string encrypted with encrypt().
	 *
	 * @param string $ciphertext The base64-encoded encrypted string with IV+tag prepended.
	 *
	 * @return string Decrypted plaintext, or empty string if decryption fails.
	 */
	public static function decrypt(string $ciphertext): string {
		if (! self::is_available()) {
			return $ciphertext;
		}

		if ('' === $ciphertext || ! str_starts_with($ciphertext, 'enc:')) {
			return $ciphertext;
		}

		$ciphertext = substr($ciphertext, 4);
		$payload    = base64_decode($ciphertext, true);

		if (false === $payload) {
			return '';
		}

		$payload_len = strlen($payload);
		if ($payload_len < self::IV_LENGTH + self::TAG_LENGTH) {
			return '';
		}

		$iv        = substr($payload, 0, self::IV_LENGTH);
		$tag       = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
		$encrypted = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

		$key        = self::get_key();
		$decrypted  = openssl_decrypt(
			$encrypted,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Check if a string is encrypted (has the enc: prefix).
	 *
	 * @param string $data The string to check.
	 *
	 * @return bool
	 */
	public static function is_encrypted(string $data): bool {
		return str_starts_with($data, 'enc:');
	}

	/**
	 * Option key for the encryption sentinel.
	 */
	private const SENTINEL_OPTION = 'gpw_encryption_sentinel';

	/**
	 * Known plaintext used for sentinel verification.
	 */
	private const SENTINEL_PLAINTEXT = 'gpw-sentinel-ok';

	/**
	 * Store an encrypted sentinel value so key rotation can be detected later.
	 * Should be called once after initial PAT save or plugin activation.
	 *
	 * @return void
	 */
	public static function store_sentinel(): void {
		if (! self::is_available()) {
			return;
		}

		$encrypted = self::encrypt(self::SENTINEL_PLAINTEXT);
		if (is_wp_error($encrypted)) {
			return;
		}

		update_option(self::SENTINEL_OPTION, $encrypted, false);
	}

	/**
	 * Verify that the current encryption key can still decrypt the sentinel.
	 * Returns true if encryption is healthy, false if keys have rotated.
	 * Returns null if no sentinel has been stored yet.
	 *
	 * @return bool|null
	 */
	public static function verify_sentinel(): ?bool {
		if (! self::is_available()) {
			return null;
		}

		$sentinel = get_option(self::SENTINEL_OPTION, '');
		if (! is_string($sentinel) || '' === $sentinel) {
			return null;
		}

		$decrypted = self::decrypt($sentinel);
		return self::SENTINEL_PLAINTEXT === $decrypted;
	}
}
