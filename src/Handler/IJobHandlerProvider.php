<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

interface IJobHandlerProvider {
    /**
     * @return IJobHandler|null Null if there is no handler of the type.
     */
    public function getHandler(string $type): ?IJobHandler;
}
