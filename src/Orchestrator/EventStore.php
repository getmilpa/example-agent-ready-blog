<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

/**
 * Append-only JSONL log of {@see ProcessEvent}s: one JSON object per line, one line per event,
 * never rewritten or truncated. A process instance's state is never stored — it is always
 * reconstructed by replaying its events through the {@see Reducer}, so this store's only jobs are
 * "append durably" and "read back in order". Zero DB, mirrors the flat-file idiom already used by
 * `Milpa\Live\Security\FileNonceStore` in `milpa/live-web`.
 *
 * `nextSeq()` and `replay()` both derive their answer from the file itself rather than from an
 * in-memory counter, on purpose: a fresh `EventStore` pointed at the same path — a different
 * process, a different request — must agree with every other instance about both "what happened"
 * and "what comes next", and the file is the only thing every instance shares.
 */
final class EventStore
{
    /**
     * @param string $path path to the JSONL log file; its directory is created on first append if missing
     */
    public function __construct(private readonly string $path = 'var/events.jsonl')
    {
    }

    /**
     * Appends `$event` as one JSON line, under an exclusive lock so concurrent appenders cannot
     * interleave partial lines.
     */
    public function append(ProcessEvent $event): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create event store directory: {$dir}");
        }

        $handle = fopen($this->path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            $line = json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            fwrite($handle, $line . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * All events belonging to `$instanceId`, in ascending `seq` order.
     *
     * @return list<ProcessEvent>
     */
    public function replay(string $instanceId): array
    {
        $events = array_values(array_filter(
            $this->readAll(),
            static fn (ProcessEvent $event): bool => $event->instanceId === $instanceId,
        ));

        usort($events, static fn (ProcessEvent $a, ProcessEvent $b): int => $a->seq <=> $b->seq);

        return $events;
    }

    /**
     * The next `seq` to assign, one past the highest `seq` currently in the store (across every
     * instance) — `1` for an empty or missing log.
     */
    public function nextSeq(): int
    {
        $max = 0;
        foreach ($this->readAll() as $event) {
            $max = max($max, $event->seq);
        }

        return $max + 1;
    }

    /**
     * Every distinct instance id present in the store, in the order each first appears (ascending
     * `seq` of its first event). Exists for read-side scans across ALL instances (e.g. listing
     * every pending approval) that cannot start from a single, already-known instance id the way
     * {@see self::replay()} does.
     *
     * @return list<string>
     */
    public function allInstanceIds(): array
    {
        $ids = [];
        foreach ($this->readAll() as $event) {
            if (!in_array($event->instanceId, $ids, true)) {
                $ids[] = $event->instanceId;
            }
        }

        return $ids;
    }

    /**
     * @return list<ProcessEvent>
     */
    private function readAll(): array
    {
        return array_map(ProcessEvent::fromArray(...), $this->readRows());
    }

    /**
     * @return list<array{instance_id: string, type: string, payload: array<string,mixed>, seq: int}>
     */
    private function readRows(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        $rows = [];

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $rows;
    }
}
