{{-- Every value is a single shell argument via escapeshellarg(); {{ }} would HTML-encode the quoting. --}}
{{-- --fail: non-2xx is an error (the status is still written by -w). --retry covers transient network errors. --speed-*: abort a stalled transfer instead of hanging. --}}
{{-- The response body is discarded: only the status matters and the raw response would end up in the server log. --}}
HTTP_CODE=$(curl --fail -sS --connect-timeout 30 --retry 3 --retry-delay 5 --speed-limit 1024 --speed-time 120 -o /dev/null -w "%{http_code}" -T {!! escapeshellarg($src) !!} -H {!! escapeshellarg('AccessKey: '.$accessKey) !!} {!! escapeshellarg($url) !!} || true)

if [ "$HTTP_CODE" = "201" ]; then
    echo "Upload successful"
else
    echo "Upload failed with HTTP code $HTTP_CODE"
    exit 1
fi
