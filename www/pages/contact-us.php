<?php

use newznab\utility\Utility;

if (isset($_POST["useremail"])) {
	//
	// send the contact info and report back to user.
	//

	if ($page->captcha->getError() === false) {
		$email = $_POST["useremail"];
		$mailto = $page->settings->getSetting('email');
		$mailsubj = "Contact Form Submitted";
		$mailhead = "From: $email\n";
		$mailbody = "Values submitted from contact form:\n";

		while (list ($key, $val) = each($_POST)) {
			if ($key != "submit") {
				$mailbody .= "$key : $val<br />\r\n";
			}
		}

		if (!preg_match("/\n/i", $_POST["useremail"])) {
			Utility::sendEmail($mailto, $mailsubj, $mailbody, $email);
		}

		$page->smarty->assign("msg", "<h2 style='padding-top:25px;'>Thanks for getting in touch with " . $page->settings->getSetting('title') . ".</h2>");
	}
}
$page->title = "Contact ".$page->settings->getSetting('title');
$page->meta_title = "Contact ".$page->settings->getSetting('title');
$page->meta_keywords = "contact us,contact,get in touch,email";
$page->meta_description = "Contact us at ".$page->settings->getSetting('title')." and submit your feedback";

$page->content = $page->smarty->fetch('contact.tpl');

$page->render();