[← Users](user-resource.md) · [Back to README](../README.md) · [Code Intelligence →](ai-code-intelligence.md)

# Agent Collaboration

This guide defines how the primary AI agent delegates complete, independently executable work to subagents in this repository.

A subagent owns its assigned scope from investigation through implementation and verification unless the task brief explicitly limits it to research, review, testing, or another narrower responsibility.

The primary agent remains responsible for orchestration, integration, conflict resolution, final verification, and communication with the user.

## Subagent orchestration policy

Use subagents conservatively.

Subagents exist to reduce context pressure, parallelize genuinely independent work, and provide specialized investigation or implementation. They must not be created merely because additional concurrency is available.

### Hard limits

- A root task may use **at most 4 subagents** unless the user explicitly requests a different limit.
- The limit of 4 is a ceiling, not a target.
- Do not spawn subagents for simple or straightforward tasks.
- Prefer solving the task directly when delegation provides no clear benefit.
- Prefer 1 subagent when one independent investigation or implementation scope is sufficient.
- Prefer 2-4 subagents only when there are genuinely independent workstreams.
- Never spawn additional subagents merely to increase parallelism.
- Never spawn duplicate subagents investigating or implementing substantially the same scope.
- Do not replace a completed subagent with another unless new independent work has actually been identified.
- Prefer continuing an existing suitable subagent instead of spawning another one for closely related follow-up work.

Recommended default:

```text
Simple task:
Main Agent only

Moderate task:
Main Agent
└── 1 Subagent if useful

Complex task:
Main Agent
├── Subagent 1
└── Subagent 2

Large task with independent workstreams:
Main Agent
├── Subagent 1
├── Subagent 2
├── Subagent 3
└── Subagent 4
```

Never create four subagents simply because four slots are available.

### Nested delegation

Subagents MUST NOT spawn other subagents.

Only the root/main agent may create subagents.

Every subagent task brief must explicitly include:

> Do not spawn additional subagents. Complete the assigned task yourself.

The intended hierarchy is always:

```text
Main Agent
├── Subagent
├── Subagent
├── Subagent
└── Subagent
```

The following is forbidden:

```text
Main Agent
└── Subagent
    └── Subagent
        └── Subagent
```

### Before spawning

Before creating a subagent, the primary agent must verify that:

1. The work is sufficiently independent to delegate.
2. The scope can be clearly bounded.
3. No existing subagent is already responsible for substantially the same work.
4. Delegation provides meaningful benefit over completing the work directly.
5. The expected benefit justifies the additional token, tool, and execution cost.
6. The work can be performed without unsafe write conflicts with other active agents.

If these conditions are not satisfied, do not spawn a new subagent.

### Token and cost discipline

Subagents consume independent model context and tool execution.

The primary agent must therefore:

- prefer the smallest useful number of subagents;
- avoid assigning the same background investigation to multiple agents;
- provide focused starting context rather than asking every subagent to rediscover the entire project;
- give each subagent explicit files, symbols, services, or questions when known;
- avoid broad prompts such as "analyze the whole project" unless that breadth is genuinely required;
- request concise completion reports instead of unnecessary raw logs;
- avoid spawning a new agent for a task that can be completed by extending an existing agent's assignment; and
- stop delegating when sufficient evidence already exists to complete the task safely.

### Lifecycle

When a subagent completes its assigned task:

1. collect its completion report;
2. inspect the resulting changes or evidence;
3. close/release the agent when supported;
4. do not automatically create a replacement agent;
5. integrate the result with findings from other agents;
6. resolve contradictions before implementation or final reporting.

A completed subagent's conclusion is evidence, not automatically accepted truth.

### Default strategy

For a complex development task, a reasonable maximum decomposition is:

```text
Main Agent
├── Subagent 1: primary investigation or backend scope
├── Subagent 2: independent subsystem or database scope
├── Subagent 3: verification, UI, testing, or review if necessary
└── Subagent 4: independent implementation scope if necessary
```

These roles are examples only.

Do not automatically assign all four roles. The primary agent should create only the agents required by the actual task.

A subagent may already own investigation, implementation, and verification for its bounded scope. A separate implementation subagent is unnecessary when an existing subagent can safely complete the work end to end.

## Roles and authority

