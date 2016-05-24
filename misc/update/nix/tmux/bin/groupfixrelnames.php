<?php
require_once realpath(dirname(dirname(dirname(dirname(dirname(__DIR__))))) . DIRECTORY_SEPARATOR . 'indexer.php');

use newznab\db\Settings;
use newznab\processing\PostProcess;
use newznab\NameFixer;
use newznab\NNTP;
use newznab\NZBContents;
use newznab\Nfo;
use newznab\MiscSorter;

$pdo = new Settings();

if (!isset($argv[1])) {
	exit($pdo->log->error("This script is not intended to be run manually, it is called from Multiprocessing."));
} else if (isset($argv[1])) {
	$namefixer = new NameFixer(['Settings' => $pdo]);
	$pieces = explode(' ', $argv[1]);
	$guidChar = $pieces[1];
	$maxperrun = $pieces[2];
	$thread = $pieces[3];

	switch (true) {
		case $pieces[0] === 'nfo' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$releases = $pdo->queryDirect(
				sprintf('
					SELECT r.id AS releases_id, r.guid, r.groups_id, r.categories_id, r.name, r.searchname,
						uncompress(nfo) AS textstring
					FROM releases r
					INNER JOIN release_nfos rn ON r.id = rn.releases_id
					WHERE r.leftguid = %s
					AND r.nzbstatus = 1
					AND r.proc_nfo = 0
					AND r.nfostatus = 1
					AND r.predb_id < 1
					ORDER BY r.id DESC
					LIMIT %s',
					$pdo->escapeString($guidChar),
					$maxperrun
				)
			);

			if ($releases instanceof Traversable) {
				foreach ($releases as $release) {
					if (preg_match('/^=newz\[NZB\]=\w+/', $release['textstring'])) {
						$namefixer->done = $namefixer->matched = false;
						$pdo->queryDirect(sprintf('UPDATE releases SET proc_nfo = 1 WHERE id = %d', $release['releases_id']));
						$namefixer->checked++;
						echo '.';
					} else {
						$namefixer->done = $namefixer->matched = false;
						if ($namefixer->checkName($release, true, 'NFO, ', 1, 1) !== true) {
							echo '.';
						}
						$namefixer->checked++;
					}
				}
			}
			break;
		case $pieces[0] === 'filename' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$namefixer->fixNamesWithFiles(1, 1, 1, 1, 1, $guidChar, $maxperrun);
			break;
		case $pieces[0] === 'srr' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$namefixer->fixNamesWithSrr(1, 1, 1, 1, 1, $guidChar, $maxperrun);
			break;
		case $pieces[0] === 'uid' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$namefixer->fixNamesWithMedia(1, 1, 1, 1, 1, $guidChar, $maxperrun);
			break;
		case $pieces[0] === 'md5' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$releases = $pdo->queryDirect(
				sprintf('
					SELECT DISTINCT r.id AS releases_id, r.name, r.searchname, r.categories_id, r.groups_id, r.dehashstatus,
						rf.name AS filename
					FROM releases r
					LEFT OUTER JOIN release_files rf ON r.id = rf.releases_id AND rf.ishashed = 1
					WHERE r.leftguid = %s
					AND nzbstatus = 1
					AND r.ishashed = 1
					AND r.dehashstatus BETWEEN -6 AND 0
					AND r.predb_id < 1
					ORDER BY r.dehashstatus DESC, r.id ASC
								LIMIT %s',
					$pdo->escapeString($guidChar),
					$maxperrun
				)
			);

			if ($releases instanceof Traversable) {
				foreach ($releases as $release) {
					if (preg_match('/[a-fA-F0-9]{32,40}/i', $release['name'], $matches)) {
						$namefixer->matchPredbHash($matches[0], $release, 1, 1, true, 1);
					} else if (preg_match('/[a-fA-F0-9]{32,40}/i', $release['filename'], $matches)) {
						$namefixer->matchPredbHash($matches[0], $release, 1, 1, true, 1);
					} else {
						$pdo->queryExec(sprintf("UPDATE releases SET dehashstatus = %d - 1 WHERE id = %d", $release['dehashstatus'], $release['releases_id']));
						echo '.';
					}
				}
			}
			break;
		case $pieces[0] === 'par2' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$releases = $pdo->queryDirect(
				sprintf('
					SELECT r.id AS releases_id, r.guid, r.groups_id
					FROM releases r
					WHERE r.leftguid = %s
					AND r.nzbstatus = 1
					AND r.proc_par2 = 0
					AND r.predb_id < 1
					ORDER BY r.id ASC
								LIMIT %s',
					$pdo->escapeString($guidChar),
					$maxperrun
				)
			);

			if ($releases instanceof Traversable) {
				$nntp = new NNTP(['Settings' => $pdo]);
				if (($pdo->getSetting('alternate_nntp') == '1' ? $nntp->doConnect(true, true) : $nntp->doConnect()) !== true) {
					exit($pdo->log->error("Unable to connect to usenet."));
				}

				$Nfo = new Nfo(['Settings' => $pdo, 'Echo' => true]);
				$nzbcontents = new NZBContents(
					array(
						'Echo' => true, 'NNTP' => $nntp, 'Nfo' => $Nfo, 'Settings' => $pdo,
						'PostProcess' => new PostProcess(['Settings' => $pdo, 'Nfo' => $Nfo, 'NameFixer' => $namefixer])
					)
				);
				foreach ($releases as $release) {
					$res = $nzbcontents->checkPAR2($release['guid'], $release['releases_id'], $release['groups_id'], 1, 1);
					if ($res === false) {
						echo '.';
					}
				}
			}
			break;
		case $pieces[0] === 'miscsorter' && isset($guidChar) && isset($maxperrun) && is_numeric($maxperrun):
			$releases = $pdo->queryDirect(
				sprintf('
					SELECT r.id AS releases_id
					FROM releases r
					WHERE r.leftguid = %s
					AND r.nzbstatus = 1
					AND r.nfostatus = 1
					AND r.proc_sorter = 0
					AND r.isrenamed = 0
					AND r.predb_id < 1
					ORDER BY r.id DESC
					LIMIT %s',
					$pdo->escapeString($guidChar),
					$maxperrun
				)
			);

			if ($releases instanceof Traversable) {
				$sorter = new MiscSorter(true, $pdo);
				foreach ($releases as $release) {
					$res = $sorter->nfosorter(null, $release['releases_id']);
				}
			}
			break;
		case $pieces[0] === 'predbft' && isset($maxperrun) && is_numeric($maxperrun) && isset($thread) && is_numeric($thread):
			$pres = $pdo->queryDirect(
				sprintf('
							SELECT p.id AS predb_id, p.title, p.source, p.searched
							FROM predb p
							WHERE LENGTH(title) >= 15 AND title NOT REGEXP "[\"\<\> ]"
							AND searched = 0
							AND DATEDIFF(NOW(), predate) > 1
							ORDER BY predate ASC
							LIMIT %s
							OFFSET %s',
					$maxperrun,
					$thread * $maxperrun - $maxperrun
				)
			);

			if ($pres instanceof Traversable) {
				foreach ($pres as $pre) {
					$namefixer->done = $namefixer->matched = false;
					$ftmatched = $searched = 0;
					$ftmatched = $namefixer->matchPredbFT($pre, 1, 1, true, 1);
					if ($ftmatched > 0) {
						$searched = 1;
					} elseif ($ftmatched < 0) {
						$searched = -6;
						echo "*";
					} else {
						$searched = $pre['searched'] - 1;
						echo ".";
					}
					$pdo->queryExec(sprintf("UPDATE predb SET searched = %d WHERE id = %d", $searched, $pre['predb_id']));
					$namefixer->checked++;
				}
			}
	}
}
