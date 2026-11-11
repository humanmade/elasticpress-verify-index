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
}
