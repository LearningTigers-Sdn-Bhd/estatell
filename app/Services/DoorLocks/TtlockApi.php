<?php

namespace App\Services\DoorLocks;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Stateless calls to the TTLock Open Platform. Every call takes the token it needs and returns plain arrays, so it
 * never reads or writes the database. See docs/27-door-locks/01-ttlock-integration.md#ttlock-api.
 */
class TtlockApi
{
    /**
     * Seconds. A call through a gateway waits for a Bluetooth link to the lock and TTLock itself waits up to 30 seconds, so ours must wait longer.
     */
    private const TIMEOUT = 15;

    private const REMOTE_TIMEOUT = 35;

    /**
     * Sign in with the TTLock app account. The password is hashed with MD5 as TTLock asks, and is not kept.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function signIn(string $username, string $password): array
    {
        return $this->tokens([
            'username' => $username,
            'password' => md5($password),
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Every lock of the account.
     *
     * @return list<array{id: int, name: string, battery: int|null, has_gateway: bool}>
     */
    public function locks(string $accessToken): array
    {
        $locks = [];
        $page = 1;

        do {
            $response = $this->post('/v3/lock/list', $accessToken, ['pageNo' => $page, 'pageSize' => 100]);

            foreach ($response['list'] ?? [] as $lock) {
                $locks[] = [
                    'id' => (int) $lock['lockId'],
                    'name' => (string) ($lock['lockAlias'] ?? $lock['lockName'] ?? $lock['lockId']),
                    'battery' => isset($lock['electricQuantity']) ? (int) $lock['electricQuantity'] : null,
                    'has_gateway' => (int) ($lock['hasGateway'] ?? 0) === 1,
                ];
            }

            $page++;
        } while ($page <= (int) ($response['pages'] ?? 1));

        return $locks;
    }

    /**
     * A PIN the lock works out itself from the time window. Needs no gateway.
     *
     * @return array{code: string, id: int}
     */
    public function generatedPasscode(string $accessToken, int $lockId, string $name, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $response = $this->post('/v3/keyboardPwd/get', $accessToken, [
            'lockId' => $lockId,
            'keyboardPwdType' => 3,
            'keyboardPwdName' => $name,
            'startDate' => $startsAt->getTimestampMs(),
            'endDate' => $endsAt->getTimestampMs(),
        ]);

        return ['code' => (string) $response['keyboardPwd'], 'id' => (int) $response['keyboardPwdId']];
    }

    /**
     * A Delete PIN: typed on the lock keypad, it invalidates every random PIN already used on that lock. It is the only way to
     * take a generated PIN away without a gateway. Needs no gateway to be made.
     */
    public function deleteCode(string $accessToken, int $lockId, CarbonInterface $startsAt): string
    {
        $response = $this->post('/v3/keyboardPwd/get', $accessToken, [
            'lockId' => $lockId,
            'keyboardPwdType' => 4,
            'keyboardPwdName' => 'Delete PINs',
            'startDate' => $startsAt->startOfHour()->getTimestampMs(),
        ]);

        return (string) $response['keyboardPwd'];
    }

    /**
     * A PIN we choose, sent to the lock through its gateway.
     */
    public function customPasscode(string $accessToken, int $lockId, string $code, string $name, CarbonInterface $startsAt, CarbonInterface $endsAt): int
    {
        $response = $this->post('/v3/keyboardPwd/add', $accessToken, [
            'lockId' => $lockId,
            'keyboardPwd' => $code,
            'keyboardPwdName' => $name,
            'startDate' => $startsAt->getTimestampMs(),
            'endDate' => $endsAt->getTimestampMs(),
            'keyboardPwdType' => 3,
            'addType' => 2,
        ], remote: true);

        return (int) $response['keyboardPwdId'];
    }

    /**
     * Remove a PIN from the lock through its gateway.
     */
    public function deletePasscode(string $accessToken, int $lockId, int $passcodeId): void
    {
        $this->post('/v3/keyboardPwd/delete', $accessToken, ['lockId' => $lockId, 'keyboardPwdId' => $passcodeId, 'deleteType' => 2], remote: true);
    }

    /**
     * Open the door through the gateway.
     */
    public function unlock(string $accessToken, int $lockId): void
    {
        $this->post('/v3/lock/unlock', $accessToken, ['lockId' => $lockId], remote: true);
    }

    /**
     * Set the lock clock to the server time through the gateway. A PIN is checked against the lock clock, so a drifted clock can refuse a valid PIN.
     */
    public function adjustClock(string $accessToken, int $lockId): void
    {
        $this->post('/v3/lock/updateDate', $accessToken, ['lockId' => $lockId], remote: true);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function tokens(array $extra): array
    {
        $response = $this->send('/oauth2/token', [
            'clientId' => config('services.ttlock.client_id'),
            'clientSecret' => config('services.ttlock.client_secret'),
            ...$extra,
        ]);

        return [
            'access_token' => (string) $response['access_token'],
            'refresh_token' => (string) $response['refresh_token'],
            'expires_in' => (int) ($response['expires_in'] ?? 7776000),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function post(string $path, string $accessToken, array $params, bool $remote = false): array
    {
        return $this->send($path, [
            'clientId' => config('services.ttlock.client_id'),
            'accessToken' => $accessToken,
            ...$params,
            'date' => now()->getTimestampMs(),
        ], $remote ? self::REMOTE_TIMEOUT : self::TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function send(string $path, array $form, int $timeout = self::TIMEOUT): array
    {
        try {
            $response = Http::asForm()->timeout($timeout)->post(rtrim((string) config('services.ttlock.base_url'), '/').$path, $form);
        } catch (ConnectionException) {
            throw TtlockException::unreachable();
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw TtlockException::unreachable();
        }

        if ((int) ($body['errcode'] ?? 0) !== 0) {
            throw TtlockException::refused((int) $body['errcode'], (string) ($body['errmsg'] ?? $body['description'] ?? ''));
        }

        return $body;
    }
}
