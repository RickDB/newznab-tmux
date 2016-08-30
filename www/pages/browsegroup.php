<?php
if (!$page->users->isLoggedIn()) {
	$page->show403();
}

use nntmux\Groups;

$groups = new Groups(['Settings' => $page->settings]);

$grouplist = $groups->getRange();
$page->smarty->assign('results', $grouplist);

$page->meta_title = "Browse Groups";
$page->meta_keywords = "browse,groups,description,details";
$page->meta_description = "Browse groups";

$page->content = $page->smarty->fetch('browsegroup.tpl');
$page->render();
