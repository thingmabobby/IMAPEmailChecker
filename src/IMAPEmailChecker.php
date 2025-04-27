<?php
declare(strict_types=1);

namespace IMAPEmailChecker;

use DateTime;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Class IMAPEmailChecker
 *
 * A class to pull emails from a mailbox using IMAP. It provides methods to retrieve emails,
 * decode email bodies (including inline images), and extract attachments.
 *
 * Public Properties:
 *  - lastuid: The last UID processed.
 *  - messages: An associative array containing the email messages found, keyed by UID.
 *
 * Usage:
 *  - checkAllEmail() - returns all emails in the mailbox.
 *  - checkSinceDate(DateTime $date) - returns emails since the given date.
 *  - checkSinceLastUID(int $uid) - returns emails since the specified UID.
 *  - deleteEmail(int $uid) - deletes an email by UID.
 *  - archiveEmail(int $uid, string $folder) - archives an email by UID.
 */
class IMAPEmailChecker
{
	// constants for mime/encoding - TYPEOTHER, ENC7BIT, ENCOTHER commented out as not used, but keeping as reference
	private const TYPETEXT = 0;
	private const TYPEMULTIPART = 1;
	private const TYPEMESSAGE = 2;
	private const TYPEAPPLICATION = 3;
	private const TYPEAUDIO = 4;
	private const TYPEIMAGE = 5;
	private const TYPEVIDEO = 6;
	private const TYPEMODEL = 7;
	//private const TYPEOTHER = 8;
	//private const ENC7BIT = 0;
	private const ENC8BIT = 1;
	private const ENCBINARY = 2;
	private const ENCBASE64 = 3;
	private const ENCQUOTEDPRINTABLE = 4;
	//private const ENCOTHER = 5;

	// The UID of the last processed email
	public int $lastuid = 0;

	// The list of messages fetched
	public array $messages = [];


 	/**
     * IMAPEmailChecker constructor.
     *
     * @param resource|\IMAP\Connection $conn  The IMAP connection resource or object.
     * @param bool $debug                      Debug mode - logs non-critical errors if true.
     * @param string $bidRegex            	   Optional regex to extract a string ('bid') from the subject.
     *                                         Must contain a capturing group (usually group 1) for the ID.
     *                                         Defaults to '/#(\d+)/'.
	 * @throws InvalidArgumentException If the provided $bidRegex is an empty string.
 	 * @throws RuntimeException If the provided IMAP connection is invalid or closed.
     */
	public function __construct(
		private $conn,
		private bool $debug = false,
		private string $bidRegex = '/#(\d+)/'
	) {
		if (!$this->isValidImapConnection($this->conn)) {
            throw new RuntimeException("Invalid or closed IMAP connection provided.");
        }

		if (trim($this->bidRegex) === '') {
			throw new InvalidArgumentException("BID Regex pattern cannot be empty.");
		}

        $this->lastuid = 0;
        $this->messages = [];
	}


	/**
	 * IMAPEmailChecker destructor.
	 * Closes the IMAP connection.
	 */
	public function __destruct()
	{
		if ($this->conn && ((class_exists('\\IMAP\\Connection') && $this->conn instanceof \IMAP\Connection) || (is_resource($this->conn) && get_resource_type($this->conn) === 'imap'))) {
            @imap_close($this->conn);
        }
	}


	 /**
     * Checks if the provided value is a valid IMAP connection
     * (resource for PHP < 8.1, \IMAP\Connection object for PHP >= 8.1)
     * and attempts a ping.
     *
     * @param mixed $conn
     * @return bool
     */
    private function isValidImapConnection($conn): bool
    {
        $isValidType = false;

        // Check for \IMAP\Connection object (PHP 8.1+)
        // Using class_exists to avoid errors on older PHP versions
        if (class_exists('\\IMAP\\Connection') && $conn instanceof \IMAP\Connection) {
            $isValidType = true;
        } elseif (is_resource($conn) && get_resource_type($conn) === 'imap') {
            $isValidType = true;
        }

        // If it's a valid type, try to ping it to see if it's alive
        if ($isValidType) {
            return @imap_ping($conn);
        }

        return false; // Not a valid IMAP connection type
    }


	/**
	 * Checks the status of the current mailbox, including UIDs for recent/unseen and the highest UID.
	 *
	 * Retrieves:
	 * - Total number of messages.
	 * - The highest UID currently existing in the mailbox.
	 * - An array containing the UIDs of recent messages (\Recent flag).
	 * - An array containing the UIDs of unseen messages (\Unseen flag).
	 *
	 * Note: 'Recent' messages have the \Recent flag. 'Unseen' messages have the \Unseen flag.
	 * The highest UID is determined by finding the UID of the message with the highest sequence number.
	 *
	 * @return array An associative array with keys 'total' (int), 'highest_uid' (int),
	 *                    'recent_uids' (array<int>), and 'unseen_uids' (array<int>),
	 *                    or false on failure of any required IMAP operation.
	 *                    'highest_uid' will be 0 if the mailbox is empty.
	 *                    Example: ['total' => 150, 'highest_uid' => 2345, 'recent_uids' => [2343, 2344, 2345], 'unseen_uids' => [2340, 2343, 2345]]
	 * @throws RuntimeException If any underlying IMAP operation (check, uid, search) fails during status retrieval.
	 */
	public function checkMailboxStatus(): array
	{
		$status = [
			'total' => 0,
			'highest_uid' => 0, // Initialize to 0 for empty mailbox case
			'recent_uids' => [],
			'unseen_uids' => [],
		];

		// 1. Get total count using imap_check()
		$check = @imap_check($this->conn);
		if ($check === false || !($check instanceof stdClass)) {
			throw new RuntimeException("IMAPEmailChecker: Failed to get mailbox check status (imap_check). Error: " . imap_last_error());			
		}
		$status['total'] = isset($check->Nmsgs) ? (int)$check->Nmsgs : 0;

		// 2. Get highest UID using imap_uid() with the highest sequence number, if mailbox is not empty
		if ($status['total'] > 0) {
			// imap_uid() takes the sequence number and returns the corresponding UID
			$highestUidResult = @imap_uid($this->conn, $status['total']);
			if ($highestUidResult === false) {
				// This could happen if the message count changed between imap_check and imap_uid,
				// or if the server has issues. Treat as failure.
				throw new RuntimeException("IMAPEmailChecker: Failed to get UID for highest sequence number ({$status['total']}). Error: " . imap_last_error());
			}
			$status['highest_uid'] = (int)$highestUidResult;
		}
		// If total is 0, highest_uid remains 0.

		// 3. Get unseen UIDs using imap_search()
		$unseenUidsResult = @imap_search($this->conn, 'UNSEEN', SE_UID);
		if ($unseenUidsResult === false) {
			throw new RuntimeException("IMAPEmailChecker: Failed to search for unseen message UIDs (imap_search UNSEEN). Error: " . imap_last_error());
		}
		$status['unseen_uids'] = array_map('intval', $unseenUidsResult);

		// 4. Get recent UIDs using imap_search()
		$recentUidsResult = @imap_search($this->conn, 'RECENT', SE_UID);
		if ($recentUidsResult === false) {
			throw new RuntimeException("IMAPEmailChecker: Failed to search for recent message UIDs (imap_search RECENT). Error: " . imap_last_error());
		}
		$status['recent_uids'] = array_map('intval', $recentUidsResult);

		// 5. Return the combined status
		return $status;
	}


