<?php
/*
show emails since specified email UID example

email.php is an example configuration file that should include the variables assigned with your IMAP server, email account, and email password

$IMAPEmailCheckerServer = "imap.yourhost.com";
$IMAPEmailCheckerAccount = "email@domain.com";
$IMAPEmailCheckerPassword = "yourpassword";

*/

require_once __DIR__ . '/../src/IMAPEmailChecker.php';
require_once __DIR__ . '/../../email.php';
use IMAPEmailChecker\IMAPEmailChecker;

try {
	$checker = IMAPEmailChecker::connect($IMAPEmailCheckerServer, $IMAPEmailCheckerAccount, $IMAPEmailCheckerPassword);
	
	// lastUID represents the email ID that you want to start searching from until present time
	$lastUID = 20;
	$messages = $checker->checkSinceLastUID($lastUID);

	if ($messages) {
		echo "<h2>Emails found: " . count($messages) . "</h2>";
		
		// loop through the emails
		foreach ($messages as $message) {
			echo "
			UID: " . $message['uid'] . "<br>
			Message #" . $message['message_number'] . "<br>
			Bid: " . $message['bid'] . "<br>
			Date: " . $message['date'] . "<br>
			From: " . $message['from'] . " (" . $message['fromaddress'] . ")<br>
			Unread? " . $message['unseen'] . "<br>
			Subject: " . $message['subject'] . "<br>
			Body:<br>" . $message['message_body'] . "<br><br>";		
		}
		
		if ($checker->lastuid > 0) {
			echo "The last UID was " . $checker->lastuid;
		}
	} else {
		echo "No messages found.";
	}
} catch (\Throwable $e) {
	echo $e->getMessage();
}