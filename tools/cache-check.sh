#!/usr/bin/env bash
#
# ReallyCache quality probe.
# Samples a live URL and reports whether the cache behaves like a
# high-quality cache should: hits after the first visit, big TTFB wins,
# correct bypasses for logged-in/cart visitors, gzip and 304 handling.
#
#   ./cache-check.sh https://example.co.il/
#   ./cache-check.sh https://example.co.il/ 20     (20 timing samples)
#
set -euo pipefail

URL="${1:-}"
SAMPLES="${2:-10}"
UA="Mozilla/5.0 (Macintosh) ReallyCache-Check/1.0"

if [ -z "$URL" ]; then
	echo "Usage: $0 <url> [samples]"
	exit 1
fi

hdr() { printf '\n\033[1;35m== %s ==\033[0m\n' "$1"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$1"; }
inf() { printf '    %s\n' "$1"; }

fetch_headers() { # extra curl args in "$@"
	curl -sk -o /dev/null -D - -A "$UA" -H 'Accept-Encoding: gzip' "$@" "$URL"
}

ttfb() { # prints seconds; extra curl args in "$@"
	curl -sk -o /dev/null -A "$UA" -H 'Accept-Encoding: gzip' -w '%{time_starttransfer}' "$@" "$URL"
}

cache_header() { # extract X-ReallyCache value from a header dump on stdin
	awk 'BEGIN{IGNORECASE=1} /^x-reallycache:/{gsub(/\r/,"");print $2}' | tail -1
}

median() { sort -n | awk '{a[NR]=$1} END{print (NR%2) ? a[(NR+1)/2] : (a[NR/2]+a[NR/2+1])/2}'; }
avg()    { awk '{s+=$1} END{printf "%.4f", s/NR}'; }
ms()     { awk -v s="$1" 'BEGIN{printf "%.0f", s*1000}'; }

hdr "ReallyCache quality probe: $URL"

# --- 1. Warm-up: MISS then HIT -------------------------------------------
H1=$(fetch_headers | cache_header || true)
H2=$(fetch_headers | cache_header || true)

hdr "1. Cache hit behavior"
if [ "$H2" = "HIT" ]; then
	ok "Second request is a HIT (first was: ${H1:-no header})"
else
	bad "Second request is not a HIT (got: ${H2:-no header})"
	inf "Is ReallyCache active on this site? Is the URL cacheable?"
fi

# --- 2. TTFB: cached vs uncached -----------------------------------------
hdr "2. Speed: time-to-first-byte over $SAMPLES samples"
HIT_TIMES=""
for _ in $(seq "$SAMPLES"); do
	HIT_TIMES="$HIT_TIMES$(ttfb)
"
done
# A cart cookie forces a bypass, so this measures the uncached path.
MISS_TIMES=""
for _ in $(seq 3); do
	MISS_TIMES="$MISS_TIMES$(ttfb -H 'Cookie: woocommerce_items_in_cart=1')
"
done

HIT_MED=$(printf '%s' "$HIT_TIMES" | median)
HIT_AVG=$(printf '%s' "$HIT_TIMES" | avg)
MISS_MED=$(printf '%s' "$MISS_TIMES" | median)

inf "Cached      TTFB: median $(ms "$HIT_MED")ms, avg $(ms "$HIT_AVG")ms"
inf "Uncached    TTFB: median $(ms "$MISS_MED")ms (bypass via cart cookie)"
SPEEDUP=$(awk -v a="$MISS_MED" -v b="$HIT_MED" 'BEGIN{ if (b>0) printf "%.1f", a/b; else print "?" }')
inf "Speedup:    x$SPEEDUP"
awk -v b="$HIT_MED" 'BEGIN{ exit !(b < 0.2) }' && ok "Cached TTFB under 200ms" || bad "Cached TTFB above 200ms — check server/network"

# --- 3. Correctness: personalized visitors must bypass -------------------
hdr "3. Correctness: bypass rules"
LOGGED=$(fetch_headers -H 'Cookie: wordpress_logged_in_abc=1' | cache_header || true)
[ "$LOGGED" = "HIT" ] && bad "Logged-in cookie was served from cache — personalization leak!" || ok "Logged-in visitors bypass the cache"

CART=$(fetch_headers -H 'Cookie: woocommerce_items_in_cart=1' | cache_header || true)
[ "$CART" = "HIT" ] && bad "Cart cookie was served from cache — stale cart risk!" || ok "Visitors with a cart bypass the cache"

NONCE=$(fetch_headers -G --data-urlencode '_wpnonce=abc' | cache_header || true)
[ "$NONCE" = "HIT" ] && bad "Nonce request served from cache!" || ok "Requests with a nonce bypass the cache"

TRACK=$(fetch_headers -G --data-urlencode 'utm_source=probe' --data-urlencode 'utm_medium=cpc' | cache_header || true)
[ "$TRACK" = "HIT" ] && ok "Tracking params (utm_*) still get cache HITs" || inf "utm request was ${TRACK:-not a HIT} — campaign traffic misses the cache"

# --- 4. Transport quality -------------------------------------------------
hdr "4. Transport"
ENC=$(fetch_headers | awk 'BEGIN{IGNORECASE=1} /^content-encoding:/{gsub(/\r/,"");print $2}' | tail -1)
[ "$ENC" = "gzip" ] || [ "$ENC" = "br" ] && ok "Compressed response ($ENC)" || bad "Response is not compressed"

LM=$(fetch_headers | awk 'BEGIN{IGNORECASE=1} /^last-modified:/{sub(/^[^:]*: /,"");gsub(/\r/,"");print}' | tail -1)
if [ -n "$LM" ]; then
	CODE=$(curl -sk -o /dev/null -w '%{http_code}' -A "$UA" -H "If-Modified-Since: $LM" "$URL")
	[ "$CODE" = "304" ] && ok "Conditional revalidation works (304)" || inf "If-Modified-Since returned $CODE"
else
	inf "No Last-Modified header on this response"
fi

hdr "Done"
