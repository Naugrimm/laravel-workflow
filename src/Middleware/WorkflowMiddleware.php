<?php

declare(strict_types=1);

namespace Workflow\Middleware;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LimitIterator;
use RuntimeException;
use SplFileObject;
use Workflow\Activity;
use Workflow\Events\ActivityCompleted;
use Workflow\Events\ActivityFailed;
use Workflow\Events\ActivityStarted;
use Workflow\Serializers\Y;
use Workflow\Workflow;

final class WorkflowMiddleware
{
    private bool $active = true;

    /**
     * @param Activity<Workflow, mixed> $job
     * @param callable $next
     * @return void
     * @throws \Throwable
     */
    public function handle($job, $next): void
    {
        Queue::stopping(fn () => $this->active ? $job->storedWorkflow->exceptions()
            ->create([
                'class' => $job::class,
                'exception' => Y::serialize(new Exception('Activity timed out.')),
            ]) : null);

        $uuid = (string) Str::uuid();

        if (false === ($encodedArguments = json_encode($job->arguments))) {
            throw new RuntimeException('Could not encode arguments.');
        }

        ActivityStarted::dispatch(
            $job->storedWorkflow->id,
            $uuid,
            $job::class,
            $job->index,
            $encodedArguments,
            now()
                ->format('Y-m-d\TH:i:s.u\Z')
        );

        try {
            $result = $next($job);

            try {
                if (false === ($encodedResult = json_encode($result))) {
                    throw new RuntimeException('Could not encode result.');
                }

                $job->storedWorkflow->toWorkflow()
                    ->next($job->index, Carbon::parse($job->now), $job::class, $result);
                ActivityCompleted::dispatch(
                    $job->storedWorkflow->id,
                    $uuid,
                    $encodedResult,
                    now()
                        ->format('Y-m-d\TH:i:s.u\Z')
                );
            } catch (\Spatie\ModelStates\Exceptions\TransitionNotFound) {
                if ($job->storedWorkflow->toWorkflow()->running()) {
                    $job->release();
                }
            }
        } catch (\Throwable $throwable) {
            $file = new SplFileObject($throwable->getFile());
            $iterator = new LimitIterator($file, max(0, $throwable->getLine() - 4), 7);

            if (false === ($encodedArguments = json_encode(json_encode([
                    'class' => get_class($throwable),
                    'message' => $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'line' => $throwable->getLine(),
                    'file' => $throwable->getFile(),
                    'trace' => $throwable->getTrace(),
                    'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
                ])))) {
                throw new RuntimeException('Could not encode arguments.');
            }

            ActivityFailed::dispatch($job->storedWorkflow->id, $uuid, $encodedArguments, now()->format('Y-m-d\TH:i:s.u\Z'));
            throw $throwable;
        } finally {
            $this->active = false;
        }
    }
}
