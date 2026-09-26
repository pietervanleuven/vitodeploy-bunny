{{-- Every value is a single shell argument via escapeshellarg(); {{ }} would HTML-encode the quoting. --}}
HTTP_CODE=$(curl -sS -o {!! escapeshellarg($dest) !!} -w "%{http_code}" -H {!! escapeshellarg('AccessKey: '.$accessKey) !!} {!! escapeshellarg($url) !!} || true)

if [ "$HTTP_CODE" = "200" ]; then
    echo "Download successful"
else
    rm -f {!! escapeshellarg($dest) !!}
    echo "Download failed with HTTP code $HTTP_CODE"
    exit 1
fi
