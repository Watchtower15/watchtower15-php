<?php

declare(strict_types=1);

namespace WatchTower15\Api;

/**
 * The API key's own record: `GET /v1/me`.
 */
final readonly class Me
{
    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $raw
     * @param list<string>         $effectiveScopes
     * @param list<array<string, mixed>>|null $roles
     */
    public function __construct(
        public string $keyUuid,
        public string $keyName,
        public string $keyLastFour,
        public array $scopes,
        public \DateTimeImmutable $keyCreatedAt,
        public ?\DateTimeImmutable $keyExpiresAt,
        public string $userUuid,
        public string $userName,
        public string $userEmail,
        public string $organisationUuid,
        public string $organisationName,
        private array $raw = [],
        /** `person`, or `service_account` for an account that owns keys for automation; null from a backend that does not say (Plan_API_06 D22). */
        public ?string $accountType = null,
        /** The key's scopes its owner's permissions allow now: what a call can use; empty from a backend that does not say. */
        public array $effectiveScopes = [],
        /** A service account's roles, which set what its keys may use; null for a person or from a backend that does not say. */
        public ?array $roles = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data The answer's `data` member.
     *
     * @internal
     */
    public static function fromData(array $data): self
    {
        $key = self::object($data['key'] ?? null);
        $user = self::object($data['user'] ?? null);
        $organisation = self::object($data['organisation'] ?? null);
        $scopes = [];
        foreach (is_array($key['scopes'] ?? null) ? $key['scopes'] : [] as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        $effective = [];
        foreach (is_array($key['effective_scopes'] ?? null) ? $key['effective_scopes'] : [] as $scope) {
            if (is_string($scope)) {
                $effective[] = $scope;
            }
        }
        $roles = null;
        if (is_array($user['roles'] ?? null)) {
            $roles = [];
            foreach ($user['roles'] as $role) {
                if (is_array($role)) {
                    /** @var array<string, mixed> $role */
                    $roles[] = $role;
                }
            }
        }

        return new self(
            self::string($key, 'uuid'),
            self::string($key, 'name'),
            self::string($key, 'last_four'),
            $scopes,
            self::instant($key['created_at'] ?? null) ?? new \DateTimeImmutable('@0'),
            self::instant($key['expires_at'] ?? null),
            self::string($user, 'uuid'),
            self::string($user, 'name'),
            self::string($user, 'email'),
            self::string($organisation, 'uuid'),
            self::string($organisation, 'name'),
            $data,
            is_string($user['account_type'] ?? null) ? $user['account_type'] : null,
            $effective,
            $roles,
        );
    }

    /** @return array<string, mixed> The `data` as sent, unknown fields included. */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $from
     */
    private static function string(array $from, string $key): string
    {
        $value = $from[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $ms = (int) $value;

        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }
}
