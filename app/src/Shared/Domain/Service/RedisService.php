<?php
declare(strict_types=1);

namespace App\Shared\Domain\Service;

readonly class RedisService
{
    private const PREFIX = 'coating';

    public function __construct(
        private \Redis $redis,
        //todo сделать так, чтобы на разные сервисы был разный префикс
        private string $prefix = self::PREFIX
    )
    {
    }

    private function getKey(string $key): string
    {
        return sprintf('%s:%s', $this->prefix, $key);
    }

    public function set(string $key, array $data, int $expire = 60 * 60 * 2): void
    {
        $this->redis->set($this->getKey($key), serialize($data));
        if ($expire) {
            $this->redis->expire($this->getKey($key), $expire);
        }
    }

    public function get(string $key, $defaultValue = null): ?array
    {
        return ($cache = $this->redis->get($this->getKey($key))) ? unserialize($cache) : $defaultValue;
    }

    public function add(string $key, array $data, int $expire = 60 * 60 * 2): void
    {
        if ($this->exists($key)) {
            $exists = $this->get($key, []);
            foreach ($data as $k => $v) {
                $exists[$k] = $v;
            }
            $this->set($key, $exists, $expire);
        } else {
            $this->set($key, $data, $expire);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del([$this->getKey($key)]);
    }

    public function exists(string $key): bool
    {
        return (bool)$this->redis->exists($this->getKey($key));
    }

    public function listPush(string $key, string $value): void
    {
        $this->redis->lPush($this->getKey($key), $value);
    }

    public function listPop(string $key): ?string
    {
        $value = $this->redis->lPop($this->getKey($key));
        return $value ?: null;
    }

    public function listRange(string $key, int $start = 0, int $end = -1, $default = []): array
    {
        $value = $this->redis->lRange($this->getKey($key), $start, $end);
        return $value ?: $default;
    }

    public function listItemRemove(string $key, string $value): void
    {
        $this->redis->lRem($this->getKey($key), $value);
    }

    public function listLen(string $key): int
    {
        return (int)$this->redis->lLen($this->getKey($key));
    }

}