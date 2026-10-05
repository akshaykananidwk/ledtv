<?php
declare(strict_types=1);

/**
 * Periodic job run by Scheduler::tick(). Every class in core/Tasks/*.php implementing this
 * interface (class name = file name) runs at most once per interval() seconds (tracked in the
 * platform setting task_last_<ClassName>). Tasks run without a hotel context — use
 * Tenant::each(fn ($hotelId) => …) to work per hotel.
 */
interface Task
{
    /** Minimum seconds between two runs (e.g. 86400 = daily). */
    public function interval(): int;

    /** Do the work; return a small summary for logs / cron --verbose. */
    public function run(): array;
}
