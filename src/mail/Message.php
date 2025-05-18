<?php

namespace yiifusion\mail;

use yii\base\BaseObject;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\mail\MailerInterface;
use yii\mail\MessageInterface;

use function array_keys;
use function basename;
use function file_get_contents;
use function implode;
use function is_file;
use function is_int;
use function is_string;
use function md5;
use function sprintf;
use function trim;
use function uniqid;

/**
 * Message is the base class for all message implementations in the mailer system.
 *
 * It implements the common methods required by MessageInterface and provides
 * additional functionality needed across different transport implementations.
 *
 * Usage example:
 * ```php
 * $message = new Message();
 * $message->setFrom('from@example.com')
 *     ->setTo(['user1@example.com' => 'User One', 'user2@example.com' => 'User Two'])
 *     ->setSubject('Test message')
 *     ->setTextBody('This is a plain text message')
 *     ->setHtmlBody('<p>This is an HTML message</p>');
 * ```
 *
 * @property-read Mailer $mailer The mailer instance that created this message.
 *
 * @author YiiFusion Team
 */
class Message extends BaseObject implements MessageInterface
{
    // SECTION: Properties
    /**
     * @var array<string, string> the sender email address(es).
     * The format is:
     * ```php
     * [
     *     'example@domain.com' => 'Sender Name',
     *     // or without a name:
     *     'example2@domain.com' => '',
     * ]
     * ```
     */
    private array $localFrom = [];

    /**
     * @var array<string, string> the receiver email address(es).
     * The format is the same as for [[localFrom]].
     */
    private array $localTo = [];

    /**
     * @var array<string, string> the reply-to email address(es).
     * The format is the same as for [[localFrom]].
     */
    private array $localReplyTo = [];

    /**
     * @var array<string, string> the Cc email address(es).
     * The format is the same as for [[localFrom]].
     */
    private array $localCc = [];

    /**
     * @var array<string, string> the Bcc email address(es).
     * The format is the same as for [[localFrom]].
     */
    private array $localBcc = [];

    /**
     * @var string the message subject
     */
    private string $localSubject = '';

    /**
     * @var string the message plain text content
     */
    private string $localTextBody = '';

    /**
     * @var string the message HTML content
     */
    private string $localHtmlBody = '';

    /**
     * @var array<string, array<string, mixed>> the message attachments.
     * The array keys are the attachment names and the array values are the attachment details.
     */
    private array $localAttachments = [];

    /**
     * @var array<string, array<string, mixed>> the embedded content.
     * The array keys are the attachment CID and the array values are the embedding details.
     */
    private array $localEmbeddings = [];

    /**
     * @var array<string, string> the custom headers for the message.
     */
    private array $localHeaders = [];

    /**
     * @var string the character set for the message.
     */
    private string $localCharset = 'utf-8';

    /**
     * @var Mailer|null the mailer instance that created this message.
     */
    private ?Mailer $localMailer = null;

    // SECTION: Getters and Setters

    // SUBSECTION: Character Set
    /**
     * Returns the character set of this message.
     *
     * Example:
     * ```php
     * $charset = $message->getCharset(); // 'utf-8' by default
     * ```
     *
     * @return string the character set of this message.
     */
    public function getCharset()
    {
        return $this->localCharset;
    }

    /**
     * Sets the character set of this message.
     *
     * Example:
     * ```php
     * $message->setCharset('ISO-8859-1');
     * ```
     *
     * @param string $charset character set name.
     *
     * @return static self reference.
     */
    public function setCharset($charset)
    {
        $this->localCharset = $charset;

        return $this;
    }

    // SUBSECTION: Mailer
    /**
     * Returns the mailer instance that created this message.
     *
     * Example:
     * ```php
     * $mailer = $message->getMailer();
     * ```
     *
     * @return Mailer|null the mailer instance that created this message.
     */
    public function getMailer(): ?Mailer
    {
        return $this->localMailer;
    }

    /**
     * Sets the mailer instance that created this message.
     *
     * Example:
     * ```php
     * $message->setMailer(Yii::$app->mailer);
     * ```
     *
     * @param Mailer|MailerInterface $mailer the mailer instance that created this message.
     *
     * @return static self reference.
     */
    public function setMailer(Mailer|MailerInterface $mailer): static
    {
        if (!$mailer instanceof Mailer) {
            throw new InvalidArgumentException(sprintf('The mailer must be an instance of %s.', Mailer::class));
        }

        $this->localMailer = $mailer;

        return $this;
    }

