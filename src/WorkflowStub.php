<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Traits\Macroable;
use LimitIterator;
use ReflectionClass;
use SplFileObject;
use Workflow\Events\WorkflowFailed;
use Workflow\Events\WorkflowStarted;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Y;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Traits\Awaits;
use Workflow\Traits\AwaitWithTimeouts;
use Workflow\Traits\Fakes;
use Workflow\Traits\SideEffects;
use Workflow\Traits\Timers;

/**
 * @template TWorkflow of Workflow
 */
final class WorkflowStub
{
    use Awaits;
    use AwaitWithTimeouts;
    use Fakes;
    use Macroable;
    use SideEffects;
    use Timers;

    private static ?\stdClass $context = null;

    /**
     * @param StoredWorkflow $storedWorkflow
     */
    private function __construct(
        protected $storedWorkflow
    ) {
        self::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => Carbon::now(),
            'replaying' => false,
        ]);
    }

    public function __call($method, $arguments)
    {
        if (collect((new ReflectionClass($this->storedWorkflow->class))->getMethods())
            ->filter(static fn ($method): bool => collect($method->getAttributes())
                ->contains(static fn ($attribute): bool => $attribute->getName() === SignalMethod::class))
            ->map(static fn ($method) => $method->getName())
            ->contains($method)
        ) {
            $this->storedWorkflow->signals()
                ->create([
                    'method' => $method,
                    'arguments' => Y::serialize($arguments),
                ]);

            $this->storedWorkflow->toWorkflow();

            if (self::faked()) {
                $this->resume();
                return;
            }

            return Signal::dispatch($this->storedWorkflow, self::connection(), self::queue());
        }

        if (collect((new ReflectionClass($this->storedWorkflow->class))->getMethods())
            ->filter(static fn ($method): bool => collect($method->getAttributes())
                ->contains(static fn ($attribute): bool => $attribute->getName() === QueryMethod::class))
            ->map(static fn ($method) => $method->getName())
            ->contains($method)
        ) {
            return (new $this->storedWorkflow->class(
                $this->storedWorkflow,
                ...Y::unserialize($this->storedWorkflow->arguments),
            ))
                ->query($method);
        }
    }

    public static function connection()
    {
        return Arr::get(
            (new ReflectionClass(self::$context->storedWorkflow->class))->getDefaultProperties(),
            'connection'
        );
    }

    public static function queue()
    {
        return Arr::get((new ReflectionClass(self::$context->storedWorkflow->class))->getDefaultProperties(), 'queue');
    }

    /**
     * @template TWorkflowClass of Workflow
     * @param class-string<TWorkflowClass> $class
     * @return self<StoredWorkflow<TWorkflowClass>, TWorkflowClass>
     */
    public static function make($class): self
    {
        $storedWorkflow = config('workflows.stored_workflow_model', StoredWorkflow::class)::create([
            'class' => $class,
        ]);

        if (!$storedWorkflow instanceof StoredWorkflow) {
            throw new \RuntimeException('StoredWorkflow model must extend ' . StoredWorkflow::class);
        }

        /** @var StoredWorkflow<TWorkflowClass, null> $storedWorkflow */
        return new self($storedWorkflow);
    }

    public static function load($id)
    {
        return self::fromStoredWorkflow(
            config('workflows.stored_workflow_model', StoredWorkflow::class)::findOrFail($id)
        );
    }

    /**
     * @template TWorkflowClass of Workflow
     * @param StoredWorkflow<TWorkflowClass, null> $storedWorkflow
     * @return self<TWorkflowClass>
     */
    public static function fromStoredWorkflow(StoredWorkflow $storedWorkflow): self
    {
        return new self($storedWorkflow);
    }

    public static function getContext(): \stdClass
    {
        return self::$context;
    }

    public static function setContext($context): void
    {
        self::$context = (object) $context;
    }

    public static function now()
    {
        return self::getContext()->now;
    }

    public function id()
    {
        return $this->storedWorkflow->id;
    }

    public function logs()
    {
        return $this->storedWorkflow->logs;
    }

    public function exceptions()
    {
        return $this->storedWorkflow->exceptions;
    }

    public function output()
    {
        if ($this->storedWorkflow->fresh()->output === null) {
            return null;
        }

        return Y::unserialize($this->storedWorkflow->fresh()->output);
    }

    public function completed(): bool
    {
        return $this->status() === WorkflowCompletedStatus::class;
    }

    public function created(): bool
    {
        return $this->status() === WorkflowCreatedStatus::class;
    }

    public function failed(): bool
    {
        return $this->status() === WorkflowFailedStatus::class;
    }

    public function running(): bool
    {
        return ! in_array($this->status(), [WorkflowCompletedStatus::class, WorkflowFailedStatus::class], true);
    }

    public function status(): string|bool
    {
        return $this->storedWorkflow->fresh()
            ->status::class;
    }

    public function fresh(): static
    {
        $this->storedWorkflow->refresh();

        return $this;
    }

    public function resume(): void
    {
        $this->fresh()
            ->dispatch();
    }

    public function start(...$arguments): void
    {
        $this->storedWorkflow->arguments = Y::serialize($arguments);

        $this->dispatch();
    }

    public function startAsChild(StoredWorkflow $parentWorkflow, int $index, $now, ...$arguments): void
    {
        $this->storedWorkflow->parents()
            ->detach();

        $this->storedWorkflow->parents()
            ->attach($parentWorkflow, [
                'parent_index' => $index,
                'parent_now' => $now,
            ]);

        $this->start(...$arguments);
    }

    public function fail($exception): void
    {
        try {
            $this->storedWorkflow->exceptions()
                ->create([
                    'class' => $this->storedWorkflow->class,
                    'exception' => Y::serialize($exception),
                ]);
        } catch (QueryException) {
            // already logged
        }

        $this->storedWorkflow->status->transitionTo(WorkflowFailedStatus::class);

        $file = new SplFileObject($exception->getFile());
        $iterator = new LimitIterator($file, max(0, $exception->getLine() - 4), 7);

        WorkflowFailed::dispatch($this->storedWorkflow->id, json_encode([
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'line' => $exception->getLine(),
            'file' => $exception->getFile(),
            'trace' => $exception->getTrace(),
            'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
        ]), now()
            ->format('Y-m-d\TH:i:s.u\Z'));

        $this->storedWorkflow->parents()
            ->each(static function ($parentWorkflow) use ($exception) {
                try {
                    $parentWorkflow->toWorkflow()
                        ->fail($exception);
                } catch (\Spatie\ModelStates\Exceptions\TransitionNotFound) {
                    return;
                }
            });
    }

    public function next($index, $now, $class, $result): void
    {
        try {
            $this->storedWorkflow->logs()
                ->create([
                    'index' => $index,
                    'now' => $now,
                    'class' => $class,
                    'result' => Y::serialize($result),
                ]);
        } catch (QueryException) {
            // already logged
        }

        $this->dispatch();
    }

    private function dispatch(): void
    {
        if ($this->created()) {
            WorkflowStarted::dispatch(
                $this->storedWorkflow->id,
                $this->storedWorkflow->class,
                json_encode(Y::unserialize($this->storedWorkflow->arguments)),
                now()
                    ->format('Y-m-d\TH:i:s.u\Z')
            );
        }

        $this->storedWorkflow->status->transitionTo(WorkflowPendingStatus::class);

        if (self::faked()) {
            $this->storedWorkflow->class::dispatchSync(
                $this->storedWorkflow,
                ...Y::unserialize($this->storedWorkflow->arguments)
            );
        } else {
            $this->storedWorkflow->class::dispatch(
                $this->storedWorkflow,
                ...Y::unserialize($this->storedWorkflow->arguments)
            );
        }
    }
}
