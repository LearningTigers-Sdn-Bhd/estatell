<?php

namespace App\Services\DoorLocks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Stateless calls to the TTLock Open Platform. Every call takes the token it needs and returns plain arrays, so it
 * never reads or writes the database. The one thing it keeps is a short cache lock around a call through a gateway, so calls to
 * one lock take turns (`oneAtATime`). See docs/27-door-locks/01-ttlock-integration.md#ttlock-api.
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
     * Every gateway of the account, with whether it is online. TTLock counts a gateway online while its TCP link is up, so an
     * unplugged one can still show online for a few minutes.
     *
     * @return list<array{id: int, name: string, online: bool}>
     */
    public function gateways(string $accessToken): array
    {
        $gateways = [];
        $page = 1;

        do {
            $response = $this->post('/v3/gateway/list', $accessToken, ['pageNo' => $page, 'pageSize' => 100]);

            foreach ($response['list'] ?? [] as $gateway) {
                $gateways[] = [
                    'id' => (int) $gateway['gatewayId'],
                    'name' => (string) ($gateway['gatewayName'] ?? $gateway['gatewayId']),
                    'online' => (int) ($gateway['isOnline'] ?? 0) === 1,
                ];
            }

            $page++;
        } while ($page <= (int) ($response['pages'] ?? 1));

        return $gateways;
    }

    /**
     * The TTLock ids of the locks that talk through one gateway.
     *
     * @return list<int>
     */
    public function gatewayLockIds(string $accessToken, int $gatewayId): array
    {
        $response = $this->post('/v3/gateway/listLock', $accessToken, ['gatewayId' => $gatewayId]);

        return array_values(array_map(fn (array $lock): int => (int) $lock['lockId'], $response['list'] ?? []));
    }

    /**
     * The lock clock, read through the gateway. Needs a gateway that can reach the lock now.
     */
    public function lockTime(string $accessToken, int $lockId): CarbonImmutable
    {
        $response = $this->post('/v3/lock/queryDate', $accessToken, ['lockId' => $lockId], remote: true);

        return CarbonImmutable::createFromTimestampMs((int) $response['date']);
    }

    /**
     * Move the end of a PIN we sent through the gateway, so the guest keeps the same PIN. TTLock needs the start date too,
     * and ignores the change without it.
     */
    public function changePasscodePeriod(string $accessToken, int $lockId, int $passcodeId, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $this->post('/v3/keyboardPwd/change', $accessToken, [
            'lockId' => $lockId,
            'keyboardPwdId' => $passcodeId,
            'startDate' => $startsAt->getTimestampMs(),
            'endDate' => $endsAt->getTimestampMs(),
            'changeType' => 2,
        ], remote: true);
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
     * Register an IC card with the lock through its gateway. `$cardNumber` is the card UID as a big-endian decimal (`CardNumber`).
     * Returns the TTLock card id. Uses `/v3/identityCard/add`, not `addForReversedCardNumber`, which stores a reversed number.
     */
    public function addCard(string $accessToken, int $lockId, int $cardNumber, string $name, CarbonInterface $startsAt, CarbonInterface $endsAt): int
    {
        $response = $this->post('/v3/identityCard/add', $accessToken, [
            'lockId' => $lockId,
            'cardNumber' => (string) $cardNumber,
            'cardName' => $name,
            'startDate' => $startsAt->getTimestampMs(),
            'endDate' => $endsAt->getTimestampMs(),
            'addType' => 2,
        ], remote: true);

        if (! isset($response['cardId']) || ! is_numeric($response['cardId']) || (int) $response['cardId'] <= 0) {
            throw TtlockException::unreachable();
        }

        return (int) $response['cardId'];
    }

    /**
     * Remove an IC card from the lock through its gateway.
     */
    public function deleteCard(string $accessToken, int $lockId, int $cardId): void
    {
        $response = $this->post('/v3/identityCard/delete', $accessToken, ['lockId' => $lockId, 'cardId' => $cardId, 'deleteType' => 2], remote: true);

        if (! isset($response['errcode']) || (int) $response['errcode'] !== 0) {
            throw TtlockException::unreachable();
        }
    }

    /**
     * The newest events of one lock between two times, up to `$limit`. Reads what TTLock already holds, so it makes no call through the
     * gateway. A lock uploads its events through its gateway within seconds; a lock without one uploads them only when someone syncs it
     * with the TTLock app, so its log can be days behind or empty.
     *
     * @return list<array{type: int, success: bool, code: string|null, username: string|null, at: CarbonImmutable}>
     */
    public function lockRecords(string $accessToken, int $lockId, CarbonInterface $from, CarbonInterface $to, int $limit = 500): array
    {
        $records = [];
        $page = 1;

        do {
            $response = $this->post('/v3/lockRecord/list', $accessToken, [
                'lockId' => $lockId,
                'startDate' => $from->getTimestampMs(),
                'endDate' => $to->getTimestampMs(),
                'pageNo' => $page,
                'pageSize' => 100,
            ]);

            foreach ($response['list'] ?? [] as $record) {
                $records[] = [
                    'type' => (int) ($record['recordType'] ?? 0),
                    'success' => (int) ($record['success'] ?? 1) === 1,
                    'code' => isset($record['keyboardPwd']) && $record['keyboardPwd'] !== '' ? (string) $record['keyboardPwd'] : null,
                    'username' => isset($record['username']) && $record['username'] !== '' ? (string) $record['username'] : null,
                    'at' => CarbonImmutable::createFromTimestampMs((int) ($record['lockDate'] ?? $record['serverDate'] ?? 0)),
                ];
            }

            $page++;
        } while (count($records) < $limit && $page <= (int) ($response['pages'] ?? 1));

        return array_slice($records, 0, $limit);
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
        $call = fn (): array => $this->send($path, [
            'clientId' => config('services.ttlock.client_id'),
            'accessToken' => $accessToken,
            ...$params,
            'date' => now()->getTimestampMs(),
        ], $remote ? self::REMOTE_TIMEOUT : self::TIMEOUT);

        return $remote && isset($params['lockId']) ? $this->oneAtATime((int) $params['lockId'], $call) : $call();
    }

    /**
     * A gateway talks to its lock over Bluetooth and answers one command at a time, and says it is busy to a second one. A guest's PIN and
     * card are sent at the same moment after a check-in, so the calls through the gateway to one lock take turns: the second waits for the
     * first, up to a little longer than a call can take, and is then sent. Locks on the same gateway are not held apart from each other.
     * A call that waited too long is treated as TTLock not answering, which `RegisterDoorCard` and the PIN sync try again.
     *
     * @param  Closure(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function oneAtATime(int $lockId, Closure $call): array
    {
        try {
            return Cache::lock('ttlock-remote:'.$lockId, self::REMOTE_TIMEOUT + 10)->block(self::REMOTE_TIMEOUT + 5, $call);
        } catch (LockTimeoutException) {
            throw TtlockException::unreachable();
        }
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

        if (! $response->successful() || ! is_array($body)) {
            throw TtlockException::unreachable();
        }

        if ((int) ($body['errcode'] ?? 0) !== 0) {
            throw TtlockException::refused((int) $body['errcode'], (string) ($body['errmsg'] ?? $body['description'] ?? ''));
        }

        return $body;
    }
}
