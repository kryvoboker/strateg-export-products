[← Agent Collaboration](agent-collaboration.md) · [Back to README](../README.md)

# AI Code Intelligence

This project is indexed in the shared [AI Code Intelligence repository](../../../hobby/ai-code-intelligence). Its PostgreSQL database stores project-scoped source metadata, searchable chunks, and embeddings. Use it to discover likely files and concepts; verify every conclusion against this repository's source and, where relevant, tests or runtime behavior.

## Project identity and shared documentation

| Item                | Value                                         |
|---------------------|-----------------------------------------------|
| Display name        | Strateg Export Products                                    |
| Stable project slug | `strateg-export-products`                                  |
| Shared repository   | `/home/kamaz/www/hobby/ai-code-intelligence/` |
| Embedding model     | `text-embedding-3-small` (1536 dimensions)    |

Start with the shared repository's [AGENTS.md](../../../hobby/ai-code-intelligence/AGENTS.md) and [README](../../../hobby/ai-code-intelligence/README.md). Use its focused guides for [setup](../../../hobby/ai-code-intelligence/docs/getting-started.md), [architecture](../../../hobby/ai-code-intelligence/docs/architecture.md), [database schema](../../../hobby/ai-code-intelligence/docs/database.md), [retrieval and indexing](../../../hobby/ai-code-intelligence/docs/retrieval-and-indexing.md), [agent workflow](../../../hobby/ai-code-intelligence/docs/agent-workflow.md), [security](../../../hobby/ai-code-intelligence/docs/security.md), and [integrations](../../../hobby/ai-code-intelligence/docs/integrations.md). The shared repository is a separate project; do not edit or reinitialize it as part of ordinary work here.

## Pick a tool for the question

| Question                                         | First tool                                                      |
|--------------------------------------------------|-----------------------------------------------------------------|
| Exact route, text, config key, or error          | `rg`                                                            |
| Known PHP symbol, definition, or references      | Serena/LSP                                                      |
| Repeated syntax pattern or safe AST rewrite      | `ast-grep`                                                      |
| Syntax-aware parse or chunk boundaries           | Tree-sitter                                                     |
| Unfamiliar business concept or design rationale  | PostgreSQL full-text plus vector search                         |
| Changed behavior in history                      | Git log/blame/diff                                              |
| Rendered UI, browser console, network, or layout | `cua_repl` / `node_repl`; Chrome DevTools MCP is an alternative |
| Proof after a code change                        | Relevant PHPUnit, static-analysis                               |

AI Factory skills such as `$aif-warmup`, `$aif-explore`, `$aif-plan`, `$aif-fix`, `$aif-implement`, `$aif-verify`, and `$aif-review` guide project work; they are not source search engines. Follow project conventions in [AGENTS.md](../AGENTS.md) and run app commands through the matching Docker service.

## Host tools and invocation

PostgreSQL and Node.js for the intelligence database run in Docker. Serena, `ast-grep`, and Tree-sitter Python bindings are host-side tools; a missing executable inside a container says nothing about host availability. Check the host before use:

```bash
/home/kamaz/.venv/bin/ast-grep --version
serena --version
/home/kamaz/.venv/bin/python -c 'from tree_sitter_language_pack import get_parser; print(get_parser("php"))'
```

Use the Python interpreter that actually has `tree_sitter_language_pack` installed if it differs from the example. For structural PHP search:

```bash
/home/kamaz/.venv/bin/ast-grep run --lang php --pattern 'class $NAME extends $BASE' httpdocs
```

For exact text, route, or symbol-name discovery:

```bash
rg -n 'ComplaintPolicy|complaints.store' httpdocs
```

For Serena, inspect the installed CLI first with `serena --help`; the documented setup exposes `serena project index` for indexing. Its first PHP run may download a language server. A DNS/registry failure is a tooling setup failure, not evidence that the PHP source is invalid. Tree-sitter's PHP parser name is `php`; smoke-test a representative file before a batch. If parser traversal fails, record the parser/version and fallback, and do not call line-based chunks syntax-aware.

## Search the shared index

The shared Compose file is `/home/kamaz/www/hobby/ai-code-intelligence/.docker/dev/docker-compose.yml`; the PostgreSQL service is `ai-code-intelligence-postgres-postgresql`. Resolve the current project ID by its slug in each session—never copy an ID from another project or search across tenants:

```bash
docker compose -f /home/kamaz/www/hobby/ai-code-intelligence/.docker/dev/docker-compose.yml \
  exec -T ai-code-intelligence-postgres-postgresql sh -lc \
  'psql -v ON_ERROR_STOP=1 -P pager=off -U "$POSTGRES_SIMPLE_USER" -d "$POSTGRES_DB" \
  -c "SELECT id, slug FROM aic_projects WHERE slug = '\''strateg-export-products'\'';"'
```

Use the returned `project_id` with the verified helpers in `docker-entrypoint-initdb.d/004_views_and_search_functions.sql` and `005_vector_index_helpers.sql`:

