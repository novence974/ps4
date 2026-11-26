package com.upsl.admin;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.HashSet;
import java.util.Set;

/** Server address, API token and the signed-in admin (with permissions), kept in private preferences. */
public final class Session {
    private static Session instance;

    private final SharedPreferences prefs;
    private String server;
    private String token;
    private JSONObject user;
    private final Set<String> permissions = new HashSet<String>();

    private Session(Context ctx) {
        prefs = ctx.getApplicationContext().getSharedPreferences("upsl_admin", Context.MODE_PRIVATE);
        server = prefs.getString("server", "");
        String sealed = SecureStore.open(prefs.getString("token", ""));
        token = sealed == null ? "" : sealed;
        user = Json.parse(prefs.getString("user", null));
        loadPermissions();
    }

    public static synchronized Session get(Context ctx) {
        if (instance == null) {
            instance = new Session(ctx);
        }
        return instance;
    }

    private void loadPermissions() {
        permissions.clear();
        if (user != null) {
            JSONArray a = user.optJSONArray("permissions");
            if (a != null) {
                for (int i = 0; i < a.length(); i++) {
                    permissions.add(a.optString(i));
                }
            }
        }
    }

    public String server() {
        return server;
    }

    public String token() {
        return token;
    }

    public JSONObject user() {
        return user;
    }

    public boolean isLoggedIn() {
        return token != null && token.length() > 0 && server != null && server.length() > 0;
    }

    /** Normalises what the admin typed: adds https://, removes a trailing slash or /api/v1. */
    public static String normalizeServer(String raw) {
        String s = raw == null ? "" : raw.trim();
        if (s.length() == 0) {
            return s;
        }
        if (!s.startsWith("http://") && !s.startsWith("https://")) {
            s = "https://" + s;
        }
        while (s.endsWith("/")) {
            s = s.substring(0, s.length() - 1);
        }
        if (s.endsWith("/api/v1")) {
            s = s.substring(0, s.length() - "/api/v1".length());
        }
        return s;
    }

    public void setServer(String s) {
        server = normalizeServer(s);
        prefs.edit().putString("server", server).apply();
    }

    public void signIn(String newToken, JSONObject newUser) {
        token = newToken;
        user = newUser;
        loadPermissions();
        prefs.edit().putString("token", SecureStore.seal(token)).putString("user", user == null ? null : user.toString()).apply();
    }

    public void updateUser(JSONObject newUser) {
        user = newUser;
        loadPermissions();
        prefs.edit().putString("user", user == null ? null : user.toString()).apply();
    }

    public void signOut() {
        token = "";
        user = null;
        permissions.clear();
        prefs.edit().remove("token").remove("user").apply();
    }

    public boolean isSuper() {
        return permissions.contains("*") || "super_admin".equals(roleSlug());
    }

    public String roleSlug() {
        JSONObject role = user == null ? null : user.optJSONObject("role");
        return role == null ? "" : role.optString("slug", "");
    }

    public String roleName() {
        JSONObject role = user == null ? null : user.optJSONObject("role");
        return role == null ? "" : Json.str(role, "name", "");
    }

    public int roleLevel() {
        JSONObject role = user == null ? null : user.optJSONObject("role");
        return role == null ? 0 : Json.num(role, "level", 0);
    }

    public int userId() {
        return user == null ? 0 : user.optInt("id", 0);
    }

    public String displayName() {
        return user == null ? "" : Json.str(user, "display_name", Json.str(user, "username", ""));
    }

    /** True if the admin has at least one of the given permissions (the Super Admin has all of them). */
    public boolean can(String... perms) {
        if (permissions.contains("*")) {
            return true;
        }
        for (String p : perms) {
            if (permissions.contains(p)) {
                return true;
            }
        }
        return false;
    }

    public SharedPreferences prefs() {
        return prefs;
    }
}
