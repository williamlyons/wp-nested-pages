<?php 

namespace NestedPages\Entities\Listing;

use NestedPages\Helpers;
use NestedPages\Entities\Confirmation\ConfirmationFactory;
use NestedPages\Entities\Post\PostDataFactory;
use NestedPages\Entities\Post\PostRepository;
use NestedPages\Entities\User\UserRepository;
use NestedPages\Entities\PostType\PostTypeRepository;
use NestedPages\Entities\Listing\ListingRepository;
use NestedPages\Config\SettingsRepository;
use NestedPages\Entities\PluginIntegration\IntegrationFactory;

/**
* Primary Post Listing
*/
class Listing 
{

	/**
	* Post Type
	* @var object WP Post Type Object
	*/
	private $post_type;

	/**
	* Hierarchical Taxonomies
	* @var array
	*/
	private $h_taxonomies;

	/**
	* Flat Taxonomies
	* @var array
	*/
	private $f_taxonomies;

	/**
	* Post Data Factory
	*/
	private $post_data_factory;

	/**
	* All Posts
	* @var array of post objects
	*/
	private $all_posts;

	/**
	* Post Data
	* @var object
	*/
	private $post;

	/**
	* Post Repository
	*/
	private $post_repo;

	/**
	* Post Type Repository
	*/
	private $post_type_repo;

	/**
	* Listing Repository
	*/
	private $listing_repo;

	/**
	* Confirmation Factory
	*/
	private $confirmation;

	/**
	* User Repository
	*/
	private $user;

	/**
	* Sorting Options
	* @var array
	*/
	private $sort_options;

	/**
	* Settings Repository
	*/
	private $settings;

	/**
	* Plugin Integrations
	*/
	private $integrations;


	public function __construct($post_type)
	{
		$this->setPostType($post_type);
		$this->integrations = new IntegrationFactory;
		$this->post_repo = new PostRepository;
		$this->user = new UserRepository;
		$this->confirmation = new ConfirmationFactory;
		$this->post_type_repo = new PostTypeRepository;
		$this->listing_repo = new ListingRepository;
		$this->post_data_factory = new PostDataFactory;
		$this->settings = new SettingsRepository;
	}

	/**
	* Called by Menu Class
	* Instantiates Listing Class
	* @since 1.2.0
	*/
	public static function admin_menu($post_type)
	{
		$class_name = get_class();
		$classinstance = new $class_name($post_type);
		return array(&$classinstance, "listPosts");
	}

	/**
	* Set the Sort Options
	*/
	private function setSortOptions()
	{
		$this->sort_options = new \StdClass();
		$this->sort_options->orderby = isset($_GET['orderby'])
			? sanitize_text_field($_GET['orderby'])
			: 'menu_order';
		$this->sort_options->order = isset($_GET['order'])
			? sanitize_text_field($_GET['order'])
			: 'ASC';
		$this->sort_options->author = isset($_GET['author'])
			? sanitize_text_field($_GET['author'])
			: null;
	}

	/**
	* Get the Current Page URL
	*/
	private function pageURL()
	{
		$base = ( $this->post_type->name == 'post' ) ? admin_url('edit.php') : admin_url('admin.php');
		return $base . '?page=' . $_GET['page'];
	}

	/**
	* Set the Post Type
	* @since 1.1.16
	*/
	private function setPostType($post_type)
	{
		$this->post_type = get_post_type_object($post_type);
	}

	/**
	* The Main View
	* Replaces Default Post Listing
	*/
	public function listPosts()
	{
		$this->setSortOptions();
		include( Helpers::view('listing') );
	}

	/**
	* Set the Taxonomies for Post Type
	*/
	private function setTaxonomies()
	{
		$this->h_taxonomies = $this->post_type_repo->getTaxonomies($this->post_type->name, true);
		$this->f_taxonomies = $this->post_type_repo->getTaxonomies($this->post_type->name, false);
	}

