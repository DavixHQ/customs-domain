<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Support;

use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final class BrokenCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache unavailable');
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }
}
