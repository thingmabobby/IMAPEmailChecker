This is a PHP class to pull emails from a mailbox using IMAP. You can probably safely ignore the "bid" you'll see. 
It's something I was testing to be able to organize emails by subject if it found a string of #N (n = a number). Kinda like how ticketing systems work.
I was testing to see if I could write a script to analyze incoming email messages as a cron job and save them to a database and associate them with specific items.

Usage:

Instantiating the class requires connection information: $checkemail = new CheckImapEmail($server,$acct,$pass);

There are 3 methods available and they all return data in an array unless the error property is set to true (then it returns an error string):

 	checkEmail() 
		- this in theory should return all of your emails, but I haven't fully tested it in a large inbox.
  
	checkSinceDate($thedate) 
		- this will search for emails since the given date from the mailbox. $thedate needs to be in a string of date format: "d M Y" (e.g. 24 May 2024)

	checkSinceLastUID($uid)
		- this will search for emails since the last specified email number (UID) - ideally you would save whatever the last UID was so you can lookup the new emails the next time using that value


Public Properties:

	error - true/false if there was an error
  
	lastuid - if searching by last UID it will return the last UID found so you can store it and refer to it in your next search to pull the latest emails since the last time you searched
 
	messages - associative array containing the email messages found with the following fields:
		-> subject - the email subject
		-> message_body - the body of the email
		-> fromaddress - the email address that sent the email
		-> from - the friendly name (e.g. Jon Doe)
		-> message_number - the unique ID of the message (UID) from the mailbox
		-> date - the date and time with UTC difference the message was sent (e.g. Fri, 24 May 2024 15:53:38 -0400)
