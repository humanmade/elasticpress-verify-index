# Review

## What failure looks like here
- A failed or partial Elasticsearch request reported as a "missing" document, or any failure that still exits 0.
- False positives: the indexable-ID list drifts from what ElasticPress really indexes, so healthy sites fail the check.
- Output that leaks post content (titles, slugs, names) instead of post IDs only.
- Machine formats (`--format=json|csv`) that are not parseable on STDOUT.

## Always check
- The indexable-ID query against `query_db()` in ElasticPress `includes/classes/Indexable/Post/Post.php` and the per-object skip in `IndexHelper::should_skip_object_index()` (`ep_post_sync_kill` and friends).
- Every `request()` caller handles `null` as a hard error (`WP_CLI::error`), never as "not found".
- Post IDs compared as ints on both sides (DB rows come back as strings, ES `_id` is a string).
- Behaviour on an empty site, a missing index, and samples over the 10,000 search window.
- README options, examples and fix advice match the code and what ElasticPress commands really do.

## Paths that need a person
- None yet. The plugin has no auth, payments, migrations or CI workflows.

## Skip
- `vendor/`, `composer.lock`, `.phpunit.result.cache`.

## Nits
- Cap 3. Code style is covered by `composer lint` (HM phpcs); don't repeat phpcs findings.

## Learned
- 2026-11-11: Per-post filters like `ep_post_sync_kill` load the full post (SyncManager `kill_sync_for_password_protected` calls `get_post`). Run them on the sampled IDs, never on the full indexable list.
