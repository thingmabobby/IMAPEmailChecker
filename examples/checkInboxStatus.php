<?php
/*
check inbox status example

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
	$mailboxStatus = $checker->checkMailboxStatus();

    $totalCount = $mailboxStatus['total'] ?? 0;
    $unseenCount = isset($mailboxStatus['unseen_uids']) ? count($mailboxStatus['unseen_uids']) : 0;
    $highestUID = $mailboxStatus['highest_uid'] ?? 0;

    echo "
    <h2>Mailbox status for {$IMAPEmailCheckerAccount}</h2>
    <ul>
        <li>Total Messages: {$totalCount}</li>
        <li>Unread Messages: {$unseenCount}</li>
        <li>Highest UID: {$highestUID}</li>
    </ul>";
} catch (\Throwable $e) {
	echo $e->getMessage();
}