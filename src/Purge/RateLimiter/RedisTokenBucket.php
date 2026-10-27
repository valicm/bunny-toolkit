<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge\RateLimiter;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\PurgeType;

/**
 * Token buckets for Bunny's purge limits, shared through Redis or Valkey.
 *
 * Bunny counts purges per account. With InMemoryTokenBucket, every process
 * believes it has the whole limit to itself, so ten workers send ten times
 * what Bunny allows and collect 429s. Here the buckets live on the server,
 * refilled and spent by one Lua script, so two processes can't both take the
 * last token, and on the server's clock rather than each host's.
 *
 * Takes a phpredis `\Redis` or a Predis client. If the server stops answering
 * or refuses the script, the process logs a warning and counts for itself
 * from then on, with $fallback: purges still go out, and a 429 still backs
 * off.
 */
final class RedisTokenBucket implements RateLimiter
{
    /**
     * Refills a bucket, then spends a token or records a 429's back-off.
     *
     * KEYS[1] the bucket. ARGV: burst, refill per second, "consume" or
     * "backoff", and the back-off in seconds. Returns the seconds until a
     * token is ready, "0.000000" when one was taken, as a string so the
     * fraction survives. Times are stored to the microsecond: Lua's tostring()
     * keeps fourteen digits, which rounds an epoch time to a tenth of a
     * millisecond, and a time rounded up reads as a bucket not yet refilled.
     */
    private const SCRIPT = <<<'LUA'
        local burst = tonumber(ARGV[1])
        local rate = tonumber(ARGV[2])
        local time = redis.call('TIME')
        local now = tonumber(time[1]) + tonumber(time[2]) / 1000000
        local held = redis.call('HMGET', KEYS[1], 'tokens', 'updated', 'until')
        local tokens = tonumber(held[1]) or burst
        local updated = tonumber(held[2]) or now
        local blocked = tonumber(held[3]) or 0
        if now > updated then
          tokens = math.min(burst, tokens + (now - updated) * rate)
          updated = now
        end
        local wait = 0
        if ARGV[3] == 'consume' then
          if blocked > now then
            wait = blocked - now
          elseif tokens >= 1 then
            tokens = tokens - 1
          else
            wait = (1 - tokens) / rate
          end
        else
          -- One token is ready when the back-off ends; the rest refill from there.
          blocked = math.max(blocked, now + tonumber(ARGV[4]))
          tokens = 1
          updated = blocked
        end
        redis.call('HSET', KEYS[1], 'tokens', string.format('%.6f', tokens), 'updated', string.format('%.6f', updated), 'until', string.format('%.6f', blocked))
        redis.call('EXPIRE', KEYS[1], math.ceil(burst / rate + math.max(0, blocked - now)) + 60)
        return string.format('%.6f', wait)
        LUA;

    private readonly PurgeLimit $exact;

    private readonly PurgeLimit $prefix;

    private readonly RateLimiter $fallback;

    /**
     * Whether the server has been ruled out for this process.
     */
    private bool $local = false;

    /**
     * @param \Redis|\Predis\ClientInterface $redis A connected client.
     * @param string $keyPrefix Prefix of each bucket's key. Installations sharing an
     *   account but not a server can't see each other's purges.
     * @param RateLimiter|null $fallback Counts once the server is out; an in-memory
     *   bucket with the same limits by default.
     */
    public function __construct(
        private readonly \Redis|\Predis\ClientInterface $redis,
        private readonly string $keyPrefix = 'bunny-toolkit:purge:',
        ?PurgeLimit $exact = null,
        ?PurgeLimit $prefix = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?RateLimiter $fallback = null,
    ) {
        $this->exact = $exact ?? PurgeType::Exact->defaultLimit();
        $this->prefix = $prefix ?? PurgeType::Prefix->defaultLimit();
        $this->fallback = $fallback ?? new InMemoryTokenBucket(exact: $this->exact, prefix: $this->prefix);
    }

    public function tryConsume(PurgeType $type): float
    {
        return $this->shared($type, 'consume', 0.0) ?? $this->fallback->tryConsume($type);
    }

    public function backOff(PurgeType $type, float $seconds): void
    {
        if ($this->shared($type, 'backoff', $seconds) === null) {
            $this->fallback->backOff($type, $seconds);
        }
    }

    /**
     * Runs the script on the shared bucket.
     *
     * @return float|null Seconds until a token is ready, or null when the server couldn't say.
     */
    private function shared(PurgeType $type, string $mode, float $seconds): ?float
    {
        if ($this->local) {
            return null;
        }

        $limit = match ($type) {
            PurgeType::Exact => $this->exact,
            PurgeType::Prefix => $this->prefix,
        };
        $key = $this->keyPrefix . $type->name;
        $args = [(string) $limit->burst, (string) $limit->refillPerSecond, $mode, (string) max(0.0, $seconds)];

        try {
            $wait = $this->redis instanceof \Redis
                ? $this->redis->eval(self::SCRIPT, [$key, ...$args], 1)
                : $this->redis->eval(self::SCRIPT, 1, $key, ...$args);
        } catch (\RedisException|\Predis\PredisException $e) {
            return $this->giveUp(sprintf('the server did not answer (%s)', $e->getMessage()));
        }

        if (!is_numeric($wait)) {
            // phpredis answers false when the script fails.
            return $this->giveUp(sprintf('the script answered %s', var_export($wait, true)));
        }

        return max(0.0, (float) $wait);
    }

    private function giveUp(string $reason): null
    {
        $this->local = true;
        $this->logger->warning('bunny.net purges are counted per process from now on: {reason}.', ['reason' => $reason]);

        return null;
    }
}
