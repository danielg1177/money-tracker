<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlaidClient
{
    public function __construct(
        private string $clientId,
        private string $secret,
        private string $baseUrl,
        private string $apiVersion,
    ) {}

    public static function fromConfig(): self
    {
        $baseUrl = config('plaid.base_url');
        if (! filled($baseUrl)) {
            $baseUrl = match (config('plaid.env')) {
                'production' => 'https://production.plaid.com',
                'development' => 'https://development.plaid.com',
                default => 'https://sandbox.plaid.com',
            };
        }

        return new self(
            (string) config('plaid.client_id'),
            (string) config('plaid.secret'),
            $baseUrl,
            (string) config('plaid.api_version'),
        );
    }

    public static function isConfigured(): bool
    {
        return filled(config('plaid.client_id')) && filled(config('plaid.secret'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        $payload = array_merge($body, [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
        ]);

        $response = Http::withHeaders([
            'Plaid-Version' => $this->apiVersion,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout(120)
            ->post($this->baseUrl.$path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Plaid HTTP '.$response->status().': '.$response->body());
        }

        /** @var array<string, mixed> */
        return $response->json();
    }
}
