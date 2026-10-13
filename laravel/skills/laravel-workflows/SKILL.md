---
name: laravel-workflows
description: Long-running processes are Durable Workflow workflows that only sequence; each step is an activity calling an action. Use when writing or starting a workflow or activity, or deciding between a workflow and a chain of jobs.
---

# Laravel Workflows

A workflow is a long-running process on Durable Workflow (`durable-workflow/workflow` ^2.0, formerly the now-abandoned `laravel-workflow/laravel-workflow`). It decides when things happen and nothing else: which step runs next, how long to wait, which signal to wait for. Each step that does work is an activity that calls an action, so an operation runs the same whether a controller or a workflow triggers it. This skill holds the decisions made so far; where it is silent, follow the project's own conventions.

## Rules

- **Reach for a workflow when the process waits.** On a signal from outside, or a timer measured in days. Steps that only need to run in order, retry on their own or report progress are a chain or batch of jobs, each calling its action ([laravel-jobs](../laravel-jobs/SKILL.md)).
- **A workflow only sequences.** What runs when, the waits, the signals. It never writes state, never reads the database to choose its next step, and never decides an outcome. Writes and decisions belong to the actions it runs and, under event sourcing, the aggregate ([laravel-event-sourcing](../laravel-event-sourcing/SKILL.md)), so every business rule lives in one place whichever path triggers the operation.
- **Every step that does work is an activity calling one action.** The same action a controller would call ([laravel-actions](../laravel-actions/SKILL.md)). The activity's `handle()` takes the workflow's arguments first and the action after them, named after its class; the engine resolves the action from the container.
- **Models cross the boundary, not ids.** Workflow and activity arguments are typed models, `handle(Invoice $invoice)`. The engine stores the model's identifier and fetches the row again when the step runs, as `SerializesModels` does for a job, so each step sees current data.
- **Under event sourcing, reactors start and signal workflows.** A reactor on the stored event that begins the process starts the workflow; a reactor on the event a waiting workflow needs signals it. Starting or signalling a workflow is a side effect, and side effects belong in reactors, which a replay never re-runs.
- **Engine mechanics come from the package's docs.** Timers, signals, and starting or loading a workflow: read the [Durable Workflow docs](https://durable-workflow.com/docs/installation/) ([durable-workflow.com](https://durable-workflow.com)). The v2 API lives under `Workflow\V2`; examples written for the old package don't apply.

## Example

The workflow sends a reminder, waits seven days, then issues a final notice:

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Models\Invoice;
use App\Workflows\Activities\IssueFinalNoticeActivity;
use App\Workflows\Activities\RemindInvoiceActivity;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\days;

class CollectInvoicePaymentWorkflow extends Workflow
{
    public function handle(Invoice $invoice): void
    {
        activity(RemindInvoiceActivity::class, $invoice);

        days(7);

        activity(IssueFinalNoticeActivity::class, $invoice);
    }
}
```

Each activity calls the action it's named after:

```php
<?php

declare(strict_types=1);

namespace App\Workflows\Activities;

use App\Actions\Invoice\RemindInvoiceAction;
use App\Models\Invoice;
use Workflow\V2\Activity;

class RemindInvoiceActivity extends Activity
{
    public function handle(Invoice $invoice, RemindInvoiceAction $remindInvoiceAction): void
    {
        $remindInvoiceAction($invoice);
    }
}
```

A reactor starts the workflow when the invoice is issued:

```php
<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\Invoice\InvoiceIssued;
use App\Models\Invoice;
use App\Workflows\CollectInvoicePaymentWorkflow;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Workflow\V2\WorkflowStub;

class InvoiceIssuedReactor extends Reactor
{
    public function onInvoiceIssued(InvoiceIssued $event): void
    {
        $invoice = Invoice::query()->where('uuid', $event->aggregateRootUuid())->firstOrFail();

        WorkflowStub::make(CollectInvoicePaymentWorkflow::class)->start($invoice);
    }
}
```

## Anti-patterns

- ❌ `if ($invoice->isPaid())` in a workflow's `handle()`. Whether the final notice still applies is the action's decision.
- ❌ A write, a query or a call to a service in a workflow, outside an activity.
- ❌ An activity that does more than call its action.
- ❌ `int $invoiceId` as a workflow or activity argument, with a lookup in `handle()`.
- ❌ A workflow for steps that never wait. Chain the jobs.

## Related

- [laravel-event-sourcing](../laravel-event-sourcing/SKILL.md) — aggregates, events and the reactors that start workflows
- [laravel-actions](../laravel-actions/SKILL.md) — the operations activities call
- [laravel-jobs](../laravel-jobs/SKILL.md) — chains and batches, for processes that don't wait
