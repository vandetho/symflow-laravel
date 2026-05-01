<?php

declare(strict_types=1);

namespace Laraflow\Data;

use Laraflow\Enums\WorkflowEventType;

class WorkflowEvent
{
    public function __construct(
        public readonly WorkflowEventType $type,
        public readonly Transition $transition,
        public readonly Marking $marking,
        public readonly string $workflowName,
    ) {}
}