    // SUBSECTION: Recipients
    /**
     * Returns the message sender email address.
     *
     * If multiple sender addresses are set, only the first one will be returned.
     *
     * Example:
     * ```php
     * $from = $message->getFrom(); // ['admin@example.com' => 'Admin']
     * ```
     *
     * @return array<string, string> the sender email address. The array keys are email addresses, and the array values
     * are the corresponding names. If no sender is set, an empty array will be returned.
     */
    public function getFrom()
    {
        return $this->localFrom;
    }

    /**
     * Sets the message sender.
     *
     * Example:
     * ```php
     * // a single address with name
     * $message->setFrom(['admin@example.com' => 'Admin']);
     *
     * // a single address without name
     * $message->setFrom('admin@example.com');
     *
     * // multiple addresses
     * $message->setFrom([
     *     'admin@example.com' => 'Admin',
     *     'info@example.com' => 'Info',
     * ]);
     * ```
     *
     * @param string|array<string, string> $from the sender email address or an array of addresses.
     *
     * @return static self reference.
     */
    public function setFrom($from)
    {
        $this->localFrom = $this->normalizeEmails($from);

        return $this;
    }

    /**
     * Returns the message recipient email addresses.
     *
     * Example:
     * ```php
     * $to = $message->getTo(); // ['user@example.com' => 'User']
     * ```
     *
     * @return array<string, string> the message recipients email addresses. The array keys are email addresses,
     * and the array values are the corresponding names. If no recipients are set, an empty array will be returned.
     */
    public function getTo()
    {
        return $this->localTo;
    }

    /**
     * Sets the message recipient(s).
     *
     * Example:
     * ```php
     * // a single address with name
     * $message->setTo(['user@example.com' => 'User']);
     *
     * // a single address without name
     * $message->setTo('user@example.com');
     *
     * // multiple addresses
     * $message->setTo([
     *     'user1@example.com' => 'User One',
     *     'user2@example.com' => 'User Two',
     * ]);
     * ```
     *
     * @param string|array<string, string> $to receiver email address or an array of addresses.
     *
     * @return static self reference.
     */
    public function setTo($to)
    {
        $this->localTo = $this->normalizeEmails($to);

        return $this;
    }

    /**
     * Returns the reply-to email address.
     *
     * Example:
     * ```php
     * $replyTo = $message->getReplyTo(); // ['reply@example.com' => 'Reply']
     * ```
     *
     * @return array<string, string> the reply-to email address. The array keys are email addresses, and the array
     * values are the corresponding names. If no reply-to address is set, an empty array will be returned.
     */
    public function getReplyTo()
    {
        return $this->localReplyTo;
    }

    /**
     * Sets the reply-to address of this message.
     *
     * Example:
     * ```php
     * $message->setReplyTo('reply@example.com');
     * // or with a name
     * $message->setReplyTo(['reply@example.com' => 'Reply Handler']);
     * ```
     *
     * @param string|array<string, string> $replyTo the reply-to address.
     *
     * @return static self reference.
     */
    public function setReplyTo($replyTo)
    {
        $this->localReplyTo = $this->normalizeEmails($replyTo);

        return $this;
    }

    /**
     * Returns the Cc (carbon copy) email addresses.
     *
     * Example:
     * ```php
     * $cc = $message->getCc(); // ['cc@example.com' => 'Cc User']
     * ```
     *
     * @return array<string, string> the Cc (carbon copy) email addresses. The array keys are email addresses,
     * and the array values are the corresponding names. If no Cc addresses are set, an empty
     * array will be returned.
     */
    public function getCc()
    {
        return $this->localCc;
    }

    /**
     * Sets the Cc (carbon copy) recipients of this message.
     *
     * Example:
     * ```php
     * $message->setCc('cc@example.com');
     * // or with a name
     * $message->setCc(['cc@example.com' => 'Cc User']);
     * // or multiple addresses
     * $message->setCc([
     *     'cc1@example.com' => 'Cc User 1',
     *     'cc2@example.com' => 'Cc User 2',
     * ]);
     * ```
     *
     * @param string|array<string, string> $cc the Cc recipients
     *
     * @return static self reference.
     */
    public function setCc($cc)
    {
        $this->localCc = $this->normalizeEmails($cc);

        return $this;
    }

    /**
     * Returns the Bcc (blind carbon copy) email addresses.
     *
     * Example:
     * ```php
     * $bcc = $message->getBcc(); // ['bcc@example.com' => 'Bcc User']
     * ```
     *
     * @return array<string, string> the Bcc (blind carbon copy) email addresses. The array keys are email addresses,
     * and the array values are the corresponding names. If no Bcc addresses are set, an empty array
     * will be returned.
     */
    public function getBcc()
    {
        return $this->localBcc;
    }

