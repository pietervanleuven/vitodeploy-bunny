HTTP_CODE=$(curl -sS -o /dev/null -w "%{http_code}" -X DELETE -H "AccessKey: {{ $accessKey }}" "https://{{ $endpoint }}/{{ $zone }}/{{ $src }}")

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
    echo "Delete successful"
else
    echo "Delete failed with HTTP code $HTTP_CODE"
    exit 1
fi