	/**
     * Performs a search on the current mailbox using custom IMAP criteria.
     *
     * Allows searching based on various criteria supported by the IMAP server.
     * Refer to RFC 3501 (Section 6.4.4 SEARCH Command) for standard criteria.
     * Common examples: 'ALL', 'UNSEEN', 'SEEN', 'ANSWERED', 'DELETED', 'FLAGGED',
     * 'FROM "user@example.com"', 'SUBJECT "Invoice"', 'BODY "important text"',
     * 'SINCE "1-Jan-2024"', 'BEFORE "31-Dec-2023"', 'KEYWORD "MyFlag"'.
     *
     * @param string $criteria   The IMAP search criteria string. Must not be empty.
     * @param bool   $returnUids If true (default), returns an array of UIDs.
     *                           If false, returns an array of message sequence numbers.
     * @return array An array of integer UIDs or message numbers matching the criteria,
     *                     sorted numerically. Returns an empty array if no messages match.
	 * @throws InvalidArgumentException If the provided criteria string is empty after trimming.
 	 * @throws RuntimeException If the underlying imap_search operation fails (e.g., server error, invalid syntax reported by server).
     */
    public function search(string $criteria, bool $returnUids = true): array
    {
        // Basic validation for the criteria string
        $trimmedCriteria = trim($criteria);
        if ($trimmedCriteria === '') {
            throw new InvalidArgumentException("IMAPEmailChecker: Search criteria cannot be empty.");
        }

        // Determine the options for imap_search
        $options = $returnUids ? SE_UID : 0; // SE_UID to return UIDs, 0 for sequence numbers

        // Perform the search
        $results = imap_search($this->conn, $trimmedCriteria, $options);

        if ($results === false) {
            // Search failed
            throw new RuntimeException("IMAPEmailChecker: imap_search failed for criteria '{$trimmedCriteria}'. Error: " . imap_last_error());
        }

        // imap_search returns an array of integers (or false on error).
        // If no messages match, it returns an empty array (which is not === false).
        // Sort the results numerically for consistency.
        sort($results, SORT_NUMERIC);

        // Return the sorted array of identifiers (UIDs or sequence numbers)
        return $results;
    }


	/**
	 * Decodes the body of an email message, identified by message number or UID.
	 *
	 * Recursively processes MIME parts, preferring HTML over plain text.
	 * Converts content to UTF-8 by:
	 *   1) reading any declared charset param,
	 *   2) or auto‑detecting via mb_detect_encoding().
	 *
	 * @param int $identifier The message sequence number or UID.
	 * @param bool $isUid Whether the identifier is a UID (true) or message number (false).
	 * @return string|false The decoded UTF-8 body, or false on failure.
	 * @throws RuntimeException If fetching the message structure (imap_fetchstructure) or a specific body part (imap_fetchbody) fails.
	 */
	private function decodeBody(int $identifier, bool $isUid = false): string|false
	{
		$options = $isUid ? FT_UID : 0;
		$structure = imap_fetchstructure($this->conn, $identifier, $options);
		if (!$structure) {
			// Error fetching structure
			throw new RuntimeException("IMAPEmailChecker: Failed to fetch structure for identifier {$identifier} (isUid: " . ($isUid ? 'true' : 'false') . ")");
		}

		$messageParts = [];
		$hasHtml = false;

		// Closure to recursively process parts
		$decodePart = function ($part, string $partNum) use (&$decodePart, $identifier, $options, &$messageParts, &$hasHtml) {
			// skip attachments explicitly marked
			if (!empty($part->disposition) && strtolower($part->disposition) === 'attachment') {
				return;
			}

			// Fetch the body part using the correct identifier type
			$raw = imap_fetchbody($this->conn, $identifier, $partNum, $options | FT_PEEK); // Use FT_PEEK to not mark as read
			if ($raw === false) {
				// Error fetching part, continue if possible
				throw new RuntimeException("IMAPEmailChecker: Failed to fetch body part {$partNum} for identifier {$identifier}");
				return;
			}

			// decode by encoding type
			$raw = match ($part->encoding) {
				self::ENC8BIT => imap_utf8($raw), // Use imap_utf8 for 8bit which might contain UTF-8
				self::ENCBINARY => imap_binary($raw),
				self::ENCBASE64 => imap_base64($raw),
				self::ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
				// ENC7BIT, ENCOTHER -> leave as is
				default => $raw
			};

			// convert charset to utf8
			$raw = $this->normalizeToUtf8($raw, $part);

			// recurse if multipart
			if (!empty($part->parts) && is_array($part->parts)) {
				foreach ($part->parts as $idx => $subPart) {
					// Pass the correct identifier and options down
					$decodePart($subPart, $partNum . '.' . ($idx + 1));
				}
				return; // Don't process the multipart container itself
			}

			// Process leaf parts (non-multipart)
			// Choose HTML over plain text
			$subtype = strtolower($part->subtype ?? '');
			$type = $part->type ?? -1; // Get the integer type, default to -1 if missing

			// Check primary type (self::TYPETEXT = 0) and subtype
			if ($type === self::TYPETEXT) { // Compare with integer constant
				if ($subtype === 'html') {
					$messageParts = [$raw]; // Prioritize HTML, replace any plain text found so far
					$hasHtml = true;
				} elseif ($subtype === 'plain') {
					if (!$hasHtml) { // Only add plain text if we haven't found HTML yet
						$messageParts[] = $raw;
					}
				}
				// Ignore other text subtypes for the main body
			}
			// If it's not text (e.g., image, application) and not explicitly an attachment,
			// it might be an inline element handled by checkForAttachments, so ignore here.
		};

		// Start recursion
		if (!empty($structure->parts) && is_array($structure->parts)) {
			// Multipart message
			foreach ($structure->parts as $i => $p) {
				$decodePart($p, (string)($i + 1));
			}
		} else {
			// Single part message (or structure describes the main part directly)
			$decodePart($structure, '1');
		}

		$body = trim(implode("\n", $messageParts));
		return $body !== '' ? $body : false; // Return false if body is effectively empty
	}


