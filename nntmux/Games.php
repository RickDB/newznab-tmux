<?php
namespace nntmux;

use nntmux\db\Settings;


class Games
{
	const REQID_FOUND 		= 1; // Request id found and release was updated.
	const REQID_NO_LOCAL	= -1; // Request id was not found via local lookup.
	const REQID_NONE		= -3; // The Request id was not found locally or via web lookup.
	const REQID_UNPROCESSED	= 0; // Release has not been processed.
	const REQID_ZERO		= -2; // The Request id was 0.

	/**
	 * @var string
	 */
	public $cookie;

	/**
	 * @var bool
	 */
	public $echoOutput;

	/**
	 * @var array|bool|int|string
	 */
	public $gameQty;

	/**
	 * @var string
	 */
	public $imgSavePath;

	/**
	 * @var int
	 */
	public $matchPercentage;

	/**
	 * @var bool
	 */
	public $maxHitRequest;

	/**
	 * @var \nntmux\db\Settings
	 */
	public $pdo;

	/**
	 * @var array|bool|string
	 */
	public $publicKey;

	/**
	 * @var string
	 */
	public $renamed;

	/**
	 * @var array|bool|int|string
	 */
	public $sleepTime;

	/**
	 * @var string
	 */
	protected $_classUsed;

	/**
	 * @var string
	 */
	protected $_gameID;

	/**
	 * @var array
	 */
	protected $_gameResults;

	/**
	 * @var object
	 */
	protected $_getGame;

	/**
	 * @var int
	 */
	protected $_resultsFound = 0;

	/**
	 * @var array|bool|int|string
	 */
	public $catWhere;

