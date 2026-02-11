The RaceConditionTest tries to reproduce an issue we have when multiple workers are handling many jobs in parallel.

The issue manifests in Workflows that are stuck in the `pending` state, while one of the workflow's children/activities exist in the Queue.

As this did only occur in the staging system where we had

a) many workers
b) workflows having many children

we suspect a race condition here.

## Test Setup:

- `\Tests\Feature\Race\StressParentWorkflow` starts a variable number of child workflows
- `\Tests\Feature\Race\StressChildWorkflow` starts a variable number of activities
- The activity just writes a line to the log.

The `RaceConditionTest.php` defaults to `100` child workflows with each having `10` activities.

Running `./vendor/bin/phpunit --filter RaceConditionTest` now reliably leads to a stall.

Some interesting things:

### Running with 2 workers

- not a single row in `workflow_logs` has an `index` > 3
- not a single workflow completes
- RuntimeException: Race run 123456 did not complete before timeout. Current status: `Workflow\States\WorkflowWaitingStatus`

```
SELECT
	count(*) AS num_logs,
	MAX(`index`)
FROM
	workflow_logs;

| num_logs | max_index |
|----------|-----------|
|      329 |         3 |

SELECT
	`index`,
	COUNT(*) AS num_logs
FROM
	workflow_logs
GROUP BY
	`index`
ORDER BY
	stored_workflow_id;

| index | num_logs |
|-------|----------|
|     0 |      100 |
|     1 |      100 |
|     2 |      100 |
|     3 |       29 | -- the number varies

```


### Running with 3 workers

- not a single row in `workflow_logs` has an `index` > 5
- not a single workflow completes
- RuntimeException: Race run 123456 did not complete before timeout. Current status: `Workflow\States\WorkflowWaitingStatus`

```
| num_logs | max_index |
|----------|-----------|
|      529 |         5 |

| index | num_logs |
|-------|----------|
|     0 |      100 |
|     1 |      100 |
|     2 |      100 |
|     3 |      100 |
|     4 |       97 | -- the number varies
|     5 |       32 | -- the number varies
```

### Running with 4 workers

- not a single row in `workflow_logs` has an `index` > 7
- not a single workflow completes
- RuntimeException: Race run 123456 did not complete before timeout. Current status: `Workflow\States\WorkflowWaitingStatus`


```
| num_logs | max_index |
|----------|-----------|
|      707 |         7 |

| index | num_logs |
|-------|----------|
|     0 |      100 |
|     1 |      100 |
|     2 |      100 |
|     3 |      100 |
|     4 |      100 |
|     5 |       98 | -- the number varies
|     6 |       91 | -- the number varies
|     7 |       18 | -- the number varies
```

### Running with 5 workers

- at least one activity with `index = 9` is logged
- not a single workflow completes
- RuntimeException: Race run 123456 did not complete before timeout. Current status: `Workflow\States\WorkflowWaitingStatus`

```
| num_logs | max_index |
|----------|-----------|
|      852 |         9 |

| index | num_logs |
|-------|----------|
|     0 |      100 |
|     1 |      100 |
|     2 |      100 |
|     3 |      100 |
|     4 |      100 |
|     5 |      100 |
|     6 |      100 |
|     7 |       91 |
|     8 |       56 |
|     9 |        5 |
```

### Running with 6 workers

- the first child workflows can be `completed` before the stall
- RuntimeException: Race run 123456 did not complete before timeout. Current status: `Workflow\States\WorkflowPendingStatus`

```
| num_logs | max_index |
|----------|-----------|
|      997 |        90 |

| index | num_logs |
|-------|----------|
|     0 |      100 |
|     1 |      100 |
|     2 |      100 |
|     3 |      100 |
|     4 |      101 |
|     5 |      100 |
|     6 |      100 |
|     7 |      100 |
|     8 |       98 |
|     9 |       77 |
|    17 |        1 |
|    18 |        1 |
|    22 |        1 |
|    23 |        1 |
|    26 |        1 |
|    29 |        1 |
|    33 |        1 |
|    41 |        1 |
|    44 |        1 |
|    52 |        1 |
|    53 |        1 |
|    57 |        1 |
|    62 |        1 |
|    63 |        1 |
|    70 |        1 |
|    73 |        1 |
|    77 |        1 |
|    81 |        1 |
|    84 |        1 |
|    86 |        1 |
|    90 |        1 |

SELECT
	count(*) AS num_workflows,
	`status`
FROM
	workflows
GROUP BY
	(`status`)

| num_workflows | status    |
|---------------|-----------|
|            51 | completed |
|            37 | pending   |
|            13 | waiting   |
```

I assume we need at least `ceil($NUMBER_OF_PARALLEL_CHILD_WORKFLOWS / 2)` workers to even have a chance to complete some workflows.

The distribution of completed/pending/waiting workflows varies, but it stays in the same area


### Running with 10 workers

- WAY more child workflows get completed.
- Most of the time, ALL child workflows get completed; only the parent then hangs in `pending`
- But there are runs, where a child also stays `pending`/`waiting`

```
| num_workflows | status    |
|---------------|-----------|
|            97 | completed |
|             4 | pending   | -- mostly a single digit
```

### Running with 20 workers

- on very few occasions, all workflows complete successfully: even then there are leftover jobs in the laravel queue
- most of the time, only the parent workflow hangs (subjectively: this scenario happens more often compared to running with 10 workers)
