<?php

class GO_OpenCalais_AutoTagger
{
	public $threshold;
	public $mapping = array();

	public function __construct()
	{
		add_action( 'wp_ajax_go_opencalais_autotag', array( $this, 'autotag' ) );
		add_action( 'go_opencalais_content', array( $this, 'go_opencalais_content' ), 5, 3 );
	}//end __construct

	/**
	 * Batch autotagging of posts
	 */
	public function autotag()
	{
		global $post, $wp_version, $wpdb;

		if ( ! current_user_can( 'manage_options' ) )
		{
			wp_die( 'You should not be here!' );
		}//end if
		?>
		<h2>Gigaom OpenCalais Bulk Auto-tagger</h2>
		<?php
		// Prep mapping for use later
		$this->mapping = go_opencalais()->config( 'mapping' );

		// any updates to the default threshold?
		$this->threshold = apply_filters( 'go_opencalais_autotag_threshold', go_opencalais()->config( 'autotagger' )['threshold'] );
		$posts_per_page  = isset( $_REQUEST['num'] ) ? (int) $_REQUEST['num'] : go_opencalais()->config( 'autotagger' )['num'];

		// sanity check
		if ( 20 < $posts_per_page )
		{
			$posts_per_page = 20;
		}//end if

		// Get posts that haven't been auto tagged yet
		$args = array(
			'post_type'      => go_opencalais()->config( 'post_types' ),
			'post_status'    => array( 'any' ),
			'posts_per_page' => $posts_per_page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'tax_query'      => array(
				array(
					'taxonomy' => go_opencalais()->slug . '-autotagger',
					'field'    => 'slug',
					'terms'    => array( go_opencalais()->config( 'autotagger' )['term'] ),
					'operator' => 'NOT IN',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( ! $query->posts )
		{
			?>
			<p>No posts found.</p>
			<a href="<?php echo esc_url( admin_url() ); ?>" onclick="clearTimeout(reloader)">WP Admin Dashboard</a>
			<?php
			die;
		} // END if
		else
		{
			?>
			<p><a href="#stop" onclick="clearTimeout(reloader)">Stop</a></p>
			<?php
		} // END else

		foreach ( $query->posts as $post )
		{
			?>
			<hr />
			<p>
			    <?php echo absint( $post->ID ); ?><br />
			    <?php echo esc_html( $post->post_title ); ?><br />
			    <a href="<?php echo esc_url( get_edit_post_link() ); ?>">Edit Post</a>
			</p>
			<?php
			// Try to autotag post
			$results = $this->autotag_post( $post );

			if ( is_wp_error( $results ) )
			{
				echo '<p style="color: red;">ERROR: ' . esc_html( $results->get_error_message() ) . '</p>';
				continue;
			}//end if

			// Show the auto tagging results for this post
			?>
			<p>Suggested Tags by Taxonomy (<span style="color: green;">Applied</span>/<span style="color: red;">Skipped</span>)</p>
			<?php
			foreach ( $results as $taxonomy => $terms )
			{
				echo '<p>' . esc_html( $taxonomy ) . '</p>';
				echo '<ul>';

				foreach ( $terms as $term )
				{
					if ( $term['usable'] )
					{
						?>
						<li style="color: green;">
							<?php echo esc_html( $term['term'] . ' (RELEVANCE: ' . $term['rel'] . ')' ); ?>
						</li>
						<?php
					}//end if
					else
					{
						?>
						<li style="color: red;">
							<?php echo esc_html( $term['term'] . ' (RELEVANCE: ' . $term['rel'] . ')' ); ?>
						</li>
						<?php
					}//end else
				}//end foreach

				echo '</ul>';
			}//end foreach
		}//end foreach

		$args = array(
			'action' => 'go_opencalais_autotag',
			'num'    => $posts_per_page,
		);
		?>
		<p><em>Will reload to the next <?php echo absint( $posts_per_page ); ?>, every 5 seconds.</em></p>
		<script type="text/javascript">
		var reloader = window.setTimeout(function(){
			window.location = "?<?php echo esc_url( http_build_query( $args ) ); ?>";
		}, 5000);
		</script>
		<p>
			  <a href="#stop" onclick="clearTimeout(reloader)">Stop</a>
			| <a href="<?php echo esc_url( admin_url() ); ?>" onclick="clearTimeout(reloader)">WP Admin Dashboard</a>
		</p>
		<?php
		die;
	}//end autotag

	/**
	 * Autotag a post and return the taxonomies and terms it was autotagged with
	 */
	protected function autotag_post( $post )
	{
		$enrich = go_opencalais()->enrich( $post );

		$result = $enrich->enrich();

		if ( is_wp_error( $result ) )
		{
			return $result;
		}//end if

		$result = $enrich->save();

		if ( is_wp_error( $result ) )
		{
			return $result;
		}//end if

		// Array of all incoming suggested tags, regardless of relevancy or taxonomy.
		// $taxes[ $tax ][ $term ] = array( 'rel' => N, 'rel_orig' => N )
		$suggested_terms = array();

		// Terms to use, by local taxonomy.
		// $valid_terms[ $local_tax ] = array( $term [ , $term, ... ] )
		$valid_terms = array();

		foreach ( $enrich->response as $obj )
		{
			if ( ! isset( $obj->relevance ) )
			{
				continue;
			}//end if

			$rel       = $obj->relevance;
			$rel_orig  = $obj->_go_orig_relevance;
			$type      = $obj->_type;
			$term      = $obj->name;
			$local_tax = NULL;
			$usable    = $rel > $this->threshold;

			// does this type map to a local taxonomy?
			if ( isset( $this->mapping[ $type ] ) )
			{
				$local_tax = $this->mapping[ $type ];
			}//end if

			if ( ! isset( $suggested_terms[ $local_tax ] ) )
			{
				$suggested_terms[ $local_tax ] = array();
			}//end if

			$suggested_terms[ $local_tax ][ $term ] = compact( 'rel', 'rel_orig', 'local_tax', 'usable', 'term', 'type' );

			if ( $usable && $local_tax )
			{
				if( ! isset( $valid_terms[ $local_tax ] ) )
				{
					$valid_terms[ $local_tax ] = array();
				}//end if

				$valid_terms[ $local_tax ][] = $term;
			}//end if
		}//end foreach

		$valid_terms = apply_filters( 'go_opencalais_autotagger_terms', $valid_terms, $suggested_terms, $post );

		// append terms to the post
		foreach ( $valid_terms as $tax => $terms )
		{
			wp_set_object_terms( $post->ID, $terms, $tax, TRUE );
		}//end foreach

		$this->mark_autotagged( $post );

		return $suggested_terms;
	}//end autotag_post

	/**
	 * Filter the go_opencalais_content hook and include post_tag terms in the content
	 * @TODO Worth deciding if there's a point to this
	 */
	public function go_opencalais_content( $content, $post )
	{
		$term_list = get_the_term_list( $post_id, 'post_tag', '', '; ', '' );

		if ( ! empty( $term_list ) && ! is_wp_error( $term_list ) )
		{
			$content = $content . "\n\n" . (string) strip_tags( $term_list );
		}//end if

		return $post->post_title . "\n\n" . $post->post_excerpt . "\n\n" . $content;
	}//end go_opencalais_content

	/**
	 * Add the autotagged term to a given post
	 */
	protected function mark_autotagged( $post )
	{
		return wp_set_object_terms( $post->ID, go_opencalais()->config( 'autotagger' )['term'], go_opencalais()->slug . '-autotagger', TRUE );
	}//end mark_autotagged
}//end class
