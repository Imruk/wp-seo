<?php
/**
 * @package WPSEO\Admin\Statistics
 */

/**
 * Class WPSEO_Statistics_Service
 */
class WPSEO_Statistics_Service {

	const CACHE_TRANSIENT_KEY = 'wpseo-dashboard-totals';

	/**
	 * @var WPSEO_OnPage_Option
	 */
	protected $onpage_option;

	/**
	 * @var WPSEO_Statistics
	 */
	protected $statistics;

	/**
	 * WPSEO_Statistics_Service contructor.
	 *
	 * @param WPSEO_Statistics    $statistics    The statistics class to retrieve statistics from.
	 * @param WPSEO_OnPage_Option $onpage_option The onpage option to retrieve onpage data from.
	 */
	public function __construct( WPSEO_Statistics $statistics = null, WPSEO_OnPage_Option $onpage_option = null ) {
		if ( null === $statistics ) {
			$statistics = new WPSEO_Statistics();
		}
		if ( null == $onpage_option ) {
			$onpage_option = new WPSEO_OnPage_Option();
		}

		$this->onpage_option = $onpage_option;
		$this->statistics    = $statistics;
	}

	/**
	 * Fetches statistics by REST request.
	 *
	 * @return WP_REST_Response The response object.
	 */
	public function get_statistics() {
		$statistics = $this->statistic_items();

		$header = __( 'Below are your published posts&#8217; SEO scores. Now is as good a time as any to start improving some of your posts!', 'wordpress-seo' );
		$onpage = false;
		if ( $this->can_view_onpage() && $this->onpage_option->is_enabled() ) {
			$onpage = $this->onpage_item();
		}

		$data = array(
			'header'     => $header,
			'seo_scores' => $statistics,
			'onpage'     => $onpage,
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * An array representing items to be added to the At a Glance dashboard widget
	 *
	 * @return array
	 */
	private function statistic_items() {
		$transient = get_transient( self::CACHE_TRANSIENT_KEY );
		$user_id   = get_current_user_id();

		if ( isset( $transient[ $user_id ] ) ) {
			return $transient[ $user_id ];
		}

		return $this->set_statistic_items_for_this_user( $transient );
	}

	/**
	 * Returns an the results of the onpage option.
	 *
	 * @param WPSEO_OnPage_Option $onpage_option The onpage option to map.
	 *
	 * @return array The results, contains a score and label.
	 */
	private function onpage_item() {
		$can_fetch = $this->onpage_option->should_be_fetched();

		switch ( $this->onpage_option->get_status() ) {
			case WPSEO_OnPage_Option::IS_INDEXABLE :
				return array(
					'score' => 'good',
					'label' => __( 'Your homepage can be indexed by search engines.', 'wordpress-seo' ),
					'can_fetch' => $can_fetch,
				);
			case WPSEO_OnPage_Option::IS_NOT_INDEXABLE :
				return array(
					'score' => 'bad',
					'label' => printf(
						/* translators: %1$s: opens a link to a related knowledge base article. %2$s: closes the link. */
						__( '%1$sYour homepage cannot be indexed by search engines%2$s. This is very bad for SEO and should be fixed.', 'wordpress-seo' ),
						'<a href="' . WPSEO_Shortlinker::get( 'https://yoa.st/onpageindexerror' ) . '" target="_blank">',
						'</a>'
					),
					'can_fetch' => $can_fetch,
				);
			case WPSEO_OnPage_Option::CANNOT_FETCH :
				return array(
					'score' => 'na',
					'label' => printf(
						/* translators: %1$s: opens a link to a related knowledge base article, %2$s: expands to Yoast SEO, %3$s: closes the link, %4$s: expands to Ryte. */
						__( '%1$s%2$s has not been able to fetch your site\'s indexability status%3$s from %4$s', 'wordpress-seo' ),
						'<a href="' . WPSEO_Shortlinker::get( 'https://yoa.st/onpagerequestfailed' ) . '" target="_blank">',
						'Yoast SEO',
						'</a>',
						'Ryte'
					),
					'can_fetch' => $can_fetch,
				);
			case WPSEO_OnPage_Option::NOT_FETCHED :
				return array(
					'score' => 'na',
					'label' => esc_html( sprintf(
					/* translators: %1$s: expands to Yoast SEO, %2$s: expands to Ryte. */
						__( '%1$s has not fetched your site\'s indexability status yet from %2$s', 'wordpress-seo' ),
						'Yoast SEO',
						'Ryte'
					) ),
					'can_fetch' => $can_fetch,
				);
		}

		return array();
	}

	/**
	 * Set the cache for a specific user
	 *
	 * @param array|boolean $transient The current stored transient with the cached data.
	 *
	 * @return mixed
	 */
	private function set_statistic_items_for_this_user( $transient ) {
		if ( $transient === false ) {
			$transient = array();
		}

		$user_id               = get_current_user_id();
		// Use array_values because array_filter may return non-zero indexed arrays.
		$transient[ $user_id ] = array_values( array_filter( $this->get_seo_scores_with_post_count(), array( $this, 'filter_items' ) ) );

		set_transient( self::CACHE_TRANSIENT_KEY, $transient, DAY_IN_SECONDS );

		return $transient[ $user_id ];
	}

	/**
	 * Get all SEO ranks and data associated with them.
	 *
	 * @return array An array of SEO scores and associated data.
	 */
	private function get_seo_scores_with_post_count() {
		$ranks = WPSEO_Rank::get_all_ranks();

		return array_map( array( $this, 'map_rank_to_widget' ), $ranks );
	}

	/**
	 * Converts a rank to data usable in the dashboard widget.
	 *
	 * @param WPSEO_Rank $rank The rank to map.
	 *
	 * @return array The mapped rank.
	 */
	private function map_rank_to_widget( WPSEO_Rank $rank ) {
		return array(
			'seo_rank' => $rank->get_rank(),
			'label'    => $this->get_label_for_rank( $rank ),
			'count'    => $this->statistics->get_post_count( $rank ),
			'link'     => $this->get_link_for_rank( $rank ),
		);
	}

	/**
	 * Returns a dashboard widget label to use for a certain rank.
	 *
	 * @param WPSEO_Rank $rank The rank to return a label for.
	 *
	 * @return string The label for the rank.
	 */
	private function get_label_for_rank( WPSEO_Rank $rank ) {
		$labels = array(
			WPSEO_Rank::NO_FOCUS => __( 'Posts without focus keyword', 'wordpress-seo' ),
			WPSEO_Rank::BAD      => __( 'Posts with bad SEO score', 'wordpress-seo' ),
			WPSEO_Rank::OK       => __( 'Posts with OK SEO score', 'wordpress-seo' ),
			WPSEO_Rank::GOOD     => __( 'Posts with good SEO score', 'wordpress-seo' ),
			/* translators: %s expands to <span lang="en">noindex</span> */
			WPSEO_Rank::NO_INDEX => sprintf( __( 'Posts that are set to &#8220;%s&#8221;', 'wordpress-seo' ), '<span lang="en">noindex</span>' ),
		);

		return $labels[ $rank->get_rank() ];
	}

	/**
	 * Filter items if they have a count of zero.
	 *
	 * @param array $item Data array.
	 *
	 * @return bool Whether or not the count is zero.
	 */
	private function filter_items( $item ) {
		return 0 !== $item['count'];
	}

	/**
	 * Returns a link for the overview of posts of a certain rank.
	 *
	 * @param WPSEO_Rank $rank The rank to return a link for.
	 *
	 * @return string The link that shows an overview of posts with that rank.
	 */
	private function get_link_for_rank( WPSEO_Rank $rank ) {
		if ( current_user_can( 'edit_others_posts' ) === false ) {
			return esc_url( admin_url( 'edit.php?post_status=publish&post_type=post&seo_filter=' . $rank->get_rank() . '&author=' . get_current_user_id() ) );
		}

		return esc_url( admin_url( 'edit.php?post_status=publish&post_type=post&seo_filter=' . $rank->get_rank() ) );
	}

	/**
	 * Gets permissions of the current user to view the onpage result.
	 *
	 * @return bool Whether or not the current user can view the onpage option result.
	 */
	private function can_view_onpage() {
		return is_multisite() ? WPSEO_Utils::grant_access() : current_user_can( 'manage_options' );
	}
}