    /**
     * Sets the Bcc (blind carbon copy) recipients of this message.
     *
     * Example:
     * ```php
     * $message->setBcc('bcc@example.com');
     * // or with a name
     * $message->setBcc(['bcc@example.com' => 'Bcc User']);
     * // or multiple addresses
     * $message->setBcc([
     *     'bcc1@example.com' => 'Bcc User 1',
     *     'bcc2@example.com' => 'Bcc User 2',
     * ]);
     * ```
     *
     * @param string|array<string, string> $bcc the BCC recipients
     *
     * @return static self reference.
     */
    public function setBcc($bcc)
    {
        $this->localBcc = $this->normalizeEmails($bcc);

        return $this;
    }

    // SUBSECTION: Content
    /**
     * Returns the message subject.
     *
     * Example:
     * ```php
     * $subject = $message->getSubject(); // 'Test message'
     * ```
     *
     * @return string the message subject
     */
    public function getSubject()
    {
        return $this->localSubject;
    }

    /**
     * Sets the message subject.
     *
     * Example:
     * ```php
     * $message->setSubject('New message from example.com');
     * ```
     *
     * @param string $subject the message subject
     *
     * @return static self reference.
     */
    public function setSubject($subject)
    {
        $this->localSubject = trim($subject);

        return $this;
    }

    /**
     * Returns the plain text body of this message.
     *
     * Example:
     * ```php
     * $textBody = $message->getTextBody(); // 'This is a plain text message'
     * ```
     *
     * @return string the plain text body of this message.
     */
    public function getTextBody()
    {
        return $this->localTextBody;
    }

    /**
     * Sets the plain text body of this message.
     *
     * Example:
     * ```php
     * $message->setTextBody('This is a plain text message');
     * ```
     *
     * @param string $text plain text content.
     *
     * @return static self reference.
     */
    public function setTextBody($text)
    {
        $this->localTextBody = $text;

        return $this;
    }

    /**
     * Returns the HTML body of this message.
     *
     * Example:
     * ```php
     * $htmlBody = $message->getHtmlBody(); // '<p>This is an HTML message</p>'
     * ```
     *
     * @return string the HTML body of this message.
     */
    public function getHtmlBody()
    {
        return $this->localHtmlBody;
    }

    /**
     * Sets the HTML body of this message.
     *
     * Example:
     * ```php
     * $message->setHtmlBody('<p>This is an HTML message</p>');
     * ```
     *
     * @param string $html HTML content.
     *
     * @return static self reference.
     */
    public function setHtmlBody($html)
    {
        $this->localHtmlBody = $html;

        return $this;
    }

    // SUBSECTION: Attachments and Embeddings
    /**
     * Returns the attachments of this message.
     *
     * Example:
     * ```php
     * $attachments = $message->getAttachments();
     * ```
     *
     * @return array<string, array<string, mixed>> the attachments of this message. The array keys are the
     * attachment names, and the array values are the corresponding attachment file paths or content.
     */
    public function getAttachments()
    {
        return $this->localAttachments;
    }

    /**
     * Attaches a file to this message.
     *
     * Example:
     * ```php
     * // Attach a file from a path
     * $message->attach('/path/to/file.pdf');
     *
     * // Attach with custom filename and content type
     * $message->attach('/path/to/file.pdf', [
     *     'fileName' => 'renamed.pdf',
     *     'contentType' => 'application/pdf',
     * ]);
     *
     * // Attach file content from a variable
     * $content = file_get_contents('/path/to/file.pdf');
     * $message->attach($content, [
     *     'fileName' => 'report.pdf',
     *     'contentType' => 'application/pdf',
     * ]);
     * ```
     *
     * @param string $fileName the file name or path to the file to be attached.
     * @param array<string, mixed> $options options for the attachment. Valid options are:
     * - fileName: name of the attachment (if not set, the basename of the file will be used)
     * - contentType: MIME type of the attachment (if not set, it will be determined from the file extension)
     * - content: content of the attachment (if not set, the file will be read from disk)
     *
     * @return static self reference.
     */
    public function attach($fileName, array $options = [])
    {
        $attachment = [];

        if (!isset($options['fileName']) || !is_string($options['fileName'])) {
            throw new InvalidConfigException('The "fileName" option is required and must be a string.');
        }

        $attachment['fileName'] = $options['fileName'];

        if (isset($options['contentType'])) {
            $attachment['contentType'] = $options['contentType'];
        }

        if (isset($options['content'])) {
            $attachment['content'] = $options['content'];
        } elseif (is_string($fileName) && is_file($fileName)) {
            $attachment['content'] = file_get_contents($fileName);
        } else {
            $attachment['content'] = $fileName;
        }

        $this->localAttachments[$attachment['fileName']] = $attachment;

        return $this;
    }

