<?php
/**
 * Helpers for comparing database posts with their Elasticsearch documents.
 *
 * @package HM\EP_Verify_Index
 */

namespace HM\EP_Verify_Index;

use WP_CLI;

/**
 * Post fields compared between the database and the index.
 */
const FIELDS = [ 'ID', 'post_name', 'post_title', 'post_type', 'post_status' ];

/**
 * Document field holding the IDs of a post's terms, in every taxonomy.
 */
const TERMS_FIELD = 'terms.*.term_id';

/**
 * Register the WP-CLI command under the ElasticPress namespace.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	// WP-CLI defers this until ElasticPress registers the parent command.
	WP_CLI::add_command( 'elasticpress verify-index', __NAMESPACE__ . '\\Command' );
}

/**
 * Get the number of items to sample.
 *
 * @param int   $total   Total number of items.
 * @param float $percent Percentage to sample, from 0 to 100.
 * @return int At least 1 when there are items, and never more than the total.
 */
function sample_size( int $total, float $percent ): int {
	if ( $total <= 0 || $percent <= 0 ) {
		return 0;
	}

	return (int) min( $total, max( 1, ceil( $total * $percent / 100 ) ) );
}

/**
 * Pick a random sample of IDs.
 *
 * @param int[] $ids  IDs to sample from.
 * @param int   $size Number of IDs to pick.
 * @return int[]
 */
function sample_ids( array $ids, int $size ): array {
	$ids = array_values( $ids );
	if ( $size >= count( $ids ) ) {
		return $ids;
	}
	if ( $size <= 0 ) {
		return [];
	}

	$keys = (array) array_rand( $ids, $size );

	return array_map( fn ( $key ) => $ids[ $key ], $keys );
}

/**
 * List the fields that differ between a database row and an indexed document.
 *
 * @param array $row Post fields from the database.
 * @param array $doc Document source from Elasticsearch.
 * @return string[] Names of the fields that don't match.
 */
function compare_fields( array $row, array $doc ): array {
	$mismatched = [];
	foreach ( FIELDS as $field ) {
		$db_value  = $row[ $field ] ?? null;
		$doc_value = $doc[ $field ] ?? null;
		if ( (string) $db_value !== (string) $doc_value || ( $db_value === null ) !== ( $doc_value === null ) ) {
			$mismatched[] = $field;
		}
	}

	return $mismatched;
}

/**
 * Add the ancestors of each term to a list of term IDs.
 *
 * @param int[] $term_ids Term IDs.
 * @param int[] $parents  Parent term IDs keyed by term ID.
 * @return int[]
 */
function with_ancestors( array $term_ids, array $parents ): array {
	$all = [];
	foreach ( $term_ids as $term_id ) {
		$term_id = (int) $term_id;
		// Stops at a term already listed, so a loop of parents ends.
		while ( $term_id && ! isset( $all[ $term_id ] ) ) {
			$all[ $term_id ] = true;
			$term_id         = (int) ( $parents[ $term_id ] ?? 0 );
		}
	}

	return array_keys( $all );
}

/**
 * List the taxonomies whose terms differ between the database and an indexed document.
 *
 * @param array[] $expected Term IDs from the database, keyed by taxonomy.
 * @param array   $doc      Document source from Elasticsearch.
 * @return string[] Document fields that don't match, such as terms.category.
 */
function compare_terms( array $expected, array $doc ): array {
	$mismatched = [];
	foreach ( $expected as $taxonomy => $term_ids ) {
		$db_ids  = array_unique( array_map( 'intval', $term_ids ) );
		$doc_ids = array_unique( array_map( 'intval', array_column( $doc['terms'][ $taxonomy ] ?? [], 'term_id' ) ) );
		sort( $db_ids );
		sort( $doc_ids );
		if ( $db_ids !== $doc_ids ) {
			$mismatched[] = 'terms.' . $taxonomy;
		}
	}

	return $mismatched;
}
