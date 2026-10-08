[← Previous Page](ai-code-intelligence.md) · [Back to README](../README.md) · [Next Page →](ai-agent-collaboration.md)

# Incremental AI Knowledge Refresh

Use this workflow to refresh the shared `ai-code-intelligence` index without reprocessing unchanged project data. The matching [ai-update-knowledge skill](../.codex/skills/ai-update-knowledge/SKILL.md) repeats these operational steps for AI agents.

## Principles

- Scope every database operation to the project resolved by its stable slug.
- Compare source hashes and manifests before writing or making paid embedding calls.
- Finalize code, documentation, and knowledge text before chunking. A text edit after embedding changes its hash and requires another embedding.
- Reuse valid chunk IDs, vectors, and completed result files. Do not re-embed unchanged content.
- Record what parsers established and what remains partial. Never invent symbols or relations to fill gaps.
- Keep batch files and manifests in the configured persistent project directory, not container `/tmp`.

## 1. Preflight once

Read this project's [AI Code Intelligence guide](ai-code-intelligence.md), its `AGENTS.md`, and the shared AIC `docs/getting-started.md`, `docs/database.md`, `docs/retrieval-and-indexing.md`, and `docs/security.md`. Confirm the current PostgreSQL service, schema, embedding script path, and persistent volume from those sources.

Resolve `strateg-export-products` to its active project ID in the shared database. Read the latest completed indexing run, project/model counts, file and chunk hashes, relation coverage, HNSW state, and saved manifests. Check `git status`, current `HEAD`, and the changes since the commit recorded by the index. Include any requested, uncommitted documentation or knowledge edits explicitly; Git diff alone does not include untracked files.

Use one compact project-scoped inventory where practical. Do not repeat broad table scans or print source text, credentials, database environment values, or embedding vectors into logs.

## 2. Freeze the refresh input

Before computing hashes:

1. Complete all requested source, documentation, README, link, and knowledge edits.
2. Avoid volatile documentation counters such as the number of batch files; use stable facts and add a commit identifier when a snapshot is useful.
3. Select changed, indexable text files only. Exclude `.env`, credentials, private configuration, dependencies, generated output, runtime data, binaries, and files outside the approved project scope.
4. Record the exact file list, source filters, parser versions, and chunking settings for the run.

Do not edit those inputs until the run is complete. If an edit is necessary, recompute that file's chunks and hash and refresh its embedding before marking the run complete.

## 3. Parse only what changed

- Use `rg` for exact text and paths, Tree-sitter for syntax-aware boundaries and direct syntax evidence, and Serena/LSP when semantic definitions or references are needed.
- Reuse valid Serena caches. Do not index the whole project for a small incremental update. Run the project-wide Serena index only when its cache is absent or broadly stale; target individual files only when LSP evidence is needed for those files.
- Batch Tree-sitter parsing over the changed-file list in one host-side pass where supported. Do not start concurrent writers against the same Serena cache.
- Upsert declarations while preserving stable symbol IDs when possible. If symbol IDs must be replaced, account for cascading incoming relations and rebuild affected edges.
- Refresh outgoing relations for the changed source symbols transactionally. Resolve targets only within the same project; require an unambiguous exact match. Keep dynamic or external targets in `target_qualified_name` and attach source path, line, extractor, and short evidence.
- Report parser failures, unmapped AST matches, unresolved targets, and unsupported edge types accurately.

## 4. Update project rows and knowledge

Create one indexing run with `running` status and its Git commit. In a project-scoped transaction, update file/document metadata, evidence-backed knowledge, deterministic chunks, symbols, and relation deltas. Preserve the schema's source constraints: code chunks reference files, document chunks reference documents, and knowledge chunks reference knowledge items.

Update existing knowledge items instead of creating duplicates. Use concise claims supported by current source or docs, meaningful `source_reference`, confidence appropriate to the evidence, and a linked knowledge chunk. Mark replaced facts `superseded`; keep current facts `active`.

For chunks, compare `(source type, source row, chunk type, sequence, content hash)`. Upsert changed chunks, preserve IDs where possible, and remove only obsolete sequences for the exact changed source. Never broadly clear project tables.

## 5. Generate only missing embeddings

Get candidates by joining project chunks to embeddings on the registered model and requiring a matching `source_content_hash`. Generate embeddings only for chunks with no matching vector. Inspect existing manifests first and reuse completed batch outputs after a failed database stage.

Embedding calls use the OpenAI API and can incur cost. Proceed only when the user's request authorizes embedding generation; an index-only request does not automatically authorize paid embedding calls.

Before calling the API, write a manifest containing project ID/slug, run ID, commit, model ID/name, dimensions, batch filenames, chunk IDs, and content hashes. Use stable unique filenames under:

```text
/home/kamaz/www/hobby/ai-code-intelligence/app/embedding-results/strateg-export-products/{year}/{month}/
```

Read the exact embedding script path from the shared getting-started guide. In the current setup it is `/app/embedding.js` inside the AIC PostgreSQL container. Call that script directly with the configured model. Keep the API key in the container environment, suppress stdout, and keep captured output private. Do not copy keys or submitted source text into logs or reports.

Insert vectors with the matching project, chunk, model, dimension, and source hash. Reuse the project/model HNSW index if it exists; create it only when missing and with the documented owner role. Do not combine owner-only index creation with ordinary vector inserts.

## 6. Verify and close the run

Before setting status to `completed`, verify:

- all file, document, knowledge, symbol, relation, chunk, and embedding writes are scoped to the resolved project;
- current chunk hashes match the source and every indexed chunk has a vector for the registered model and expected dimensions;
- relation counts and unresolved-target samples agree with the parser results;
- the project/model HNSW index exists;
- the saved manifest matches the result files and database chunk IDs/hashes;
- the indexing run contains accurate counts, parser notes, limitations, and a completion timestamp.

If a stage fails, leave the run incomplete or mark it failed, inspect committed rows and saved manifests, and resume from the failed stage. Never regenerate successful paid batches just because a later SQL step failed.

## Time-saving checklist

| Avoid                                                   | Do this                                                                                                  |
|---------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| Reindexing every file                                   | Compare the indexed commit and hashes; refresh only changed paths                                        |
| Calling Serena separately for every Vue file by default | Reuse its cache; use one project pass only when broadly stale, and target LSP work to files that need it |
| Editing docs after embeddings                           | Finalize docs and links before calculating chunk hashes                                                  |
| Embedding all chunks again                              | Query for missing or hash-mismatched vectors by model                                                    |
| Repeating many ad hoc SQL reads                         | Run one scoped preflight and one compact verification set                                                |
| Retrying a paid batch blindly                           | Check its manifest and persistent result file, then reuse it                                             |

## See Also

- [AI Code Intelligence](ai-code-intelligence.md) — shared tools, database, and retrieval
- [AI Agent Collaboration](ai-agent-collaboration.md) — work ownership and delegation
- [AI Update Knowledge skill](../.codex/skills/ai-update-knowledge/SKILL.md) — executable agent workflow