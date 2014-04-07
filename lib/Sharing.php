<?php
require_once(dirname(__FILE__)."/../bin/config.php");
require_once(WWW_DIR."lib/framework/db.php");
require_once(WWW_DIR.'/lib/nntp.php');
require_once("Yenc.php");
require_once("functions.php");


/**
 * Class Sharing
 */
Class Sharing
{
	/**
	 *      --------------------------------------------
	 *      sharing_sites table (contains remote sites):
	 *      --------------------------------------------
	 *      ID            ID of the site.
	 *      site_name     Name of the site.
	 *      site_guid     Unique hash identifier for the site.
	 *      last_time     Newest comment time for this site.
	 *      first_time    Oldest comment time for this site.
	 *      enabled       Have we enabled this site?
	 *      comments      How many comments has this site given us so far?
	 *
	 *      -------------------------------------------
	 *      sharing table (contains local settings):
	 *      -------------------------------------------
	 *      site_guid     Unique identifier for our site.
	 *      site_name     Our site name.
	 *      enabled       Is sharing/fetching enabled or disabled (overrides settings below)?
	 *      posting       Should we upload our comments?
	 *      fetching      Should we fetch remote comments?
	 *      auto_enable   Should we auto_enable new sites?
	 *      hide_users    Hide usernames before uploading comments?
	 *      last_article  Last article number we downloaded from usenet.
	 *      max_push      Max comments to upload per run.
	 *      max_pull      Max articles to download per run.
	 *
	 *      -------------------------------------------
	 *      releasecomments table (modifications)
	 *      -------------------------------------------
	 *      shared        Has this comment been shared or have we received it from another site. (0 not shared, 1 shared, 2 received)
	 *      shareID       Unique identifier to know if we already have the comment or not.
	 *      nzb_guid      Guid of the NZB's first message-id.
	 */

	/**
	 * @var DB
	 */
	protected $db;

	/**
	 * @var NNTP
	 */
	protected $nntp;

	/**
	 * @var Yenc
	 */
	protected $yEnc;

	/**
	 * Array containing site settings.
	 * @var array
	 */
	protected $siteSettings = array();

	/**
	 * Group to work in.
	 * @const
	 */
	const group = 'alt.binaries.zines';

	/**
	 * Construct.
	 *
	 * @param DB $db
	 * @param NNTP $nntp
	 */
	public function __construct(&$db = null, &$nntp = null)
	{
		if (!is_null($db)) {
			$this->db = $db;
		} else {
			$this->db = new DB();
		}

		// Get all sharing info from DB.
		$check = $this->db->queryOneRow('SELECT * FROM sharing');

		// Initiate sharing settings if this is the first time..
		if (empty($check)) {
			$check = $this->initSettings();
		}

		// Second check to make sure nothing went wrong.
		if (empty($check)) {
			return;
		}

		$this->nntp = $nntp;

		// Cache sharing settings.
		$this->siteSettings = $check;
		unset($check);

		// Convert to bool to speed up checking.
		$this->siteSettings['hide_users'] = ($this->siteSettings['hide_users'] == 1 ? true : false);
		$this->siteSettings['auto_enable'] = ($this->siteSettings['auto_enable'] == 1 ? true : false);
		$this->siteSettings['posting'] = ($this->siteSettings['posting'] == 1 ? true : false);
		$this->siteSettings['fetching'] = ($this->siteSettings['fetching'] == 1 ? true : false);
		$this->siteSettings['enabled'] = ($this->siteSettings['enabled'] == 1 ? true : false);
		$this->siteSettings['start_position'] = ($this->siteSettings['start_position'] == 1 ? true : false);

        //read functions from functions.php
        $this->functions = new Functions();
	}

	/**
	 * Main method.
	 */
	public function start()
	{
		// Admin has disabled sharing so return.
		if ($this->siteSettings['enabled'] === false) {
			return;
		}

		$this->yEnc = new Yenc();
		if (is_null($this->nntp)) {
			$this->nntp = new NNTP();
			$this->nntp->doConnect();
		}

		if ($this->siteSettings['posting']) {
			$this->postAll();
		}
		if ($this->siteSettings['fetching']) {
			$this->fetchAll();
		}
		$this->matchComments();
	}

	/**
	 * Initialise of reset sharing settings.
	 *
	 * @param string $siteGuid Optional hash (must be sha1) we can set the site guid to.
	 *
	 * @return array|bool
	 */
	public function initSettings(&$siteGuid = '')
	{
		$this->db->exec('TRUNCATE TABLE sharing');
		$siteName = uniqid('newznab_', true);
		$this->db->exec(
			sprintf('
				INSERT INTO sharing (site_name, site_guid, max_push, max_pull, hide_users, start_position, auto_enable, fetching)
				VALUES (%s, %s, 40 , 200, 1, 1, 1, 1)',
				$this->db->escapeString($siteName),
				$this->db->escapeString(($siteGuid === '' ? sha1($siteName) : $siteGuid))
			)
		);
		return $this->db->queryOneRow('SELECT * FROM sharing');
	}

	/**
	 * Post all new comments to usenet.
	 */
	protected function postAll()
	{
		// Get all comments that we have not posted yet.
		$newComments = $this->db->query(
			sprintf(
				'SELECT rc.text, rc.ID, %s, u.username, r.gid as nzb_guid
				FROM releasecomment rc
				INNER JOIN users u ON rc.userID = u.ID
				INNER JOIN releases r on rc.releaseID = r.ID
				WHERE rc.shared = 0 LIMIT %d',
				$this->functions->unix_timestamp_column('rc.createddate'),
				$this->siteSettings['max_push']
			)
		);

		// Check if we have any comments to push.
		if (count($newComments) === 0) {
			return;
		}
			echo '(Sharing) Starting to upload comments.' . PHP_EOL;


		// Loop over the comments.
		foreach($newComments as $comment) {
			$this->postComment($comment);
		}
			echo PHP_EOL . '(Sharing) Finished uploading comments.' . PHP_EOL;
	}

	/**
	 * Post a comment to usenet.
	 *
	 * @param array $row
	 */
	protected function postComment(&$row)
	{
		// Create a unique identifier for this comment.
		$sid = sha1($row['unix_time'] . $row['text'] . $row['nzb_guid']);

		// Example of a subject.
		//(_nZEDb_)nZEDb_533f16e46a5091.73152965_3d12d7c1169d468aaf50d5541ef02cc88f3ede10 - [1/1] "92ba694cebc4fbbd0d9ccabc8604c71b23af1131" (1/1) yEnc

		// Attempt to upload the comment to usenet.
		$success = $this->nntp->postArticle(
			self::group,
			('(_nZEDb_)' . $this->siteSettings['site_name'] . '_' . $this->siteSettings['site_guid'] . ' - [1/1] "' . $sid . '" yEnc (1/1)'),
			json_encode(
				array(
					'USER'  => ($this->siteSettings['hide_users'] ? 'ANON' : $row['username']),
					'TIME'  => $row['unix_time'],
					'SID'   => $sid,
					'RID'   => $row['nzb_guid'],
					'BODY'  => $row['text']
				)
			),
			'<anon@anon.com>'
		);

		// Check if we succesfully uploaded it.
		if ($this->nntp->isError($success) === false && $success === true) {

			// Update DB to say we posted the article.
			$this->db->exec(
				sprintf('
					UPDATE releasecomment
					SET shared = 1, shareID = %s
					WHERE ID = %d',
					$this->db->escapeString($sid),
					$row['ID']
				)
			);
				echo '.';
		}
	}

	/**
	 * Match added comments to releases.
	 */
	protected function matchComments()
	{
		$res = $this->db->query('
			SELECT r.ID, r.gid as nzb_guid
			FROM releases r
			INNER JOIN releasecomment rc ON rc.nzb_guid = r.gid
			WHERE rc.releaseID = 0'
		);

		$found = count($res);
		if ($found > 0) {
			foreach ($res as $row) {
				$this->db->exec(
					sprintf(
						"UPDATE releasecomment SET releaseID = %d, gid = %s WHERE nzb_guid = %s",
						$row['ID'],
                        $this->db->escapeString($row['nzb_guid']),
						$this->db->escapeString($row['nzb_guid'])
					)
				);
				$this->db->exec(sprintf('UPDATE releases SET comments = comments + 1 WHERE ID = %d', $row['ID']));
			}
				echo '(Sharing) Matched ' . $found . ' comments.' . PHP_EOL;
		}
	}

	/**
	 * Get all new comments from usenet.
	 */
	protected function fetchAll()
	{
		// Get NNTP group data.
		$group = $this->nntp->selectGroup(self::group, false, true);

		// Check if there's an issue.
		if ($this->nntp->isError($group)) {
			return;
		}

		// Check if this is the first time, set our oldest article.
		if ($this->siteSettings['last_article'] == 0) {
			// If the user picked to start from the oldest, get the oldest.
			if ($this->siteSettings['start_position'] === true) {
					echo '(Sharing) This is the first time running sharing so we will get the first article which will take a few seconds.' . PHP_EOL;
            $this->siteSettings['last_article'] = $ourOldest = (string)($group['first']);

			// Else get the newest.
			} else {
				$this->siteSettings['last_article'] = $ourOldest = (string)($group['last'] - 1000);
			}
		} else {
			$ourOldest = (string)($this->siteSettings['last_article'] + 1);
		}

		// Set our newest to our oldest wanted + max pull setting.
		$newest = (string)($ourOldest + $this->siteSettings['max_pull']);

		// Check if our newest wanted is newer than the group's newest, set to group's newest.
		if ($newest >= $group['last']) {
			$newest = $group['last'];
		}

		// We have nothing to do, so return.
		if ($ourOldest > $newest) {
			return;
		}
			echo '(Sharing) Starting to fetch new comments.' . PHP_EOL;

		// Get the wanted aritcles
		$headers = $this->nntp->getOverview($ourOldest . '-' . $newest, true, false);

		// Check if we received nothing or there was an error.
		if ($this->nntp->isError($headers) || count($headers) === 0) {
			return;
		}

		$found = 0;
		// Loop over NNTP headers until we find comments.
		foreach ($headers as $header) {
			//(_nZEDb_)nZEDb_533f16e46a5091.73152965_3d12d7c1169d468aaf50d5541ef02cc88f3ede10 - [1/1] "92ba694cebc4fbbd0d9ccabc8604c71b23af1131" (1/1) yEnc
			if ($header['From'] === '<anon@anon.com>' &&
				preg_match('/^\(_nZEDb_\)(?P<site>.+?)_(?P<guid>[a-f0-9]{40}) - \[1\/1\] "(?P<sid>[a-f0-9]{40})" yEnc \(1\/1\)$/i', $header['Subject'], $matches)) {

				// Check if this is from our own site.
				if ($matches['guid'] === $this->siteSettings['site_guid']) {
					continue;
				}

				// Check if we already have the comment.
				$check = $this->db->queryOneRow(
					sprintf('SELECT ID FROM releasecomment WHERE shareID = %s',
						$this->db->escapeString($matches['sid'])
					)
				);

				// We don't have it, so insert it.
				if ($check === false) {

					// Check if we have the site and if it is enabled.
					$check = $this->db->queryOneRow(
						sprintf('SELECT enabled FROM sharing_sites WHERE site_guid = %s',
							$this->db->escapeString($matches['guid'])
						)
					);

					if ($check === false) {
						// Check if the user has auto enable on.
						if ($this->siteSettings['auto_enable'] === false) {
							// Insert the site so the admin can enable it later on.
							$this->db->exec(
								sprintf('
									INSERT INTO sharing_sites
									(site_name, site_guid, last_time, first_time, enabled, comments)
									VALUES (%s, %s, NOW(), NOW(), 0, 0)',
									$this->db->escapeString($matches['site']),
									$this->db->escapeString($matches['guid'])
								)
							);
							return;
						} else {
							// Insert the site as enabled since the user has auto enabled on.
							$this->db->exec(
								sprintf('
									INSERT INTO sharing_sites
									(site_name, site_guid, last_time, first_time, enabled, comments)
									VALUES (%s, %s, NOW(), NOW(), 1, 0)',
									$this->db->escapeString($matches['site']),
									$this->db->escapeString($matches['guid'])
								)
							);
						}
					} else {
						// The user has disabled this site, so return.
						if ($check['enabled'] == 0) {
							return;
						}
					}

					// Insert the comment, if we got it, update the site to increment comment count.
					if ($this->insertNewComment($header['Message-ID'], $matches['guid'])) {
						$this->db->exec(
							sprintf('
								UPDATE sharing_sites SET comments = comments + 1, last_time = NOW(), site_name = %s WHERE site_guid = %s',
								$this->db->escapeString($matches['site']),
								$this->db->escapeString($matches['guid'])
							)
						);
						// Update once in a while in case the user cancels the script.
						if ($found++ % 10 == 0) {
							$this->siteSettings['lastarticle'] = $header['Number'];
							$this->db->exec(sprintf('UPDATE sharing SET last_article = %d',$header['Number']));
						}
							echo '.';
					}
				}
			}
		}
		// Update sharing's last article number.
		$this->siteSettings['lastarticle'] = $newest;
		$this->db->exec(sprintf('UPDATE sharing SET last_article = %d',$newest));
			if ($found > 0) {
				echo PHP_EOL . '(Sharing) Fetched ' . $found . ' new comments.' . PHP_EOL;
			} else {
				echo '(Sharing) Finish looking for new comments, but did not find any.' . PHP_EOL;
			}
	}

	/**
	 * Fetch a comment and insert it.
	 *
	 * @param string $messageID Message-ID for the article.
	 * @param string $siteid    ID of the site.
	 *
	 * @return bool
	 */
	protected function insertNewComment(&$messageID, &$siteid)
	{
		// Get the article body.
		$body = $this->nntp->getMessage(self::group, $messageID);

		// Check if there's an error.
		if ($this->nntp->isError($body)) {
			return false;
		}

		// Decompress the body.
		$body = gzinflate($body);
		if ($body === false) {
			return false;
		}

		// Decode the body.
		$body = json_decode($body, true);
		if ($body === false) {
			return false;
		}

		// Just in case.
		if (!isset($body['USER'])) {
			return false;
		}

        // Set sn names to anon.
		if (substr($body['USER'], 0, 3) === 'sn-') {
			$body['USER'] = 'ANON';
		}

		// Check if we have the user.
		$user = $this->db->queryOneRow(
			sprintf('SELECT ID FROM users WHERE username = %s',
				$this->db->escapeString('SH_' . $body['USER'])
			)
		);

		// If we don't have the user, insert the user.
		if ($user === false) {
			$userid = $this->db->queryInsert(
				sprintf(
					"INSERT INTO users (username, email, password, rsstoken, createddate, userseed, role)
					VALUES (%s, 'sharing@nZEDb.com', %s, %s, NOW(), %s, 0)",
					$this->db->escapeString(('SH_' . $body['USER'])),
					$this->db->escapeString(md5(uniqid('fgf56', true))),
					$this->db->escapeString(md5(uniqid('sfsde', true))),
					$this->db->escapeString(md5(uniqid('f344w', true)))
				)
			);
		} else {
			$userid = $user['ID'];
		}

		// Insert the comment.
		if ($this->db->exec(
			sprintf('
				INSERT INTO releasecomment (text, userID, createddate, issynced, shareID, shared, nzb_guid, releaseID, host, siteID)
				VALUES (%s, %d, %s, 1, %s, 2, %s, 0, "", %s)',
				$this->db->escapeString($body['BODY']),
				$userid,
				$this->functions->from_unixtime(($body['TIME'] > time() ? time() : $body['TIME'])),
				$this->db->escapeString($body['SID']),
				$this->db->escapeString($body['RID']),
				$this->db->escapeString($siteid)
			)
		)) {
			return true;
		}
		return false;
	}

}