    /**
     * Embeds a file into the message and returns its CID source.
     *
     * Example:
     * ```php
     * // Embed an image from a path
     * $cid = $message->embed('/path/to/image.jpg');
     * $message->setHtmlBody("Here is an embedded image: <img src='$cid' />");
     *
     * // Embed with custom content ID
     * $cid = $message->embed('/path/to/image.jpg', [
     *     'cid' => 'my-custom-image-id',
     * ]);
     * ```
     *
     * @param string $fileName the file name or path to the file to be embedded.
     * @param array<string, mixed> $options options for embedding. Valid options are:
     * - fileName: name of the file to be embedded (if not set, the basename of the file will be used)
     * - contentType: MIME type of the file (if not set, it will be determined from the file extension)
     * - content: content of the file (if not set, the file will be read from disk)
     * - cid: content ID of the file (if not set, a unique ID will be generated)
     *
     * @return string the content ID of the embedded file.
     */
    public function embed($fileName, array $options = [])
    {
        $embedding = [];

        if (!isset($options['fileName'])) {
            $options['fileName'] = is_string($fileName) ? basename($fileName) : 'embed.dat';
        }

        $embedding['fileName'] = $options['fileName'];

        if (isset($options['contentType'])) {
            $embedding['contentType'] = $options['contentType'];
        }

        if (isset($options['content'])) {
            $embedding['content'] = $options['content'];
        } elseif (is_string($fileName) && is_file($fileName)) {
            $embedding['content'] = file_get_contents($fileName);
        } else {
            $embedding['content'] = $fileName;
        }

        $cid = isset($options['cid']) && is_string($options['cid']) ?
               $options['cid']
               : (string)md5(uniqid('embed_', true));

        $this->localEmbeddings[$cid] = $embedding;

        return 'cid:' . $cid;
    }

    /**
     * Returns the embeddings of this message.
     *
     * Example:
     * ```php
     * $embeddings = $message->getEmbeddings();
     * ```
     *
     * @return array<string, array<string, mixed>> the embeddings of this message.
     * The array keys are the content IDs, and the array values are the embedding file information.
     */
    public function getEmbeddings()
    {
        return $this->localEmbeddings;
    }

    /**
     * Attaches content to this message.
     *
     * Example:
     * ```php
     * // Attach content as a file
     * $message->attachContent('file content', [
     *     'fileName' => 'report.pdf',
     *     'contentType' => 'application/pdf',
     * ]);
     * ```
     *
     * @param string $content the content to be attached.
     * @param array<string, mixed> $options options for the attachment. Valid options are:
     * - fileName: name of the attachment (required)
     * - contentType: MIME type of the attachment (if not set, it will default to application/octet-stream)
     *
     * @return static self reference.
     */
    public function attachContent($content, array $options = [])
    {
        if (!isset($options['fileName']) || !is_string($options['fileName'])) {
            throw new InvalidConfigException('The "fileName" option is required and must be a string.');
        }

        $attachment = [
            'fileName' => $options['fileName'],
            'content'  => $content,
        ];

        if (isset($options['contentType'])) {
            $attachment['contentType'] = $options['contentType'];
        }

        $this->localAttachments[$attachment['fileName']] = $attachment;

        return $this;
    }

