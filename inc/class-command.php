<?php
/**
 * WP-CLI command that checks a sample of posts against the Elasticsearch index.
 *
 * @package HM\EP_Verify_Index
 */

namespace HM\EP_Verify_Index;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPress\Utils;
use WP_CLI;
use WP_CLI\Utils as CLI_Utils;
use WP_Query;

/**
 * Check that the Elasticsearch post index matches the database.
 */
class Command {

	/**
	 * Largest sample the index check can fetch in one search request.
	 */
	const MAX_INDEX_SAMPLE = 10000;

	/**
	 * Number of IDs fetched per request.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Output format for the list of problems.
	 *
	 * @var string
	 */
	protected $format = 'table';

	/**
	 * Check a random sample of posts against the Elasticsearch index for the current site.
	 *
	 * Compares the ID, slug, title, post type and post status. The database check finds posts that are
	 * missing from the index or out of date. The index check finds documents for posts that were deleted
	 * or are no longer indexable. Exits with status 1 if it finds any problems.
	 *
	 * Run it once for each site in a multisite network, with --url.
	 *
	 * ## OPTIONS
	 *
	 * [--sample=<percent>]
	 * : Percentage of posts and documents to check.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--check=<check>]
	 * : Which side to sample from.
	 * ---
	 * default: both
	 * options:
	 *   - both
	 *   - database
	 *   - index
	 * ---
	 *
	 * [--format=<format>]
	 * : Format for the list of problems.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check 10% of the posts and documents on one site.
	 *     $ wp elasticpress verify-index --url=example.com
	 *
	 *     # Check every site in a network.
	 *     $ wp site list --field=url | xargs -I % wp elasticpress verify-index --url=%
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$percent = (float) CLI_Utils\get_flag_value( $assoc_args, 'sample', 10 );
		$check   = CLI_Utils\get_flag_value( $assoc_args, 'check', 'both' );
		$format  = CLI_Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$this->format = $format;

		if ( $percent <= 0 || $percent > 100 ) {
			WP_CLI::error( 'The sample must be more than 0 and at most 100.' );
		}

		$indexable = Indexables::factory()->get( 'post' );
		if ( ! $indexable ) {
			WP_CLI::error( 'The ElasticPress post indexable is not active on this site.' );
		}

		if ( Utils\is_indexing() ) {
			WP_CLI::warning( 'ElasticPress is indexing this site now, so the results may include posts that are not indexed yet.' );
		}

		$indexable_ids = $this->get_indexable_ids( $indexable );
		$problems      = [];

		if ( $check !== 'index' ) {
			$problems = array_merge( $problems, $this->check_database_sample( $indexable, $indexable_ids, $percent ) );
		}

		if ( $check !== 'database' ) {
			$problems = array_merge( $problems, $this->check_index_sample( $indexable, $indexable_ids, $percent ) );
		}

		if ( empty( $problems ) ) {
			if ( $format !== 'table' ) {
				CLI_Utils\format_items( $format, [], [ 'check', 'ID', 'problem', 'fields' ] );
			}
			WP_CLI::success( sprintf( 'No problems found in index %s.', $indexable->get_index_name() ) );
			return;
		}

		CLI_Utils\format_items( $format, $problems, [ 'check', 'ID', 'problem', 'fields' ] );
		WP_CLI::error( sprintf( 'Found %d problems in index %s.', count( $problems ), $indexable->get_index_name() ) );
	}

	/**
	 * Get the IDs of every post ElasticPress would index on the current site.
	 *
	 * Uses the same post types, statuses and query filters as ElasticPress's own sync. The sync kill
	 * filters run later, on the sampled IDs only, because they can load each post.
	 *
	 * @param \ElasticPress\Indexable\Post\Post $indexable The post indexable.
	 * @return int[]
	 */
	protected function get_indexable_ids( $indexable ): array {
		/** These filters are documented in ElasticPress's IndexHelper.php. */
		$sync_args = apply_filters( 'ep_cli_index_args', apply_filters( 'ep_sync_index_args', [] ) );
		if ( isset( $sync_args['include'] ) ) {
			$sync_args['post__in'] = $sync_args['include'];
		}
		if ( isset( $sync_args['exclude'] ) ) {
			$sync_args['post__not_in'] = $sync_args['exclude'];
		}
		unset( $sync_args['include'], $sync_args['exclude'], $sync_args['per_page'] );

		$query_args = wp_parse_args( $sync_args, [
			'post_type'           => $indexable->get_indexable_post_types(),
			'post_status'         => $indexable->get_indexable_post_status(),
			'has_password'        => false,
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'ep_integrate'        => false,
			'cache_results'       => false,
		] );

		/** This filter is documented in ElasticPress's Indexable/Post/Post.php. */
		$query_args = apply_filters( 'ep_index_posts_args', apply_filters( 'ep_post_query_db_args', $query_args ) );

		// Only the IDs are needed, whatever the filters set.
		$query_args['fields']         = 'ids';
		$query_args['posts_per_page'] = -1;
		$query_args['ep_integrate']   = false;
		$query_args['cache_results']  = false;
		unset( $query_args['offset'], $query_args['paged'] );

		$query = new WP_Query( $query_args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Load a batch of posts into the object cache in one query, for the sync kill filters.
	 *
	 * @param int[] $ids Post IDs.
	 * @return void
	 */
	protected function prime_posts( array $ids ): void {
		_prime_post_caches( $ids, false, false );
	}

	/**
	 * Check whether a sync kill filter stops ElasticPress from indexing a post.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	protected function is_sync_killed( int $id ): bool {
		/** This filter is documented in ElasticPress's Indexable.php. */
		if ( apply_filters( 'ep_post_sync_kill', false, $id ) ) {
			return true;
		}

		if ( has_filter( 'ep_post_index_kill' ) && apply_filters_deprecated( 'ep_post_index_kill', [ false, $id ], 'ElasticPress 5.3.3', 'ep_post_sync_kill' ) ) {
			return true;
		}

		if ( has_filter( 'ep_item_sync_kill' ) ) {
			$indexable = Indexables::factory()->get( 'post' );

			return (bool) apply_filters_deprecated( 'ep_item_sync_kill', [ false, get_post( $id ), $indexable ], 'ElasticPress 5.3.3', 'ep_post_sync_kill' );
		}

		return false;
	}

	/**
	 * Check a sample of indexable posts from the database against their documents.
	 *
	 * @param \ElasticPress\Indexable\Post\Post $indexable     The post indexable.
	 * @param int[]                             $indexable_ids IDs of every indexable post.
	 * @param float                             $percent       Percentage to sample.
	 * @return array[] Problems found.
	 */
	protected function check_database_sample( $indexable, array $indexable_ids, float $percent ): array {
		$sample   = sample_ids( $indexable_ids, sample_size( count( $indexable_ids ), $percent ) );
		$problems = [];
		$missing  = 0;
		$skipped  = 0;

		foreach ( array_chunk( $sample, self::BATCH_SIZE ) as $batch ) {
			$this->prime_posts( $batch );
			$rows = $this->get_post_rows( $batch );
			$docs = $this->get_documents( $indexable->get_index_name(), $batch );

			foreach ( $batch as $id ) {
				if ( ! isset( $rows[ $id ] ) ) {
					// Deleted since the ID list was built.
					continue;
				}

				if ( $this->is_sync_killed( $id ) ) {
					++$skipped;
					continue;
				}

				if ( ! isset( $docs[ $id ] ) ) {
					++$missing;
					$problems[] = $this->problem( 'database', $id, 'missing from index' );
					continue;
				}

				$fields = compare_fields( $rows[ $id ], $docs[ $id ] );
				if ( $fields ) {
					$problems[] = $this->problem( 'database', $id, 'mismatch', $fields );
				}
			}
		}

		$this->summary( sprintf(
			'Database: checked %d of %d indexable posts, after %d skipped by sync kill filters. %d missing from the index, %d not matching.',
			count( $sample ) - $skipped,
			count( $indexable_ids ),
			$skipped,
			$missing,
			count( $problems ) - $missing
		) );

		return $problems;
	}

	/**
	 * Check a sample of documents from the index against the database.
	 *
	 * @param \ElasticPress\Indexable\Post\Post $indexable     The post indexable.
	 * @param int[]                             $indexable_ids IDs of every indexable post.
	 * @param float                             $percent       Percentage to sample.
	 * @return array[] Problems found.
	 */
	protected function check_index_sample( $indexable, array $indexable_ids, float $percent ): array {
		$index = $indexable->get_index_name();
		$total = $this->request( $index . '/_count', [ 'query' => [ 'match_all' => (object) [] ] ] )['count'] ?? null;
		if ( $total === null ) {
			WP_CLI::error( sprintf( 'Could not count the documents in index %s.', $index ) );
		}

		$size = sample_size( (int) $total, $percent );
		if ( $size > self::MAX_INDEX_SAMPLE ) {
			WP_CLI::warning( sprintf( 'Checking %d documents, the most one search request can return.', self::MAX_INDEX_SAMPLE ) );
			$size = self::MAX_INDEX_SAMPLE;
		}

		$response = $this->request( $index . '/_search', [
			'size'    => $size,
			'_source' => FIELDS,
			'query'   => [
				'function_score' => [
					'query'        => [ 'match_all' => (object) [] ],
					'random_score' => (object) [],
				],
			],
		] );
		if ( ! isset( $response['hits']['hits'] ) ) {
			WP_CLI::error( sprintf( 'Could not search index %s.', $index ) );
		}

		$docs = [];
		foreach ( $response['hits']['hits'] as $hit ) {
			$docs[ (int) $hit['_id'] ] = $hit['_source'];
		}

		$indexable_lookup = array_flip( $indexable_ids );
		$problems         = [];
		$stale            = 0;

		foreach ( array_chunk( array_keys( $docs ), self::BATCH_SIZE ) as $batch ) {
			$this->prime_posts( $batch );
			$rows = $this->get_post_rows( $batch );
			foreach ( $batch as $id ) {
				if ( ! isset( $rows[ $id ] ) ) {
					++$stale;
					$problems[] = $this->problem( 'index', $id, 'post deleted' );
					continue;
				}

				if ( ! isset( $indexable_lookup[ $id ] ) || $this->is_sync_killed( $id ) ) {
					++$stale;
					$problems[] = $this->problem( 'index', $id, 'post not indexable' );
					continue;
				}

				$fields = compare_fields( $rows[ $id ], $docs[ $id ] );
				if ( $fields ) {
					$problems[] = $this->problem( 'index', $id, 'mismatch', $fields );
				}
			}
		}

		$this->summary( sprintf(
			'Index: checked %d of %d documents. %d for deleted or non-indexable posts, %d not matching.',
			count( $docs ),
			$total,
			$stale,
			count( $problems ) - $stale
		) );

		return $problems;
	}

	/**
	 * Get the compared fields for a set of documents from the index.
	 *
	 * Stops the command if the request fails, so that errors are not reported as missing documents.
	 *
	 * @param string $index Index name.
	 * @param int[]  $ids   Document IDs.
	 * @return array[] Document sources keyed by ID, for the documents that exist.
	 */
	protected function get_documents( string $index, array $ids ): array {
		$path     = $index . '/_mget?_source_includes=' . implode( ',', FIELDS );
		$response = $this->request( $path, [ 'ids' => array_map( 'strval', $ids ) ] );
		if ( ! isset( $response['docs'] ) ) {
			WP_CLI::error( sprintf( 'Could not fetch documents from index %s.', $index ) );
		}

		$docs = [];
		foreach ( $response['docs'] as $doc ) {
			if ( isset( $doc['error'] ) ) {
				WP_CLI::error( sprintf( 'Could not fetch document %s from index %s.', $doc['_id'], $index ) );
			}
			if ( ! empty( $doc['found'] ) ) {
				$docs[ (int) $doc['_id'] ] = $doc['_source'];
			}
		}

		return $docs;
	}

	/**
	 * Get the compared fields for a set of posts, straight from the database.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[] Rows keyed by post ID.
	 */
	protected function get_post_rows( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_title, post_type, post_status FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			),
			ARRAY_A
		);