	/**
	 * Ensure the given raw part data is UTF‑8.
	 *
	 * 1) Reads any declared charset param from the part’s parameters or dparameters
	 * 2) Falls back to mb_detect_encoding() against common encodings
	 * 3) Converts to UTF‑8 if needed
	 *
	 * @param string $raw  The decoded-but-not‑yet‑re‑encoded part body.
	 * @param object $part The MIME part object from imap_fetchstructure().
	 * @return string      The UTF‑8–encoded text.
	 */
	private function normalizeToUtf8(string $raw, object $part): string
	{
		// look for a declared charset
		$charset = null;
		// Check parameters first (often used for text parts)
		if (!empty($part->parameters) && is_array($part->parameters)) {
			foreach ($part->parameters as $p) {
				if (isset($p->attribute) && strcasecmp($p->attribute, 'charset') === 0) {
					$charset = $p->value;
					break;
				}
			}
		}

		// Check dparameters if not found in parameters (often used for attachments/disposition)
		if (!$charset && !empty($part->dparameters) && is_array($part->dparameters)) {
			foreach ($part->dparameters as $p) {
				if (isset($p->attribute) && strcasecmp($p->attribute, 'charset') === 0) {
					$charset = $p->value;
					break;
				}
			}
		}

		// fallback auto‑detect if no charset declared or if it's something weird
		$needsConversion = false;
		if ($charset) {
			// Normalize charset name (e.g., remove quotes, handle aliases if necessary)
			$charset = strtoupper(trim($charset, " \t\n\r\0\x0B\"'"));
			if ($charset !== 'UTF-8' && $charset !== 'DEFAULT' && $charset !== 'US-ASCII') { // Assume US-ASCII is compatible
				$needsConversion = true;
			} elseif ($charset === 'DEFAULT') { // 'DEFAULT' often means ISO-8859-1 or system default
				$charset = 'ISO-8859-1'; // A common guess for 'DEFAULT'
				$needsConversion = true;
			}
		} else {
			// Auto-detect if no charset was specified
			// Limit detection order to prevent false positives if possible
			$detected = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252'], true);
			if ($detected && $detected !== 'UTF-8') {
				$charset = $detected;
				$needsConversion = true;
			} elseif (!$detected && !mb_check_encoding($raw, 'UTF-8')) {
				// If detection fails AND it's not valid UTF-8, guess ISO-8859-1
				$charset = 'ISO-8859-1';
				$needsConversion = true; // Attempt conversion
			}
		}

		// convert if needed and possible
		if ($needsConversion && $charset) {
			$convertedValue = null;

			try {
				$convertedAttempt = mb_convert_encoding($raw, 'UTF-8', $charset);
		
				if ($convertedAttempt !== false) {
					$convertedValue = $convertedAttempt;
				} else {
					if ($this->debug) { error_log("IMAPEmailChecker: mb_convert_encoding returned false when converting from '{$charset}' to 'UTF-8'. Input likely contained invalid byte sequences."); }
				}
			} catch (\ValueError $e) {
				// PHP 8+: Invalid encoding name
				if ($this->debug) { error_log("IMAPEmailChecker: mb_convert_encoding failed due to invalid encoding name '{$charset}'. Error: " . $e->getMessage()); }
			}
		
			// Only update $raw if conversion was successful
			if ($convertedValue !== null) {
				$raw = $convertedValue;
			}
		}

		// Final check: Ensure the final string is valid UTF-8, replacing invalid sequences
		// This helps clean up potential issues from failed conversions or mixed encodings.
		if (!mb_check_encoding($raw, 'UTF-8')) {
			$raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
		}

		return $raw;
	}


	/**
	 * Checks for attachments and inline images in the message, identified by message number or UID.
	 * This method finds ALL attachments and inline parts. Filtering happens later in processMessage.
	 *
	 * @param int $identifier The message sequence number or UID.
	 * @param bool $isUid Whether the identifier is a UID (true) or message number (false).
	 * @return array An array of attachments/inline parts with keys: filename, content, type, mime_type, disposition
	 *               and optionally content_id for inline images.
	 * @throws RuntimeException If fetching the message structure (imap_fetchstructure) fails.
	 */
	private function checkForAttachments(int $identifier, bool $isUid = false): array
	{
		$options = $isUid ? FT_UID : 0;
		$structure = imap_fetchstructure($this->conn, $identifier, $options);
		if ($structure === false) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to fetch structure for identifier {$identifier} (isUid: " . ($isUid ? 'true' : 'false') . "). Cannot process attachments. IMAP Error: " . $error);
		}
		$attachments = [];

		// Need to handle case where the top-level structure itself might be an attachment (rare)
		// For now, focus on parts as that's the common case.

		if (isset($structure->parts) && is_array($structure->parts)) {
			$flattenParts = function ($parts, $prefix = '') use (&$flattenParts, &$attachments, $identifier, $options, $isUid) {
				foreach ($parts as $i => $part) {
					$partNum = $prefix . ($i + 1);

					// Check for filename in parameters or dparameters
					$filename = null;

					// Prefer filename from Content-Disposition dparameters
					if (!empty($part->dparameters)) {
						foreach ($part->dparameters as $param) {
							if (isset($param->attribute) && strcasecmp($param->attribute, 'filename') === 0) {
								$filename = $param->value;
								break;
							}
						}
					}

					// Fallback to name from Content-Type parameters if filename not found
					if ($filename === null && !empty($part->parameters)) {
						foreach ($part->parameters as $param) {
							if (isset($param->attribute) && strcasecmp($param->attribute, 'name') === 0) {
								$filename = $param->value;
								break;
							}
						}
					}

					// Decode filename if MIME encoded
					if ($filename !== null) {
						$filename = $this->decodeHeaderValue($filename); // Use the same decoder as headers
					}

					// Determine if it's an attachment or inline based on disposition or filename presence
					$disposition = strtolower($part->disposition ?? '');
					$isAttachment = ($disposition === 'attachment');
					$isInline = ($disposition === 'inline');
					$contentId = null;
					if ($isInline && isset($part->id)) {
						$contentId = trim($part->id, '<>');
					}

					// Consider it an attachment if disposition is 'attachment' OR
					// if it's not 'inline', not text/plain, not text/html, not multipart/*, AND has a filename.
					$type = $part->type ?? -1; // Get integer type
					$subtype = strtolower($part->subtype ?? '');

					if (!$isAttachment && !$isInline && $filename !== null) {
						// Check if it's NOT typical viewable content like text or a container
						if ($type !== self::TYPETEXT || ($subtype !== 'plain' && $subtype !== 'html')) {
							if ($type !== self::TYPEMULTIPART && $type !== self::TYPEMESSAGE) { // Messages can contain parts, don't treat container as attachment
								$isAttachment = true; // Treat as attachment if it has a filename and isn't viewable body content or a container
							}
						}
					}

					if ($isAttachment || $isInline) {
						// Fetch content using the correct identifier type
						$content = imap_fetchbody($this->conn, $identifier, $partNum, $options | FT_PEEK);
						if ($content === false) {
							$error = imap_last_error() ?: 'Unknown error';
        					if ($this->debug) { error_log("IMAPEmailChecker: Failed to fetch body for part {$partNum} (identifier {$identifier}, isUid: " . ($isUid ? 'true' : 'false') . "). Skipping this part. IMAP Error: " . $error); }
							continue; // Skip if fetching failed
						}

						$content = match($part->encoding) {
							self::ENCBASE64 => imap_base64($content),
							self::ENCQUOTEDPRINTABLE => quoted_printable_decode($content),
							// Assume others are already decoded or don't need decoding (binary, 8bit, 7bit)
							default => $content
						};

						// Construct full MIME type string
						$mimeType = $this->getMimeTypeString($type, $subtype);

						$attachmentData = [
							'filename' => $filename ?? 'unknown_' . $partNum, // Provide default if no filename found
							'content'  => $content,
							'type'     => $subtype, // e.g., JPEG, PNG, PDF (legacy/simple type)
							'mime_type'=> $mimeType, // Full MIME type e.g. image/jpeg
							'disposition' => $disposition ?: ($isAttachment ? 'attachment' : ($isInline ? 'inline' : null)), // Store disposition
						];

						if ($isInline && $contentId !== null) {
							$attachmentData['content_id'] = $contentId;
						}

						$attachments[] = $attachmentData;
					}

					// Recurse into subparts if they exist (e.g., multipart/related, message/rfc822)
					if (!empty($part->parts) && is_array($part->parts)) {
						// Pass the part number prefix correctly
						$flattenParts($part->parts, $partNum . '.');
					}
				}
			};

			$flattenParts($structure->parts);
		}
		// Note: This doesn't handle attachments in non-multipart messages well,
		// but those are less common. A single-part message that *is* an attachment
		// would likely be handled by the calling context checking the main structure type.

