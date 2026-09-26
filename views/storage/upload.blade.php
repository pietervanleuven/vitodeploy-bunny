{{-- Every value is a single shell argument via escapeshellarg(); {{ }} would HTML-encode the quoting. --}}
RESPONSE_FILE=$(mktemp)

HTTP_CODE=$(curl -sS -o "$RESPONSE_FILE" -w "%{http_code}" -T {!! escapeshellarg($src) !!} -H {!! escapeshellarg('AccessKey: '.$accessKey) !!} {!! escapeshellarg($url) !!} || true)

cat "$RESPONSE_FILE"
echo ""
rm -f "$RESPONSE_FILE"

if [ "$HTTP_CODE" = "201" ]; then
    echo "Upload successful"
else
    echo "Upload failed with HTTP code $HTTP_CODE"
    exit 1
fi
