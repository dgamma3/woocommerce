<?php

namespace Automattic\WooCommerce\Tests\Internal\ProductQueryFilters;

use Automattic\WooCommerce\Internal\ProductQueryFilters\FilterDataProvider;

/**
 * Tests related to FilterClausesGenerator service.
 */
class FilterDataProviderTest extends AbstractProductQueryFiltersTest {
	/**
	 * The system under test.
	 *
	 * @var DataRegenerator
	 */
	private $sut;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$container = wc_get_container();
		$this->sut = $container->get( FilterDataProvider::class );

		$this->fixture_data->add_product_review( $this->products[0]->get_id(), 5 );
		$this->fixture_data->add_product_review( $this->products[1]->get_id(), 3 );
		$this->fixture_data->add_product_review( $this->products[3]->get_id(), 5 );
	}

	/**
	 * @testdox Test price range without filter.
	 */
	public function test_get_filtered_price_with_default_query() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );

		$this->test_get_filtered_price_with( $wp_query );
	}

	/**
	 * @testdox Test price range with stock filter set to instock.
	 */
	public function test_get_filtered_price_with_stock_filter() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );

		$wp_query->set( 'filter_stock_status', 'instock' );
		$this->test_get_filtered_price_with(
			$wp_query,
			function( $product_data ) {
				return 'instock' === $product_data['stock_status'];
			}
		);
	}

	/**
	 * @testdox Test price range with stock filter set to multiple options.
	 */
	public function test_get_filtered_price_with_stock_filter_multiple() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );
		$wp_query->set( 'filter_stock_status', 'outofstock,onbackorder' );
		$this->test_get_filtered_price_with(
			$wp_query,
			function( $product_data ) {
				return 'outofstock' === $product_data['stock_status'] ||
				'onbackorder' === $product_data['stock_status'];
			}
		);
	}

	/**
	 * @testdox Test stock counts without filter.
	 */
	public function test_get_stock_status_counts_with_default_query() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );

		$this->test_get_stock_status_counts_with( $wp_query );
	}

	/**
	 * @testdox Test stock counts with min price.
	 */
	public function test_get_stock_status_counts_with_min_price() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );
		$wp_query->set( 'min_price', 20 );
		$this->test_get_stock_status_counts_with(
			$wp_query,
			function( $product_data ) {
				if ( ! isset( $product_data['variations'] ) ) {
					return $product_data['regular_price'] >= 20;
				}

				foreach ( $product_data['variations'] as $variation_data ) {
					if ( $variation_data['props']['regular_price'] < 20 ) {
						return false;
					}
				}
				return true;
			}
		);
	}

	/**
	 * @testdox Test rating counts without filter.
	 */
	public function test_get_rating_counts_with_default_query() {
		$wp_query   = new \WP_Query( array( 'post_type' => 'product' ) );
		$query_vars = array_filter( $wp_query->query_vars );

		$actual_rating_counts   = (array) $this->sut->get_rating_counts( $query_vars );
		$expected_rating_counts = array(
			3 => 1,
			5 => 2,
		);

		$this->assertEqualsCanonicalizing(
			$expected_rating_counts,
			$actual_rating_counts
		);
	}

	/**
	 * @testdox Test rating counts with min price.
	 */
	public function test_get_rating_counts_with_min_price() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );
		$wp_query->set( 'min_price', 20 );
		$query_vars = array_filter( $wp_query->query_vars );

		$actual_rating_counts   = (array) $this->sut->get_rating_counts( $query_vars );
		$expected_rating_counts = array(
			3 => 1,
			5 => 1,
		);

		$this->assertEqualsCanonicalizing(
			$expected_rating_counts,
			$actual_rating_counts
		);
	}

	/**
	 * @testdox Test attribute count without filter.
	 */
	public function test_get_attribute_counts_with_default_query() {
		$wp_query                = new \WP_Query( array( 'post_type' => 'product' ) );
		$query_vars              = array_filter( $wp_query->query_vars );
		$actual_attribute_counts = (array) $this->sut->get_attribute_counts( $query_vars, 'pa_color' );

		$expected_attribute_counts = array();
		foreach ( get_terms( array( 'taxonomy' => 'pa_color' ) ) as $term ) {
			$expected_attribute_counts[ $term->term_id ] = $term->count;
		}

		$this->assertEqualsCanonicalizing( $expected_attribute_counts, $actual_attribute_counts );
	}

	/**
	 * @testdox Test attribute count with max price.
	 */
	public function test_get_attribute_counts_with_max_price() {
		$wp_query = new \WP_Query( array( 'post_type' => 'product' ) );
		$wp_query->set( 'max_price', 55 );
		$query_vars              = array_filter( $wp_query->query_vars );
		$actual_attribute_counts = (array) $this->sut->get_attribute_counts( $query_vars, 'pa_color' );

		$expected_attribute_counts = array();
		foreach ( get_terms( array( 'taxonomy' => 'pa_color' ) ) as $term ) {
			if ( in_array( $term->name, array( 'red', 'green' ), true ) ) {
				$expected_attribute_counts[ $term->term_id ] = 1;
			}
		}

		$this->assertEqualsCanonicalizing( $expected_attribute_counts, $actual_attribute_counts );
	}

	/**
	 * Test stock count.
	 *
	 * @param \WP_Query $wp_query        WP_Query instance.
	 * @param callable  $filter_callback Callback passed to filter test products.
	 */
	private function test_get_stock_status_counts_with( $wp_query, $filter_callback = null ) {
		$query_vars = array_filter( $wp_query->query_vars );

		$actual_stock_status_counts = (array) $this->sut->get_stock_status_counts( $query_vars );

		$expected_stock_status_counts = array(
			'instock'     => 0,
			'outofstock'  => 0,
			'onbackorder' => 0,
		);

		if ( $filter_callback ) {
			$filtered_product_data = array_filter(
				$this->products_data,
				$filter_callback
			);
		} else {
			$filtered_product_data = $this->products_data;
		}

		foreach ( $filtered_product_data as $product_data ) {
			$expected_stock_status_counts[ $product_data['stock_status'] ] += 1;
		}

		$this->assertEqualsCanonicalizing( $expected_stock_status_counts, $actual_stock_status_counts );
	}

	/**
	 * Test filter price range.
	 *
	 * @param \WP_Query $wp_query        WP_Query instance.
	 * @param callable  $filter_callback Callback passed to filter test products.
	 */
	private function test_get_filtered_price_with( $wp_query, $filter_callback = null ) {
		$query_vars = array_filter( $wp_query->query_vars );

		$prices = array();

		if ( $filter_callback ) {
			$filtered_product_data = array_filter(
				$this->products_data,
				$filter_callback
			);
		} else {
			$filtered_product_data = $this->products_data;
		}

		foreach ( $filtered_product_data as $product_data ) {
			$prices[] = $product_data['regular_price'] ?? null;

			if ( isset( $product_data['variations'] ) ) {
				foreach ( $product_data['variations'] as $variation_data ) {
					$prices[] = $variation_data['props']['regular_price'] ?? null;
				}
			}
		}

		$prices = array_filter( $prices );
		$prices = array_map( 'intval', $prices );

		$expected_price_range = array(
			'min_price' => min( $prices ),
			'max_price' => max( $prices ),
		);

		$actual_price_range = (array) $this->sut->get_filtered_price( $query_vars );

		$this->assertEqualsCanonicalizing( $expected_price_range, $actual_price_range );
	}

}