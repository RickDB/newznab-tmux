<?php
require_once './config.php';


use nntmux\ReleaseRegex;

// login check
$admin = new AdminPage;
$regex  = new ReleaseRegex();

if (isset($_GET['action']) && $_GET['action'] == "2")
{
		$id     = (int)$_GET['regex_id'];
		$regex->delete($id);
		print "Regex $id deleted.";
}
