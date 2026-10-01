<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * CPU, memory and disks of each server, as of the last minute.
 */
#[Lazy]
class ServerList extends Card
{
    public function render(): View
    {
        $servers = $this->values('server')->map(function (stdClass $server) {
            $data = json_decode((string) $server->value, true) ?: [];

            $row = new stdClass;
            $row->name = (string) $server->key;
            $row->seen_at = (int) $server->timestamp;
            $row->cpu = isset($data['cpu']) ? (float) $data['cpu'] : null;
            $row->memory_used = (int) ($data['memory_used'] ?? 0);
            $row->memory_total = (int) ($data['memory_total'] ?? 0);
            $row->disks = (array) ($data['disks'] ?? []);
            $row->series = [
                'cpu' => $this->graph('server_cpu', 'avg', $row->name),
                'memory' => $this->graph('server_memory', 'avg', $row->name),
            ];

            return $row;
        });

        return view('laralyze::cards.server-list', ['servers' => $servers]);
    }
}
