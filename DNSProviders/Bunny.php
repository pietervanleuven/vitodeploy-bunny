<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders;

use App\DNSProviders\AbstractDNSProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class Bunny extends AbstractDNSProvider
{
    private const string API_BASE_URL = 'https://api.bunny.net/';

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
            $response = Http::withHeaders([
                'AccessKey' => $credentials['api_key'],
                'Accept' => 'application/json',
            ])
                ->baseUrl(self::API_BASE_URL)
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
        try {
            $response = $this->getClient()->get('dnszone', [
                'page' => 1,
                'perPage' => 1000,
            ]);

            if (! $response->successful()) {
                Log::error('Failed to fetch Bunny DNS zones', ['response' => $response->json()]);

                return [];
            }

            return collect($this->listFrom($response->json(), 'Items', 'zones'))
                ->map(fn (mixed $zone): ?array => is_array($zone) ? $this->formatZone($zone) : null)
                ->filter()
                ->values()
                ->toArray();
        } catch (Throwable $e) {
            Log::error('Bunny DNS getDomains exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getDomain(string $domainId): array
    {
        try {
            $response = $this->getClient()->get("dnszone/{$domainId}");

            if (! $response->successful()) {
                Log::error('Failed to fetch Bunny DNS zone', ['domainId' => $domainId, 'response' => $response->json()]);

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
            Log::error('Failed to fetch Bunny DNS records', ['domainId' => $domainId, 'response' => $response->json()]);
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
                Log::error('Failed to create Bunny DNS record', ['domainId' => $domainId, 'input' => $recordData, 'response' => $response->json()]);
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
                Log::error('Failed to update Bunny DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'input' => $recordData, 'response' => $response->json()]);
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
                Log::error('Failed to delete Bunny DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'response' => $response->json()]);

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
        return Http::withHeaders([
            'AccessKey' => $this->dnsProvider->credentials['api_key'],
            'Accept' => 'application/json',
        ])->baseUrl(self::API_BASE_URL);
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
        $type = array_search(strtoupper($input['type']), self::RECORD_TYPES);

        if ($type === false || ! in_array($input['type'], ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'SRV', 'NS', 'CAA', 'PTR'])) {
            throw ValidationException::withMessages(['record' => "Bunny DNS does not support {$input['type']} records"]);
        }

        $payload = [
            'Type' => $type,
            'Name' => $this->normalizeRecordName($domainId, $input['name']),
            'Value' => $input['content'],
            'Ttl' => (int) ($input['ttl'] ?? 300),
        ];

        if (isset($input['priority'])) {
            $payload['Priority'] = (int) $input['priority'];
        }

        return $payload;
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
