<?php
/**
 * PHP Email Sender — with CC/BCC and multiple attachments
 * Single-file, no dependencies (uses PHP's built-in mail() + manual MIME multipart).
 *
 * NOTE: mail() relies on a properly configured MTA (sendmail/postfix) or
 * SMTP settings in php.ini. On shared hosting this usually works out of
 * the box; on your own server you may need to configure sendmail_path.
 * For guaranteed deliverability (SPF/DKIM, retries, logging) consider
 * swapping mail() for PHPMailer + real SMTP credentials later — the form
 * and validation logic below would stay the same.
 */

$status  = "";
$isError = false;

// ---- Config -----------------------------------------------------------
$maxFileSize   = 8 * 1024 * 1024;     // 8 MB per file
$maxTotalSize  = 20 * 1024 * 1024;    // 20 MB total
$maxFiles      = 5;
$allowedExt    = ["jpg","jpeg","png","gif","pdf","txt","doc","docx","xls","xlsx","zip","csv"];

// ---- Helpers ------------------------------------------------------------

function cleanHeader($value) {
    // Strip CR/LF and any header-injection attempts
    return str_replace(["\r", "\n", "%0a", "%0d"], "", trim($value));
}

/**
 * Validates a comma-separated list of emails.
 * Returns an array of valid, cleaned emails, or false if any are invalid.
 */
function parseEmailList($raw) {
    $raw = trim($raw);
    if ($raw === "") return [];

    $parts = array_map('trim', explode(",", $raw));
    $valid = [];

    foreach ($parts as $addr) {
        if ($addr === "") continue;
        $addr = cleanHeader($addr);
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $valid[] = $addr;
    }
    return $valid;
}

