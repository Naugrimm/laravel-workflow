<?php

declare(strict_types=1);

namespace Tests\Unit;

use Laravel\SerializableClosure\SerializableClosure;
use Tests\TestCase;
use Tests\TestCaseRequiringDatabase;
use Workflow\AsyncWorkflow;
use Workflow\WorkflowStub;

final class AsyncWorkflowTest extends TestCaseRequiringDatabase
{
    public function testWorkflow(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(AsyncWorkflow::class)->id());
        $workflow->start(new SerializableClosure(static fn () => 'test'));

        $this->assertSame('test', $workflow->output());
    }
}
