<?php
/**
*
* @package Titania
* @version $Id$
* @copyright (c) 2008 phpBB Customisation Database Team
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

class posts_overlord
{
	/**
	* Posts array
	* Stores [id] => post object
	*
	* @var array
	*/
	public static $posts = array();

	public static $sort_by = array(
		'a' => array('AUTHOR', 'u.username_clean'),
		't' => array('POST_TIME', 'p.post_time', true),
		's' => array('SUBJECT', 'p.post_subject'),
	);

	/**
	 * Get the post object
	 *
	 * @param <int> $post_id
	 * @return <object|bool> False if the post does not exist in the self::$posts array (load it first!) post object if it exists
	 */
	public static function get_post_object($post_id)
	{
		if (!isset(self::$posts[$post_id]))
		{
			return false;
		}

		$post = new titania_post();
		$post->__set_array(self::$posts[$post_id]);

		return $post;
	}

/*
user_post_show_days
user_post_sortby_type
user_post_sortby_dir

$sort_dir_text = array('a' => $user->lang['ASCENDING'], 'd' => $user->lang['DESCENDING']);

// Post ordering options
$limit_post_days = array(0 => $user->lang['ALL_POSTS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);

$sort_by_post_text = array('a' => $user->lang['AUTHOR'], 't' => $user->lang['POST_TIME'], 's' => $user->lang['SUBJECT']);
$sort_by_post_sql = array('a' => 'u.username_clean', 't' => 'p.post_id', 's' => 'p.post_subject');

*/

	/**
	* Do everything we need to display the forum like page
	*
	* @param object $topic the topic object
	*/
	public static function display_topic_complete($topic)
	{
		// Setup the sort tool
		$sort = new titania_sort();
		$sort->set_sort_keys(self::$sort_by);
		if (isset(self::$sort_by[phpbb::$user->data['user_post_sortby_type']]))
		{
			$sort->default_key = phpbb::$user->data['user_post_sortby_type'];
		}
		$sort->default_dir = phpbb::$user->data['user_post_sortby_dir'];

		// Setup the pagination tool
		$pagination = new titania_pagination();
		$pagination->default_limit = phpbb::$config['posts_per_page'];
		$pagination->request();

		// if a post_id was given we must start from the appropriate page
		$post_id = request_var('p', 0);
		if ($post_id)
		{
			$sql = 'SELECT COUNT(p.post_id) as start FROM ' . TITANIA_POSTS_TABLE . ' p
				WHERE p.post_id < ' . $post_id . '
					AND p.topic_id = ' . $topic->topic_id . '
				ORDER BY ' . $sort->get_order_by();
			phpbb::$db->sql_query($sql);
			$start = phpbb::$db->sql_fetchfield('start');
			phpbb::$db->sql_freeresult();

			$pagination->start = ($start > 0) ? (floor($start / $pagination->limit) * $pagination->limit) : 0;
		}

		// check to see if they want to view the latest unread post
		if (request_var('view', '') == 'unread')
		{
			$mark_time = titania_tracking::get_track(TITANIA_TRACK_TOPICS, $topic->topic_id);

			if ($mark_time > 0)
			{
				$sql = 'SELECT COUNT(p.post_id) as start FROM ' . TITANIA_POSTS_TABLE . ' p
					WHERE p.post_time <= ' . $mark_time . '
						AND p.topic_id = ' . $topic->topic_id . '
					ORDER BY post_time ASC';
				phpbb::$db->sql_query($sql);
				$start = phpbb::$db->sql_fetchfield('start');
				phpbb::$db->sql_freeresult();

				$pagination->start = ($start > 0) ? (floor($start / $pagination->limit) * $pagination->limit) : 0;
			}
		}

/*
user_topic_show_days

$limit_topic_days = array(0 => $user->lang['ALL_TOPICS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);
*/

		self::display_topic($topic, $sort, $pagination);
		self::assign_common();

		phpbb::$template->assign_vars(array(
			'S_IS_LOCKED'		=> (bool) $topic->topic_locked,
		));
	}

	/**
	* Display topic section for support/tracker/etc
	*
	* @param object $topic The topic object
	* @param object|boolean $sort The sort object (includes/tools/sort.php)
	* @param object|boolean $pagination The pagination object (includes/tools/pagination.php)
	*/
	public static function display_topic($topic, $sort = false, $pagination = false)
	{
		$sql_ary = array(
			'SELECT' => 'p.*',
			'FROM'		=> array(
				TITANIA_POSTS_TABLE => 'p',
			),
			'WHERE' => 'p.post_access >= ' . titania::$access_level . '
				AND p.topic_id = ' . (int) $topic->topic_id,
			'ORDER_BY'	=> 'p.post_time ASC',
		);

		// Sort options
		if ($sort !== false)
		{
			$sql_ary['ORDER_BY'] = $sort->get_order_by();
		}

		// Main SQL Query
		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		// Handle pagination
		$pagination->sql_count($sql_ary, 'post_id');
		$pagination->build_pagination($topic->get_url());

		// Get the data
		$post_ids = $user_ids = array();
		$last_post_time = 0;  // tracking
		$result = phpbb::$db->sql_query_limit($sql, $pagination->limit, $pagination->start);
		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			self::$posts[$row['post_id']] = $row;

			$post_ids[] = $row['post_id'];
			$user_ids[] = $row['post_user_id'];

			$last_post_time = $row['post_time']; // to set tracking
		}
		phpbb::$db->sql_freeresult($result);

		// Grab the tracking data
		$last_mark_time = titania_tracking::get_track(TITANIA_TRACK_TOPICS, $topic->topic_id);

		// Store tracking data
		titania_tracking::track(TITANIA_TRACK_TOPICS, $topic->topic_id, $last_post_time);

		// load the user data
		users_overlord::load($user_ids);

		$post = new titania_post();

		// Loop de loop
		$prev_post_time = 0;
		foreach ($post_ids as $post_id)
		{
			$post->__set_array(self::$posts[$post_id]);

			phpbb::$template->assign_block_vars('posts', array_merge(
				$post->assign_details(),
				users_overlord::assign_details($post->post_user_id),
				array(
					'S_FIRST_UNREAD'	=> ($post->post_time >= $last_mark_time && $prev_post_time < $last_mark_time) ? true : false,
				)
			));
	//S_IGNORE_POST
	//POST_ICON_IMG
	//MINI_POST_IMG

			$prev_post_time = $post->post_time;
		}

		unset($post);
	}

	public static function assign_common()
	{
		phpbb::$template->assign_vars(array(
			'REPORT_IMG'		=> phpbb::$user->img('icon_post_report', 'REPORT_POST'),
			'REPORTED_IMG'		=> phpbb::$user->img('icon_topic_reported', 'TOPIC_REPORTED'),
			'UNAPPROVED_IMG'	=> phpbb::$user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
			'WARN_IMG'			=> phpbb::$user->img('icon_user_warn', 'WARN_USER'),

			'EDIT_IMG' 			=> phpbb::$user->img('icon_post_edit', 'EDIT_POST'),
			'DELETE_IMG' 		=> phpbb::$user->img('icon_post_delete', 'DELETE_POST'),
			'INFO_IMG' 			=> phpbb::$user->img('icon_post_info', 'VIEW_INFO'),
			'PROFILE_IMG'		=> phpbb::$user->img('icon_user_profile', 'READ_PROFILE'),
			'SEARCH_IMG' 		=> phpbb::$user->img('icon_user_search', 'SEARCH_USER_POSTS'),
			'PM_IMG' 			=> phpbb::$user->img('icon_contact_pm', 'SEND_PRIVATE_MESSAGE'),
			'EMAIL_IMG' 		=> phpbb::$user->img('icon_contact_email', 'SEND_EMAIL'),
			'WWW_IMG' 			=> phpbb::$user->img('icon_contact_www', 'VISIT_WEBSITE'),
			'ICQ_IMG' 			=> phpbb::$user->img('icon_contact_icq', 'ICQ'),
			'AIM_IMG' 			=> phpbb::$user->img('icon_contact_aim', 'AIM'),
			'MSN_IMG' 			=> phpbb::$user->img('icon_contact_msnm', 'MSNM'),
			'YIM_IMG' 			=> phpbb::$user->img('icon_contact_yahoo', 'YIM'),
			'JABBER_IMG'		=> phpbb::$user->img('icon_contact_jabber', 'JABBER') ,
		));
	}
}