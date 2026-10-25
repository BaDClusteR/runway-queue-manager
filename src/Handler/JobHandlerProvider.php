<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

use Runway\Exception\ConfigException;
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
        $this->handlers ??= $this->getTaggedHandlers();

        return array_find($this->handlers, static fn(IJobHandler $handler): bool => $handler->getType() === $type);
    }

    /**
     * @return IJobHandler[]
     *
     * @throws ConfigException If a service with the tag is not a handler (a configuration error).
     */
    protected function getTaggedHandlers(): array {
        $handlers = [];

        foreach (Container::getInstance()->getServicesByTag(static::HANDLER_TAG) as $service) {
            if (!$service instanceof IJobHandler) {
                throw new ConfigException(sprintf(
                    'A service with the "%s" tag is not a job handler: %s does not implement %s.',
                    static::HANDLER_TAG,
                    get_debug_type($service),
                    IJobHandler::class
                ));
            }

            $handlers[] = $service;
        }

        return $handlers;
    }
}