	/**
	 * @param array $options Class instances / Echo to cli.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Echo'     => false,
			'ColorCLI' => null,
			'Settings' => null,
		];
		$options += $defaults;
		$this->echoOutput = ($options['Echo'] && NN_ECHOCLI);

		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());

		$this->publicKey = $this->pdo->getSetting('giantbombkey');
		$this->gameQty = ($this->pdo->getSetting('maxgamesprocessed') != '') ? $this->pdo->getSetting('maxgamesprocessed') : 150;
		$this->sleepTime = ($this->pdo->getSetting('amazonsleep') != '') ? $this->pdo->getSetting('amazonsleep') : 1000;
		$this->imgSavePath = NN_COVERS . 'games' . DS;
		$this->renamed = '';
		$this->matchPercentage = 60;
		$this->maxHitRequest = false;
		$this->cookie = NN_TMP . 'xxx.cookie';
		if ($this->pdo->getSetting('lookupgames') == 2) {
			$this->renamed = 'AND isrenamed = 1';
		}
		$this->catWhere = 'AND categories_id = ' . Category::PC_GAMES . ' ';
		//$this->cleangames = ($this->pdo->getSetting('('lookupgames') == 2) ? 'AND isrenamed = 1' : '';
	}

	public function getGamesInfo($id)
	{
		return $this->pdo->queryOneRow(
			sprintf("
				SELECT gamesinfo.*, genres.title AS genres
				FROM gamesinfo
				LEFT OUTER JOIN genres ON genres.id = gamesinfo.genre_id
				WHERE gamesinfo.id = %d",
				$id
			)
		);
	}

	public function getGamesInfoByName($title)
	{
		return $this->pdo->queryOneRow(
			sprintf("
				SELECT *
				FROM gamesinfo
				WHERE title = %s",
				$this->pdo->escapeString($title)
			)
		);
	}

	public function getRange($start, $num)
	{
		return $this->pdo->query(
			sprintf(
				"SELECT gi.*, g.title AS genretitle FROM gamesinfo gi INNER JOIN genres g ON gi.genre_id = g.id ORDER BY createddate DESC %s",
				($start === false ? '' : 'LIMIT ' . $num . ' OFFSET ' . $start)
			)
		);
	}

	public function getCount()
	{
		$res = $this->pdo->queryOneRow("SELECT COUNT(id) AS num FROM gamesinfo");
		return ($res === false ? 0 : $res["num"]);
	}

	/**
	 * @param       $cat
	 * @param       $start
	 * @param       $num
	 * @param       $orderby
	 * @param int   $maxage
	 * @param array $excludedcats
	 *
	 * @return array
	 */
	public function getGamesRange($cat, $start, $num, $orderby, $maxage = -1, $excludedcats = [])
	{
		$browseby = $this->getBrowseBy();

		$catsrch = '';
		if (count($cat) > 0 && $cat[0] != -1) {
			$catsrch = (new Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}

		if ($maxage > 0) {
			$maxage = sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxage);
		} else {
			$maxage = '';
		}

		$exccatlist = "";
		if (count($excludedcats) > 0) {
			$exccatlist = " AND r.categories_id NOT IN (" . implode(",", $excludedcats) . ")";
		}

		$order = $this->getGamesOrder($orderby);

		$games = $this->pdo->queryCalc(
				sprintf("
				SELECT SQL_CALC_FOUND_ROWS con.id,
					GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id
				FROM gamesinfo con
				LEFT JOIN releases r ON con.id = r.gamesinfo_id
				WHERE r.nzbstatus = 1
				AND con.title != ''
				AND con.cover = 1
				AND r.passwordstatus %s
				AND %s %s %s %s
				GROUP BY con.id
				ORDER BY %s %s %s",
						Releases::showPasswords($this->pdo),
						$browseby,
						$catsrch,
						$maxage,
						$exccatlist,
						$order[0],
						$order[1],
						($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
				), true, NN_CACHE_EXPIRY_MEDIUM
		);

		$gameIDs = $releaseIDs = false;

		if (is_array($games['result'])) {
			foreach ($games['result'] AS $game => $id) {
				$gameIDs[] = $id['id'];
				$releaseIDs[] = $id['grp_release_id'];
			}
		}

		$return = $this->pdo->query(
				sprintf("
				SELECT
					GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id,
					GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') as grp_rarinnerfilecount,
					GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview,
					GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password,
					GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid,
					GROUP_CONCAT(rn.releases_id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid,
					GROUP_CONCAT(g.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname,
					GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name,
					GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate,
					GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size,
					GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts,
					GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments,
					GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs,
					GROUP_CONCAT(df.failed ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_failed,
				con.*, YEAR (con.releasedate) as year, r.gamesinfo_id, g.name AS group_name,
				rn.releases_id AS nfoid
				FROM releases r
				LEFT OUTER JOIN groups g ON g.id = r.groups_id
				LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
				LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
				INNER JOIN gamesinfo con ON con.id = r.gamesinfo_id
				WHERE con.id IN (%s)
				AND r.id IN (%s)
				AND %s
				GROUP BY con.id
				ORDER BY %s %s",
						(is_array($gameIDs) ? implode(',', $gameIDs) : -1),
						(is_array($releaseIDs) ? implode(',', $releaseIDs) : -1),
						$catsrch,
						$order[0],
						$order[1]
				), true, NN_CACHE_EXPIRY_MEDIUM
		);
		if (!empty($return)) {
			$return[0]['_totalcount'] = (isset($games['total']) ? $games['total'] : 0);
		}
		return $return;
	}

	public function getGamesOrder($orderby)
	{
		$order = ($orderby == '') ? 'r.postdate' : $orderby;
		$orderArr = explode("_", $order);
		switch ($orderArr[0]) {
			case 'title':
				$orderfield = 'con.title';
				break;
			case 'releasedate':
				$orderfield = 'con.releasedate';
				break;
			case 'genre':
				$orderfield = 'con.genre_id';
				break;
			case 'size':
				$orderfield = 'r.size';
				break;
			case 'files':
				$orderfield = 'r.totalpart';
				break;
			case 'stats':
				$orderfield = 'r.grabs';
				break;
			case 'posted':
			default:
				$orderfield = 'r.postdate';
				break;
		}
		$ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

		return [$orderfield, $ordersort];
	}

	public function getGamesOrdering()
	{
		return [
			'title_asc', 'title_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc',
			'files_asc', 'files_desc', 'stats_asc', 'stats_desc',
			'releasedate_asc', 'releasedate_desc', 'genre_asc', 'genre_desc'
		];
	}

	public function getBrowseByOptions()
	{
		return ['title' => 'title', 'genre' => 'genre_id', 'year' => 'year'];
	}

	public function getBrowseBy()
	{
		$browseby = ' ';
		$browsebyArr = $this->getBrowseByOptions();
		$like = 'LIKE';

		foreach ($browsebyArr as $bbk => $bbv) {
			if (isset($_REQUEST[$bbk]) && !empty($_REQUEST[$bbk])) {
				$bbs = stripslashes($_REQUEST[$bbk]);
				if ($bbk === 'year') {
					$browseby .= 'YEAR (con.releasedate) ' . $like . ' (' . $this->pdo->escapeString('%' . $bbs . '%') . ') AND ';
				} else {
					$browseby .= 'con.' . $bbv . ' ' . $like . ' (' . $this->pdo->escapeString('%' . $bbs . '%') . ') AND ';
				}
			}
		}

		return $browseby;
	}

	public function makeFieldLinks($data, $field)
	{
		$tmpArr = explode(', ', $data[$field]);
		$newArr = [];
		$i = 0;
		foreach ($tmpArr as $ta) {
			if (trim($ta) == '') {
				continue;
			}
			// Only use first 6.
			if ($i > 5) {
				break;
			}
			$newArr[] =
				'<a href="' . WWW_TOP . '/games?' . $field . '=' . urlencode($ta) . '" title="' .
				$ta . '">' . $ta . '</a>';
			$i++;
		}

		return implode(', ', $newArr);
	}

	/**
	 * Updates the game for game-edit.php
	 *
	 * @param $id
	 * @param $title
	 * @param $asin
	 * @param $url
	 * @param $publisher
	 * @param $releasedate
	 * @param $esrb
	 * @param $cover
	 * @param $trailerurl
	 * @param $genreID
	 */
	public function update($id, $title, $asin, $url, $publisher, $releasedate, $esrb, $cover, $trailerurl, $genreID)
	{

		$this->pdo->queryExec(
			sprintf("
				UPDATE gamesinfo
				SET title = %s, asin = %s, url = %s, publisher = %s,
					releasedate = %s, esrb = %s, cover = %d, trailer = %s, genre_id = %d, updateddate = NOW()
				WHERE id = %d",
				$this->pdo->escapeString($title),
				$this->pdo->escapeString($asin),
				$this->pdo->escapeString($url),
				$this->pdo->escapeString($publisher),
				$this->pdo->escapeString($releasedate),
				$this->pdo->escapeString($esrb),
				$cover,
				$this->pdo->escapeString($trailerurl),
				$genreID,
				$id
			)
		);
	}

	/**
	 * Process each game, updating game information from Giantbomb
	 *
	 * @param $gameInfo
	 *
	 * @return bool
	 */
	public function updateGamesInfo($gameInfo)
	{
		$gen = new Genres(['Settings' => $this->pdo]);
		$ri = new ReleaseImage($this->pdo);

		$con = [];

		// Process Steam first before giantbomb
		// Steam has more details
		$this->_gameResults = [];
		$this->_getGame = new Steam();
		$this->_classUsed = "steam";
		$this->_getGame->cookie = $this->cookie;
		$this->_getGame->searchTerm = $gameInfo['title'];
		if($this->_getGame->search() !== false){
			$this->_gameResults = $this->_getGame->getAll();
		}
		if (count($this->_gameResults) < 1) {
			$this->_getGame = new Desura();
			$this->_classUsed = "desura";
			$this->_getGame->cookie = $this->cookie;
			$this->_getGame->searchTerm = $gameInfo['title'];
			if ($this->_getGame->search() !== false) {
				$this->_gameResults = $this->_getGame->getAll();
			}
		}
		if (count($this->_gameResults) < 1) {
			$this->_getGame = new Greenlight();
			$this->_classUsed = "gl";
			$this->_getGame->cookie = $this->cookie;
			$this->_getGame->searchTerm = $gameInfo['title'];
			if ($this->_getGame->search() !== false) {
				$this->_gameResults = $this->_getGame->getAll();
			}
		}
		if (count($this->_gameResults) < 1) {
			$this->_gameResults = (array)$this->fetchGiantBombID($gameInfo['title']);
			if ($this->maxHitRequest === true) {
				return false;
			}
		}
		if(empty($this->_gameResults['title'])){
			return false;
		}
		if(!is_array($this->_gameResults)){
			return false;
		}
		if (count($this->_gameResults) > 1) {
			$genreName = '';
			switch ($this->_classUsed) {

				case "desura":
					if (isset($this->_gameResults['cover']) && $this->_gameResults['cover'] != '') {
						$con['coverurl'] = (string)$this->_gameResults['cover'];
					}

					if (isset($this->_gameResults['backdrop']) && $this->_gameResults['backdrop'] != '') {
						$con['backdropurl'] = (string)$this->_gameResults['backdrop'];
					}

					$con['title'] = (string)$this->_gameResults['title'];
					$con['asin'] = $this->_gameResults['desuragameid'];
					$con['url'] = (string)$this->_gameResults['directurl'];

					if (isset($this->_gameResults['gamedetails']['Publisher'])) {
						$con['publisher'] = (string)$this->_gameResults['gamedetails']['Publisher'];
					} else {
						$con['publisher'] = "Unknown";
					}

					if (isset($this->_gameResults['rating'])) {
						$con['esrb'] = (string)$this->_gameResults['rating'];
					} else {
						$con['esrb'] = "Not Rated";
					}

					if (isset($this->_gameResults['description'])) {
						$con['review'] = trim(strip_tags((string)$this->_gameResults['description']));
					}

					if (isset($this->_gameResults['trailer'])) {
						$con['trailer'] = (string)$this->_gameResults['trailer'];
					}

					if (isset($this->_gameResults['gamedetails']['Genre'])) {
						$genres = (string)$this->_gameResults['gamedetails']['Genre'];
						$genreName = $this->_matchGenre($genres);
					}
					break;

				case "gb":
					$con['coverurl'] = (string)$this->_gameResults['image']['super_url'];
					$con['title'] = (string)$this->_gameResults['name'];
					$con['asin'] = $this->_gameID;
					$con['url'] = (string)$this->_gameResults['site_detail_url'];
					if (is_array($this->_gameResults['publishers'])) {
						while (list($key) = each($this->_gameResults['publishers'])) {
							if ($key == 0) {
								$con['publisher'] = (string)$this->_gameResults['publishers'][$key]['name'];
							}
						}
					} else {
						$con['publisher'] = "Unknown";
					}

					if (is_array($this->_gameResults['original_game_rating'])) {
						$con['esrb'] = (string)$this->_gameResults['original_game_rating'][0]['name'];
					} else {
						$con['esrb'] = (string)$this->_gameResults['original_game_rating']['name'];
					}
					$con['releasedate'] = (string)$this->_gameResults['original_release_date'];

					if (isset($this->_gameResults['description'])) {
						$con['review'] = trim(strip_tags((string)$this->_gameResults['description']));
					}
					if (isset($this->_gameResults['genres'][0]['name'])) {
						$genres = (string)$this->_gameResults['genres'][0]['name'];
						$genreName = $this->_matchGenre($genres);
					}
					break;
				case "gl":
					if (isset($this->_gameResults['cover']) && $this->_gameResults['cover'] != '') {
						$con['coverurl'] = (string)$this->_gameResults['cover'];
					}

					if (isset($this->_gameResults['backdrop'])) {
						$con['backdropurl'] = (string)$this->_gameResults['backdrop'];
					}

					$con['title'] = (string)$this->_gameResults['title'];
					$con['asin'] = $this->_gameResults['greenlightgameid'];
					$con['url'] = (string)$this->_gameResults['directurl'];
					$con['publisher'] = "Unknown";
					$con['esrb'] = "Not Rated";

					if (isset($this->_gameResults['description'])) {
						$con['review'] = trim(strip_tags((string)$this->_gameResults['description']));
					}

					if (isset($this->_gameResults['trailer'])) {
						$con['trailer'] = (string)$this->_gameResults['trailer'];
					}

					if (isset($this->_gameResults['gamedetails']['Genre'])) {
						$genres = (string)$this->_gameResults['gamedetails']['Genre'];
						$genreName = $this->_matchGenre($genres);
					}
					break;
				case "steam":
					if (isset($this->_gameResults['cover']) && $this->_gameResults['cover'] != '') {
						$con['coverurl'] = (string)$this->_gameResults['cover'];
					}

					if (isset($this->_gameResults['backdrop']) && $this->_gameResults['backdrop'] != '') {
						$con['backdropurl'] = (string)$this->_gameResults['backdrop'];
					}

					$con['title'] = (string)$this->_gameResults['title'];
					$con['asin'] = $this->_gameResults['steamgameid'];
					$con['url'] = (string)$this->_gameResults['directurl'];

					if (isset($this->_gameResults['gamedetails']['Publisher'])) {
						$con['publisher'] = (string)$this->_gameResults['gamedetails']['Publisher'];
					} else {
						$con['publisher'] = "Unknown";
					}

					if (isset($this->_gameResults['rating'])) {
						$con['esrb'] = (string)$this->_gameResults['rating'];
					} else {
						$con['esrb'] = "Not Rated";
					}

					if (!empty($this->_gameResults['gamedetails']['Release Date'])) {
						$dateReleased = $this->_gameResults['gamedetails']['Release Date'];
						if (!preg_match('#^\s*(?P<month>\w+)\s+(?P<day>\d{1,2}),?\s+(?P<year>\d{4})\s*$#',
							$dateReleased)) {
							if (preg_match('#^\s*(?P<month>\w+)\s+(?P<year>\d{4})\s*$#',
								$dateReleased, $matches)) {
								$dateReleased = "{$matches['month']} 1, {$matches['year']}";
							}
						}

						$date = \DateTime::createFromFormat('M/j/Y', $dateReleased);
						if ($date instanceof \DateTime) {
							$con['releasedate'] = (string)$date->format('Y-m-d');
						}
					}

					if (isset($this->_gameResults['description'])) {
						$con['review'] = trim(strip_tags((string)$this->_gameResults['description']));
					}

					if (isset($this->_gameResults['trailer'])) {
						$con['trailer'] = (string)$this->_gameResults['trailer'];
					}

					if (isset($this->_gameResults['gamedetails']['Genre'])) {
						$genres = (string)$this->_gameResults['gamedetails']['Genre'];
						$genreName = $this->_matchGenre($genres);
					}

					break;
				default:
					return false;
			}
		} else {
			return false;
		}
		// Load genres.
		$defaultGenres = $gen->getGenres(Genres::GAME_TYPE);
		$genreassoc = [];
		foreach ($defaultGenres as $dg) {
			$genreassoc[$dg['id']] = strtolower($dg['title']);
		}

		// Prepare database values.
		if (isset($con['coverurl'])) {
			$con['cover'] = 1;
		} else {
			$con['cover'] = 0;
		}
		if (isset($con['backdropurl'])) {
			$con['backdrop'] = 1;
		} else {
			$con['backdrop'] = 0;
		}
		if (!isset($con['trailer'])) {
			$con['trailer'] = 0;
		}
		if (empty($con['title'])) {
			$con['title'] = $gameInfo['title'];
		}
		if(!isset($con['releasedate'])){
			$con['releasedate'] = "";
		}

		if ($con['releasedate'] == "''") {
			$con['releasedate'] = "";
		}
		if(!isset($con['review'])){
			$con['review'] = 'No Review';
		}
		$con['classused'] = $this->_classUsed;

		if (empty($genreName)) {
			$genreName = 'Unknown';
		}

		if (in_array(strtolower($genreName), $genreassoc)) {
			$genreKey = array_search(strtolower($genreName), $genreassoc);
		} else {
			$genreKey = $this->pdo->queryInsert(
				sprintf("
					INSERT INTO genres (title, type)
					VALUES (%s, %d)",
					$this->pdo->escapeString($genreName),
					Genres::GAME_TYPE
				)
			);
		}

		$con['gamesgenre'] = $genreName;
		$con['gamesgenreID'] = $genreKey;

		$check = $this->pdo->queryOneRow(
			sprintf('
				SELECT id
				FROM gamesinfo
				WHERE asin = %s',
				$this->pdo->escapeString($con['asin'])
			)
		);
		if ($check === false) {
			$gamesId = $this->pdo->queryInsert(
				sprintf("
					INSERT INTO gamesinfo
						(title, asin, url, publisher, genre_id, esrb, releasedate, review, cover, backdrop, trailer, classused, createddate, updateddate)
					VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, NOW(), NOW())",
					$this->pdo->escapeString($con['title']),
					$this->pdo->escapeString($con['asin']),
					$this->pdo->escapeString($con['url']),
					$this->pdo->escapeString($con['publisher']),
					($con['gamesgenreID'] == -1 ? "null" : $con['gamesgenreID']),
					$this->pdo->escapeString($con['esrb']),
					($con['releasedate'] != "" ? $this->pdo->escapeString($con['releasedate']) : "null"),
					$this->pdo->escapeString(substr($con['review'], 0, 3000)),
					$con['cover'],
					$con['backdrop'],
					$this->pdo->escapeString($con['trailer']),
					$this->pdo->escapeString($con['classused'])
				)
			);
		} else {
			$gamesId = $check['id'];
			$this->pdo->queryExec(
				sprintf('
					UPDATE gamesinfo
					SET
						title = %s, asin = %s, url = %s, publisher = %s, genre_id = %s,
						esrb = %s, releasedate = %s, review = %s, cover = %d, backdrop = %d, trailer = %s, classused = %s, updateddate = NOW()
					WHERE id = %d',
					$this->pdo->escapeString($con['title']),
					$this->pdo->escapeString($con['asin']),
					$this->pdo->escapeString($con['url']),
					$this->pdo->escapeString($con['publisher']),
					($con['gamesgenreID'] == -1 ? "null" : $con['gamesgenreID']),
					$this->pdo->escapeString($con['esrb']),
					($con['releasedate'] != "" ? $this->pdo->escapeString($con['releasedate']) : "null"),
					$this->pdo->escapeString(substr($con['review'], 0, 3000)),
					$con['cover'],
					$con['backdrop'],
					$this->pdo->escapeString($con['trailer']),
					$this->pdo->escapeString($con['classused']),
					$gamesId
				)
			);
		}

		if ($gamesId) {
			if ($this->echoOutput) {
				$this->pdo->log->doEcho(
					$this->pdo->log->header("Added/updated game: ") .
					$this->pdo->log->alternateOver("   Title:    ") .
					$this->pdo->log->primary($con['title'])
				);
			}
			if($con['cover'] === 1){
				$con['cover'] = $ri->saveImage($gamesId, $con['coverurl'], $this->imgSavePath, 250, 250);
			}
			if($con['backdrop'] === 1){
				$con['backdrop'] = $ri->saveImage($gamesId . '-backdrop', $con['backdropurl'], $this->imgSavePath, 1920, 1024);
			}
		} else {
			if ($this->echoOutput) {
				$this->pdo->log->doEcho(
					$this->pdo->log->headerOver("Nothing to update: ") .
					$this->pdo->log->primary($con['title'] . ' (PC)' )
				);
			}
		}

		return $gamesId;
	}

	/**
	 * Get Giantbomb id from title
	 *
	 * @param string $title
	 *
	 * @return bool|mixed Array if no result False
	 */

	public function fetchGiantBombID($title = '')
	{
		$obj = new \GiantBomb($this->publicKey);
		try {
			$fields = [
				"api_detail_url", "name"
			];
			$result = json_decode(json_encode($obj->search($title, $fields, 10, 1, ["game"])), true);
			// We hit the maximum request.
			if (empty($result)) {
				$this->maxHitRequest = true;
				return false;
			}
			if (!is_array($result['results']) || (int)$result['number_of_total_results'] === 0) {
				$result = false;
			} else {
				$this->_resultsFound = count($result['results']) - 1;
				if ($this->_resultsFound !== 0) {
					for ($i = 0; $i <= $this->_resultsFound; $i++) {
						similar_text(strtolower($result['results'][$i]['name']), strtolower($title), $p);
						if ($p > 77) {
							$result = $result['results'][$i];
							preg_match('/\/\d+\-(?<asin>\d+)\//', $result['api_detail_url'], $matches);
							$this->_gameID = (string)$matches['asin'];
							$result = $this->fetchGiantBombArray();
							$this->_classUsed = "gb";
							break;
						}
						if ($i === $this->_resultsFound) {
							return false;
						}
					}

				} else {
					return false;
				}
			}
		} catch (\Exception $e) {
			$result = false;
		}

		return $result;
	}

	/**
	 * Fetch Giantbomb results from GameID
	 *
	 * @return bool|mixed
	 */
	public function fetchGiantBombArray()
	{
		$obj = new \GiantBomb($this->publicKey);
		try {
			$fields = [
				"deck", "description", "original_game_rating", "api_detail_url", "image", "genres",
				"name", "publishers", "original_release_date", "reviews",
				"site_detail_url"
			];
			$result = json_decode(json_encode($obj->game($this->_gameID, $fields)), true);
			$result = $result['results'];
		} catch (\Exception $e) {
			$result = false;
		}

		return $result;
	}

	public function processGamesReleases()
	{
		$res = $this->pdo->queryDirect(
			sprintf('
				SELECT searchname, id
				FROM releases
				WHERE nzbstatus = 1 %s
				AND gamesinfo_id = 0 %s
				ORDER BY postdate DESC
				LIMIT %d',
				$this->renamed,
				$this->catWhere,
				$this->gameQty
			)
		);

		if ($res instanceof \Traversable && $res->rowCount() > 0) {
			if ($this->echoOutput) {
				$this->pdo->log->doEcho($this->pdo->log->header("Processing " . $res->rowCount() . ' games release(s).'));
			}

			foreach ($res as $arr) {

				// Reset maxhitrequest
				$this->maxHitRequest = false;
				$startTime = microtime(true);
				$usedgb = false;
				$gameInfo = $this->parseTitle($arr['searchname']);
				if ($gameInfo !== false) {

					if ($this->echoOutput) {
						$this->pdo->log->doEcho(
							$this->pdo->log->headerOver('Looking up: ') .
							$this->pdo->log->primary($gameInfo['title'] . ' (PC)' )
						);
					}

					// Check for existing games entry.
					$gameCheck = $this->getGamesInfoByName($gameInfo['title']);

					if ($gameCheck === false) {
						$gameId = $this->updateGamesInfo($gameInfo);
						$usedgb = true;
						if ($gameId === false) {
							$gameId = -2;

							// Leave gamesinfo_id 0 to parse again
							if($this->maxHitRequest === true){
								$gameId = 0;
							}
						}

					} else {
						$gameId = $gameCheck['id'];
					}
					// Update release.
					$this->pdo->queryExec(sprintf('UPDATE releases SET gamesinfo_id = %d WHERE id = %d %s', $gameId, $arr['id'], $this->catWhere));
				} else {
					// Could not parse release title.
					$this->pdo->queryExec(sprintf('UPDATE releases SET gamesinfo_id = %d WHERE id = %d %s', -2, $arr['id'], $this->catWhere));

					if ($this->echoOutput) {
						echo '.';
					}
				}

				// Sleep to not flood giantbomb.
				$diff = floor((microtime(true) - $startTime) * 1000000);
				if ($this->sleepTime * 1000 - $diff > 0 && $usedgb === true) {
					usleep($this->sleepTime * 1000 - $diff);
				}
			}
		} else {
			if ($this->echoOutput) {
				$this->pdo->log->doEcho($this->pdo->log->header('No games releases to process.'));
			}
		}
	}

	/**
	 * Parse the game release title
	 *
	 * @param string $releasename
	 *
	 * @return array|bool
	 */
	public function parseTitle($releasename)
	{
		// Get name of the game from name of release.
		if (preg_match(
			'/^(.+((EFNet|EFNet\sFULL|FULL\sabgxEFNet|abgx\sFULL|abgxbox360EFNet)\s|illuminatenboard\sorg|' .
			'Place2(hom|us)e.net|united-forums? co uk|\(\d+\)))?(?P<title>.*?)[\.\-_ \:](v\.?\d\.\d|RIP|ADDON|' .
			'EUR|USA|JP|ASIA|JAP|JPN|AUS|MULTI(\.?\d{1,2})?|PATCHED|FULLDVD|DVD5|DVD9|DVDRIP|\(GAMES\)\s*\(C\)|PROPER|REPACK|RETAIL|' .
			'DEMO|DISTRIBUTION|BETA|REGIONFREE|READ\.?NFO|NFOFIX|Update|BWClone|CRACKED|Remastered|Fix|LINUX|x86|x64|Windows|Steam|Patch|GoG|Dox|No\.Intro|' .
			// Group names, like Reloaded, CPY, Razor1911, etc
			'[a-z0-9]{2,}$)/i',
			preg_replace('/\sMulti\d?\s/i', '', $releasename), $matches)) {

			// Replace dots, underscores, colons, or brackets with spaces.
			$result = [];
			$result['title'] = str_replace(' RF ', ' ', preg_replace('/(\-|\:|\.|_|\%20|\[|\])/', ' ', $matches['title']));
			// Replace any foreign words at the end of the release
			$result['title'] = preg_replace('/(brazilian|chinese|croatian|danish|deutsch|dutch|english|estonian|flemish|finnish|french|german|greek|hebrew|icelandic|italian|latin|nordic|norwegian|polish|portuguese|japenese|japanese|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish)$/i', '', $result['title']);
			// Remove PC ISO) ( from the beginning bad regex from Games category?
			$result['title'] = preg_replace('/^(PC\sISO\)\s\()/i', '', $result['title']);
			// Finally remove multiple spaces and trim leading spaces.
			$result['title'] = trim(preg_replace('/\s{2,}/', ' ', $result['title']));
			// Needed to add code to handle DLC Properly.
			if (stripos($result['title'], 'dlc') !== false) {
				$result['dlc'] = '1';
				if (stripos($result['title'], 'Rock Band Network') !== false) {
					$result['title'] = 'Rock Band';
				} else if (stripos($result['title'], '-') !== false) {
					$dlc = explode("-", $result['title']);
					$result['title'] = $dlc[0];
				} else if (preg_match('/(.*? .*?) /i', $result['title'], $dlc)) {
					$result['title'] = $dlc[0];
				}
			}
			if(empty($result['title'])){
				return false;
			}
			$browseNode = '94';
			$result['node'] = $browseNode;
			$result['release'] = $releasename;

			return array_map("trim", $result);
		}

		return false;
	}

	/**
	 * See if genre name exists
	 *
	 * @param $nodeName
	 *
	 * @return bool|string
	 */
	public function matchBrowseNode($nodeName)
	{
		$str = '';

		//music nodes above mp3 download nodes
		switch ($nodeName) {
			case 'Action':
			case 'Adventure':
			case 'Arcade':
			case 'Board Games':
			case 'Cards':
			case 'Casino':
			case 'Flying':
			case 'Puzzle':
			case 'Racing':
			case 'Rhythm':
			case 'Role-Playing':
			case 'Simulation':
			case 'Sports':
			case 'Strategy':
			case 'Trivia':
				$str = $nodeName;
				break;
		}

		return ($str !== '') ? $str : false;
	}

	/**
	 * Matches Genres
	 *
	 * @param string $genre
	 *
	 * @return string
	 */
	protected function _matchGenre($genre = '')
	{
		$genreName = '';
		$a = str_replace('-', ' ', $genre);
		$tmpGenre = explode(',', $a);
		if (is_array($tmpGenre)) {
			foreach ($tmpGenre as $tg) {
				$genreMatch = $this->matchBrowseNode(ucwords($tg));
				if ($genreMatch !== false) {
					$genreName = (string)$genreMatch;
					break;
				}
			}
			if(empty($genreName)){
				$genreName = $tmpGenre[0];
			}
		} else {
			$genreName = $genre;
		}

		return $genreName;
	}
}
