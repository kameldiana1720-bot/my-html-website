<?php
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Please submit the contact form.");
}

$name = $_POST["name"] ?? null;
$email = $_POST["email"] ?? null;
$topic = $_POST["topic"] ?? null;
$message = $_POST["message"] ?? null;
$updates = $_POST["updates"] ?? "";

if (
    !is_string($name) ||
    !is_string($email) ||
    !is_string($topic) ||
    !is_string($message) ||
    !is_string($updates)
) {
    http_response_code(400);
    exit("Invalid form data.");
}

$name = trim($name);
$email = trim($email);
$message = trim($message);

if (
    $name === "" ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !in_array($topic, ["html", "website"], true) ||
    $message === "" ||
    !in_array($updates, ["", "yes"], true)
) {
    http_response_code(400);
    exit("Please check the form and submit valid information.");
}

$documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? "";
if ($documentRoot === "") {
    http_response_code(500);
    exit("The server document root is not configured.");
}

$storageDirectory = dirname($documentRoot) . DIRECTORY_SEPARATOR . "private_messages";
if (
    !is_dir($storageDirectory) &&
    !mkdir($storageDirectory, 0700, true) &&
    !is_dir($storageDirectory)
) {
    http_response_code(500);
    exit("The server could not create the private message folder.");
}

$messageRecord = [
    "received_at" => date(DATE_ATOM),
    "name" => $name,
    "email" => $email,
    "topic" => $topic,
    "message" => $message,
    "updates" => $updates === "yes",
];
$encodedRecord = json_encode($messageRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encodedRecord === false) {
    http_response_code(500);
    exit("The server could not prepare the message for storage.");
}

$storageFile = $storageDirectory . DIRECTORY_SEPARATOR . "messages.jsonl";
$bytesWritten = file_put_contents($storageFile, $encodedRecord . PHP_EOL, FILE_APPEND | LOCK_EX);
if ($bytesWritten === false) {
    http_response_code(500);
    exit("The server could not save the message.");
}

$mailStatus = "not_configured";
$autoloadFile = __DIR__ . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";
$mailConfigFile = $storageDirectory . DIRECTORY_SEPARATOR . "mail_config.php";

if (!is_file($autoloadFile)) {
    $mailStatus = "missing_library";
} elseif (is_file($mailConfigFile)) {
    require_once $autoloadFile;
    $mailConfig = require $mailConfigFile;

    if (
        !is_array($mailConfig) ||
        !isset(
            $mailConfig["recipient"],
            $mailConfig["smtp_username"],
            $mailConfig["smtp_password"]
        ) ||
        !is_string($mailConfig["recipient"]) ||
        !is_string($mailConfig["smtp_username"]) ||
        !is_string($mailConfig["smtp_password"]) ||
        !filter_var($mailConfig["recipient"], FILTER_VALIDATE_EMAIL) ||
        !filter_var($mailConfig["smtp_username"], FILTER_VALIDATE_EMAIL) ||
        $mailConfig["smtp_password"] === ""
    ) {
        $mailStatus = "invalid_config";
    } elseif (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $mailStatus = "missing_library";
    } else {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = "smtp.gmail.com";
            $mail->SMTPAuth = true;
            $mail->Username = $mailConfig["smtp_username"];
            $mail->Password = $mailConfig["smtp_password"];
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = "UTF-8";
            $mail->setFrom($mailConfig["smtp_username"], "Website contact form");
            $mail->addAddress($mailConfig["recipient"]);
            $mail->addReplyTo($email, $name);
            $mail->Subject = "Website contact: " . $topic;
            $mail->Body = "Name: {$name}\nEmail: {$email}\nTopic: {$topic}\n"
                . "Website updates: " . ($updates === "yes" ? "Yes" : "No")
                . "\n\nMessage:\n{$message}";
            $mail->send();
            $mailStatus = "sent";
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log("Contact form email failed: " . $exception->getMessage());
            $mailStatus = "failed";
        }
    }
}

$safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
$safeTopic = htmlspecialchars($topic, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"));
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Message received</title>
  </head>
  <body>
    <main>
      <h1>Thank you, <?= $safeName ?>!</h1>
      <p>Your message about <?= $safeTopic ?> was saved on this computer:</p>
      <p><?= $safeMessage ?></p>
      <?php if ($updates === "yes"): ?>
        <p>You asked to receive website updates.</p>
      <?php endif; ?>
      <?php if ($mailStatus === "sent"): ?>
        <p>An email notification was sent.</p>
      <?php elseif ($mailStatus === "failed"): ?>
        <p>The message was saved, but the email could not be sent. Check the XAMPP PHP error log.</p>
      <?php elseif ($mailStatus === "invalid_config"): ?>
        <p>The message was saved, but the email settings are incomplete or invalid.</p>
      <?php elseif ($mailStatus === "missing_library"): ?>
        <p>The message was saved, but PHPMailer is not installed.</p>
      <?php else: ?>
        <p>The message was saved, but email delivery is not configured yet.</p>
      <?php endif; ?>
      <p><a href="new.html">Return to the page</a></p>
    </main>
  </body>
</html>
