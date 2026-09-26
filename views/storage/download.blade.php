{{-- Every value is a single shell argument via escapeshellarg(); {{ }} would HTML-encode the quoting. --}}
{{-- --fail: non-2xx is an error and no error body is written to the destination. --retry covers transient network errors. --speed-*: abort a stalled transfer instead of hanging. --}}
HTTP_CODE=$(curl --fail -sS --connect-timeout 30 --retry 3 --retry-delay 5 --speed-limit 1024 --speed-time 120 -o {!! escapeshellarg($dest) !!} -w "%{http_code}" -H {!! escapeshellarg('AccessKey: '.$accessKey) !!} {!! escapeshellarg($url) !!} || true)

if [ "$HTTP_CODE" = "200" ]; then
    echo "Download successful"
else
    rm -f {!! escapeshellarg($dest) !!}
    echo "Download failed with HTTP code $HTTP_CODE"
    exit 1
fi
