vcl 4.1;

import std;

# ─── Backend: Nginx on port 8080 ─────────────────────────────────────────────
backend default {
    .host = "nginx";
    .port = "8080";
    .first_byte_timeout    = 600s;
    .connect_timeout       = 10s;
    .between_bytes_timeout = 600s;
}

# ─── ACL: hosts allowed to send PURGE/BAN requests ───────────────────────────
acl purge {
    "localhost";
    "127.0.0.0/8";
    "10.0.0.0/8";
    "172.16.0.0/12";
    "192.168.0.0/16";
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_recv – normalize and decide pass/pipe/hash
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_recv {

    # Health-check probe
    if (req.url == "/varnish_healthcheck") {
        return (synth(200, "OK"));
    }

    # ── PURGE ────────────────────────────────────────────────────────────────
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "PURGE not allowed from " + client.ip));
        }
        return (purge);
    }

    # ── BAN (tag-based invalidation used by Magento FPC) ─────────────────────
    if (req.method == "BAN") {
        if (!client.ip ~ purge) {
            return (synth(405, "BAN not allowed from " + client.ip));
        }
        if (req.http.X-Magento-Tags-Pattern) {
            ban("obj.http.X-Magento-Tags ~ " + req.http.X-Magento-Tags-Pattern);
        } else {
            ban("req.url ~ " + req.url);
        }
        return (synth(200, "Banned"));
    }

    # ── Only handle GET/HEAD; pass everything else ────────────────────────────
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # ── Strip marketing/tracking query params ────────────────────────────────
    set req.url = regsuball(req.url,
        "[?&](utm_source|utm_medium|utm_campaign|utm_term|utm_content|gclid|fbclid|_ga)=[^&]*",
        "");
    set req.url = regsub(req.url, "[?|&]$", "");

    # ── Magento admin / checkout / customer area: always pass ─────────────────
    if (req.url ~ "^/(admin|checkout|customer|rest|soap|graphql|index\.php/admin)") {
        return (pass);
    }

    # ── Pass if any Magento session cookie is present ────────────────────────
    # Forward the full, unmodified Cookie header to the backend so PHP can
    # read PHPSESSID, X-Magento-Vary, etc. without corruption.
    # Note: regsuball backreferences (\\1) are broken in Varnish 7.x —
    # they output the literal string "\1" instead of the captured group,
    # which corrupts every cookie name and breaks PHP session parsing.
    if (req.http.Cookie ~ "(PHPSESSID|private_content_version|X-Magento-Vary|form_key|mage-cache-sessid)=") {
        return (pass);
    }

    # No Magento session cookies — strip everything and serve from cache
    if (req.http.Cookie) {
        unset req.http.Cookie;
    }

    return (hash);
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_hash
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_hash {
    hash_data(req.url);
    hash_data(req.http.host);
    if (req.http.X-Forwarded-Proto) {
        hash_data(req.http.X-Forwarded-Proto);
    }
    # Include customer group variation so different groups get separate cache entries
    if (req.http.cookie ~ "X-Magento-Vary=") {
        hash_data(regsub(req.http.cookie, "^.*?X-Magento-Vary=([^;]+);*.*$", "\1"));
    }
    return (lookup);
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_backend_response – store and tag objects
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_backend_response {
    # Magento sends two Cache-Control headers:
    #   Cache-Control: no-cache (browser directive — tells browsers not to cache)
    #   X-Magento-Cache-Control: max-age=86400,public,s-maxage=86400 (Varnish directive)
    # Varnish already parsed beresp.ttl from the original Cache-Control (0s).
    # We use X-Magento-Cache-Control as authoritative and set beresp.ttl explicitly.
    if (beresp.http.X-Magento-Cache-Control ~ "max-age") {
        # Mark private responses as uncacheable
        if (beresp.http.X-Magento-Cache-Control ~ "private") {
            set beresp.uncacheable = true;
            set beresp.ttl = 120s;
            return (deliver);
        }
        # Set Varnish TTL from X-Magento-Cache-Control max-age
        set beresp.ttl = std.duration(
            regsub(beresp.http.X-Magento-Cache-Control, ".*max-age=([0-9]+).*", "\1") + "s",
            86400s);
        # Update Cache-Control header sent to browsers (strip s-maxage)
        set beresp.http.Cache-Control = regsub(beresp.http.X-Magento-Cache-Control,
            ",?\s*s-maxage=[0-9]+", "");
    } else {
        # No Magento cache hint — don't cache
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }

    # Don't cache non-200/404 responses
    if (beresp.status != 200 && beresp.status != 404) {
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }

    # Don't cache pages with TTL=0 (e.g. login, account pages)
    if (beresp.ttl <= 0s) {
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }

    # Remove Set-Cookie from responses that ARE being cached so session
    # cookies don't get stuck in cached objects and leak to other users
    unset beresp.http.Set-Cookie;

    return (deliver);
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_deliver – response cleanup
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_deliver {
    # Debug header — shows HIT/MISS and hit count
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
        set resp.http.X-Cache-Hits = obj.hits;
    } else {
        set resp.http.X-Cache = "MISS";
    }

    # Remove internal Varnish/Magento cache-tag headers from the public response
    unset resp.http.X-Magento-Tags;
    unset resp.http.X-Powered-By;
    unset resp.http.Server;
    unset resp.http.Via;

    return (deliver);
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_synth – synthetic responses (health, errors)
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_synth {
    if (resp.status == 200 && resp.reason == "OK") {
        set resp.http.Content-Type = "text/plain; charset=utf-8";
        synthetic("OK");
        return (deliver);
    }

    set resp.http.Content-Type = "text/html; charset=utf-8";
    synthetic({"<!DOCTYPE html><html><head><title>"} + resp.status + " " + resp.reason + {"</title></head>
<body><h1>"} + resp.status + " " + resp.reason + {"</h1></body></html>"});
    return (deliver);
}