	/**
	* Opening list tag <ol>
	* @param array $pages - array of page objects from current query
	* @param int $count - current count in loop
	*/
	private function listOpening($pages, $count, $sortable = true)
	{

		if ( $this->isSearch() ) $sortable = false;

		// Get array of child pages
		$children = array();
		$all_children = $pages;
		foreach($all_children as $child){
			array_push($children, $child->ID);
		}

		// Compare child pages with user's toggled pages
		$compared = array_intersect($this->listing_repo->visiblePages($this->post_type->name), $children);

		// Primary List
		if ( $count == 0 ) {
			echo ( $this->user->canSortPages() && $sortable ) 
				? '<ol class="sortable nplist visible" id="np-' . $this->post_type->name . '">' 
				: '<ol class="sortable no-sort nplist visible" id="np-' . $this->post_type->name . '">';
			return;
		}

		// Don't create new list for child elements of posts in trash
		// if ( $this->all_posts[$count - 1]->post_status == 'trash' ) return;

		echo '<ol class="nplist';
		if ( count($compared) > 0 ) echo ' visible" style="display:block;';
		echo '" id="np-' . $this->post_type->name . '">';
		 
	}

	/**
	* Set Post Data
	* @param object post object
	*/
	private function setPost($post)
	{
		$this->post = $this->post_data_factory->build($post, $this->h_taxonomies, $this->f_taxonomies);
	}

	/**
	* Get count of published child posts
	* @param object $post
	*/
	private function publishedChildrenCount($post)
	{
		$publish_count = 0;
		foreach ( $this->all_posts as $p ){
			if ( $p->post_parent == $post->id && $p->post_status !== 'trash' ) $publish_count++;
		}
		return $publish_count;
	}

	/**
	* Is this a search
	* @return boolean
	*/
	private function isSearch()
	{
		return ( isset($_GET['search']) && $_GET['search'] !== "" ) ? true : false;
	}

	/**
	* Is the list filtered?
	*/ 
	private function isFiltered()
	{
		return ( isset($_GET['category']) && $_GET['category'] !== "all" ) ? true : false;
	}

	/**
	* Loop through all the pages and create the nested / sortable list
	* Recursive Method, called in page.php view
	*/
	private function getPosts()
	{
		$this->setTaxonomies();
		$this->getAllPosts();
		$this->listPostLevel();
		return;
	}

	/**
	* Get All the Posts
	*/
	private function getAllPosts()
	{
		if ( $this->post_type->name == 'page' ) {
			$post_type = array('page');
			if ( !$this->settings->menusDisabled() ) $post_type[] = 'np-redirect';
		} else {
			$post_type = array($this->post_type->name);
		}
		
		$query_args = array(
			'post_type' => $post_type,
			'posts_per_page' => -1,
			'author' => $this->sort_options->author,
			'orderby' => $this->sort_options->orderby,
			'post_status' => array('publish', 'pending', 'draft', 'private', 'future', 'trash'),
			'order' => $this->sort_options->order
		);
		
		if ( $this->isSearch() ) $query_args = $this->searchParams($query_args);
		if ( $this->isFiltered() ) $query_args = $this->filterParams($query_args);
		
		$query_args = apply_filters('nestedpages_page_listing', $query_args);
		
		add_filter( 'posts_clauses', array($this, 'queryFilter') );
		$all_posts = new \WP_Query($query_args);
		remove_filter( 'posts_clauses', array($this, 'queryFilter') );
		
		if ( $all_posts->have_posts() ) :
			$this->all_posts = $all_posts->posts;
		endif; wp_reset_postdata();
	}

	/**
	* List a single tree node of posts
	*/
	private function listPostLevel($parent = 0, $count = 0, $level = 1)
	{
		$continue_nest = true;
		$continue_nest = apply_filters('nestedpages_page_nesting', $continue_nest, $level);

		if ( !$this->isSearch() ){
			$pages = get_page_children($parent, $this->all_posts);
			if ( !$pages ) return;
			$parent_status = get_post_status($parent);
			$level++;
			if ( $parent_status !== 'trash' ) $this->listOpening($pages, $count);
		} else {
			$pages = $this->all_posts;
			echo '<ol class="sortable no-sort nplist visible">';
		}

		foreach($pages as $page) :

			if ( $page->post_parent !== $parent && !$this->isSearch() ) continue;
			$count++;
			
			global $post;
			$post = $page;
			$this->setPost($post);

			if ( $this->post->status !== 'trash' ) :

				echo '<li id="menuItem_' . $this->post->id . '" class="page-row';

				// Published?
				if ( $this->post->status == 'publish' ) echo ' published';
				if ( $this->post->status == 'draft' ) echo ' draft';
				
				// Hidden in Nested Pages?
				if ( $this->post->np_status == 'hide' ) echo ' np-hide';

				// Taxonomies
				echo $this->addTaxonomyCss();
				
				echo '">';
				
				$count++;

				$row_view = ( $this->post->type !== 'np-redirect' ) ? 'partials/row' : 'partials/row-link';
				include( Helpers::view($row_view) );

			endif; // trash status
			
			if ( !$this->isSearch() && $continue_nest ) $this->listPostLevel($page->ID, $count, $level);
			
			if ( $this->post->status !== 'trash' ) echo '</li>';
			
			if ( $this->publishedChildrenCount($this->post) > 0 && !$this->isSearch() && $continue_nest ) echo '</ol>';
		
		endforeach;

		if ( $parent_status !== 'trash' ) echo '</ol><!-- list close -->';
	}

