<?php

namespace Laralyze\Tests\Fixtures;

use Laralyze\Recorders\Recorder;
use RuntimeException;

class OrdersRecorder extends Recorder
{
    protected array $listen = [OrderPlaced::class];

    public function record(OrderPlaced $event): void
    {
        if ($event->plan === 'explode') {
            throw new RuntimeException('Recorder bug');
        }

        $plan = $this->group($event->plan);

        if ($this->shouldIgnore($plan) || ! $this->shouldSample()) {
            return;
        }

        $this->laralyze->record('order', $plan, $event->total)
            ->sample($this->sampleRate())
            ->count()
            ->sum();

        if ($event->total >= $this->threshold($plan)) {
            $this->laralyze->record('large_order', $plan, $event->total)->count();
        }
    }
}
