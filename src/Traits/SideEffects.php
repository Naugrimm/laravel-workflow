<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Database\QueryException;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Serializers\Y;

trait SideEffects
{
    /**
     * @template T
     * @param callable(): T $callable
     * @return PromiseInterface<T>
     */
    public static function sideEffect(callable $callable): PromiseInterface
    {
        if (self::$context === null) {
            throw new \RuntimeException('ActivityStub::sideEffect() must be called within a workflow');
        }

        $log = self::$context->storedWorkflow->logs()
            ->whereIndex(self::$context->index)
            ->first();

        if ($log !== null) {
            ++self::$context->index;
            return resolve($log->result !== null ? Y::unserialize($log->result) : null);
        }

        $result = $callable();

        if (! self::$context->replaying) {
            try {
                self::$context->storedWorkflow->logs()
                    ->create([
                        'index' => self::$context->index,
                        'now' => self::$context->now,
                        'class' => self::$context->storedWorkflow->class,
                        'result' => Y::serialize($result),
                    ]);
            } catch (QueryException $exception) {
                $log = self::$context->storedWorkflow->logs()
                    ->whereIndex(self::$context->index)
                    ->first();

                if ($log !== null) {
                    ++self::$context->index;
                    return resolve($log->result !== null ? Y::unserialize($log->result) : null);
                }
            }
        }

        ++self::$context->index;
        return resolve($result);
    }
}
