<?php
namespace nntmux;

use nntmux\db\Settings;

/**
 * This class handles data access for forum and post data.
 */
class Forum
{
	/**
	 * Add a forum post.
	 *
	 * @param     $parentid
	 * @param     $userid
	 * @param     $subject
	 * @param     $message
	 * @param int $locked
	 * @param int $sticky
	 * @param int $replies
	 *
	 * @return int
	 */
	public function add($parentid, $userid, $subject, $message, $locked = 0, $sticky = 0, $replies = 0)
	{
		$db = new Settings();

		if ($message == "")
			return -1;

		if ($parentid != 0)
		{
			$par = $this->getParent($parentid);
			if ($par == false)
				return -1;

			$db->queryExec(sprintf("update forumpost set replies = replies + 1, updateddate = now() where id = %d", $parentid));
		}

		$db->queryInsert(sprintf("INSERT INTO `forumpost` (`forumID`,`parentid`,`users_id`,`subject`,`message`, `locked`, `sticky`, `replies`, `createddate`, `updateddate`) VALUES ( 1,  %d, %d,  %s,  %s, %d, %d, %d,NOW(),  NOW())",
			$parentid, $userid, $db->escapeString($subject)	, $db->escapeString($message), $locked, $sticky, $replies));
	}

	/**
	 * Get the top level post in a thread.
	 *
	 * @param $parent
	 *
	 * @return array|bool
	 */
	public function getParent($parent)
	{
		$db = new Settings();
		return $db->queryOneRow(sprintf(" SELECT forumpost.*, users.username from forumpost left outer join users on users.id = forumpost.users_id where forumpost.id = %d ", $parent));
	}

	/**
	 * Get recent posts.
	 *
	 * @param $limit
	 *
	 * @return array
	 */
	public function getRecentPosts($limit)
	{
		$db = new Settings();
		return $db->query(sprintf("select forumpost.*, users.username from forumpost join (select case when parentid = 0 then id else parentid end as id, max(createddate) from forumpost group by case when parentid = 0 then id else parentid end order by max(createddate) desc) x on x.id = forumpost.id inner join users on users_id = users.id limit %d", $limit));
	}


	/**
	 * Get all child posts for a parent.
	 *
	 * @param $parent
	 *
	 * @return array
	 */
	public function getPosts($parent)
	{
		$db = new Settings();
		return $db->query(sprintf(" SELECT forumpost.*, CASE WHEN role=%d THEN 1 ELSE 0 END  AS 'isadmin', users.username from forumpost left outer join users on users.id = forumpost.users_id where forumpost.id = %d or parentid = %d order by createddate asc limit 250", Users::ROLE_ADMIN, $parent, $parent));
	}

	/**
	 * Get a forumpost by its id.
	 *
	 * @param $id
	 *
	 * @return array|bool
	 */
	public function getPost($id)
	{
		$db = new Settings();
		return $db->queryOneRow(sprintf(" SELECT * from forumpost where id = %d", $id));
	}

	/**
	 * Get a count of all forum posts.
	 */
	public function getBrowseCount()
	{
		$db = new Settings();
		$res = $db->queryOneRow(sprintf("select count(id) as num from forumpost where parentid = 0"));
		return $res["num"];
	}

	/**
	 * Get a list of forum posts for browse list by limit.
	 *
	 * @param $start
	 * @param $num
	 *
	 * @return array
	 */
	public function getBrowseRange($start, $num)
	{
		$db = new Settings();

		if ($start === false)
			$limit = "";
		else
			$limit = " LIMIT ".$start.",".$num;

		return $db->query(sprintf(" SELECT forumpost.*, users.username from forumpost left outer join users on users.id = forumpost.users_id where parentid = 0 order by updateddate desc".$limit ));
	}

	/**
	 * Delete an entire thread.
	 *
	 * @param $parent
	 */
	public function deleteParent($parent)
	{
		$db = new Settings();
		$db->queryExec(sprintf("DELETE from forumpost where id = %d or parentid = %d", $parent, $parent));
	}

	/**
	 * Delete a forumpost row.
	 *
	 * @param $id
	 */
	public function deletePost($id)
	{
		$db = new Settings();
		$post = $this->getPost($id);
		if ($post)
		{
			if ($post["parentid"] == "0")
				$this->deleteParent($id);
			else
				$db->queryExec(sprintf("DELETE from forumpost where id = %d", $id));
		}
	}

	/**
	 * Delete all forumposts for a user.
	 *
	 * @param $id
	 */
	public function deleteUser($id)
	{
		$db = new Settings();
		$db->queryExec(sprintf("DELETE from forumpost where users_id = %d", $id));
	}

	/**
	 * Count of all posts for a user.
	 *
	 * @param $uid
	 *
	 * @return
	 */
	public function getCountForUser($uid)
	{
		$db = new Settings();
		$res = $db->queryOneRow(sprintf("select count(id) as num from forumpost where users_id = %d", $uid));
		return $res["num"];
	}

	/**
	 * Get forum posts for a user for paged list in profile.
	 *
	 * @param $uid
	 * @param $start
	 * @param $num
	 *
	 * @return array
	 */
	public function getForUserRange($uid, $start, $num)
	{
		$db = new Settings();

		if ($start === false)
			$limit = "";
		else
			$limit = " LIMIT ".$start.",".$num;

		return $db->query(sprintf(" SELECT forumpost.*, users.username FROM forumpost LEFT OUTER JOIN users ON users.id = forumpost.users_id where users_id = %d order by forumpost.createddate desc ".$limit, $uid));
	}
}
