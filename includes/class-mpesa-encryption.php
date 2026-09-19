<?php
/**
 * M-Pesa Credential Encryption
 *
 * Provides authenticated encryption (AES-256-GCM) for sensitive API
 * credentials, keyed off WordPress's own AUTH_KEY/AUTH_SALT.
 *
 * ============================================================================
 * REWRITTEN 2026-08-25 -- read this before touching is_encrypted()/decrypt()
 * ============================================================================
 *
 * The previous version used AES-256-CBC and guessed whether a stored value
 * was "already encrypted" purely from its shape (valid base64, long enough
 * to contain an IV). That heuristic had a real, confirmed false-positive:
 * a plaintext Daraja consumer_secret/passkey is itself often a long
 * hex/alphanumeric string, which can easily satisfy "valid base64, long
 * enough" by pure coincidence. When that happened, `process_admin_options()`
 * in class-wc-mpesa-till-gateway.php wrongly concluded the freshly-typed
 * plaintext was "already encrypted" and skipped encrypting it -- silently
 * storing the raw credential. Later, decrypt() would see that same
 * base64-shaped plaintext, assume it was ciphertext, and fail to decrypt
 * it -- which surfaced as a misleading "encryption key may have changed"
 * admin notice, even though the real cause was that nothing had ever been
 * encrypted in the first place. Confirmed directly against a real stored
 * value during triage: a 64-character hex passkey passed strict
 * base64_decode() and decoded to 48 bytes, well past the old check's
 * 16-byte threshold.
 *
 * Two changes fix this at the root instead of patching around it:
 *
 *   1. Every value this class encrypts now carries an explicit, fixed
 *      prefix (self::PREFIX) that a plaintext credential will essentially
 *      never contain by coincidence. is_encrypted() checks for that exact
 *      prefix -- deterministic, not a guess.
 *   2. Encryption moved from AES-256-CBC to AES-256-GCM, an *authenticated*
 *      cipher: decryption doesn't just produce bytes, it cryptographically
 *      verifies they haven't been tampered with and were encrypted under
 *      the same key. A wrong key or corrupted data makes openssl_decrypt()
 *      fail outright (tag verification failure) rather than silently
 *      returning garbage that looks superficially plausible. Combined with
 *      (1), there is no longer any ambiguity about whether decryption
 *      should have worked.
 *
 * No effort was made to keep this compatible with data encrypted by the
 * previous CBC-based version (explicit product decision -- see
 * PROGRESS.md's 2026-08-25 entry for why). Any credential encrypted under
 * the old scheme will no longer decrypt correctly. A one-time reset routine
 * in the main plugin file (since removed -- every known install had already
 * been through it) handled that transition explicitly and safely: rather than risk
 * silently double-encrypting old ciphertext (which would corrupt it and
 * could send garbled credentials to Safaricom's live API without any
 * visible error), it deliberately clears the encrypted credential fields
 * once, on upgrade, and surfaces a clear admin notice asking the merchant
 * to re-enter them. Failing loud and requiring a re-enter is the safe
 * choice for a payment gateway; failing silent with corrupted credentials
 * is not.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Encryption {

    /**
     * Encryption method. GCM is an AEAD (authenticated encryption with
     * associated data) cipher -- it detects tampering and wrong-key
     * decryption attempts cryptographically, unlike plain CBC.
     */
    const METHOD = 'aes-256-gcm';

    /**
     * GCM auth tag length in bytes. 16 (128-bit) is the standard/maximum
     * and what OpenSSL defaults to.
     */
    const TAG_LENGTH = 16;

    /**
     * Fixed prefix marking a value as "encrypted by this class, this
     * format." Deliberately not something a real credential would ever
     * start with. is_encrypted() checks for this exact string -- nothing
     * fuzzier. Bumping this (e.g. to 'mpesa_enc_v2:') is how a future
     * format change would signal "don't treat v1 data as valid" without
     * re-introducing the ambiguity this rewrite removed.
     */
    // STORED-DATA FORMAT -- DO NOT RENAME. This marker is written at the start of
    // every saved credential; changing it makes all stored credentials
    // unrecognised. (The same applies to the HKDF label 'mpesa_till_credential_encryption'
    // and the fallback label 'mpesa_encryption_fallback' below: they are inputs to
    // the encryption key, not namespace prefixes.) The plugin-wide rename to
    // marupurupu_* deliberately left these alone.
    const PREFIX = 'mpesa_enc_v1:';

    /**
     * Whether the encryption key falls back to a value derivable by anyone
     * who knows the site's URL (i.e. AUTH_KEY/AUTH_SALT are unset or left
     * at their WordPress placeholder). Used both to pick the key and to
     * surface an admin warning -- see Marupurupu_Encryption_Admin.
     *
     * @return bool
     */
    public static function is_using_weak_key() {
        if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here') {
            return false;
        }

        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== 'put your unique phrase here') {
            return false;
        }

        return true;
    }

    /**
     * Derive the encryption key via HKDF (RFC 5869) rather than a bare
     * hash of a single WordPress constant. Combining AUTH_KEY (as the
     * input keying material) with AUTH_SALT (as the HKDF salt) and a
     * fixed, plugin-specific info string means this key is cryptographically
     * separated from anything else in WordPress core or other plugins that
     * might also derive keys from AUTH_KEY alone -- a compromise or reuse
     * elsewhere doesn't hand over this key "for free."
     *
     * @return string 32-byte raw key suitable for AES-256.
     */
    private static function get_key() {
        if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here') {
            $salt = (defined('AUTH_SALT') && AUTH_SALT !== 'put your unique phrase here') ? AUTH_SALT : '';
            return hash_hkdf('sha256', AUTH_KEY, 32, 'mpesa_till_credential_encryption', $salt);
        }

        // Fallback to SECURE_AUTH_KEY/SECURE_AUTH_SALT if AUTH_KEY isn't set.
        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== 'put your unique phrase here') {
            $salt = (defined('SECURE_AUTH_SALT') && SECURE_AUTH_SALT !== 'put your unique phrase here') ? SECURE_AUTH_SALT : '';
            return hash_hkdf('sha256', SECURE_AUTH_KEY, 32, 'mpesa_till_credential_encryption', $salt);
        }

        error_log('M-Pesa Encryption Warning: WordPress security keys not properly configured in wp-config.php');
        // Fallback so the plugin doesn't fatal -- is_using_weak_key() lets
        // the admin UI proactively warn about this instead of it being
        // silently discoverable only in error_log.
        return hash_hkdf('sha256', get_site_url() . 'mpesa_encryption_fallback', 32, 'mpesa_till_credential_encryption');
    }

    /**
     * Encrypt sensitive data.
     *
     * @param string $data Plaintext to encrypt.
     * @return string self::PREFIX + base64(iv + tag + ciphertext), or '' on empty input.
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            error_log('M-Pesa Encryption Error: OpenSSL extension not available. Refusing to store credential unencrypted.');
            update_option('marupurupu_encryption_degraded', true);
            // Unlike the previous version, do NOT fall back to storing the
            // plaintext -- silently persisting an unencrypted credential is
            // worse than a clear failure. Callers must treat '' as
            // "encryption unavailable, value not saved."
            return '';
        }

        try {
            $key = self::get_key();
            $iv_length = openssl_cipher_iv_length(self::METHOD);
            $iv = openssl_random_pseudo_bytes($iv_length);
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::TAG_LENGTH
            );

            if ($encrypted === false) {
                error_log('M-Pesa Encryption Error: Failed to encrypt data');
                update_option('marupurupu_encryption_degraded', true);
                return '';
            }

            delete_option('marupurupu_encryption_degraded');

            return self::PREFIX . base64_encode($iv . $tag . $encrypted);

        } catch (Exception $e) {
            error_log('M-Pesa Encryption Exception: ' . $e->getMessage());
            update_option('marupurupu_encryption_degraded', true);
            return '';
        }
    }

    /**
     * Decrypt sensitive data.
     *
     * Fails closed: if $data carries the encrypted-format prefix but
     * decryption/authentication fails (wrong key, corrupted/tampered
     * data), this returns '' rather than the still-encrypted bytes --
     * using ciphertext as if it were a real credential would send garbage
     * to Safaricom's API with no visible error. marupurupu_decryption_key_mismatch
     * is set so Marupurupu_Encryption_Admin can surface a clear notice instead.
     *
     * @param string $data Value as stored (may or may not be encrypted).
     * @return string Decrypted plaintext, the original value if it was
     *                never encrypted, or '' if decryption genuinely failed.
     */
    public static function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        if (!self::is_encrypted($data)) {
            // No prefix -- this was never encrypted by this class. Return
            // as-is rather than guessing.
            return $data;
        }

        if (!function_exists('openssl_decrypt')) {
            error_log('M-Pesa Encryption Error: OpenSSL extension not available. Cannot decrypt stored credential.');
            return '';
        }

        try {
            $key = self::get_key();
            $payload = substr($data, strlen(self::PREFIX));
            $decoded = base64_decode($payload, true);

            $iv_length = openssl_cipher_iv_length(self::METHOD);
            $min_length = $iv_length + self::TAG_LENGTH;

            if ($decoded === false || strlen($decoded) < $min_length) {
                error_log('M-Pesa Encryption Error: Encrypted value is malformed (wrong length/encoding).');
                update_option('marupurupu_decryption_key_mismatch', true);
                return '';
            }

            $iv = substr($decoded, 0, $iv_length);
            $tag = substr($decoded, $iv_length, self::TAG_LENGTH);
            $encrypted = substr($decoded, $iv_length + self::TAG_LENGTH);

            $decrypted = openssl_decrypt(
                $encrypted,
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                // GCM tag verification failed -- this is now a genuine
                // signal (wrong key or corrupted data), not a guess.
                error_log('M-Pesa Encryption Error: Failed to decrypt/authenticate stored credential. Encryption key may have changed, or the stored value was corrupted.');
                update_option('marupurupu_decryption_key_mismatch', true);
                return '';
            }

            delete_option('marupurupu_decryption_key_mismatch');

            return $decrypted;

        } catch (Exception $e) {
            error_log('M-Pesa Encryption Exception: ' . $e->getMessage());
            update_option('marupurupu_decryption_key_mismatch', true);
            return '';
        }
    }

    /**
     * Check if a value is in this class's encrypted format.
     *
     * Deliberately just a prefix check -- see the file-level doc comment
     * for why the previous shape-based heuristic was wrong.
     *
     * @param string $data Value to check.
     * @return bool
     */
    public static function is_encrypted($data) {
        return is_string($data) && strpos($data, self::PREFIX) === 0;
    }

    /**
     * Migrate existing unencrypted credentials to encrypted format.
     *
     * @return array Results of migration
     */
    public static function migrate_credentials() {
        $results = array(
            'success' => false,
            'message' => '',
            'migrated' => 0,
        );

        $gateway = WC()->payment_gateways()->payment_gateways()['mpesa_till'] ?? null;

        if (!$gateway) {
            $results['message'] = 'M-Pesa gateway not found';
            return $results;
        }

        $migrated_count = 0;
        $fields_to_encrypt = array('consumer_key', 'consumer_secret', 'test_consumer_key', 'test_consumer_secret', 'passkey');

        foreach ($fields_to_encrypt as $field) {
            $value = $gateway->get_option($field);

            if (empty($value)) {
                continue;
            }

            if (self::is_encrypted($value)) {
                continue;
            }

            $encrypted = self::encrypt($value);
            if ($encrypted === '') {
                continue; // encryption failed -- leave the field alone rather than wipe it
            }
            $gateway->update_option($field, $encrypted);
            $migrated_count++;
        }

        $results['success'] = true;
        $results['migrated'] = $migrated_count;
        $results['message'] = sprintf('Successfully migrated %d credential(s) to encrypted format', $migrated_count);

        return $results;
    }

    /**
     * Test encryption/decryption functionality.
     *
     * @return bool True if encryption is working correctly
     */
    public static function test_encryption() {
        $test_data = 'test_credential_12345';

        try {
            $encrypted = self::encrypt($test_data);
            $decrypted = self::decrypt($encrypted);

            return $decrypted === $test_data;
        } catch (Exception $e) {
            error_log('M-Pesa Encryption Test Failed: ' . $e->getMessage());
            return false;
        }
    }
}
