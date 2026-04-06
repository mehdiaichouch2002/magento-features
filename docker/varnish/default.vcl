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

    # ── Pass if any session/auth cookie is present ────────────────────────────
    if (req.http.Cookie) {
        set req.http.Cookie = ";" + req.http.Cookie;
        set req.http.Cookie = regsuball(req.http.Cookie, "; +", ";");
        set req.http.Cookie = regsuball(req.http.Cookie,
            ";(PHPSESSID|private_content_version|form_key|mage-cache-sessid|mage-cache-storage|mage-cache-storage-section-invalidation|MAGE_CACHE_SESSID|section_data_clean|mage-messages|recently_viewed_product|recently_compared_product|product_data_storage)=",
            "; \\1=");
        set req.http.Cookie = regsuball(req.http.Cookie, ";[^ ][^;]*", "");
        set req.http.Cookie = regsuball(req.http.Cookie, "^[; ]+|[; ]+$", "");

        if (req.http.Cookie == "") {
            unset req.http.Cookie;
        } else {
            return (pass);
        }
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
    return (lookup);
}

# ─────────────────────────────────────────────────────────────────────────────
# vcl_backend_response – store and tag objects
# ─────────────────────────────────────────────────────────────────────────────
sub vcl_backend_response {
    # Strip s-maxage from Cache-Control (let Varnish decide TTL)
    set beresp.http.Cache-Control = regsub(beresp.http.Cache-Control, "s-maxage=[0-9]+", "");

    # Mark as uncacheable if backend says so
    if (beresp.http.Cache-Control ~ "private" || beresp.http.Cache-Control ~ "no-cache") {
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }

    # Remove cookies from cached responses
    unset beresp.http.Set-Cookie;

    # Default TTL if backend doesn't specify
    if (beresp.ttl <= 0s) {
        set beresp.ttl = 120s;
        set beresp.uncacheable = true;
    }

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
