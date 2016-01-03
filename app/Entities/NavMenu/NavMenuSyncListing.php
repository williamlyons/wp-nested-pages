<?php 

namespace NestedPages\Entities\NavMenu;

use NestedPages\Entities\NavMenu\NavMenuSync;
use NestedPages\Helpers;
use NestedPages\Entities\Post\PostDataFactory;

/**
* Syncs the Generated Menu to Match the Listing
*/
class NavMenuSyncListing extends NavMenuSync 
{

	/**
	* Individual Post
	* @var array
	*/
	private $post;

	/**
	* All Posts
	*/
	private $all_posts;

	/**
	* Menu Position Count
	* @var int
	*/
	private $count = 0;

	/**
	* Nest Level
	* @var int
	*/
	private $nest_level = 0;

	/**
	* Post Data Factory
	*/
	private $post_factory;

	public function __construct()
	{
		parent::__construct();
		$this->post_factory = new PostDataFactory;
	}

	/**
	* Recursive function loops through pages/links and their children
	*/
	public function sync()
	{	
		$this->getAllPosts();
		$this->syncLevel();
	}

	/**
	* Get All the Pages & Links
	*/
	private function getAllPosts()
	{		
		$query_args = array(
			'post_type' => array('page', 'np-redirect'),
			'posts_per_page' => -1,
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'post_status' => 'publish'
		);
		$query_args = apply_filters('nestedpages_page_menu', $query_args);
		$all_posts = new \WP_Query($query_args);		
		if ( $all_posts->have_posts() ) :
			$this->all_posts = $all_posts->posts;
		endif; wp_reset_postdata();
	}

	/**
	* Loop through a single level of posts and sync menu items
	*/
	private function syncLevel($parent = 0, $menu_parent = 0)
	{
		$continue_nest = true;
		$continue_nest = apply_filters('nestedpages_menu_nesting', $continue_nest, $this->nest_level);
		if ( !$continue_nest ) return; // TODO: remove items past this level from the menu

		$pages = $this->getChildren($parent, $this->all_posts);
		if ( empty($pages) ) {
			if ( $this->nest_level > 0 ) $this->nest_level--;
			return;
		}
		
		foreach($pages as $page) :
			if ( $page->post_parent !== $parent ) continue;
			$this->count++;
			global $post;
			$post = $page;
			$this->post = $this->post_factory->build($post);
			// var_dump($this->nest_level);
			$this->syncPost($menu_parent);
		endforeach;
		$this->nest_level++;
	}

	/**
	* Sync an individual item
	* @since 1.3.4
	*/
	private function syncPost($menu_parent)
	{
		// Get the Menu Item
		$query_type = ( $this->post->type == 'np-redirect' ) ? 'xfn' : 'object_id';
		$menu_item_id = $this->nav_menu_repo->getMenuItem($this->post->id, $query_type);
		if ( $this->post->nav_status == 'hide' ) return $this->removeItem($menu_item_id);
		$menu = $this->syncMenuItem($menu_parent, $menu_item_id);
		$this->syncLevel( $this->post->id, $menu );
	}

	/**
	* Sync Link Menu Item
	* @since 1.1.4
	*/
	private function syncMenuItem($menu_parent, $menu_item_id)
	{
		$type = ( $this->post->nav_type ) ? $this->post->nav_type : 'custom';
		$object = ( $this->post->nav_object ) ? $this->post->nav_object : 'custom';
		$object_id = ( $this->post->nav_object_id  ) ? intval($this->post->nav_object_id) : null;
		$url = ( $type == 'custom' ) ? esc_url($this->post->content) : '';
		$xfn = $this->post->id;
		$title = ( $this->post->nav_title ) ? $this->post->nav_title : $this->post->title;
		
		// Compatibility for 1.4.1 - Reset Page links
		if ( $this->post->type == 'page' ){
			$type = 'post_type';
			$object = 'page';
			$object_id = $this->post->id;
			$xfn = 'page';
		}
		
		$args = array(
			'menu-item-title' => $title,
			'menu-item-position' => $this->count,
			'menu-item-url' => $url,
			'menu-item-attr-title' => $this->post->nav_title_attr,
			'menu-item-status' => 'publish',
			'menu-item-classes' => $this->post->nav_css,
			'menu-item-type' => $type,
			'menu-item-object' => $object,
			'menu-item-object-id' => $object_id,
			'menu-item-parent-id' => $menu_parent,
			'menu-item-xfn' => $xfn,
			'menu-item-target' => $this->post->link_target
		);
		$menu = wp_update_nav_menu_item($this->id, $menu_item_id, $args);
		return $menu;
	}

	/**
	* Filter out page children
	* @return array
	*/
	private function getChildren($parent, $posts)
	{
		foreach ( $posts as $key => $post ){
			if ( $post->post_parent !== $parent ) unset($posts[$key]);
		}
		return $posts;
	}

}