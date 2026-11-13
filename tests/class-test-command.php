<?php
/**
 * Tests for the command's Elasticsearch handling.
 *
 * @package HM\EP_Verify_Index
 */

namespace HM\EP_Verify_Index\Tests;

use HM\EP_Verify_Index\Command;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_CLI;

/**
 * A command with Elasticsearch and the database replaced by fixed responses.
 */
class Testable_Command extends Command {

	/**
	 * Responses keyed by request path. A missing path fails like a failed request.
	 *
	 * @var array
	 */
	public array $responses = [];

	/**
	 * Database rows keyed by post ID.
	 *
	 * @var array
	 */
	public array $rows = [];

	/**
	 * Post IDs that a sync kill filter stops.
	 *
	 * @var int[]
	 */
	public array $killed = [];

	/**
	 * Post IDs passed to is_sync_killed().
	 *
	 * @var int[]
	 */
	public array $kill_checks = [];

	/**
	 * Term relationship rows, as get_term_rows() returns them.
	 *
	 * @var array[]
	 */
	public array $term_rows = [];

	/**
	 * Term rows, as get_parent_rows() returns them.
	 *
	 * @var array[]
	 */
	public array $parent_rows = [];

	/**
	 * Taxonomies indexed for every post.
	 *
	 * @var string[]
	 */
	public array $taxonomies = [ 'category', 'language' ];

	/**
	 * Whether ancestor terms are indexed.
	 *
	 * @var bool
	 */
	public bool $hierarchy = true;

	/**
	 * Term IDs passed to get_parent_rows(), one entry per call.
	 *
	 * @var array[]
	 */
	public array $parent_lookups = [];

	/**
	 * Turn on the terms comparison, as --terms does.
	 *
	 * @return void
	 */
	public function enable_terms(): void {
		$this->terms = true;
	}

	/**
	 * Return the fixed relationship rows for the IDs.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[]
	 */
	protected function get_term_rows( array $ids ): array {
		return array_values( array_filter( $this->term_rows, fn ( $row ) => in_array( (int) $row['object_id'], $ids, true ) ) );
	}

	/**
	 * Record the lookup and return the fixed term rows for the IDs.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @return array[]
	 */
	protected function get_parent_rows( array $term_ids ): array {
		$this->parent_lookups[] = $term_ids;

		return array_values( array_filter( $this->parent_rows, fn ( $row ) => in_array( (int) $row['term_id'], $term_ids, true ) ) );
	}

	/**
	 * Use the fixed list of taxonomies.
	 *
	 * @param object $indexable The post indexable.
	 * @param int    $id        Post ID.
	 * @return string[]
	 */
	protected function get_indexed_taxonomies( $indexable, int $id ): array {
		return $this->taxonomies;
	}

	/**
	 * Use the fixed setting.
	 *
	 * @return bool
	 */
	protected function allow_term_hierarchy(): bool {
		return $this->hierarchy;
	}

	/**
	 * Record the check and use the fixed list.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	protected function is_sync_killed( int $id ): bool {
		$this->kill_checks[] = $id;

		return in_array( $id, $this->killed, true );
	}

	/**
	 * No object cache in the tests.
	 *
	 * @param int[] $ids Post IDs.
	 * @return void
	 */
	protected function prime_posts( array $ids ): void {
	}

	/**
	 * Return the fixed response for a path.
	 *
	 * @param string $path Request path.
	 * @param array  $body Request body.
	 * @return array|null
	 */
	protected function request( string $path, array $body ): ?array {
		return $this->responses[ $path ] ?? null;
	}

	/**
	 * Return the fixed rows for the IDs.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[]
	 */
	protected function get_post_rows( array $ids ): array {
		return array_intersect_key( $this->rows, array_flip( $ids ) );
	}

	/**
	 * Expose get_documents() to the tests.
	 *
	 * @param int[] $ids Document IDs.
	 * @return array[]
	 */
	public function documents( array $ids ): array {
		return $this->get_documents( 'test-index', $ids );
	}

	/**
	 * Expose check_database_sample() to the tests, checking every post.
	 *
	 * @param int[] $indexable_ids IDs of every indexable post.
	 * @param float $percent       Percentage to sample.
	 * @return array[]
	 */
	public function database_sample( array $indexable_ids, float $percent = 100 ): array {
		return $this->check_database_sample( $this->fake_indexable(), $indexable_ids, $percent );
	}

