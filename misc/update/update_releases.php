<?php
require_once realpath(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'bootstrap.php');

use nntmux\Category;
use nntmux\ConsoleTools;
use nntmux\NNTP;
use nntmux\db\Settings;
use nntmux\processing\ProcessReleases;

$category = new Category();
$pdo = new Settings();

if (isset($argv[2]) && $argv[2] === 'true') {
	// Create the connection here and pass
	$nntp = new NNTP(['Settings' => $pdo]);
	if ($nntp->doConnect() !== true) {
		exit($pdo->log->error("Unable to connect to usenet."));
	}
}
if ($pdo->getSetting('tablepergroup') === 1) {
	exit($pdo->log->error("You are using 'tablepergroup', you must use .../misc/update/nix/multiprocessing/releases.php"));
}

$groupName = isset($argv[3]) ? $argv[3] : '';
if (isset($argv[1]) && isset($argv[2])) {
	$consoletools = new ConsoleTools(['ColorCLI' => $pdo->log]);
	$releases = new ProcessReleases(['Settings' => $pdo, 'ConsoleTools' => $consoletools]);
	if ($argv[1] == 1 && $argv[2] == 'true') {
		$releases->processReleases(1, 1, $groupName, $nntp, true);
	} else if ($argv[1] == 1 && $argv[2] == 'false') {
		$releases->processReleases(1, 2, $groupName, $nntp, true);
	} else if ($argv[1] == 2 && $argv[2] == 'true') {
		$releases->processReleases(2, 1, $groupName, $nntp, true);
	} else if ($argv[1] == 2 && $argv[2] == 'false') {
		$releases->processReleases(2, 2, $groupName, $nntp, true);
	} else if ($argv[1] == 4 && ($argv[2] == 'true' || $argv[2] == 'false')) {
		echo $pdo->log->header("Moving all releases to other -> misc, this can take a while, be patient.");
		$releases->resetCategorize();
	} else if ($argv[1] == 5 && ($argv[2] == 'true' || $argv[2] == 'false')) {
		echo $pdo->log->header("Categorizing all non-categorized releases in other->misc using usenet subject. This can take a while, be patient.");
		$timestart = time();
		$relcount = $releases->categorizeRelease('name', "WHERE iscategorized = 0 AND categories_id = " . Category::OTHER_MISC);
		$time = $consoletools->convertTime(time() - $timestart);
		echo $pdo->log->primary("\n" . 'Finished categorizing ' . $relcount . ' releases in ' . $time . " seconds, using the usenet subject.");
	} else if ($argv[1] == 6 && $argv[2] == 'true') {
		echo $pdo->log->header("Categorizing releases in all sections using the searchname. This can take a while, be patient.");
		$timestart = time();
		$relcount = $releases->categorizeRelease('searchname', '');
		$consoletools = new ConsoleTools(['ColorCLI' => $pdo->log]);
		$time = $consoletools->convertTime(time() - $timestart);
		echo $pdo->log->primary("\n" . 'Finished categorizing ' . $relcount . ' releases in ' . $time . " seconds, using the search name.");
	} else if ($argv[1] == 6 && $argv[2] == 'false') {
		echo $pdo->log->header("Categorizing releases in misc sections using the searchname. This can take a while, be patient.");
		$timestart = time();
		$relcount = $releases->categorizeRelease('searchname',
			sprintf("WHERE categories_id IN (%s, %s, %s, %s, %s, %s)",
				Category::GAME_OTHER,
				Category::MOVIE_OTHER,
				Category::MUSIC_OTHER,
				Category::TV_OTHER,
				Category::XXX_OTHER,
				Category::OTHER_MISC
			));
		$consoletools = new ConsoleTools(['ColorCLI' => $pdo->log]);
		$time = $consoletools->convertTime(time() - $timestart);
		echo $pdo->log->primary("\n" . 'Finished categorizing ' . $relcount . ' releases in ' . $time . " seconds, using the search name.");
	} else {
		exit($pdo->log->error("Wrong argument, type php update_releases.php to see a list of valid arguments."));
	}
} else {
	exit($pdo->log->error("\nWrong set of arguments.\n"
			. "php update_releases.php 1 true			...: Creates releases and attempts to categorize new releases\n"
			. "php update_releases.php 2 true			...: Creates releases and leaves new releases in other -> misc\n"
			. "\nYou must pass a second argument whether to post process or not, true or false\n"
			. "You can pass a third optional argument, a group name (ex.: alt.binaries.multimedia).\n"
			. "\nExtra commands::\n"
			. "php update_releases.php 4 true			...: Puts all releases in other-> misc (also resets to look like they have never been categorized)\n"
			. "php update_releases.php 5 true			...: Categorizes all releases in other-> misc (which have not been categorized already)\n"
			. "php update_releases.php 6 false			...: Categorizes releases in misc sections using the search name\n"
			. "php update_releases.php 6 true			...: Categorizes releases in all sections using the search name\n"));
}
