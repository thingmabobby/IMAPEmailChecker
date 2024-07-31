<?php
/* 
search by date example

..\email.php is an example configuration file that should include the variables assigned with your IMAP server, email account, and email password

$IMAPEmailCheckerServer = "imap.yourhost.com";
$IMAPEmailCheckerAccount = "email@domain.com";
$IMAPEmailCheckerPassword = "yourpassword";

*/

require_once('IMAPEmailChecker.php');
require_once('..\email.php');
use IMAPEmailChecker\IMAPEmailChecker;

$imapConnection = @imap_open("{" . $IMAPEmailCheckerServer . "}", $IMAPEmailCheckerAccount, $IMAPEmailCheckerPassword);
if (!$imapConnection) {
	echo "Error connecting to IMAP mailbox!";
} else {
	$checkEmail = new IMAPEmailChecker($imapConnection);

	// date is passed as a DateTime object
	$thedate = new DateTime("24 May 2024", new DateTimeZone('UTC'));
	$messages = $checkEmail->checkSinceDate($thedate);

	if ($messages) {
		echo "<h2>Emails found: " . count($messages) . "</h2>";
		
		// loop through the emails
		foreach ($messages as $message) {
			$toaddresses = "";
			$ccaddresses = "";
			$bccaddresses = "";
			
			if (isset($message['to']) && !empty($message['to']) && is_array($message['to'])) {
				$tocount = 0;
				foreach ($message['to'] as $thisto) {
					$tocount++;
					$toaddresses .= $thisto;
					if ($tocount < count($message['to'])) {
						$toaddresses .= ",";
					}
				}
			}
			
			if (isset($message['cc']) && !empty($message['cc']) && is_array($message['cc'])) {
				$cccount = 0;
				foreach ($message['cc'] as $thiscc) {
					$cccount++;
					$ccaddresses .= $thiscc;
					if ($cccount < count($message['cc'])) {
						$ccaddresses .= ",";
					}								
				}
			}
			
			if (isset($message['bcc']) && !empty($message['bcc']) && is_array($message['bcc'])) {
				$bcccount = 0;
				foreach ($message['bcc'] as $thisbcc) {
					$bcccount++;
					$bccaddresses .= $thisbcc;
					if ($bcccount < count($message['bcc'])) {
						$bccaddresses .= ",";
					}								
				}
			}
			
			echo "
			Message #" . $message['message_number'] . "<br>
			Bid: " . $message['bid'] . "<br>
			Date: " . $message['date'] . "<br>
			To: " . $toaddresses . " - total: " . $message['tocount'] . "<br>
			CC: " . $ccaddresses . "<br>
			BCC: " . $bccaddresses . "<br>
			From: " . $message['from'] . " (" . $message['fromaddress'] . ")<br>
			Unread? " . $message['unseen'] . "<br>
			Subject: " . $message['subject'] . "<br>
			Body:<br>" . $message['message_body'] . "<br><br>";	
		}
	} else {
		echo "No messages found.";
	}
}

?>