<?php
/**
 * Tests for the comparison helpers.
 *
 * @package HM\EP_Verify_Index
 */

namespace HM\EP_Verify_Index\Tests;

use HM\EP_Verify_Index;
use PHPUnit\Framework\TestCase;

/**
 * Test the sampling and comparison helpers.
 */
class Test_Namespace extends TestCase {

	/**
	 * Sample sizes round up, never exceed the total, and are empty for nothing to check.
	 *
	 * @dataProvider sample_size_provider
	 *
	 * @param int   $total    Total items.
	 * @param float $percent  Percentage.
	 * @param int   $expected Expected size.
	 */
	public function test_sample_size( int $total, float $percent, int $expected ): void {
		$this->assertSame( $expected, EP_Verify_Index\sample_size( $total, $percent ) );
	}

	/**
	 * Cases for test_sample_size.
	 *
	 * @return array[]
	 */
	public static function sample_size_provider(): array {
		return [
			'ten percent'          => [ 1000, 10, 100 ],
			'rounds up'            => [ 1001, 10, 101 ],
			'at least one'         => [ 3, 10, 1 ],
			'no items'             => [ 0, 10, 0 ],
			'all items'            => [ 50, 100, 50 ],
			'fraction of percent'  => [ 10000, 0.5, 50 ],
			'zero percent'         => [ 100, 0, 0 ],
		];
	}

	/**
	 * A sample has the right size, no duplicates, and only IDs from the input.
	 */
	public function test_sample_ids_picks_unique_ids_from_input(): void {
		$ids    = range( 1, 200 );
		$sample = EP_Verify_Index\sample_ids( $ids, 20 );

		$this->assertCount( 20, $sample );
		$this->assertSame( $sample, array_values( array_unique( $sample ) ) );
		$this->assertSame( [], array_diff( $sample, $ids ) );
	}

	/**
	 * Asking for one ID returns a list, not a bare value.
	 */
	public function test_sample_ids_returns_list_for_single_id(): void {
		$sample = EP_Verify_Index\sample_ids( [ 7, 8, 9 ], 1 );

		$this->assertCount( 1, $sample );
		$this->assertContains( $sample[0], [ 7, 8, 9 ] );
	}

	/**
	 * Asking for more IDs than exist returns them all.
	 */
	public function test_sample_ids_returns_everything_when_size_is_larger(): void {
		$this->assertSame( [ 4, 5 ], EP_Verify_Index\sample_ids( [ 4, 5 ], 10 ) );
		$this->assertSame( [], EP_Verify_Index\sample_ids( [ 4, 5 ], 0 ) );
	}

	/**
	 * Matching values pass even when the database gives strings and the index gives integers.
	 */
	public function test_compare_fields_matches_across_types(): void {
		$row = [
			'ID'          => '42',
			'post_name'   => 'hello-world',
			'post_title'  => 'Hello world',
			'post_type'   => 'post',
			'post_status' => 'publish',
		];
		$doc = [
			'ID'          => 42,
			'post_id'     => 42,
			'post_name'   => 'hello-world',
			'post_title'  => 'Hello world',
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->assertSame( [], EP_Verify_Index\compare_fields( $row, $doc ) );
	}

	/**
	 * Changed and missing fields are reported by name.
	 */
	public function test_compare_fields_lists_changed_and_missing_fields(): void {
		$row = [
			'ID'          => '42',
			'post_name'   => 'new-slug',
			'post_title'  => '',
			'post_type'   => 'page',
			'post_status' => 'draft',
		];
		$doc = [
			'ID'          => 42,
			'post_name'   => 'old-slug',
			'post_type'   => 'page',
			'post_status' => 'publish',
		];

		$this->assertSame( [ 'post_name', 'post_title', 'post_status' ], EP_Verify_Index\compare_fields( $row, $doc ) );
	}
}
