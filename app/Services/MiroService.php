<?php

namespace App\Services;

use App\Models\MiroConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MiroService
{
    private ?MiroConnection $cachedConnection = null;

    private bool $connectionLoaded = false;

    public function authorizationUrl(): string
    {
        if (!config('services.miro.client_id') || !config('services.miro.redirect_uri')) {
            throw new RuntimeException('MIRO_CLIENT_ID and MIRO_REDIRECT_URI must be configured first.');
        }

        $state = Str::random(40);
        session(['miro_oauth_state' => $state]);

        return config('services.miro.authorize_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.miro.client_id'),
            'redirect_uri' => config('services.miro.redirect_uri'),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): MiroConnection
    {
        $response = Http::post(config('services.miro.token_url').'?'.http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => config('services.miro.client_id'),
            'client_secret' => config('services.miro.client_secret'),
            'code' => $code,
            'redirect_uri' => config('services.miro.redirect_uri'),
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Miro authorization failed: '.$response->body());
        }

        return $this->persistTokens($response->json());
    }

    public function connection(): ?MiroConnection
    {
        if ($this->connectionLoaded) {
            return $this->cachedConnection;
        }

        $this->connectionLoaded = true;
        $connection = MiroConnection::query()->latest('id')->first();

        if (!$connection) {
            $this->cachedConnection = null;

            return null;
        }

        if ($connection->expires_at && $connection->expires_at->lte(now()->addMinute())) {
            if ($connection->refresh_token) {
                $connection = $this->refresh($connection);
            } else {
                // Non-expiring Miro tokens do not have refresh tokens.
                $connection->forceFill(['expires_at' => null])->save();
                $connection = $connection->refresh();
            }
        }

        $this->cachedConnection = $connection;

        return $this->cachedConnection;
    }

    public function disconnect(): void
    {
        MiroConnection::query()->delete();
        $this->cachedConnection = null;
        $this->connectionLoaded = true;
    }

    public function listBoards(): array
    {
        return $this->request()->get('/boards', ['limit' => 50])->throw()->json('data', []);
    }

    public function getBoard(string $boardId): array
    {
        return $this->request()->get('/boards/'.$boardId)->throw()->json();
    }

    public function getBoardItems(string $boardId, ?string $type = null): array
    {
        $items = [];
        $cursor = null;

        do {
            $query = ['limit' => 50];

            if ($type) {
                $query['type'] = $type;
            }

            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $json = $this->request()
                ->get("/boards/{$boardId}/items", $query)
                ->throw()
                ->json();

            $items = [...$items, ...($json['data'] ?? [])];
            $cursor = $json['cursor'] ?? data_get($json, 'pagination.cursor');
        } while (is_string($cursor) && $cursor !== '');

        return $items;
    }

    public function getConnectors(string $boardId): array
    {
        $connectors = [];
        $cursor = null;

        do {
            $query = ['limit' => 50];

            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $json = $this->request()
                ->get("/boards/{$boardId}/connectors", $query)
                ->throw()
                ->json();

            $connectors = [...$connectors, ...($json['data'] ?? [])];
            $cursor = $json['cursor'] ?? data_get($json, 'pagination.cursor');
        } while (is_string($cursor) && $cursor !== '');

        return $connectors;
    }

    public function getShape(string $boardId, string $itemId): array
    {
        return $this->request()->get("/boards/{$boardId}/shapes/{$itemId}")->throw()->json();
    }

    public function getText(string $boardId, string $itemId): array
    {
        return $this->request()->get("/boards/{$boardId}/texts/{$itemId}")->throw()->json();
    }

    public function getExternalAnchor(string $boardId, string $itemId, ?string $sourceType = null): array
    {
        if ($sourceType === 'text') {
            return $this->getText($boardId, $itemId);
        }

        return $this->getShape($boardId, $itemId);
    }

    public function createShape(string $boardId, array $payload): array
    {
        return $this->request()->post("/boards/{$boardId}/shapes", $payload)->throw()->json();
    }

    /**
     * Create up to 20 Miro board items in a single HTTP request.
     *
     * Miro's bulk endpoint accepts a JSON array. Every entry must include a
     * "type" property. This importer currently sends shape items through it.
     */
    public function createItemsBulk(string $boardId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        if (count($items) > 20) {
            throw new RuntimeException('Miro bulk creation accepts at most 20 items per request.');
        }

        $json = $this->request()
            ->post("/boards/{$boardId}/items/bulk", array_values($items))
            ->throw()
            ->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        $data = is_array($json) ? ($json['data'] ?? null) : null;

        return is_array($data) ? array_values($data) : [];
    }

    public function updateShape(string $boardId, string $itemId, array $payload): array
    {
        return $this->request()->patch("/boards/{$boardId}/shapes/{$itemId}", $payload)->throw()->json();
    }

    public function deleteShape(string $boardId, string $itemId): void
    {
        $this->request()->delete("/boards/{$boardId}/shapes/{$itemId}")->throw();
    }

    public function createConnector(string $boardId, array $payload): array
    {
        return $this->request()->post("/boards/{$boardId}/connectors", $payload)->throw()->json();
    }

    public function updateConnector(string $boardId, string $connectorId, array $payload): array
    {
        return $this->request()->patch("/boards/{$boardId}/connectors/{$connectorId}", $payload)->throw()->json();
    }

    public function deleteConnector(string $boardId, string $connectorId): void
    {
        $this->request()->delete("/boards/{$boardId}/connectors/{$connectorId}")->throw();
    }

    private function request(): PendingRequest
    {
        $connection = $this->connection();

        if (!$connection) {
            throw new RuntimeException('Miro is not connected. Open Miro Settings and connect your account first.');
        }

        return Http::baseUrl(config('services.miro.api_url'))
            ->acceptJson()
            ->asJson()
            ->withToken($connection->access_token)
            ->timeout(60)
            ->connectTimeout(15)
            ->retry(2, 1200, throw: false);
    }

    private function refresh(MiroConnection $connection): MiroConnection
    {
        if (!$connection->refresh_token) {
            throw new RuntimeException('The Miro access token expired and no refresh token is available. Reconnect Miro.');
        }

        $response = Http::post(config('services.miro.token_url').'?'.http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => config('services.miro.client_id'),
            'client_secret' => config('services.miro.client_secret'),
            'refresh_token' => $connection->refresh_token,
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Unable to refresh the Miro access token. Reconnect Miro.');
        }

        return $this->persistTokens($response->json(), $connection);
    }

    private function persistTokens(array $data, ?MiroConnection $connection = null): MiroConnection
    {
        if (empty($data['access_token'])) {
            throw new RuntimeException('Miro did not return an access token.');
        }

        $connection ??= MiroConnection::query()->latest('id')->first() ?? new MiroConnection();

        $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in'])
            ? (int) $data['expires_in']
            : null;

        $refreshToken = $data['refresh_token'] ?? $connection->refresh_token;
        $isExpiringToken = !empty($refreshToken) && $expiresIn !== null && $expiresIn > 0;

        if (!$isExpiringToken && ($expiresIn === null || $expiresIn <= 0)) {
            $refreshToken = null;
        }

        $connection->fill([
            'team_id' => $data['team_id'] ?? $connection->team_id,
            'user_id' => $data['user_id'] ?? $connection->user_id,
            'access_token' => $data['access_token'],
            'refresh_token' => $refreshToken,
            'token_type' => $data['token_type'] ?? 'bearer',
            'scopes' => $data['scope'] ?? $connection->scopes,
            'expires_at' => $isExpiringToken ? now()->addSeconds($expiresIn) : null,
        ]);
        $connection->save();

        MiroConnection::query()->where('id', '!=', $connection->id)->delete();

        $this->cachedConnection = $connection->refresh();
        $this->connectionLoaded = true;

        return $this->cachedConnection;
    }
}