	/**
	 * A stand-in for the ElasticPress post indexable.
	 *
	 * @return object
	 */
	protected function fake_indexable() {
		return new class() {
			public function get_index_name() {
				return 'test-index';
			}
		};
	}

	/**
	 * Expose check_index_sample() to the tests.
	 *
	 * @param int[] $indexable_ids IDs of every indexable post.
	 * @return array[]
	 */
	public function index_sample( array $indexable_ids ): array {
		return $this->check_index_sample( $this->fake_indexable(), $indexable_ids, 100 );
	}
}

/**
 * Test how the command handles Elasticsearch responses.
 */
class Test_Command extends TestCase {

	/**
	 * Path used for multi-get requests.
	 */
	const MGET = 'test-index/_mget?_source_includes=ID,post_name,post_title,post_type,post_status';

	/**
	 * Reset the log before each test.
	 */
	protected function setUp(): void {
		WP_CLI::$logs = [];
	}

	/**
	 * Path used for multi-get requests when terms are compared.
	 */
	const MGET_TERMS = self::MGET . ',terms.*.term_id';

	/**
	 * Build a term relationship row.
	 *
	 * @param int    $id       Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $term_id  Term ID.
	 * @param int    $parent   Parent term ID.
	 * @return array
	 */
	protected static function term_row( int $id, string $taxonomy, int $term_id, int $parent = 0 ): array {
		return [
			'object_id' => (string) $id,
			'taxonomy'  => $taxonomy,
			'term_id'   => (string) $term_id,
			'parent'    => (string) $parent,
		];
	}

	/**
	 * Build a document as Elasticsearch returns it when terms are compared.
	 *
	 * @param int     $id    Post ID.
	 * @param array[] $terms Term IDs keyed by taxonomy.
	 * @return array
	 */
	protected static function doc( int $id, array $terms ): array {
		$doc = self::row( $id );
		foreach ( $terms as $taxonomy => $term_ids ) {
			$doc['terms'][ $taxonomy ] = array_map( fn ( $term_id ) => [ 'term_id' => $term_id ], $term_ids );
		}

		return $doc;
	}

	/**
	 * Build a database row.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 * @return array
	 */
	protected static function row( int $id, string $title = 'Title' ): array {
		return [
			'ID'          => (string) $id,
			'post_name'   => 'slug-' . $id,
			'post_title'  => $title,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];
	}

	/**
	 * A failed multi-get stops the command instead of reporting missing documents.
	 */
	public function test_failed_request_stops_the_command(): void {
		$command = new Testable_Command();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not fetch documents from index test-index.' );
		$command->documents( [ 1, 2 ] );
	}

	/**
	 * An error for a single document stops the command too.
	 */
	public function test_document_error_stops_the_command(): void {
		$command                          = new Testable_Command();
		$command->responses[ self::MGET ] = [
			'docs' => [
				[
					'_id'   => '1',
					'found' => true,
					'_source' => self::row( 1 ),
				],
				[
					'_id'   => '2',
					'error' => [ 'type' => 'shard_not_available' ],
				],
			],
		];

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not fetch document 2 from index test-index.' );
		$command->documents( [ 1, 2 ] );
	}

