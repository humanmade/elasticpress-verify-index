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
composer require humanmade/elasticpress-verify-index:dev-main
```

As a plugin, by copying this repository into the plugins folder and activating it.

To run it once without installing it, load it with `--require`:

```sh
wp --require=path/to/elasticpress-verify-index/plugin.php elasticpress verify-index
```

## Usage

```sh
wp elasticpress verify-index [--sample=<percent>] [--check=<check>] [--format=<format>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--sample` | `10` | Percentage of posts and documents to check, more than 0 and at most 100. |
| `--check` | `both` | `database`, `index` or `both`. |
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

If ElasticPress is still indexing the site, the command warns you, because posts may not be indexed yet.

## Fixing problems

For "missing from index" and "mismatch" problems, reindex the affected posts, then run the check again:

```sh
wp elasticpress sync --include=1224,1225,1226
```

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
