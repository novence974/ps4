package com.upsl.admin;

import android.os.Build;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * Encrypts the API token before it is written to the app's private storage, with an AES-GCM key that lives in
 * the Android Keystore (Android 6+; the key never leaves the device's secure hardware/keystore).
 * Android 5.x has no app-usable keystore AES keys: there the token is stored in private storage only.
 */
final class SecureStore {
    private static final String ALIAS = "upsl_admin_token_key";
    private static final String KEYSTORE = "AndroidKeyStore";

    private SecureStore() {
    }

    static String seal(String plain) {
        if (plain == null || plain.length() == 0) {
            return plain;
        }
        if (Build.VERSION.SDK_INT >= 23) {
            try {
                Cipher c = Cipher.getInstance("AES/GCM/NoPadding");
                c.init(Cipher.ENCRYPT_MODE, key());
                byte[] ct = c.doFinal(plain.getBytes("UTF-8"));
                return "k1:" + Base64.encodeToString(c.getIV(), Base64.NO_WRAP) + ":" + Base64.encodeToString(ct, Base64.NO_WRAP);
            } catch (Exception e) {
                android.util.Log.w("SecureStore", "keystore unavailable, storing in private storage only: " + e);
            }
        }
        return "p:" + plain;
    }

    /** Returns null when the value cannot be decrypted (e.g. restored on another device): the admin signs in again. */
    static String open(String stored) {
        if (stored == null || stored.length() == 0) {
            return stored;
        }
        if (stored.startsWith("p:")) {
            return stored.substring(2);
        }
        if (!stored.startsWith("k1:")) {
            return stored;
        }
        try {
            String[] parts = stored.split(":");
            Cipher c = Cipher.getInstance("AES/GCM/NoPadding");
            c.init(Cipher.DECRYPT_MODE, key(), new GCMParameterSpec(128, Base64.decode(parts[1], Base64.NO_WRAP)));
            return new String(c.doFinal(Base64.decode(parts[2], Base64.NO_WRAP)), "UTF-8");
        } catch (Exception e) {
            return null;
        }
    }

    private static SecretKey key() throws Exception {
        KeyStore ks = KeyStore.getInstance(KEYSTORE);
        ks.load(null);
        if (ks.containsAlias(ALIAS)) {
            return ((KeyStore.SecretKeyEntry) ks.getEntry(ALIAS, null)).getSecretKey();
        }
        KeyGenerator g = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE);
        g.init(new KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build());
        return g.generateKey();
    }
}
