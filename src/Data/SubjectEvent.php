<?php

declare(strict_types=1);

namespace Laraflow\Data;

use Laraflow\Enums\WorkflowEventType;

class SubjectEvent extends WorkflowEvent
{
    public function __construct(
        WorkflowEventType $type,
        Transition $transition,
        Marking $marking,
        string $workflowName,
        public readonly object $subject,
    ) {
        parent::__construct($type, $transition, $marking, $workflowName);
    }
}
