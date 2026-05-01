# Engine API

## WorkflowEngine

The core engine manages token-based markings, fires transitions, and emits events.

```php
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Data\WorkflowDefinition;
use Laraflow\Data\Place;
use Laraflow\Data\Transition;
use Laraflow\Enums\WorkflowType;

$definition = new WorkflowDefinition(
    name: 'order',
    type: WorkflowType::StateMachine,
    places: [
        new Place(name: 'draft'),
        new Place(name: 'submitted'),
        new Place(name: 'approved'),
    ],
    transitions: [
        new Transition(name: 'submit', froms: ['draft'], tos: ['submitted']),
        new Transition(name: 'approve', froms: ['submitted'], tos: ['approved']),
    ],
    initialMarking: ['draft'],
);

$engine = new WorkflowEngine($definition);
```

### Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getMarking()` | `Marking` | Current marking (clone) |
| `setMarking(Marking)` | `void` | Override current marking |
| `getInitialMarking()` | `Marking` | The initial marking from definition |
| `getActivePlaces()` | `string[]` | Places with token count > 0 |
| `getEnabledTransitions()` | `Transition[]` | Transitions that can fire now |
| `can(string)` | `TransitionResult` | Check if a transition can fire |
| `apply(string)` | `Marking` | Fire a transition (throws if blocked) |
| `use(callable)` | `void` | Register a middleware |
| `reset()` | `void` | Reset to initial marking |
| `on(WorkflowEventType, callable, ?string $transitionName = null, int $priority = 0)` | `Closure` | Subscribe to events (returns unsubscribe). Optional transition scope and priority — see "Listener scoping & priority" below. |
| `getDefinition()` | `WorkflowDefinition` | The underlying definition |

### TransitionResult

```php
$result = $engine->can('approve');

if (!$result->allowed) {
    foreach ($result->blockers as $blocker) {
        echo $blocker->code;    // "not_in_place", "guard_blocked", "unknown_transition", "invalid_marking"
        echo $blocker->message;
    }
}
```

### Constructor Options

```php
$engine = new WorkflowEngine(
    definition: $definition,
    guardEvaluator: $myGuardEvaluator, // implements GuardEvaluatorInterface
    middleware: [
        function ($ctx, $next) {
            // wrap apply() lifecycle
            return $next();
        },
    ],
    listenerErrorMode: ListenerErrorMode::Collect, // see "Listener errors" below
    onListenerError: fn (\Throwable $e, WorkflowEvent $event) => report($e),
);
```

### Listener scoping & priority

`on()` accepts two optional arguments to keep listener registration declarative
instead of forcing every callback to start with
`if ($event->transition->name === '…')`:

```php
// Wildcard listener — fires for every transition (priority 0).
$engine->on(WorkflowEventType::Entered, fn ($e) => Audit::log($e));

// Scoped listener — fires only when the 'paid' transition completes.
$engine->on(
    WorkflowEventType::Enter,
    fn ($e) => Inventory::deduct($e),
    transitionName: 'paid',
    priority: 100, // higher = earlier
);

// Lower priority — runs after the inventory deduction above.
$engine->on(
    WorkflowEventType::Enter,
    fn ($e) => Slack::notifyWarehouse($e),
    transitionName: 'paid',
    priority: 50,
);
```

**Dispatch order:** higher `$priority` fires first; ties preserve registration
order (FIFO across both wildcard and scoped registrations). Wildcard and
scoped listeners interleave by priority globally — there is no implicit
"wildcard always before scoped" rule.

`Subject\Workflow::on()` accepts the same arguments and forwards them to the
underlying engine.

### Listener errors

By default a throwing event listener bubbles up and aborts `apply()` mid-transition,
which can leave the marking inconsistent (tokens removed from the source place but
not yet added to the target). Choose a `ListenerErrorMode` to control this:

| Mode | Behavior |
|------|----------|
| `Throw` (default) | Rethrow the first listener exception immediately. Backwards compatible. |
| `Collect` | Catch listener exceptions, complete the transition (marking is fully written), then throw a `ListenerExceptionAggregate` exposing every collected `\Throwable` via `getExceptions()`. |
| `Swallow` | Catch listener exceptions and pass them to `onListenerError($throwable, $event)` (or silently ignore if no callback). The transition completes normally. |

Subsequent listeners always run in `Collect` and `Swallow` modes — one bad listener
does not silence the rest. Middleware exceptions are not caught: middleware is the
caller's escape hatch and any throw there still aborts the transition.

## Guards

Implement `GuardEvaluatorInterface` to control transition access:

```php
use Laraflow\Contracts\GuardEvaluatorInterface;
use Laraflow\Data\GuardResult;
use Laraflow\Data\Marking;
use Laraflow\Data\Transition;

class MyGuardEvaluator implements GuardEvaluatorInterface
{
    public function evaluate(string $expression, Marking $marking, Transition $transition): bool|GuardResult
    {
        // Return a plain bool for simple allow/deny...
        return match ($expression) {
            'is_admin' => auth()->user()?->isAdmin() ?? false,
            default => true,
        };
    }
}
```

Return a `GuardResult` when you want the failure reason to flow into the `TransitionBlocker` (so the UI/API can show it without inspecting the guard expression):

```php
public function evaluate(string $expression, Marking $marking, Transition $transition): bool|GuardResult
{
    if ($expression === 'is_admin' && ! auth()->user()?->isAdmin()) {
        return GuardResult::deny('You must be an admin to approve.', 'not_admin');
    }

    return GuardResult::allow();
}
```

When the guard returns `GuardResult::deny($reason, $code)`, the blocker uses `$code` (or `'guard_blocked'` if omitted) and `$reason` as its message. A plain `false` falls back to the legacy `guard_blocked` code with the expression in the message.

Add guards to transitions:

```php
new Transition(
    name: 'approve',
    froms: ['submitted'],
    tos: ['approved'],
    guard: 'is_admin',
),
```

## Validation

Catch structural problems before creating an engine:

```php
use Laraflow\Engine\Validator;

$result = Validator::validate($definition);

if (!$result->valid) {
    foreach ($result->errors as $error) {
        echo "[{$error->type->value}] {$error->message}\n";
    }
}
```

| Error Type | Description |
|-----------|-------------|
| `no_initial_marking` | No initial marking defined |
| `invalid_initial_marking` | References a non-existent place |
| `invalid_transition_source` | Transition `from` references unknown place |
| `invalid_transition_target` | Transition `to` references unknown place |
| `unreachable_place` | Place unreachable from initial marking (BFS) |
| `dead_transition` | Transition can never fire |
| `orphan_place` | Place has no transitions |
| `invalid_weight` | Weight is not a positive integer |

## Pattern Analysis

Detect structural patterns in your workflow:

```php
use Laraflow\Engine\Analyzer;

$analysis = Analyzer::analyze($definition);

// Transition patterns
$analysis->transitions['start_review']->pattern; // TransitionPattern::AndSplit
$analysis->transitions['publish']->pattern;       // TransitionPattern::AndJoin

// Place patterns
$analysis->places['review']->patterns;            // [PlacePattern::OrSplit]
```

**Transition patterns:** `Simple`, `AndSplit`, `AndJoin`, `AndSplitJoin`

**Place patterns (workflow):** `Simple`, `OrSplit`, `OrJoin`, `AndSplit`, `AndJoin`

**Place patterns (state_machine):** `Simple`, `XorSplit`, `XorJoin`