```sql
SELECT * FROM aic_search_chunks_text(:project_id, 'complaint evidence upload', 10);
SELECT * FROM aic_search_symbols_fuzzy(:project_id, 'ComplaintController', 10);
SELECT * FROM aic_search_chunks_vector_exact(:project_id, :model_id, :query_vector, 10);
SELECT * FROM aic_search_chunks_vector_hnsw(:project_id, :model_id, :query_vector, 10);
```

The vector query must use the registered model's dimensions (1536 here) and both the resolved project and model IDs. FTS and fuzzy symbol search need only the project ID. Vector results are ranked candidates, not proof of call paths or behavior; open the referenced source files and use Serena, `rg`, tests, and Git to verify. The index does not currently provide populated `aic_symbol_relations`, so do not rely on graph traversal for this project.

## What is indexed and what it can tell you

The verified full index for this checkout completed on 2026-10-04. Its project row is separate from the older `strateg-export-products` project and points to this repository root:

`/home/kamaz/www/strateg-projects/strateg-export-products.com`

The project-scoped index reports:

| Data                                  | Count / status |
|---------------------------------------|---------------:|
| Indexed file rows                     |          1,264 |
| Symbol rows                           |          4,020 |
| Chunks / current embeddings           |  4,343 / 4,343 |
| Documents / active knowledge items    |         15 / 4 |
| Stale or missing embeddings           |              0 |
| Project/model HNSW index              |        Present |
| Symbol relations                      |  Not populated |
| Embedding batches / generated vectors |    136 / 4,343 |

The index covers first-party PHP source, tests, project documentation, selected safe metadata, and the OpenAPI specification. It excludes `.env` and local/runtime configuration, credentials, `vendor`, XHProf, generated/runtime data, uploads, static web assets, Codeception output, captured request snapshots, generic `.codex` skill files, and `ai-chat.txt`. Nine source chunks had credential-like literals redacted before chunking; application files were not changed. Serena indexed 1,264 PHP files locally. The four active knowledge items summarize architecture/request flow, the separate Ukraine and Poland database connections, integration authentication boundaries, and unresolved test contracts.

Chunks provide full-text and vector retrieval; symbol records help locate declarations. Source chunks use non-overlapping 80-line windows. Tree-sitter parsed the PHP sources without reported parse failures and extracted declaration symbols; stored symbol ranges use declaration lines, not full AST extents. `aic_symbol_relations` is empty, so the index cannot establish call or dependency paths. PostgreSQL FTS reports that words longer than 2,047 characters are ignored. Treat search results as leads and verify behavior in source and tests.

The 4,343 current embeddings use `text-embedding-3-small` at 1536 dimensions. Batch results and chunk/hash mapping are persisted under `/home/kamaz/www/hobby/ai-code-intelligence/app/embedding-results/strateg-export-products/`; the manifest is `strateg-export-products-full-20261004-manifest.json`. The project/model HNSW index is present, and verification found zero missing or stale vectors. Counts may become stale after later source changes.

## Refreshing the index and paid embeddings

Do not refresh embeddings unless the task authorizes the OpenAI API cost. Before any refresh, inspect project/chunk counts, content hashes, model registration, indexing-run status, and existing result files. Exclude credentials, `.env` files, runtime/generated data, and any source not approved for indexing. Embeddings and batch files are persistent source-derived data.

The embedding utility is `/app/embedding.js` in the shared database container. It currently accepts `<api-key> <result-file> <model> <text> [text...]`, prints the API key and input text to stdout, and can incur API charges. Suppress stdout and keep captured logs private. Store outputs in the persistent host directory `/home/kamaz/www/hobby/ai-code-intelligence/app/embedding-results/strateg-export-products/{year}/{month}/` (mounted at `/app/embedding-results/strateg-export-products/{year}/{month}/`), never only in container `/tmp`. For new batches, save a manifest mapping every result file to chunk IDs, content hashes, model, and dimensions. Reuse completed result files after DB/index failures; regenerate only when source hashes or model changed.

Safe database sequence: resolve the slug and model; persist project-scoped files, symbols, documents, knowledge, and chunks; persist embedding result files; insert and commit vectors; create the HNSW index; verify counts and index; then mark the indexing run `completed`. `$POSTGRES_SIMPLE_USER` is intended for ordinary scoped reads/writes but may not own `aic_embeddings`; index creation may require `$POSTGRES_USER` (the table owner). Do not combine vector inserts and owner-only index creation in one transaction. For partial failures, inspect saved result files, counts, and run status and resume at the failed stage—do not broadly delete project data or re-embed unchanged chunks.

## See Also

- [Project architecture](architecture.md) — application boundaries and data flow
- [Agent Collaboration](agent-collaboration.md) — delegation and shared-workspace rules
- [Shared retrieval and indexing guide](../../../hobby/ai-code-intelligence/docs/retrieval-and-indexing.md) — authoritative operational details