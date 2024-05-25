<?php

declare(strict_types=1);

class CheckImapConnection {
	protected string $server = ""; // imap server url
	protected string $port = ""; // imap server port (optional)
	protected string $acct = ""; // email address
	protected string $pass = ""; // email password	
}