| Role          | Responsibility                                                                                                                                                                                                                                                                                    |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Primary agent | Understands the user's goal, performs initial reconnaissance, divides work into bounded tasks, controls subagent count, assigns ownership, integrates and reviews results, resolves conflicts, performs final verification, reports to the user, and is the only agent allowed to run `git push`. |
| Subagent      | Completes its assigned work within the defined scope, uses available project tools, may modify authorized files or services, verifies its result, and reports evidence and remaining risks to the primary agent.                                                                                  |

Subagents are full contributors, not inherently read-only reviewers.

However, their authority is limited by the task brief.

A subagent may be assigned as:

```text
research-only
implementation
review
testing
database
UI/runtime investigation
infrastructure
documentation
```

If the task brief states that the assignment is read-only or research-only, the subagent must not modify project files, database state, services, Git state, or external systems.

Within their assignment and the current session's available tools and permissions, subagents may:

- analyze requirements;
- read source code and documentation;
- search with `rg`, Serena/LSP, ast-grep, Tree-sitter, and the project index;
- collect evidence from PostgreSQL and configured services while respecting project scope and read/write authorization;
- inspect Docker services, PHP-FPM, NGINX, application servers, queues, and other runtime components when tools and permissions are available;
- use host and container utilities, package managers, Node.js, Python, PHP, and other installed tools;
- work with the shared `ai-code-intelligence` project to index this application, update project-scoped knowledge, or create embeddings when explicitly included in the assignment and authorized;
- inspect browser/UI/runtime state using the configured browser tooling;
- create, edit, or delete files within the assigned write scope; and
- use Git to inspect changes and, when explicitly included in the assignment, stage and commit them.

**Subagents must never run `git push`, in any form.**

This includes:

```text
git push
git push --force
git push --force-with-lease
pushing tags
pushing branches
pushing refs
pushing mirrors
```

Only the primary agent may push after reviewing the integrated result and confirming that the user's request authorizes the operation.

Delegation does not expand a session's access.

Agents must use only the tools and permissions actually available to them and must not bypass a denied command, sandbox restriction, authentication boundary, or approval gate.

If a task requires unavailable access or explicit approval, the subagent must report the blocker and a safe next step to the primary agent.

## Delegation protocol

Each subagent task brief must state:

1. The desired outcome.
2. The exact owned scope.
3. Whether the assignment is read-only or permits writes.
4. The files, modules, symbols, services, or operational responsibility involved when known.
5. Relevant evidence and constraints already discovered by the primary agent.
6. Required verification.
7. Expected completion-report format.
8. Whether Git staging or a commit is part of the assignment.
9. The instruction that the subagent must not spawn other subagents.
10. The instruction that `git push` is forbidden.

A good task brief should resemble:

```text
Goal:
Determine why duplicate payment records can be created.

Scope:
- PaymentService
- payment repository/model
- payment-related migrations
- transaction and idempotency behavior

Authorization:
Research only. Do not modify files or database state.

Use:
- Serena/LSP
- rg
- PostgreSQL project index
- Git history where useful

Do not:
- spawn subagents
- modify files
- modify database state
- commit
- push

Return:
- status
- findings
- evidence
- relevant files/symbols
- verification performed
- risks
- unresolved questions
```

## Concurrent work and write scopes

Concurrent subagents should have disjoint write scopes.

If two tasks must touch the same file, configuration, database object, generated artifact, or shared service:

1. sequence the work; or
2. explicitly divide ownership into non-overlapping regions when that is demonstrably safe.

Agents may share the same checkout.

Edits made by one agent may therefore become immediately visible to other agents.

Never assume that a subagent has a private Git worktree unless a separate worktree was explicitly created for it.

Before modifying files, every writing subagent must:

```bash
git status --short
```

and inspect relevant existing changes.

Subagents must:

- preserve user changes;
- preserve unrelated changes created by other agents;
- never reset the repository to obtain a clean tree;
- never restore or overwrite files outside their assigned scope;
- never use destructive Git commands to remove another agent's changes;
- re-check affected files before writing if another agent may have modified nearby code.

If a write conflict is discovered, stop editing the conflicting scope and report it to the primary agent.

## Shared data and services

### AI Code Intelligence database

Database operations must be scoped to the correct project.

For this repository, resolve the project row using the project slug:

```text
strateg-export-products
```

before performing project-scoped database operations.

Do not assume that the slug itself is the UUID `project_id`.

Resolve the actual project identifier first, for example conceptually:

```sql
SELECT id
FROM aic_projects
WHERE slug = 'strateg-export-products'
  AND active = TRUE;
```

Use the resulting `project_id` for subsequent operations.

Follow [AI Code Intelligence](ai-code-intelligence.md) and the shared repository's operational guidance.

