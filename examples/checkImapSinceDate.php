<?php
/* 
search by date example

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
	
	// date is passed as a DateTime object
	$thedate = new DateTime("28 May 2025", new DateTimeZone('UTC'));
	$messages = $checker->checkSinceDate($thedate);

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
	} else {
		echo "No messages found.";
	}
} catch (\Throwable $e) {
	echo $e->getMessage();
}