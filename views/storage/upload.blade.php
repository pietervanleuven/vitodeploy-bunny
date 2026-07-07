RESPONSE_FILE=$(mktemp)

HTTP_CODE=$(curl -sS -o "$RESPONSE_FILE" -w "%{http_code}" -T "{{ $src }}" -H "AccessKey: {{ $accessKey }}" "https://{{ $endpoint }}/{{ $zone }}/{{ $dest }}")

cat "$RESPONSE_FILE"
echo ""
rm -f "$RESPONSE_FILE"

if [ "$HTTP_CODE" = "201" ]; then
    echo "Upload successful"
else
    echo "Upload failed with HTTP code $HTTP_CODE"
    exit 1
fi