### Database write authorization

Subagents may perform database writes only when:

- the assignment explicitly requires them;
- the correct project has been resolved;
- the affected tables are within the assignment's authority;
- the operation preserves project scoping and referential integrity; and
- the write is necessary to complete the assigned work.

Read-only investigation must not mutate:

```text
project data
indexed symbols
relations
chunks
embeddings
knowledge
application data
```

unless explicitly authorized.

### Embeddings and paid operations

Keep credentials, local environment files, private data, and secrets out of:

```text
logs
indexed content
knowledge items
embedding inputs
agent reports
```

Before retrying embedding generation or another paid external operation:

1. inspect existing indexing/embedding state;
2. determine whether the work has already completed;
3. avoid regenerating unchanged embeddings;
4. avoid duplicate batches;
5. report material paid operations to the primary agent.

### Runtime services

Report material side effects such as:

```text
database writes
index refreshes
package installations
dependency upgrades
external API requests
Docker restarts
web-server restarts
queue restarts
migration execution
cache flushes
```

Do not restart shared services merely as a troubleshooting shortcut when a narrower diagnostic step is available.

## Browser and UI ownership

Browser automation may use a shared logged-in browser session.

Only **one subagent at a time** should control a shared interactive browser session unless separate isolated browser contexts have explicitly been created.

The active browser-owning agent must report material state changes, including:

```text
navigation
authentication/login/logout
submitted forms
created/modified application data
session changes
cookies/session-dependent state
viewport or device-mode changes when relevant
```

Other agents may inspect source code concurrently but should not manipulate the same browser session.

For UI/runtime investigation, use the project's configured browser tooling according to the UI intelligence guidance.

Browser observations should be correlated with source code before making changes:

```text
runtime UI
    ->
DOM / styles / network / console
    ->
owning component/template
    ->
source code
```

## Git handoff

Subagents may inspect:

```text
git status
git diff
git log
git show
git blame
branches
commits
```

They may stage and commit only when the task brief explicitly authorizes those operations.

Before staging:

1. inspect staged changes;
2. inspect unstaged changes;
3. include only files belonging to the assigned scope;
4. verify that unrelated user or agent changes are not included.

If a commit is created, report its hash to the primary agent.

Subagents must never push.

The primary agent reviews and integrates the work and alone handles any authorized remote push.

## Required completion report

At task completion, every subagent must return a concise structured report.

### Status

One of:

```text
complete
partial
blocked
```

### Summary

Describe what was changed, discovered, or verified.

### Evidence

Include relevant evidence such as:

```text
file paths
line numbers
symbols
database observations
service/runtime observations
browser/runtime observations
reproducible behavior
Git commits/history
```

Do not include unnecessarily large raw outputs when a concise summary and exact reference are sufficient.

### Files

List files:

```text
created
modified
deleted
```

If no files changed, explicitly state that.

### Verification

Report:

- commands executed;
- tests performed;
- manual/runtime checks;
- relevant successful results;
- failed checks;
- checks that were not performed.

Never imply that an unperformed verification passed.

### Side effects

Report material side effects, including:

```text
database writes
package installations
service restarts
external API calls
embedding generation
index updates
application data modifications
```

If none occurred, state that.

### Git

Report:

- commit hash if applicable;
- staged state if relevant; and
- explicit confirmation that no `git push` was performed.

### Open items

List:

```text
remaining risks
unresolved questions
blocked work
follow-up recommendations
integration concerns
```

The primary agent reviews the report and resulting changes, resolves integration issues, performs the necessary final verification, and gives the user a consolidated result.

A subagent's completion report is evidence for review, not a substitute for primary-agent integration.

## Primary-agent integration rules

After subagents complete their work, the primary agent must:

1. collect all required reports;
2. inspect relevant changes directly;
3. compare overlapping or contradictory findings;
4. resolve conflicts;
5. verify that work stayed within assigned scopes;
6. run or coordinate final integration checks;
7. inspect the final Git diff;
8. ensure no required work remains delegated but unfinished;
9. update persistent project knowledge only when findings are sufficiently verified; and
10. report one consolidated result to the user.

The primary agent must not blindly combine subagent recommendations.

When reports disagree, inspect the underlying evidence and determine which conclusion is supported by the repository or runtime behavior.

## See Also

- [Architecture](architecture.md) — application structure and boundaries
- [AI Code Intelligence](ai-code-intelligence.md) — project-scoped retrieval and indexing