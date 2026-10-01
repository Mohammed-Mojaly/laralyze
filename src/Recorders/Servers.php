<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * CPU, memory and disk of every server that runs the scheduler, taken
 * once a minute by a task Laralyze adds to your schedule.
 */
class Servers extends Recorder
{
    public function register(Application $app): void
    {
        $app->afterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));

        if ($app->resolved(Schedule::class)) {
            $this->schedule($app->make(Schedule::class));
        }
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->laralyze->rescue(fn () => $this->snapshot()))
            ->everyMinute()
            ->name('laralyze:servers');
    }

    public function snapshot(): void
    {
        $server = $this->serverName();
        $cpu = $this->cpu();
        [$memoryUsed, $memoryTotal] = $this->memory();

        $disks = [];

        foreach ((array) ($this->config['directories'] ?? ['/']) as $directory) {
            $total = @disk_total_space((string) $directory);
            $free = @disk_free_space((string) $directory);

            if ($total !== false && $free !== false) {
                $disks[] = ['directory' => (string) $directory, 'used' => (int) ($total - $free), 'total' => (int) $total];
            }
        }

        if ($cpu !== null) {
            $this->laralyze->record('server_cpu', $server, $cpu)->avg()->max();
        }

        if ($memoryTotal > 0) {
            $this->laralyze->record('server_memory', $server, $memoryUsed / $memoryTotal * 100)->avg()->max();
        }

        $this->laralyze->set('server', $server, (string) json_encode([
            'cpu' => $cpu,
            'memory_used' => $memoryUsed,
            'memory_total' => $memoryTotal,
            'disks' => $disks,
        ]));
    }

    protected function serverName(): string
    {
        return (string) ($this->config['server_name'] ?? gethostname() ?: 'server');
    }

    /**
     * Busy CPU as a percentage.
     */
    protected function cpu(): ?float
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => $this->linuxCpu(),
            'Darwin' => $this->run("top -l 1 | grep -E '^CPU' | awk '{ print 100 - \$7 }'"),
            'Windows' => $this->run('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"'),
            default => null,
        };
    }

    /**
     * Two reads of /proc/stat a moment apart; the share of time not idle.
     */
    protected function linuxCpu(): ?float
    {
        $read = function (): ?array {
            $lines = @file('/proc/stat');

            if ($lines === false || ! isset($lines[0])) {
                return null;
            }

            $line = $lines[0];

            $values = array_map(intval(...), array_slice(preg_split('/\s+/', trim($line)) ?: [], 1));

            return [array_sum($values), ($values[3] ?? 0) + ($values[4] ?? 0)];
        };

        $first = $read();
        usleep(250_000);
        $second = $read();

        if ($first === null || $second === null || $second[0] <= $first[0]) {
            return null;
        }

        return round((1 - ($second[1] - $first[1]) / ($second[0] - $first[0])) * 100, 1);
    }

    /**
     * Used and total memory in bytes.
     *
     * @return array{0: int, 1: int}
     */
    protected function memory(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+)/m', (string) file_get_contents('/proc/meminfo'), $matches);
            $info = array_combine($matches[1], array_map(intval(...), $matches[2]));
            $total = ($info['MemTotal'] ?? 0) * 1_024;

            return [$total - ($info['MemAvailable'] ?? 0) * 1_024, $total];
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $total = (int) $this->run('sysctl -n hw.memsize');
            $pageSize = (int) $this->run('pagesize');
            $free = (int) $this->run("vm_stat | awk '/Pages (free|inactive|speculative)/ { sum += \$NF } END { print sum }'");

            return [max(0, $total - $free * $pageSize), $total];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $total = (int) $this->run('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem).TotalVisibleMemorySize"') * 1_024;
            $free = (int) $this->run('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory"') * 1_024;

            return [max(0, $total - $free), $total];
        }

        return [0, 0];
    }

    protected function run(string $command): ?float
    {
        try {
            $output = trim(Process::timeout(10)->run($command)->output());
        } catch (Throwable) {
            return null;
        }

        return is_numeric($output) ? (float) $output : null;
    }
}