	/**
	* Search Posts
	*/
	private function searchParams($query_args)
	{
		$query_args['post_title_like'] = sanitize_text_field($_GET['search']);
		return $query_args;
	}

	/**
	* Filter Posts
	*/
	private function filterParams($query_args)
	{
		if ( !isset($_GET['category']) ) return $query_args;
		$query_args['cat'] = sanitize_text_field($_GET['category']);
		return $query_args;
	}

	/**
	* Query filter to add taxonomies to return data
	* Fixes N+1 problem with taxonomies, eliminating need to query on every post
	*/
	public function queryFilter($pieces)
	{
		global $wpdb;
		
		// Add Hierarchical Categories
		foreach($this->h_taxonomies as $tax){
			$name = $tax->name;
			$tr = 'tr_' . $tax->name;
			$tt = 'tt_' . $tax->name;
			$t = 't_' . $tax->name;

			$pieces['join'] .= "
				LEFT JOIN $wpdb->term_relationships AS $tr ON $tr.object_id = $wpdb->posts.ID
				LEFT JOIN $wpdb->term_taxonomy $tt ON $tt.term_taxonomy_id = $tr.term_taxonomy_id AND $tt.taxonomy = '$name'
				LEFT JOIN $wpdb->terms AS $t ON $t.term_id = $tt.term_id";
			$pieces['fields'] .= ",GROUP_CONCAT(DISTINCT $t.term_id SEPARATOR ',') AS $name";
		}

		// Add Flat Categories
		foreach($this->f_taxonomies as $tax){
			$name = $tax->name;
			$tr = 'tr_' . $tax->name;
			$tt = 'tt_' . $tax->name;
			$t = 't_' . $tax->name;

			$pieces['join'] .= "
				LEFT JOIN $wpdb->term_relationships AS $tr ON $tr.object_id = $wpdb->posts.ID
				LEFT JOIN $wpdb->term_taxonomy $tt ON $tt.term_taxonomy_id = $tr.term_taxonomy_id AND $tt.taxonomy = '$name'
				LEFT JOIN $wpdb->terms AS $t ON $t.term_id = $tt.term_id";
			$pieces['fields'] .= ",GROUP_CONCAT(DISTINCT $t.term_id SEPARATOR ',') AS $name";
		}

		$pieces['groupby'] = "$wpdb->posts.ID"; 		
		return $pieces;
	}

	/**
	* Add taxonomy css classes
	*/
	private function addTaxonomyCss()
	{
		$out = ' ';
		
		// Build Hierarchical string
		if ( count($this->h_taxonomies) > 0 ) {
			foreach ( $this->h_taxonomies as $taxonomy ){
				$taxname = $taxonomy->name;
				if ( !isset($this->post->$taxname) ) continue;
				$terms = $this->post->$taxname;
				foreach ( $terms as $term ){
					$out .= 'in-' . $taxonomy->name . '-' . $term . ' ';
				}
			}
		}

		// Build Non-Hierarchical string
		if ( count($this->f_taxonomies) > 0 ) {
			foreach ( $this->f_taxonomies as $taxonomy ){
				$taxname = $taxonomy->name;
				if ( !isset($this->post->$taxname) ) continue;
				$terms = $this->post->$taxname;
				foreach ( $terms as $term ){
					$out .= 'inf-' . $taxonomy->name . '-nps-' . $term . ' ';
				}
			}
		}
		return $out;
	}

}