		return array_column( $rows, null, 'ID' );
	}

	/**
	 * Send a JSON request to Elasticsearch through ElasticPress.
	 *
	 * @param string $path Request path.
	 * @param array  $body Request body.
	 * @return array|null Decoded response, or null on failure.
	 */
	protected function request( string $path, array $body ): ?array {
		$response = Elasticsearch::factory()->remote_request( $path, [
			'method' => 'POST',
			'body'   => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Print a summary line, on STDERR when the problems are printed as CSV or JSON.
	 *
	 * @param string $message Summary line.
	 * @return void
	 */
	protected function summary( string $message ): void {
		if ( $this->format === 'table' ) {
			WP_CLI::log( $message );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Keeps STDOUT parseable.
		fwrite( STDERR, $message . "\n" );
	}

	/**
	 * Build a row for the problems table.
	 *
	 * @param string   $check   Which check found it.
	 * @param int      $id      Post ID.
	 * @param string   $problem What is wrong.
	 * @param string[] $fields  Fields that don't match.
	 * @return array
	 */
	protected function problem( string $check, int $id, string $problem, array $fields = [] ): array {
		return [
			'check'   => $check,
			'ID'      => $id,
			'problem' => $problem,
			'fields'  => implode( ', ', $fields ),
		];
	}
}