    /**
     * Embeds content into the message and returns its CID source.
     *
     * Example:
     * ```php
     * // Embed content as an image
     * $imageContent = file_get_contents('/path/to/image.jpg');
     * $cid = $message->embedContent($imageContent, [
     *     'fileName' => 'image.jpg',
     *     'contentType' => 'image/jpeg',
     * ]);
     * $message->setHtmlBody("Here is an embedded image: <img src='$cid' />");
     * ```
     *
     * @param string $content the content to be embedded.
     * @param array<string, mixed> $options options for embedding. Valid options are:
     * - fileName: name of the file to be embedded (required)
     * - contentType: MIME type of the file (if not set, it will default to application/octet-stream)
     * - cid: content ID of the file (if not set, a unique ID will be generated)
     *
     * @return string the content ID of the embedded file.
     */
    public function embedContent($content, array $options = []): string
    {
        if (!isset($options['fileName'])) {
            throw new InvalidConfigException('The "fileName" option is required.');
        }

        $embedding = [
            'fileName' => $options['fileName'],
            'content'  => $content,
        ];

        if (isset($options['contentType'])) {
            $embedding['contentType'] = $options['contentType'];
        }

        $cid = isset($options['cid']) && is_string($options['cid']) ?
               $options['cid'] :
               (string)md5(uniqid('embed_', true));

        $this->localEmbeddings[$cid] = $embedding;

        return 'cid:' . $cid;
    }
    // SUBSECTION: Headers
    /**
     * Returns the headers of this message.
     *
     * Example:
     * ```php
     * $headers = $message->getHeaders(); // ['X-Priority' => '1', 'X-Custom' => 'Value']
     * ```
     *
     * @return array<string, string> the headers of this message.
     */
    public function getHeaders()
    {
        return $this->localHeaders;
    }

    /**
     * Sets the headers of this message.
     *
     * Example:
     * ```php
     * $message->setHeaders([
     *     'X-Priority' => '1',
     *     'X-Custom' => 'Value',
     * ]);
     * ```
     *
     * @param array<string, string> $headers headers to be set for the message.
     *
     * @return static self reference.
     */
    public function setHeaders($headers)
    {
        $this->localHeaders = $headers;

        return $this;
    }

    /**
     * Adds a custom header to this message.
     *
     * Example:
     * ```php
     * $message->addHeader('X-Priority', '1');
     * $message->addHeader('X-Custom', 'Value');
     * ```
     *
     * @param string $name  header name
     * @param string $value header value
     *
     * @return static self reference.
     */
    public function addHeader($name, $value)
    {
        $this->localHeaders[$name] = $value;

        return $this;
    }

    // SECTION: Actions
    /**
     * Sends this message.
     *
     * Example:
     * ```php
     * $message = Yii::$app->mailer->compose()
     *     ->setFrom('from@example.com')
     *     ->setTo('to@example.com')
     *     ->setSubject('Test message')
     *     ->setTextBody('Plain text content')
     *     ->setHtmlBody('<b>HTML content</b>');
     *
     * if ($message->send()) {
     *     echo 'Email sent successfully!';
     * } else {
     *     echo 'Failed to send email.';
     * }
     * ```
     *
     * @return bool whether the message was sent successfully.
     *
     * @throws InvalidConfigException if no mailer is set to send this message.
     */
    public function send(?MailerInterface $mailer = null): bool
    {
        if ($mailer !== null) {
            $this->setMailer($mailer);
        }

        if ($this->getMailer() === null) {
            throw new InvalidConfigException('No mailer is set to send this message.');
        }

        return $this->getMailer()->send($this);
    }

    /**
     * Returns the string representation of this message.
     *
     * @return string the string representation of this message.
     */
    public function toString(): string
    {
        return $this->getSubject() . "\n"
            . "From: " . implode(', ', array_keys($this->getFrom())) . "\n"
            . "To: " . implode(', ', array_keys($this->getTo())) . "\n"
            . ($this->getReplyTo() ? "Reply-To: " . implode(', ', array_keys($this->getReplyTo())) . "\n" : '')
            . ($this->getCc() ? "Cc: " . implode(', ', array_keys($this->getCc())) . "\n" : '')
            . ($this->getBcc() ? "Bcc: " . implode(', ', array_keys($this->getBcc())) . "\n" : '')
            . "\n"
            . ($this->getHtmlBody() ? $this->getHtmlBody() : $this->getTextBody());
    }

    // SECTION: Helpers
    /**
     * Normalizes email address to the format of [email => name].
     *
     * Example:
     * ```php
     * $normalized = $message->normalizeEmails('user@example.com');
     * // ['user@example.com' => '']
     *
     * $normalized = $message->normalizeEmails(['user@example.com' => 'User']);
     * // ['user@example.com' => 'User']
     * ```
     *
     * @param string|array<string|int, string> $emails email address or an array of addresses.
     *
     * @return array<string, string> normalized email address or addresses.
     */
    protected function normalizeEmails($emails): array
    {
        if (is_string($emails)) {
            $emails = [$emails => ''];
        }

        $result = [];
        foreach ($emails as $email => $name) {
            if (is_int($email)) {
                $email = $name;
                $name  = '';
            }
            $result[$email] = $name;
        }

        return $result;
    }
}
