<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders;

use App\DNSProviders\AbstractDNSProvider;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyApi;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class Bunny extends AbstractDNSProvider
{
    /**
     * Bunny's maximum page size for zone listings.
     */
    private const int ZONES_PER_PAGE = 1000;

    /**
     * Safety cap so a misbehaving API can never keep us paging forever.
     */
    private const int MAX_ZONE_PAGES = 100;

    /**
     * Record types that can be managed from Vito. Bunny-specific types
     * (Redirect, PullZone, Script, ...) must be managed in the Bunny dashboard.
     *
     * @var array<int, string>
     */
    private const array SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'SRV', 'NS', 'CAA', 'PTR'];

    /**
     * RFC 1123 host name (underscores allowed for service labels), with an
     * optional trailing dot.
     */
    private const string HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.?$/i';

    /**
     * CAA record content: flags, tag and a quoted value.
     */
    private const string CAA_PATTERN = '/^\d{1,3}\s+(issue|issuewild|iodef)\s+"[^"]*"$/';

    /**
     * Bunny's API represents record types as integers.
     *
     * @var array<int, string>
     */
    private const array RECORD_TYPES = [
        0 => 'A',
        1 => 'AAAA',
        2 => 'CNAME',
        3 => 'TXT',
        4 => 'MX',
        5 => 'REDIRECT',
        6 => 'FLATTEN',
        7 => 'PULLZONE',
        8 => 'SRV',
        9 => 'CAA',
        10 => 'PTR',
        11 => 'SCRIPT',
        12 => 'NS',
        13 => 'SVCB',
        14 => 'HTTPS',
        15 => 'TLSA',
    ];

    public static function id(): string
    {
        return 'bunny';
    }

    public function validationRules(array $input): array
    {
        return [
            'api_key' => 'required|string',
        ];
    }

    public function credentialData(array $input): array
    {
        return [
            'api_key' => $input['api_key'],
        ];
    }

    public function editValidationRules(array $input): array
    {
        return [
            'api_key' => 'nullable|string',
        ];
    }

    public function mergeEditData(array $input): array
    {
        $credentials = $this->dnsProvider->credentials;
        $needsReconnect = false;

        if (! empty($input['api_key'])) {
            $credentials['api_key'] = $input['api_key'];
            $needsReconnect = true;
        }

        return [$credentials, $needsReconnect];
    }

    public function connect(array $credentials): bool
    {
        try {
            $response = app(BunnyApi::class)->client((string) ($credentials['api_key'] ?? ''))
                ->get('dnszone', ['page' => 1, 'perPage' => 5]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Bunny DNS connection failed', ['status' => $response->status()]);

            return false;
        } catch (Throwable $e) {
            Log::error('Bunny DNS connection exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getDomains(): array
    {
        $zones = [];
        $page = 1;

        try {
            do {
                $response = $this->getClient()->get('dnszone', [
                    'page' => $page,
                    'perPage' => self::ZONES_PER_PAGE,
                ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch Bunny DNS zones', ['page' => $page, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);
                    break;
                }

                $json = $response->json();
                $items = $this->listFrom($json, 'Items', 'zones');
                array_push($zones, ...$items);

                $hasMore = $items !== [] && is_array($json) && ($json['HasMoreItems'] ?? false) === true;
                $page++;
            } while ($hasMore && $page <= self::MAX_ZONE_PAGES);
        } catch (Throwable $e) {
            Log::error('Bunny DNS getDomains exception', ['page' => $page, 'error' => $e->getMessage()]);
        }

        return collect($zones)
            ->map(fn (mixed $zone): ?array => is_array($zone) ? $this->formatZone($zone) : null)
            ->filter()
            ->values()
            ->toArray();
    }

    public function getDomain(string $domainId): array
    {
        try {
            $response = $this->getClient()->get("dnszone/{$domainId}");

            if (! $response->successful()) {
                Log::error('Failed to fetch Bunny DNS zone', ['domainId' => $domainId, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);

                return [];
            }

            $zone = $response->json();
            $formatted = is_array($zone) ? $this->formatZone($zone) : null;

            if ($formatted === null) {
                Log::warning('Bunny DNS returned an unexpected zone payload', ['domainId' => $domainId]);

                return [];
            }

            return $formatted;
        } catch (Throwable $e) {
            Log::error('Bunny DNS getDomain exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @throws \RuntimeException when the records cannot be fetched
     */
    public function getRecords(string $domainId): array
    {
        try {
            $response = $this->getClient()->get("dnszone/{$domainId}");
        } catch (Throwable $e) {
            Log::error('Bunny DNS getRecords exception', ['domainId' => $domainId, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to fetch DNS records: could not reach the Bunny API', 0, $e);
        }

        if (! $response->successful()) {
            Log::error('Failed to fetch Bunny DNS records', ['domainId' => $domainId, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);
            throw new \RuntimeException('Failed to fetch DNS records: '.$this->errorMessage($response->json()));
        }

        $zone = $response->json();

        if (! is_array($zone) || ! isset($zone['Records']) || ! is_array($zone['Records'])) {
            Log::error('Bunny DNS returned an unexpected records payload', ['domainId' => $domainId]);
            throw new \RuntimeException('Failed to fetch DNS records: unexpected response from the Bunny API');
        }

        return collect($zone['Records'])
            ->map(fn (mixed $record): ?array => is_array($record) ? $this->formatRecord($record) : null)
            ->filter()
            ->values()
            ->toArray();
    }

    public function createRecord(string $domainId, array $recordData): array
    {
        try {
            $response = $this->getClient()->put("dnszone/{$domainId}/records", $this->buildPayload($domainId, $recordData));

            if (! $response->successful()) {
                Log::error('Failed to create Bunny DNS record', ['domainId' => $domainId, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);
                throw ValidationException::withMessages(['record' => 'Failed to create DNS record: '.$this->errorMessage($response->json())]);
            }

            $record = $response->json();
            $formatted = is_array($record) ? $this->formatRecord($record) : null;

            if ($formatted === null) {
                Log::error('Bunny DNS returned an unexpected record payload', ['domainId' => $domainId]);
                throw ValidationException::withMessages(['record' => 'Failed to create DNS record: unexpected response from the Bunny API']);
            }

            return [
                'id' => $formatted['id'],
                'type' => $recordData['type'],
                'name' => $formatted['name'],
                'content' => $formatted['content'],
                'ttl' => $formatted['ttl'],
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Bunny DNS createRecord exception', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['record' => 'Failed to create DNS record: '.$e->getMessage()]);
        }
    }

    public function updateRecord(string $domainId, string $recordId, array $recordData): array
    {
        try {
            $response = $this->getClient()->post("dnszone/{$domainId}/records/{$recordId}", $this->buildPayload($domainId, $recordData));

            if (! $response->successful()) {
                Log::error('Failed to update Bunny DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);
                throw ValidationException::withMessages(['record' => 'Failed to update DNS record: '.$this->errorMessage($response->json())]);
            }

            return [
                'id' => $recordId,
                'type' => $recordData['type'],
                'name' => $recordData['name'],
                'content' => $recordData['content'],
                'ttl' => $recordData['ttl'] ?? 300,
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Bunny DNS updateRecord exception', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['record' => 'Failed to update DNS record: '.$e->getMessage()]);
        }
    }

    public function deleteRecord(string $domainId, string $recordId): bool
    {
        try {
            $response = $this->getClient()->delete("dnszone/{$domainId}/records/{$recordId}");

            if (! $response->successful()) {
                Log::error('Failed to delete Bunny DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'status' => $response->status(), 'message' => $this->errorMessage($response->json())]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Bunny DNS deleteRecord exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function getClient(): PendingRequest
    {
        return app(BunnyApi::class)->client((string) ($this->dnsProvider->credentials['api_key'] ?? ''));
    }

    /**
     * The list under $key of a paginated Bunny response, or an empty list
     * when the payload does not have the expected shape.
     *
     * @return array<mixed>
     */
    private function listFrom(mixed $json, string $key, string $context): array
    {
        if (! is_array($json) || ! isset($json[$key]) || ! is_array($json[$key])) {
            Log::warning("Bunny DNS returned an unexpected {$context} payload", ['missing' => $key]);

            return [];
        }

        return array_values($json[$key]);
    }

    /**
     * @param  array<string, mixed>  $zone
     * @return ?array<string, mixed> null when required keys are missing
     */
    private function formatZone(array $zone): ?array
    {
        if (! $this->isId($zone['Id'] ?? null) || ! is_string($zone['Domain'] ?? null)) {
            Log::warning('Bunny DNS zone is missing required fields', ['keys' => array_keys($zone)]);

            return null;
        }

        return [
            'id' => (string) $zone['Id'],
            'name' => $zone['Domain'],
            'status' => ($zone['NameserversDetected'] ?? false) ? 'active' : 'pending',
            'created_on' => $zone['DateCreated'] ?? null,
            'modified_on' => $zone['DateModified'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return ?array<string, mixed> null when required keys are missing
     */
    private function formatRecord(array $record): ?array
    {
        if (
            ! $this->isId($record['Id'] ?? null)
            || ! is_int($record['Type'] ?? null)
            || ! is_string($record['Name'] ?? null)
            || ! is_string($record['Value'] ?? null)
            || ! is_int($record['Ttl'] ?? null)
        ) {
            Log::warning('Bunny DNS record is missing required fields', ['keys' => array_keys($record)]);

            return null;
        }

        $type = self::RECORD_TYPES[$record['Type']] ?? 'UNKNOWN';
        $priority = $record['Priority'] ?? null;

        return [
            'id' => (string) $record['Id'],
            'type' => $type,
            'name' => $record['Name'] === '' ? '@' : $record['Name'],
            'content' => $record['Value'],
            'ttl' => $record['Ttl'],
            'proxied' => (bool) ($record['Accelerated'] ?? false),
            'priority' => in_array($type, ['MX', 'SRV']) && is_int($priority) ? $priority : null,
            'created_on' => null,
            'modified_on' => null,
        ];
    }

    private function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildPayload(string $domainId, array $input): array
    {
        $record = $this->validateRecord($input);
        $type = array_search($record['type'], self::RECORD_TYPES, true);

        $payload = [
            'Type' => $type,
            'Name' => $this->normalizeRecordName($domainId, $record['name']),
            'Value' => $record['content'],
            'Ttl' => $record['ttl'] ?? 300,
        ];

        if ($record['priority'] !== null) {
            $payload['Priority'] = $record['priority'];
        }

        return $payload;
    }

    /**
     * Validate the record input Vito hands us. Core only checks generic
     * shape (and forwards every request key), so the record-specific
     * rules live here.
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, name: string, content: string, ttl: ?int, priority: ?int}
     *
     * @throws ValidationException
     */
    private function validateRecord(array $input): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));

        $data = [
            'type' => $type,
            'name' => $input['name'] ?? null,
            'content' => is_string($input['content'] ?? null) ? trim($input['content']) : ($input['content'] ?? null),
            'ttl' => ($input['ttl'] ?? null) === '' ? null : ($input['ttl'] ?? null),
            'priority' => ($input['priority'] ?? null) === '' ? null : ($input['priority'] ?? null),
        ];

        $validated = Validator::make($data, [
            'type' => ['required', Rule::in(self::SUPPORTED_TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:4096', ...$this->contentRules($type)],
            'ttl' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'priority' => match ($type) {
                'MX' => ['required', 'integer', 'min:0', 'max:65535'],
                'SRV' => ['nullable', 'integer', 'min:0', 'max:65535'],
                default => ['prohibited'],
            },
        ], [
            'type.in' => 'Bunny DNS does not support :input records.',
            'content.ipv4' => 'The content of an A record must be an IPv4 address.',
            'content.ipv6' => 'The content of an AAAA record must be an IPv6 address.',
            'content.regex' => $type === 'CAA'
                ? 'The content of a CAA record must look like: 0 issue "letsencrypt.org".'
                : 'The content of a :attribute must be a valid host name.',
            'priority.required' => 'MX records require a priority.',
            'priority.prohibited' => 'Only MX and SRV records accept a priority.',
        ])->validate();

        return [
            'type' => $type,
            'name' => (string) $validated['name'],
            'content' => (string) $validated['content'],
            'ttl' => isset($validated['ttl']) ? (int) $validated['ttl'] : null,
            'priority' => isset($validated['priority']) ? (int) $validated['priority'] : null,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function contentRules(string $type): array
    {
        return match ($type) {
            'A' => ['ipv4'],
            'AAAA' => ['ipv6'],
            'CNAME', 'NS', 'PTR', 'MX', 'SRV' => [
                'regex:'.self::HOSTNAME_PATTERN,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                        $fail('The content of a :attribute must be a host name, not an IP address.');
                    }
                },
            ],
            'CAA' => ['regex:'.self::CAA_PATTERN],
            default => [],
        };
    }

    /**
     * Bunny expects record names relative to the zone ("" for the root),
     * while users may enter "@" or a fully qualified name.
     */
    private function normalizeRecordName(string $domainId, string $name): string
    {
        $name = trim($name);

        if ($name === '@' || $name === '') {
            return '';
        }

        $domain = $this->getDomain($domainId)['name'] ?? null;

        if ($domain !== null) {
            if ($name === $domain) {
                return '';
            }

            if (str_ends_with($name, '.'.$domain)) {
                return substr($name, 0, -strlen('.'.$domain));
            }
        }

        return $name;
    }

    private function errorMessage(mixed $response): string
    {
        if (is_array($response)) {
            return $response['Message'] ?? $response['ErrorKey'] ?? 'Unknown error';
        }

        return 'Unknown error';
    }
}