// ---- Handle submission --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $senderName = trim($_POST["sender_name"] ?? "");
    $sender     = trim($_POST["sender"] ?? "");
    $recipient  = trim($_POST["recipient"] ?? "");
    $cc         = trim($_POST["cc"] ?? "");
    $bcc        = trim($_POST["bcc"] ?? "");
    $subject    = trim($_POST["subject"] ?? "");
    $message    = trim($_POST["message"] ?? "");

    $recipients = parseEmailList($recipient);
    $ccList     = parseEmailList($cc);
    $bccList    = parseEmailList($bcc);

    if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        $status = "Invalid sender email.";
        $isError = true;
    } elseif ($recipients === false || empty($recipients)) {
        $status = "Invalid or missing recipient email(s).";
        $isError = true;
    } elseif ($ccList === false) {
        $status = "Invalid email address in CC field.";
        $isError = true;
    } elseif ($bccList === false) {
        $status = "Invalid email address in BCC field.";
        $isError = true;
    } elseif ($subject === "") {
        $status = "Please enter a subject.";
        $isError = true;
    } elseif ($message === "") {
        $status = "Please enter a message.";
        $isError = true;
    } else {

        // --- Sanitize header-facing fields ---
        $sender     = cleanHeader($sender);
        $senderName = cleanHeader($senderName);
        $subject    = cleanHeader($subject);

        // --- Validate attachments ---
        $attachments = [];
        $totalSize   = 0;
        $fileError   = "";

        if (!empty($_FILES["attachments"]) && is_array($_FILES["attachments"]["name"])) {
            $count = count($_FILES["attachments"]["name"]);

            if ($count > $maxFiles) {
                $fileError = "You can attach at most $maxFiles files.";
            } else {
                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES["attachments"]["error"][$i] === UPLOAD_ERR_NO_FILE) {
                        continue; // empty slot, skip
                    }
                    if ($_FILES["attachments"]["error"][$i] !== UPLOAD_ERR_OK) {
                        $fileError = "Error uploading file: " . $_FILES["attachments"]["name"][$i];
                        break;
                    }

                    $tmpPath  = $_FILES["attachments"]["tmp_name"][$i];
                    $origName = basename($_FILES["attachments"]["name"][$i]);
                    $size     = $_FILES["attachments"]["size"][$i];
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt, true)) {
                        $fileError = "File type not allowed: $origName";
                        break;
                    }
                    if ($size > $maxFileSize) {
                        $fileError = "File too large (max " . ($maxFileSize / 1024 / 1024) . "MB): $origName";
                        break;
                    }

                    $totalSize += $size;
                    if ($totalSize > $maxTotalSize) {
                        $fileError = "Total attachment size exceeds " . ($maxTotalSize / 1024 / 1024) . "MB.";
                        break;
                    }

                    // Verify it's actually an uploaded file (not a path injection)
                    if (!is_uploaded_file($tmpPath)) {
                        $fileError = "Invalid upload.";
                        break;
                    }

                    $attachments[] = [
                        "name" => preg_replace('/[\r\n"]/', "", $origName),
                        "type" => mime_content_type($tmpPath) ?: "application/octet-stream",
                        "data" => file_get_contents($tmpPath),
                    ];
                }
            }
        }

        if ($fileError !== "") {
            $status  = $fileError;
            $isError = true;
        } else {

            // --- Build headers ---
            $fromHeader = $senderName !== ""
                ? mb_encode_mimeheader($senderName, "UTF-8") . " <$sender>"
                : $sender;

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: " . $fromHeader . "\r\n";
            $headers .= "Reply-To: " . $sender . "\r\n";

            if (!empty($ccList)) {
                $headers .= "Cc: " . implode(", ", $ccList) . "\r\n";
            }
            if (!empty($bccList)) {
                $headers .= "Bcc: " . implode(", ", $bccList) . "\r\n";
            }

            // --- Build body (HTML) ---
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, "UTF-8"));
            $htmlBody    = "<html><body>" . $safeMessage . "</body></html>";

            if (empty($attachments)) {
                // Simple HTML email, no attachments
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body = $htmlBody;
            } else {
                // Multipart/mixed with HTML body + attachments
                $boundary = "b" . md5(uniqid((string) mt_rand(), true));

                $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

                $body  = "--$boundary\r\n";
                $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $body .= $htmlBody . "\r\n\r\n";

                foreach ($attachments as $file) {
                    $encoded = chunk_split(base64_encode($file["data"]));
                    $body .= "--$boundary\r\n";
                    $body .= "Content-Type: " . $file["type"] . "; name=\"" . $file["name"] . "\"\r\n";
                    $body .= "Content-Transfer-Encoding: base64\r\n";
                    $body .= "Content-Disposition: attachment; filename=\"" . $file["name"] . "\"\r\n\r\n";
                    $body .= $encoded . "\r\n";
                }

                $body .= "--$boundary--";
            }

            $recipientHeader = implode(", ", $recipients);

            if (mail($recipientHeader, $subject, $body, $headers)) {
                $status  = "Email sent successfully!";
                $isError = false;
            } else {
                $status  = "Failed to send email. Check your server's mail configuration.";
                $isError = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Email Sender</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            padding: 40px;
        }

        .container {
            max-width: 700px;
            margin: auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0,0,0,.1);
        }

        h1 {
            margin-top: 0;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .hint {
            font-weight: normal;
            font-size: 12px;
            color: #777;
        }

        input,
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
        }

        input[type="file"] {
            padding: 8px;
            background: #fafafa;
        }

        textarea {
            min-height: 180px;
            resize: vertical;
        }

        .row {
            display: flex;
            gap: 15px;
        }

        .row > div {
            flex: 1;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            border: 0;
            border-radius: 6px;
            background: #222;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #444;
        }

        .status {
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 6px;
        }

        .status.ok {
            background: #e3f7e6;
            color: #17632c;
        }

        .status.err {
            background: #fdeaea;
            color: #8a1f1f;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Send Email</h1>

    <?php if (!empty($status)): ?>
        <div class="status <?= $isError ? "err" : "ok" ?>">
            <?= htmlspecialchars($status, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <div class="row">
            <div>
                <label for="sender_name">Sender Name <span class="hint">(optional)</span></label>
                <input
                    type="text"
                    id="sender_name"
                    name="sender_name"
                    placeholder="Your Name"
                    value="<?= htmlspecialchars($_POST["sender_name"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                >
            </div>
            <div>
                <label for="sender">Sender Email</label>
                <input
                    type="email"
                    id="sender"
                    name="sender"
                    placeholder="sender@yourdomain.com"
                    value="<?= htmlspecialchars($_POST["sender"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                    required
                >
            </div>
        </div>

        <label for="recipient">Recipient Email(s) <span class="hint">(comma-separated for multiple)</span></label>
        <input
            type="text"
            id="recipient"
            name="recipient"
            placeholder="recipient@example.com, another@example.com"
            value="<?= htmlspecialchars($_POST["recipient"] ?? "", ENT_QUOTES, "UTF-8") ?>"
            required
        >

        <div class="row">
            <div>
                <label for="cc">CC <span class="hint">(optional, comma-separated)</span></label>
                <input
                    type="text"
                    id="cc"
                    name="cc"
                    placeholder="cc@example.com"
                    value="<?= htmlspecialchars($_POST["cc"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                >
            </div>
            <div>
                <label for="bcc">BCC <span class="hint">(optional, comma-separated)</span></label>
                <input
                    type="text"
                    id="bcc"
                    name="bcc"
                    placeholder="bcc@example.com"
                    value="<?= htmlspecialchars($_POST["bcc"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                >
            </div>
        </div>

        <label for="subject">Subject</label>
        <input
            type="text"
            id="subject"
            name="subject"
            placeholder="Enter email subject"
            value="<?= htmlspecialchars($_POST["subject"] ?? "", ENT_QUOTES, "UTF-8") ?>"
            required
        >

        <label for="message">Message</label>
        <textarea
            id="message"
            name="message"
            placeholder="Write your message..."
            required
        ><?= htmlspecialchars($_POST["message"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>

        <label for="attachments">
            Attachments <span class="hint">(up to <?= $maxFiles ?> files, <?= $maxFileSize / 1024 / 1024 ?>MB each — jpg, png, gif, pdf, txt, doc(x), xls(x), zip, csv)</span>
        </label>
        <input
            type="file"
            id="attachments"
            name="attachments[]"
            multiple
        >

        <button type="submit">Send Email</button>

    </form>

</div>

</body>
</html>
