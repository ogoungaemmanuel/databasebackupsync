<?php

namespace DatabaseBackupSync\Support;

class Metrics
{
    /**
     * @var array<string, int>
     */
    protected array $counters = [];

    /**
     * @var array<string, float|int>
     */
    protected array $gauges = [];

    /**
     * @var array<string, array<string, int>>
     */
    protected array $taggedCounters = [];

    public function increment(string $name, int $by = 1, array $tags = []): void
    {
        if ($tags === []) {
            $this->counters[$name] = ($this->counters[$name] ?? 0) + $by;

            return;
        }

        $key = $name.'{'.implode(',', array_map(fn ($k, $v) => "$k=$v", array_keys($tags), $tags)).'}';
        $this->taggedCounters[$key] = ($this->taggedCounters[$key] ?? 0) + $by;
    }

    public function gauge(string $name, float|int $value, array $tags = []): void
    {
        $key = $name.($tags === [] ? '' : '{'.implode(',', array_map(fn ($k, $v) => "$k=$v", array_keys($tags), $tags)).'}');
        $this->gauges[$key] = $value;
    }

    /**
     * @return array{counters: array<string, int>, gauges: array<string, float|int>}
     */
    public function snapshot(): array
    {
        return [
            'counters' => array_merge($this->counters, $this->taggedCounters),
            'gauges' => $this->gauges,
        ];
    }

    /**
     * Emit a structured log line (Prometheus text format) for scraping.
     */
    public function exportToLog(string $prefix = 'database_backup'): void
    {
        $lines = [];

        foreach (array_merge($this->counters, $this->taggedCounters) as $name => $value) {
            $lines[] = sprintf('%s_%s %d', $prefix, $this->normalize($name), $value);
        }

        foreach ($this->gauges as $name => $value) {
            $lines[] = sprintf('%s_%s %s', $prefix, $this->normalize($name), $value);
        }

        logger()->info('database-backup: metrics', ['metrics' => $lines]);
    }

    protected function normalize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_:]/', '_', $name) ?? $name;
    }
}
