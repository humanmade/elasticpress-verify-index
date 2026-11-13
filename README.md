# ElasticPress Verify Index

A WP-CLI command that checks a random sample of posts against the ElasticPress index. Use it after a reindex, a database sync or a migration to check that search is complete and up to date.

ElasticPress can tell you how many documents an index has, but matching counts can still hide documents that are missing, out of date or left over. This command compares the actual fields.

## What it checks

For the current site, it compares these fields in the database and in Elasticsearch:

- `ID`
- `post_name` (the slug)
- `post_title`
- `post_type`
- `post_status`

It samples from both sides:

- **Database:** a random sample of the posts that ElasticPress would index. It reports posts that are missing from the index, and posts whose fields don't match.
- **Index:** a random sample of the documents in the index. It reports documents for posts that were deleted or are no longer indexable, and documents whose fields don't match.

The list of indexable posts uses the same post types, statuses, query filters and sync kill filters (`ep_post_sync_kill`) as an ElasticPress sync. Password-protected posts are left out, as ElasticPress does, unless a filter adds them.

### Terms

With `--terms`, it also compares the terms assigned to each post, in every taxonomy ElasticPress indexes for that post. A post whose document has missing, extra or different terms is reported as a mismatch, with `terms.<taxonomy>` in the fields column.

Use it when archives, filters or search depend on terms in the index, for example on a multilingual site where every query filters on a language taxonomy. A document can match on every post field and still be left out of those queries because it has lost a term.

The expected terms are read straight from the database, not through the object cache, so the check also finds documents that were indexed while the cache held out-of-date terms. It uses the same taxonomies as an ElasticPress sync (`ep_sync_taxonomies`), and expects the ancestors of each term too, unless `ep_sync_terms_allow_hierarchy` turns them off.

It compares term IDs only. It doesn't notice a term that was renamed without its posts being reindexed. If a site changes a post's terms on the way into the index, with a `get_the_terms` or `ep_post_sync_args` filter, those posts are reported as mismatches.

### Output

The command lists problems by post ID only, so its output is safe to share in a ticket. It exits with status 1 if it finds a problem, or if a request to Elasticsearch fails. A failed request is never reported as a missing document.

## Requirements

- ElasticPress with the post indexable active. Tested with ElasticPress 5.3.
- Elasticsearch 7 or later, or OpenSearch
- WP-CLI

## Installation

Install it in whichever way suits you.

As a WP-CLI package, so it's available on every site you run WP-CLI against, without changing the site's code:

```sh
wp package install humanmade/elasticpress-verify-index
```

Remove it again with `wp package uninstall humanmade/elasticpress-verify-index`.

With Composer, as part of a site's dependencies:

```sh
composer require humanmade/elasticpress-verify-index
```

As a plugin, by copying this repository into the plugins folder and activating it.

To run it once without installing it, load it with `--require`:

```sh
wp --require=path/to/elasticpress-verify-index/plugin.php elasticpress verify-index
```

## Usage

```sh
wp elasticpress verify-index [--sample=<percent>] [--check=<check>] [--terms] [--format=<format>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--sample` | `10` | Percentage of posts and documents to check, more than 0 and at most 100. |
| `--check` | `both` | `database`, `index` or `both`. |
| `--terms` | off | Also compare the terms assigned to each post. |
| `--format` | `table` | Format for the list of problems: `table`, `csv` or `json`. |

The index check returns at most 10,000 documents, the most one search request can return.

With `--format=csv` or `--format=json`, the summary lines go to STDERR, so you can pipe STDOUT to another tool.

Check 10% of one site:

```sh
wp elasticpress verify-index --url=example.com
```

Check every post on one site:

```sh
wp elasticpress verify-index --url=example.com --sample=100
```

Check every post on one site, including its terms:

```sh
wp elasticpress verify-index --url=example.com --sample=100 --terms
```

Check every site in a multisite network. Each site has its own index, so run the command once per site:

```sh
wp site list --field=url | xargs -I % wp elasticpress verify-index --url=%
```

Example output:

```
Database: checked 108 of 108 indexable posts, after 0 skipped by sync kill filters. 1 missing from the index, 2 not matching.
Index: checked 107 of 107 documents. 0 for deleted or non-indexable posts, 2 not matching.
check     ID    problem             fields
database  1224  mismatch            post_title
database  1225  missing from index
database  1226  mismatch            post_status
index     1226  mismatch            post_status
index     1224  mismatch            post_title
Error: Found 5 problems in index example-post-1.
```

With `--terms`, a post whose document has the wrong terms in a taxonomy looks like this:

```
database  1227  mismatch            terms.category
```

If ElasticPress is still indexing the site, the command warns you, because posts may not be indexed yet.

## Fixing problems

For "missing from index" and "mismatch" problems, reindex the affected posts, then run the check again:

```sh
wp elasticpress sync --indexables=post --include=1224,1225,1226
```

`--indexables=post` keeps the sync to posts. Without it, ElasticPress applies the same IDs to every active indexable, such as terms or users.

If a `terms.<taxonomy>` mismatch is still there after the sync, the object cache may hold out-of-date terms for those posts, and the sync reads terms through it. Clear the cached terms for those posts, then sync again:

```sh
wp eval 'foreach ( [ 1224, 1225, 1226 ] as $id ) { clean_object_term_cache( $id, get_post_type( $id ) ); }'
```

This clears the terms for those posts only. `wp cache flush` also works, but it empties the whole object cache, usually for every site on a multisite network.

A sync doesn't delete documents, so it can't fix "post deleted" or "post not indexable" problems. Delete those documents one at a time:

```sh
wp elasticpress request <index>/_doc/<ID> --method=DELETE
```

Or rebuild the whole index with `wp elasticpress sync --setup`. This deletes the index first, so search results are incomplete until the sync finishes.

## Development

```sh
composer install
composer lint
composer test
```

## License

GPL-2.0-or-later.
