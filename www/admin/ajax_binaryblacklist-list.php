<?php
require_once './config.php';


use nntmux\Binaries;

// login check
$admin = new AdminPage;
$bin  = new Binaries();

if (isset($_GET['action']) && $_GET['action'] == "2")
{
		$id = (int)$_GET['bin_id'];
		$bin->deleteBlacklist($id);
		print "Blacklist $id deleted.";
}
