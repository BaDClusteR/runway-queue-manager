<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

use Runway\Singleton\Container;

class JobHandlerProvider implements IJobHandlerProvider {
    public const string HANDLER_TAG = 'queue.job_handler';

    /**
     * @param IJobHandler[]|null $handlers By default, the services with the HANDLER_TAG tag.
     */
    public function __construct(
        protected ?array $handlers = null
    ) {}

    public function getHandler(string $type): ?IJobHandler {
        $this->handlers ??= Container::getInstance()->getServicesByTag(static::HANDLER_TAG);

        return array_find($this->handlers, static fn(IJobHandler $handler): bool => $handler->getType() === $type);
    }
}