	/**
	 * Found documents are keyed by ID, and documents that don't exist are left out.
	 */
	public function test_documents_keyed_by_id(): void {
		$command                          = new Testable_Command();
		$command->responses[ self::MGET ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::row( 1 ),
				],
				[
					'_id'   => '2',
					'found' => false,
				],
			],
		];

		$this->assertSame( [ 1 => self::row( 1 ) ], $command->documents( [ 1, 2 ] ) );
	}

	/**
	 * The index check reports deleted, non-indexable and changed posts.
	 */
	public function test_index_sample_reports_stale_and_changed_documents(): void {
		$command                                   = new Testable_Command();
		$command->responses['test-index/_count']  = [ 'count' => 4 ];
		$command->responses['test-index/_search'] = [
			'hits' => [
				'hits' => [
					[
						'_id' => '1',
						'_source' => self::row( 1 ),
					],
					[
						'_id' => '2',
						'_source' => self::row( 2 ),
					],
					[
						'_id' => '3',
						'_source' => self::row( 3 ),
					],
					[
						'_id' => '4',
						'_source' => self::row( 4, 'Old title' ),
					],
				],
			],
		];
		// Post 2 was deleted, and post 3 is no longer indexable.
		$command->rows = [
			1 => self::row( 1 ),
			3 => self::row( 3 ),
			4 => self::row( 4, 'New title' ),
		];

		$problems = $command->index_sample( [ 1, 4 ] );

		$this->assertSame(
			[
				[
					'check' => 'index',
					'ID' => 2,
					'problem' => 'post deleted',
					'fields' => '',
				],
				[
					'check' => 'index',
					'ID' => 3,
					'problem' => 'post not indexable',
					'fields' => '',
				],
				[
					'check' => 'index',
					'ID' => 4,
					'problem' => 'mismatch',
					'fields' => 'post_title',
				],
			],
			$problems
		);
		$this->assertSame( [ 'Index: checked 4 of 4 documents. 2 for deleted or non-indexable posts, 1 not matching.' ], WP_CLI::$logs );
	}

	/**
	 * A failed count stops the index check.
	 */
	public function test_failed_count_stops_the_index_check(): void {
		$command = new Testable_Command();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not count the documents in index test-index.' );
		$command->index_sample( [ 1 ] );
	}

	/**
	 * Kill filters run on the sampled posts only, and killed posts are skipped, not reported.
	 */
	public function test_database_sample_runs_kill_filters_on_sample_only(): void {
		$command         = new Testable_Command();
		$command->rows   = array_map( [ self::class, 'row' ], array_combine( range( 1, 100 ), range( 1, 100 ) ) );
		$command->killed = range( 1, 100 );
		$command->responses[ self::MGET ] = [ 'docs' => [] ];

		$problems = $command->database_sample( range( 1, 100 ), 10 );

		$this->assertSame( [], $problems );
		$this->assertCount( 10, $command->kill_checks );
		$this->assertStringContainsString( 'checked 0 of 100 indexable posts, after 10 skipped', WP_CLI::$logs[0] );
	}

	/**
	 * The index check reports a document as not indexable when a kill filter stops its post.
	 */
	public function test_index_sample_reports_killed_post_as_not_indexable(): void {
		$command                                   = new Testable_Command();
		$command->responses['test-index/_count']  = [ 'count' => 1 ];
		$command->responses['test-index/_search'] = [
			'hits' => [
				'hits' => [
					[
						'_id' => '5',
						'_source' => self::row( 5 ),
					],
				],
			],
		];
		$command->rows   = [ 5 => self::row( 5 ) ];
		$command->killed = [ 5 ];

		$this->assertSame(
			[
				[
					'check' => 'index',
					'ID' => 5,
					'problem' => 'post not indexable',
					'fields' => '',
				],
			],
			$command->index_sample( [ 5 ] )
		);
	}

	/**
	 * Without the terms comparison, a document with different terms passes.
	 */
	public function test_terms_are_ignored_by_default(): void {
		$command                          = new Testable_Command();
		$command->rows                    = [ 1 => self::row( 1 ) ];
		$command->term_rows               = [ self::term_row( 1, 'language', 7 ) ];
		$command->responses[ self::MGET ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::row( 1 ),
				],
			],
		];

		$this->assertSame( [], $command->database_sample( [ 1 ] ) );
	}

	/**
	 * The database check reports a post whose document is missing a term, by taxonomy.
	 */
	public function test_database_sample_reports_missing_and_changed_terms(): void {
		$command            = new Testable_Command();
		$command->rows      = [
			1 => self::row( 1 ),
			2 => self::row( 2 ),
			3 => self::row( 3, 'New title' ),
		];
		$command->term_rows = [
			self::term_row( 1, 'category', 5 ),
			self::term_row( 1, 'language', 7 ),
			self::term_row( 2, 'category', 5 ),
			self::term_row( 2, 'language', 7 ),
			self::term_row( 3, 'category', 5 ),
			self::term_row( 3, 'language', 7 ),
		];
		$command->enable_terms();
		$command->responses[ self::MGET_TERMS ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::doc( 1, [
						'language' => [ 7 ],
						'category' => [ 5 ],
					] ),
				],
				[
					'_id'     => '2',
					'found'   => true,
					'_source' => self::doc( 2, [ 'category' => [ 5 ] ] ),
				],
				[
					'_id'     => '3',
					'found'   => true,
					'_source' => self::doc( 3, [
						'category' => [ 6 ],
						'language' => [ 7 ],
					] ),
				],
			],
		];

		$this->assertSame(
			[
				[
					'check' => 'database',
					'ID' => 2,
					'problem' => 'mismatch',
					'fields' => 'terms.language',
				],
				[
					'check' => 'database',
					'ID' => 3,
					'problem' => 'mismatch',
					'fields' => 'post_title, terms.category',
				],
			],
			$command->database_sample( [ 1, 2, 3 ] )
		);
		$this->assertStringContainsString( '0 missing from the index, 2 not matching.', WP_CLI::$logs[0] );
	}

	/**
	 * The index check asks for the term IDs and reports documents with terms the post no longer has.
	 */
	public function test_index_sample_reports_terms_removed_from_the_post(): void {
		$command                                   = new Testable_Command();
		$command->responses['test-index/_count']  = [ 'count' => 2 ];
		$command->responses['test-index/_search'] = [
			'hits' => [
				'hits' => [
					[
						'_id' => '1',
						'_source' => self::doc( 1, [ 'category' => [ 5 ] ] ),
					],
					[
						'_id' => '2',
						'_source' => self::doc( 2, [ 'category' => [ 5 ] ] ),
					],
				],
			],
		];
		$command->rows                             = [
			1 => self::row( 1 ),
			2 => self::row( 2 ),
		];
		$command->term_rows                        = [ self::term_row( 1, 'category', 5 ) ];
		$command->enable_terms();

		$this->assertSame(
			[
				[
					'check' => 'index',
					'ID' => 2,
					'problem' => 'mismatch',
					'fields' => 'terms.category',
				],
			],
			$command->index_sample( [ 1, 2 ] )
		);
	}

	/**
	 * Ancestor terms are expected in the document, and each one is looked up once.
	 */
	public function test_ancestor_terms_are_expected(): void {
		$command              = new Testable_Command();
		$command->rows        = [
			1 => self::row( 1 ),
			2 => self::row( 2 ),
		];
		$command->term_rows   = [
			self::term_row( 1, 'category', 30, 20 ),
			self::term_row( 2, 'category', 30, 20 ),
		];
		$command->parent_rows = [
			[
				'term_id'  => '20',
				'taxonomy' => 'category',
				'parent'   => '10',
			],
			[
				'term_id'  => '10',
				'taxonomy' => 'category',
				'parent'   => '0',
			],
		];
		$command->enable_terms();
		$command->responses[ self::MGET_TERMS ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::doc( 1, [ 'category' => [ 30, 20, 10 ] ] ),
				],
				[
					'_id'     => '2',
					'found'   => true,
					'_source' => self::doc( 2, [ 'category' => [ 30 ] ] ),
				],
			],
		];

		$problems = $command->database_sample( [ 1, 2 ] );

		$this->assertSame( [ 2 ], array_column( $problems, 'ID' ) );
		$this->assertSame( 'terms.category', $problems[0]['fields'] );
		$this->assertSame( [ [ 20 ], [ 10 ] ], $command->parent_lookups );
	}

	/**
	 * With ancestors turned off in ElasticPress, only the assigned terms are expected.
	 */
	public function test_ancestor_terms_are_not_expected_without_hierarchy(): void {
		$command            = new Testable_Command();
		$command->hierarchy = false;
		$command->rows      = [ 1 => self::row( 1 ) ];
		$command->term_rows = [ self::term_row( 1, 'category', 30, 20 ) ];
		$command->enable_terms();
		$command->responses[ self::MGET_TERMS ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::doc( 1, [ 'category' => [ 30 ] ] ),
				],
			],
		];

		$this->assertSame( [], $command->database_sample( [ 1 ] ) );
		$this->assertSame( [], $command->parent_lookups );
	}

	/**
	 * Terms in a taxonomy ElasticPress doesn't index for the post are left out of the comparison.
	 */
	public function test_terms_in_other_taxonomies_are_ignored(): void {
		$command             = new Testable_Command();
		$command->taxonomies = [ 'category' ];
		$command->rows       = [ 1 => self::row( 1 ) ];
		$command->term_rows  = [
			self::term_row( 1, 'category', 5 ),
			self::term_row( 1, 'private_flag', 9 ),
		];
		$command->enable_terms();
		$command->responses[ self::MGET_TERMS ] = [
			'docs' => [
				[
					'_id'     => '1',
					'found'   => true,
					'_source' => self::doc( 1, [ 'category' => [ 5 ] ] ),
				],
			],
		];

		$this->assertSame( [], $command->database_sample( [ 1 ] ) );
	}
}