		return $attachments;
	}

	/**
	 * Helper to convert IMAP type/subtype constants/strings to a MIME type string.
	 *
	 * @param int $type The integer type constant (e.g., self::TYPETEXT).
	 * @param string $subtype The subtype string (e.g., 'html').
	 * @return string The full MIME type string (e.g., 'text/html').
	 */
	private function getMimeTypeString(int $type, string $subtype): string
	{
		$primaryType = match ($type) {
			self::TYPETEXT => 'text',
			self::TYPEMULTIPART => 'multipart',
			self::TYPEMESSAGE => 'message',
			self::TYPEAPPLICATION => 'application',
			self::TYPEAUDIO => 'audio',
			self::TYPEIMAGE => 'image',
			self::TYPEVIDEO => 'video',
			self::TYPEMODEL => 'model',
			default => 'application', // Default for TYPEOTHER or unknown
		};
		// Subtype might be empty for some types, default to octet-stream if application
		if (empty($subtype)) {
			return $primaryType === 'application' ? 'application/octet-stream' : $primaryType;
		}
		return $primaryType . '/' . strtolower($subtype);
	}


	/**
	 * Replaces CID references in HTML with corresponding inline image data URIs.
	 *
	 * Searches for "cid:" references in the HTML and replaces them with base64-encoded
	 * data URIs using the matching inline image attachment. Uses the FULL list of attachments found.
	 *
	 * @param string $html The original HTML content.
	 * @param array $allAttachments The list of ALL attachments/inline parts from checkForAttachments().
	 * @return string The HTML content with embedded inline images.
	 */
	private function embedInlineImages(string $html, array $allAttachments): string
	{
		if (empty($allAttachments) || strpos($html, 'cid:') === false) {
			return $html; // No attachments or no CIDs found, return original HTML
		}

		return preg_replace_callback('/(["\']?)cid:([^"\'\s>]+)(["\']?)/i', function ($matches) use ($allAttachments) {
			$cid = $matches[2]; // The actual Content-ID
			$quoteStart = $matches[1]; // Leading quote, if any
			$quoteEnd = $matches[3]; // Trailing quote, if any

			foreach ($allAttachments as $attachment) {
				// Check if it's inline and the content_id matches
				if (isset($attachment['content_id']) && $attachment['content_id'] === $cid && ($attachment['disposition'] ?? null) === 'inline') {
					// Use the full mime_type calculated earlier.
					$mime = $attachment['mime_type'] ?? 'application/octet-stream'; // Fallback needed?
					if (strpos($mime, '/') === false) { // Simple check if it looks like a full mime type
						// If only subtype was stored previously, reconstruct (e.g., image/jpeg)
						// This depends on how mime_type was stored; best to store the full one.
						$mime = 'image/' . strtolower($mime); // Assuming image if only subtype stored
					}
					$base64 = base64_encode($attachment['content']);
					// Return the data URI, preserving original quotes if they existed
					return $quoteStart . "data:" . $mime . ";base64," . $base64 . $quoteEnd;
				}
			}
			// Return the original match (e.g., "cid:some_id") if no corresponding inline attachment is found.
			return $matches[0];
		}, $html);
	}


	/**
	 * Formats an address object from imap_headerinfo into a string.
	 *
	 * @param stdClass $addr The address object (must have mailbox and host properties).
	 * @return string The formatted email address "mailbox@host". Returns empty string if invalid.
	 */
	private function formatAddress(stdClass $addr): string
	{
		// Check if mailbox and host exist and are not empty
		if (isset($addr->mailbox) && $addr->mailbox !== '' && isset($addr->host) && $addr->host !== '') {
			// Handle potential encoding in mailbox part (though less common than personal)
			$mailbox = $this->decodeHeaderValue($addr->mailbox);
			// Hostnames generally shouldn't be MIME encoded, but decode just in case? Usually not needed.
			// $host = $this->decodeHeaderValue($addr->host);
			$host = $addr->host;
			return $mailbox . "@" . $host;
		}
		// Handle special case: undiscosed recipients, group syntax etc.
		// Example: "undisclosed-recipients:;" -> mailbox will be "undisclosed-recipients" host will be null/missing
		// Example: "Group Name: member1@a.com, member2@b.com;" -> mailbox="Group Name", host=null/missing
		// For now, we only return valid mailbox@host formats.
		return '';
	}

	/**
	 * Formats an array of address objects from imap_headerinfo into an array of strings.
	 * Handles nested groups if present.
	 *
	 * @param array|null $addresses Array of address objects (e.g., $header->to).
	 * @return array An array of formatted email address strings.
	 */
	private function formatAddressList(?array $addresses): array
	{
		$list = [];
		if (is_array($addresses)) {
			foreach ($addresses as $addr) {
				if ($addr instanceof stdClass) {
					// Check for group syntax (mailbox set, host missing) - recurse if needed, though imap_headerinfo usually flattens?
					// Let's assume imap_headerinfo provides a flat list of final recipients.
					$formatted = $this->formatAddress($addr);
					if ($formatted !== '') {
						$list[] = $formatted;
					}
					// If you needed to handle RFC822 group syntax explicitly, you'd check for missing host
					// and potentially iterate over a 'groupaddresses' property if the library provided it.
				}
			}
		}
		return $list;
	}

	/**
	 * Decodes a potentially MIME-encoded header value to UTF-8.
	 *
	 * Uses mb_decode_mimeheader and then ensures the result is valid UTF-8,
	 * attempting conversion from ISO-8859-1 as a fallback if necessary.
	 * Logs failures during decoding or conversion attempts.
	 *
	 * @param string|null $value The raw header value.
	 * @return string The decoded string, or empty string if input is null.
	 */
	private function decodeHeaderValue(?string $value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		$decoded = mb_decode_mimeheader($value);
		if ($decoded === false) {
			if ($this->debug) { error_log("IMAPEmailChecker: mb_decode_mimeheader failed for header value. Returning original value: " . $value); }
			// Return the original raw value as a fallback, it might be plain ASCII
			return $value;
		}

		// It's already UTF-8, we're done
		if (mb_check_encoding($decoded, 'UTF-8')) {
			return $decoded;
		}

		$converted = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
		if ($converted !== false) {
			return $converted;
		} else {
			// Conversion failed (e.g., $decoded had invalid ISO-8859-1 byte sequences)
			if ($this->debug) { error_log("IMAPEmailChecker: mb_convert_encoding failed to convert decoded header from ISO-8859-1 to UTF-8. Returning originally decoded (non-UTF-8) value."); }
			
			// Fallback: Return the result from mb_decode_mimeheader, even though it's not UTF-8.
			// This preserves the decoded information as best as possible.
			return $decoded;
		}
	}


	/**
	 * Fetches and processes specific email messages by their identifiers (UIDs or Sequence Numbers).
	 *
	 * This method attempts to retrieve the full details for each valid message identifier provided.
	 * If processing fails for a specific message (e.g., due to server errors fetching headers/body),
	 * an error is logged, and processing continues with the next identifier.
	 * Errors are also logged for any invalid identifiers provided in the input array (non-integer or <= 0).
	 *
	 * Note: This method does NOT update the main $this->messages or $this->lastuid properties
	 * of the class, as it's intended for fetching specific messages on demand.
	 *
	 * @param array $identifiers An array of potential integer UIDs or message sequence numbers.
	 * @param bool  $isUid       True if the identifiers are UIDs (default), False if they are sequence numbers.
	 * @return array An associative array where keys are the successfully processed identifiers
	 *               (using the UID from the message if $isUid=true, otherwise the original sequence number)
	 *               and values are the processed message data arrays. Messages that failed processing
	 *               or had invalid identifiers will be omitted from the results but logged.
	 */
	public function fetchMessagesByIds(array $identifiers, bool $isUid = true): array
	{
		$fetchedMessages = [];
		if (empty($identifiers)) {
			return $fetchedMessages; // Return empty if no IDs provided
		}

		foreach ($identifiers as $originalId) {
			// 1. Validate the individual identifier BEFORE processing
			if (!is_int($originalId)) {
				if ($this->debug) { error_log("IMAPEmailChecker: Skipping non-integer identifier provided to fetchMessagesByIds: " . var_export($originalId, true)); }
				continue; // Skip to next identifier
			}
			if ($originalId <= 0) {
				if ($this->debug) { error_log("IMAPEmailChecker: Skipping non-positive identifier provided to fetchMessagesByIds: {$originalId}"); }
				continue; // Skip to next identifier
			}

			// $originalId is now confirmed as a positive integer

			// 2. Try processing the message, catching exceptions
			try {
				// processMessage now throws RuntimeException on failure
				$processed = $this->processMessage($originalId, $isUid);

				// Determine the key for the results array
				// Use UID from the message if available and requested, otherwise use original ID
				$key = $isUid ? ($processed['uid'] ?? $originalId) : $originalId;
				$fetchedMessages[$key] = $processed;

			} catch (RuntimeException $e) { 
				// Catch exceptions from processMessage
				// Log the failure for this specific message and continue the loop
				if ($this->debug) { error_log("IMAPEmailChecker: Failed to process message identifier {$originalId} (isUid: " . ($isUid ? 'true' : 'false') . ") during fetchMessagesByIds. Error: " . $e->getMessage()); }
			} 
		}

		return $fetchedMessages;
	}
	

	/**
	 * Processes an individual email message and returns its data as an associative array.
	 * Uses either the message sequence number or the UID.
	 *
	 * This method attempts to retrieve all core components of the message. If critical
	 * components like message number, UID, or header information cannot be retrieved,
	 * or if fetching the attachment structure fails, it will throw an exception.
	 * Failures during body decoding or fetching individual attachment contents are logged,
	 * allowing the method to return partial data (e.g., message without body or with fewer attachments).
	 *
	 * @param int $identifier The message number or UID to process.
	 * @param bool $isUid True if $identifier is a UID, false if it's a message number. Default is false.
	 * @return array The processed message data array.
	 * @throws RuntimeException If fetching essential message information (MsgNo, UID, Header)
	 *                         		or the attachment structure fails.
	 */
	private function processMessage(int $identifier, bool $isUid = false): array
	{
		// Using @ to suppress potential PHP warnings for imap_* functions,
    	// as we will check the return value and throw exceptions with imap_last_error().

		// 1. Get Message Number and UID
		$msgNo = $isUid ? @imap_msgno($this->conn, $identifier) : $identifier;
		if (!$msgNo) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to get message number for " . ($isUid ? "UID" : "identifier") . " {$identifier}. IMAP Error: " . $error);
		}

		// Get UID if we started with message number, or confirm the one we have
		$uid = $isUid ? $identifier : @imap_uid($this->conn, $msgNo);
		if (!$uid) {
			$error = imap_last_error() ?: 'Unknown error';
        	throw new RuntimeException("Failed to get UID for message number {$msgNo}. IMAP Error: " . $error);
		}

		// 2. Fetch Header Info using Message Number
		$header = @imap_headerinfo($this->conn, $msgNo);
		if (!$header || !($header instanceof stdClass)) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to fetch header info for message number {$msgNo} (UID: {$uid}). IMAP Error: " . $error);
		}

		// 3. Attempt to Decode Body (Catch failure, proceed without body)
		$messageBody = '';
		try {
			$decoded = $this->decodeBody($identifier, $isUid);
			if ($decoded !== false) {
					$messageBody = $decoded;
			}
		} catch (RuntimeException $e) {
			// Log the failure but continue processing the message
			if ($this->debug) { error_log("IMAPEmailChecker: Error decoding body for UID {$uid} (MsgNo: {$msgNo}). Proceeding without body. Details: " . $e->getMessage()); }
		}

		// 4. Attempt to Check for ALL Attachments (Catch failure, proceed without attachments)
		$allAttachments = [];
		try {
			$allAttachments = $this->checkForAttachments($identifier, $isUid);
		} catch (RuntimeException $e) {
			// Log the failure to get attachment structure, but continue processing
			if ($this->debug) { error_log("IMAPEmailChecker: Error checking attachments for UID {$uid} (MsgNo: {$msgNo}). Proceeding without attachments. Details: " . $e->getMessage()); }
		}

		// 5. Embed Inline Images (if body exists) using the FULL list of attachments/inline parts
		if ($messageBody !== '' && !empty($allAttachments)) {
			$messageBody = $this->embedInlineImages($messageBody, $allAttachments);
		}

		// 6. Assemble Processed Data
		$processed = [];
		$processed['uid'] = $uid; // Use UID as the primary key
		$processed['message_number'] = $msgNo;

		// Clean message-id: remove angle brackets if present
		$messageIdRaw = isset($header->message_id) ? trim($header->message_id) : null;
		$processed['message_id'] = $messageIdRaw ? trim($messageIdRaw, '<>') : null;
		$processed['subject'] = isset($header->subject) ? $this->decodeHeaderValue($header->subject) : '';
		$processed['message_body'] = $messageBody;
		$processed['date'] = isset($header->date) ? $header->date : null; // Raw date string

		// Try parsing the date for easier use later, fallback to raw string
		$processed['datetime'] = null;
		if (isset($header->udate) && $header->udate > 0) { // Check udate is valid
			$processed['datetime'] = (new DateTime())->setTimestamp($header->udate);
		} elseif (isset($header->date)) {
			try {
				// Attempt parsing with DateTime, might fail on weird formats
				$processed['datetime'] = new DateTime($header->date);
			} catch (\Exception $e) {
				// Fallback: try parsing with strtotime as it's more lenient
				$ts = strtotime($header->date);
				if ($ts !== false) {
					$processed['datetime'] = (new DateTime())->setTimestamp($ts);
				} else {
					$processed['datetime'] = null; // Parsing failed completely
				}
			}
		}

		// Process Addresses using helper methods
		$processed['to'] = isset($header->to) ? $this->formatAddressList($header->to) : [];
		$processed['tocount'] = count($processed['to']);

		$processed['cc'] = isset($header->cc) ? $this->formatAddressList($header->cc) : [];
		$processed['cccount'] = count($processed['cc']);

		$processed['bcc'] = isset($header->bcc) ? $this->formatAddressList($header->bcc) : [];
		$processed['bcccount'] = count($processed['bcc']);

		// From address (prefer 'from', fallback to 'sender')
		$fromAddress = '';
		$fromDisplay = ''; // Full "Personal <mailbox@host>"
		$fromSource = $header->from ?? ($header->sender ?? null); // Prefer 'from'
		if (!empty($fromSource) && is_array($fromSource)) {
			// Use the first address in the array
			$fromObj = $fromSource[0];
			if ($fromObj instanceof stdClass) {
				$fromAddress = $this->formatAddress($fromObj);
				// Construct display name (handle potential encoding in 'personal')
				$personal = isset($fromObj->personal) ? $this->decodeHeaderValue($fromObj->personal) : '';
				if ($personal !== '' && $fromAddress !== '') {
					$fromDisplay = $personal . ' <' . $fromAddress . '>';
				} elseif ($fromAddress !== '') {
					// If no personal name, display is just the address
					$fromDisplay = $fromAddress;
				} elseif ($personal !== '') {
					// If personal name but no valid address (e.g., group syntax), display is just the name
					$fromDisplay = $personal;
				}
			}
		}

		// Fallback using the pre-formatted 'fromaddress'/'senderaddress' if our parsing failed
		if ($fromDisplay === '' && isset($header->fromaddress)) {
			$fromDisplay = $this->decodeHeaderValue($header->fromaddress);
		} elseif ($fromDisplay === '' && isset($header->senderaddress)) {
			$fromDisplay = $this->decodeHeaderValue($header->senderaddress);
		}

		// Try to extract email from display string if address still missing
		if ($fromAddress === '' && $fromDisplay !== '') {
			if (preg_match('/<([^>]+@[^>]+)>/', $fromDisplay, $matches)) {
				$fromAddress = $matches[1];
			} elseif (strpos($fromDisplay, '@') !== false && strpos($fromDisplay, '<') === false) {
				// Assume display *is* the address if no brackets and contains @
				$fromAddress = $fromDisplay;
			}
		}

		$processed['fromaddress'] = $fromAddress; // Just email@domain.com
		$processed['from'] = $fromDisplay; // Full display string

		// Searches the subject for a string with a given custom regex and saves it as "bid"
		$thisbid = null;
		if ($processed['subject'] !== '') {
            // Use '@' to suppress potential warnings from invalid patterns (user input)
            $matchResult = @preg_match($this->bidRegex, $processed['subject'], $matches);

            if ($matchResult === 1) {
                if (isset($matches[1])) {
                    $thisbid = $matches[1];
                } else {
                     if ($this->debug) { error_log("IMAPEmailChecker: BID regex '{$this->bidRegex}' matched subject but capturing group 1 was not found in matches: " . var_export($matches, true)); }
                }
            } elseif ($matchResult === false) {
                 if ($this->debug) { error_log("IMAPEmailChecker: preg_match error executing BID regex '{$this->bidRegex}'. Error: " . preg_last_error_msg()); }
            }
        }
        $processed['bid'] = $thisbid;

		// Unseen flag ('U' = unseen, ' ' = recent but unseen)
		// Check 'Recent' flag as well ('N' = recent, 'R' = read, ' ' = not recent)
		// Unseen = Unseen flag is 'U' OR (Recent flag is 'N' AND Seen flag is not set)
		$isUnseen = (isset($header->Unseen) && trim($header->Unseen) === 'U');
		$isRecent = (isset($header->Recent) && trim($header->Recent) === 'N');
		$isSeen = (isset($header->Seen) && trim($header->Seen) === 'S'); // Check Seen flag explicitly

		// Consider unseen if explicitly marked 'U', or if Recent='N' and not explicitly Seen='S'
		$processed['unseen'] = $isUnseen || ($isRecent && !$isSeen);

		// Filter attachments to exclude inline images (those with content_id), mimicking old behavior.
		$filteredAttachments = array_filter($allAttachments, function($attachment) {
			// Check if 'content_id' key exists and is not null/empty (robust check)
			return !isset($attachment['content_id']) || empty($attachment['content_id']);
		});
		// Assign the filtered list, resetting array keys for cleaner JSON/output if needed
		$processed['attachments'] = array_values($filteredAttachments);

		return $processed;
	}


	/**
	 * Retrieves and processes all emails from the mailbox.
	 *
	 * Attempts to fetch an overview first for efficiency. If overview fetching fails,
	 * it falls back to iterating through messages by sequence number.
	 * For each email, it attempts to decode the body, retrieve attachments, embed inline images,
	 * and extract header details using the processMessage method.
	 * If processing an individual message fails critically (as determined by processMessage throwing
	 * an exception), an error is logged, and that message is skipped.
	 * Results are stored in the public $messages property keyed by UID and also returned by this method.
	 * The public $lastuid property is updated to the highest UID encountered.
	 *
	 * @return array An array of successfully processed email data, keyed by UID.
	 *               Messages that failed processing will be omitted but logged.
	 * @throws RuntimeException If obtaining the initial message count (imap_num_msg) fails.
	 */
	public function checkAllEmail(): array
	{
		// Use @ suppression for imap functions where we check the return value
		$msg_count_result = @imap_num_msg($this->conn);
		if ($msg_count_result === false) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to get message count (imap_num_msg). IMAP Error: " . $error);
		}
		$msg_count = (int) $msg_count_result; // Cast to int

		$this->messages = []; // Reset messages
		$current_last_uid = $this->lastuid; // Track the highest UID encountered

		if ($msg_count > 0) {
			$processedViaOverview = false; // Flag to track if overview was attempted and successful

			// Attempt to fetch overview first
			$overviews = @imap_fetch_overview($this->conn, "1:{$msg_count}", 0);

			// Check specifically for fetch_overview failure (returns false)
			if ($overviews === false) {
				$error = imap_last_error() ?: 'Unknown error';
				if ($this->debug) { error_log("IMAPEmailChecker: imap_fetch_overview failed. Falling back to message number iteration. IMAP Error: " . $error); }
				// Proceed to fallback loop below
			}
			// Check if overview succeeded but returned empty (less common for 1:N) or wasn't an array
			elseif (empty($overviews) || !is_array($overviews)) {
				if ($this->debug) { error_log("IMAPEmailChecker: imap_fetch_overview returned empty or invalid data. Falling back to message number iteration."); }
				// Proceed to fallback loop below
			}
			// Overview succeeded and returned data
			else {
				$processedViaOverview = true; // Mark that we used the overview path
				foreach ($overviews as $overview) {
					if (!isset($overview->uid)) {
						if ($this->debug) { error_log("IMAPEmailChecker: Skipping overview entry without UID during checkAllEmail."); }
						continue;
					}
					$uid = (int)$overview->uid;

					try {
						// processMessage throws RuntimeException on critical failure
						$processed = $this->processMessage($uid, true);

						// Store successful result
						$this->messages[$uid] = $processed;

						if ($uid > $current_last_uid) {
							$current_last_uid = $uid;
						}
					} catch (RuntimeException $e) {
						// Log processing failure for this UID and continue
						if ($this->debug) { error_log("IMAPEmailChecker: Failed to process message UID {$uid} during checkAllEmail (overview loop). Error: " . $e->getMessage()); }
						// Continue to the next overview item
					}
				}
			}

			// --- Fallback Loop ---
			// Execute only if overview failed or returned no usable data
			if (!$processedViaOverview) {
				for ($i = 1; $i <= $msg_count; $i++) {
					try {
						// Process by message number, processMessage throws on critical failure
						$processed = $this->processMessage($i, false);

						// We need the UID to store it correctly
						if (isset($processed['uid'])) {
							$uid = $processed['uid'];
							$this->messages[$uid] = $processed; // Store by UID

							if ($uid > $current_last_uid) {
								$current_last_uid = $uid;
							}
						} else {
							// This case should ideally not happen if processMessage succeeded
							// but didn't return a UID, indicating a logic error in processMessage.
							if ($this->debug) { error_log("IMAPEmailChecker: Processed message number {$i} successfully but UID missing in result during fallback loop."); }
						}
					} catch (RuntimeException $e) {
						// Log processing failure for this message number and continue
						if ($this->debug) { error_log("IMAPEmailChecker: Failed to process message number {$i} during checkAllEmail (fallback loop). Error: " . $e->getMessage()); }
						// Continue to the next message number
					} 
				} 
			}
		}

		$this->lastuid = $current_last_uid; // Update last processed UID
		return $this->messages;
	}


	/**
	 * Retrieves emails from the mailbox received on or after a specified date.
	 *
	 * Searches the mailbox for messages matching the criteria and processes each found message.
	 * If processing an individual message fails critically (as determined by processMessage throwing
	 * an exception), an error is logged, and that message is skipped.
	 * Results are stored in the public $messages property keyed by UID and also returned by this method.
	 * The public $lastuid property is updated to the highest UID encountered among the processed messages.
	 *
	 * @param DateTime $date The starting date (inclusive). Messages from this day onward will be checked.
	 * @return array An array of successfully processed email data, keyed by UID. Returns an empty array
	 *               if no messages are found matching the date criteria. Messages that failed processing
	 *               will be omitted but logged.
	 * @throws RuntimeException If the underlying imap_search operation fails.
	 */
	public function checkSinceDate(DateTime $date): array // Return type is now array only
	{
		$this->messages = [];

		// Use the current $lastuid as the starting point for updating the highest seen UID
		$current_last_uid = $this->lastuid;

		// Format date for IMAP SINCE command (RFC 3501 format: d-M-Y)
		$thedate = $date->format('d-M-Y');
		$searchCriteria = "SINCE \"{$thedate}\"";

		// Search returns UIDs because of SE_UID. Use @ to suppress potential warnings.
		$uids = @imap_search($this->conn, $searchCriteria, SE_UID);

		// Check specifically for imap_search failure
		if ($uids === false) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("imap_search failed for criteria '{$searchCriteria}'. IMAP Error: " . $error);
		}

		// Sort UIDs just in case the server doesn't return them ordered
		sort($uids, SORT_NUMERIC);

		foreach ($uids as $uid) {
			$uid = (int)$uid;

			try {
				// processMessage throws RuntimeException on critical failure
				$processed = $this->processMessage($uid, true);

				// Store successful result
				$this->messages[$uid] = $processed;

				if ($uid > $current_last_uid) {
					$current_last_uid = $uid;
				}
			} catch (RuntimeException $e) {
				// Log processing failure for this UID and continue
				if ($this->debug) { error_log("IMAPEmailChecker: Failed to process message UID {$uid} during checkSinceDate. Error: " . $e->getMessage()); }
				// Continue to the next UID
			} 
		}

		// Update lastuid
		$this->lastuid = $current_last_uid;

		return $this->messages;
	}


	/**
	 * Retrieves emails from the mailbox with UIDs strictly greater than the specified UID.
	 *
	 * Fetches an overview of messages within the specified UID range and processes each found message.
	 * If processing an individual message fails critically (as determined by processMessage throwing
	 * an exception), an error is logged, and that message is skipped.
	 * Results are stored in the public $messages property keyed by UID and also returned by this method.
	 * The public $lastuid property is updated to the highest UID encountered among the successfully processed messages.
	 *
	 * @param int $uid The UID *after* which to fetch messages (exclusive).
	 * @return array An array of successfully processed email data, keyed by UID. Returns an empty array
	 *               if no messages are found with a UID greater than the input $uid. Messages that failed processing
	 *               will be omitted but logged.
	 * @throws RuntimeException If the underlying imap_fetch_overview operation fails.
	 */
	public function checkSinceLastUID(int $uid): array
	{
		$this->messages = [];

		// Start tracking from the provided UID. This will be updated only if newer messages are successfully processed.
		$highest_processed_uid = $uid;

		// The range {$startUidPlusOne}:* means all UIDs >= $startUidPlusOne.
		// Use '*' for the maximum possible UID.
		$startUidPlusOne = $uid + 1;
		$searchRange = "{$startUidPlusOne}:*";

		// Fetch overview for the range. Use @ to suppress potential warnings.
		$overviewList = @imap_fetch_overview($this->conn, $searchRange, FT_UID);

		// Check specifically for fetch_overview failure
		if ($overviewList === false) {
			$error = imap_last_error() ?: 'Unknown error';
			// Don't update lastuid if the fundamental check failed
			throw new RuntimeException("imap_fetch_overview failed for range '{$searchRange}'. IMAP Error: " . $error);
		}

		foreach ($overviewList as $ov) {
			// Ensure we have a valid UID from the overview
			if (!isset($ov->uid)) {
				if ($this->debug) { error_log("IMAPEmailChecker: Skipping overview entry without UID during checkSinceLastUID."); }
				continue;
			}

			$currentUid = (int)$ov->uid;
			// Double check it's actually greater than the requested UID (should be based on search range, but safety check)
			if ($currentUid <= $uid) {
				if ($this->debug) { error_log("IMAPEmailChecker: Overview for range '{$searchRange}' returned unexpected UID {$currentUid}. Skipping."); }
				continue;
			}

			try {
				$processed = $this->processMessage($currentUid, true);

				// Store successful result
				$this->messages[$currentUid] = $processed;

				// Update the highest UID *successfully processed* in this batch
				if ($currentUid > $highest_processed_uid) {
					$highest_processed_uid = $currentUid;
				}
			} catch (RuntimeException $e) {
				// Log processing failure for this UID and continue
				if ($this->debug) { error_log("IMAPEmailChecker: Failed to process message UID {$currentUid} during checkSinceLastUID. Error: " . $e->getMessage()); }
				// Continue to the next overview item
			}
		}

		// Update the class property ONLY to the highest UID successfully processed in this run.
		// If no new messages were processed successfully, $this->lastuid remains unchanged
		// because $highest_processed_uid would still equal the input $uid.
		$this->lastuid = $highest_processed_uid;

		return $this->messages;
	}

	
	/**
	 * Retrieves unread emails (\Unseen flag) from the mailbox.
	 *
	 * Searches for messages marked as Unseen and processes each found message.
	 * If processing an individual message fails critically (as determined by processMessage throwing
	 * an exception), an error is logged, and that message is skipped.
	 * Results are stored in the public $messages property keyed by UID and also returned by this method.
	 * The public $lastuid property is updated to the highest UID encountered among the processed messages.
	 *
	 * @return array An array of successfully processed unread email data, keyed by UID. Returns an empty array
	 *               if no unread emails are found. Messages that failed processing will be omitted but logged.
	 * @throws RuntimeException If the underlying imap_search operation for 'UNSEEN' messages fails.
	 */
	public function checkUnreadEmails(): array
	{
		$this->messages = [];

		// Keep track of the highest UID encountered among successfully processed messages in this batch
		$highest_processed_uid = $this->lastuid;

		// Search for messages with the \Unseen flag. SE_UID returns UIDs.
		// Use @ to suppress potential PHP warnings.
		$uids = @imap_search($this->conn, 'UNSEEN', SE_UID);

		// Check specifically for imap_search failure
		if ($uids === false) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("imap_search failed for criteria 'UNSEEN'. IMAP Error: " . $error);
		}

		// Sort UIDs just in case the server doesn't return them ordered
		sort($uids, SORT_NUMERIC);

		foreach ($uids as $uid) {
			$uid = (int)$uid;

			try {
				$processed = $this->processMessage($uid, true);

				// Store successful result
				$this->messages[$uid] = $processed;

				// Update the highest UID *successfully processed* in this batch
				if ($uid > $highest_processed_uid) {
					$highest_processed_uid = $uid;
				}
			} catch (RuntimeException $e) {
				// Log processing failure for this UID and continue
				if ($this->debug) { error_log("IMAPEmailChecker: Failed to process unread message UID {$uid} during checkUnreadEmails. Error: " . $e->getMessage()); }
				// Continue to the next UID
			} 
		}

		// Update the class property to the highest UID successfully processed in this run.
		$this->lastuid = $highest_processed_uid;

		return $this->messages;
	}


	/**
	 * Sets or clears the read status (\Seen flag) for one or more emails by their UIDs.
	 *
	 * @param array<int> $uids     An array of positive integer UIDs to modify.
	 *                             Example: [123, 456] or [$singleUid]
	 * @param bool       $markAsRead If true, sets the \Seen flag (marks as read).
	 *                             If false, clears the \Seen flag (marks as unread).
	 * @return void
	 * @throws InvalidArgumentException If the $uids array contains any non-positive or non-integer values.
	 * @throws RuntimeException If the underlying IMAP operation (imap_setflag_full or imap_clearflag_full) fails.
	 */
	public function setMessageReadStatus(array $uids, bool $markAsRead): void
	{
		if (empty($uids)) {
			return;
		}

		// Validate UIDs: Ensure all are positive integers. Throw if any are invalid.
		$validatedUids = [];
		foreach ($uids as $uid) {
			if (filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
				// Throw specific exception for bad input
				throw new InvalidArgumentException("Invalid UID provided in \$uids array: " . var_export($uid, true) . ". All UIDs must be positive integers.");
			}
			$validatedUids[] = (int)$uid;
		}

		// Convert the array of validated UIDs into a comma-separated string sequence
		$uidSequence = implode(',', $validatedUids);
		$flag = "\\Seen"; // The flag we are manipulating

		$success = false;
		$actionVerb = $markAsRead ? 'set' : 'clear';
		$actionDesc = $markAsRead ? 'mark read' : 'mark unread';

		// Use imap_setflag_full to mark as read, imap_clearflag_full to mark as unread
		// Use @ to suppress potential PHP warnings, check return value instead.
		if ($markAsRead) {
			$success = @imap_setflag_full($this->conn, $uidSequence, $flag, ST_UID);
		} else {
			$success = @imap_clearflag_full($this->conn, $uidSequence, $flag, ST_UID);
		}

		// Check if the IMAP operation failed
		if (!$success) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to {$actionVerb} {$flag} flag ({$actionDesc}) for UIDs: {$uidSequence}. IMAP Error: " . $error);
		}
	}


	/**
	 * Deletes an email from the mailbox by UID.
	 *
	 * This method marks the specified email for deletion and then expunges the mailbox
	 * to permanently remove messages marked for deletion.
	 *
	 * @param int $uid The UID of the email to delete. Must be a positive integer.
	 * @return void
	 * @throws InvalidArgumentException If the provided UID is not a positive integer.
	 * @throws RuntimeException If marking the message for deletion (imap_delete) or
	 *                         expunging the mailbox (imap_expunge) fails due to an IMAP error.
	 */
	public function deleteEmail(int $uid): void
	{
		// 1. Validate Input UID
		if ($uid <= 0) {
			throw new InvalidArgumentException("Invalid UID provided: {$uid}. UID must be a positive integer.");
		}

		// 2. Mark the message for deletion using its UID
		// Use @ to suppress potential PHP warnings, check return value.
		if (!@imap_delete($this->conn, (string)$uid, FT_UID)) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to mark UID {$uid} for deletion. IMAP Error: " . $error);
		}

		// 3. Permanently remove emails marked for deletion from the current mailbox.
		// Use @ to suppress potential PHP warnings.
		if (!@imap_expunge($this->conn)) {
			// imap_expunge can return false if there was nothing to expunge,
			// so we MUST check imap_last_error() to see if a real error occurred.
			$lastError = imap_last_error();
			if ($lastError) {
				// Only throw if there was a reported error during expunge
				throw new RuntimeException("Failed to expunge mailbox after marking UID {$uid} for deletion. IMAP Error: " . $lastError);
			}
		}
	}


	/**
	 * Archives an email by moving it to a specified folder by UID.
	 *
	 * This method moves the email identified by UID from the current mailbox to the
	 * target archive folder and then expunges the current mailbox to remove the
	 * original message placeholder.
	 *
	 * @param int    $uid           The UID of the email to archive. Must be a positive integer.
	 * @param string $archiveFolder The target folder name where the email should be moved. Must not be empty.
	 *                              (Default: "Archive"). Note: Folder names might need IMAP UTF-7 encoding
	 *                              depending on characters used and server requirements, which this method
	 *                              does not handle automatically. Pass pre-encoded names if necessary.
	 * @return void
	 * @throws InvalidArgumentException If the UID is not positive or the archive folder name is empty.
	 * @throws RuntimeException If moving the message (imap_mail_move) or expunging the mailbox
	 *                         (imap_expunge with a reported error) fails.
	 */
	public function archiveEmail(int $uid, string $archiveFolder = 'Archive'): void
	{
		// 1. Validate Input UID
		if ($uid <= 0) {
			throw new InvalidArgumentException("Invalid UID provided: {$uid}. UID must be a positive integer.");
		}

		// 2. Validate Archive Folder Name (Basic check)
		$trimmedFolder = trim($archiveFolder);
		if ($trimmedFolder === '') {
			throw new InvalidArgumentException("Archive folder name cannot be empty.");
		}

		// 3. Move the email to the archive folder using UID.
		// CP_UID is used for the options so the sequence ($uid) is treated as a UID.
		// Use @ to suppress potential PHP warnings, check return value.
		if (!@imap_mail_move($this->conn, (string)$uid, $archiveFolder, CP_UID)) {
			$error = imap_last_error() ?: 'Unknown error';
			throw new RuntimeException("Failed to move UID {$uid} to folder '{$archiveFolder}'. IMAP Error: " . $error);
		}

		// 4. Remove the moved email placeholder from the current mailbox.
		// Use @ to suppress potential PHP warnings.
		if (!@imap_expunge($this->conn)) {
			// Check if a real error occurred during expunge.
			$lastError = imap_last_error();
			if ($lastError) {
				// Only throw if there was a reported error during expunge
				throw new RuntimeException("Failed to expunge mailbox after moving UID {$uid} to '{$archiveFolder}'. IMAP Error: " . $lastError);
			}
		}
	}
}