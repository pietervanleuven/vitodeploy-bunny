{{-- Every value is a single shell argument via escapeshellarg(); {{ }} would HTML-encode the quoting. --}}
{{-- --fail: non-2xx is an error, but the status is still written by -w so a 404 (already gone) can be accepted below. --}}
HTTP_CODE=$(curl --fail -sS --connect-timeout 30 --retry 3 --retry-delay 5 -o /dev/null -w "%{http_code}" -X DELETE -H {!! escapeshellarg('AccessKey: '.$accessKey) !!} {!! escapeshellarg($url) !!} || true)

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
    echo "Delete successful"
else
    echo "Delete failed with HTTP code $HTTP_CODE"
    exit 1
fi
