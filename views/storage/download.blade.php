HTTP_CODE=$(curl -sS -o "{{ $dest }}" -w "%{http_code}" -H "AccessKey: {{ $accessKey }}" "https://{{ $endpoint }}/{{ $zone }}/{{ $src }}")

if [ "$HTTP_CODE" = "200" ]; then
    echo "Download successful"
else
    rm -f "{{ $dest }}"
    echo "Download failed with HTTP code $HTTP_CODE"
    exit 1
fi
