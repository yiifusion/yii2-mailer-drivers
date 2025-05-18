<?php

namespace yiifusion\mail\transports\mailgun;

use Throwable;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\httpclient\Client;
use yii\httpclient\Exception as HttpClientException;
use yii\httpclient\Request;
use yiifusion\exceptions\MailTransportException;
use yiifusion\mail\Message;
use yiifusion\mail\transports\BaseTransport;

use function array_keys;
use function current;
use function get_class;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function key;
use function sprintf;

/**
 * MailgunTransport implements a mail transport based on the Mailgun API.
 *
 * To use MailgunTransport, you should configure it in the application configuration like the following:
 *
 * ```php
 * 'components' => [
 *     'mailer' => [
 *         'class' => 'yiifusion\mail\Mailer',
 *         'transportClass' => 'yiifusion\mail\transports\mailgun\MailgunTransport',
 *         'transportConfig' => [
 *             'apiKey' => 'your-api-key-here',
 *             'domain' => 'your-domain.com',
 *         ],
 *     ],
 * ],
 * ```
 *
 * @see https://documentation.mailgun.com/en/latest/api-sending.html
 *
 * @author YiiFusion Team
 */
class MailgunTransport extends BaseTransport
{
    /**
     * @var string the Mailgun API key
     */
    public string $apiKey = '';

    /**
     * @var string the Mailgun domain
     */
    public string $domain = '';

    /**
     * @var string the Mailgun API endpoint
     */
    protected string $apiEndpoint = 'https://api.mailgun.net/v3';

    /**
     * @var string the Mailgun EU API endpoint
     */
    protected string $euApiEndpoint = 'https://api.eu.mailgun.net/v3';

    /**
     * @var bool whether to use the EU region API endpoint
     */
    public bool $useEuRegion = false;

    /**
     * @var array<string, mixed> additional options for Mailgun API
     *
     * @see https://documentation.mailgun.com/en/latest/api-sending.html for available options
     */
    public array $mailgunOptions = [];

    // SECTION: Initialization
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (empty($this->apiKey)) {
            throw new InvalidConfigException('MailgunTransport::apiKey must be set.');
        }

        if (empty($this->domain)) {
            throw new InvalidConfigException('MailgunTransport::domain must be set.');
        }

        parent::init();

        $this->log(
            'MailgunTransport initialized',
            [
                'domain'          => $this->domain,
                'endpoint'        => $this->useEuRegion ? $this->euApiEndpoint : $this->apiEndpoint,
                'trackingEnabled' => $this->enableTracking,
            ]
        );
    }

    // SECTION: Actions
    /**
     * @inheritdoc
     */
    public function send(Message $message): bool
    {
        try {
            $this->log(
                'Preparing to send email via Mailgun',
                [
                    'subject' => $message->getSubject(),
                    'to'      => array_keys($message->getTo()),
                    'from'    => array_keys($message->getFrom()),
                ]
            );

            $this->validateMessage($message);

            $payload = $this->buildPayload($message);
            $request = $this->createRequest($payload);

            $this->logRequest($request, 'Mailgun API Request');

            $response = $request->send();

            $this->logResponse($response, 'Mailgun API Response');

            /**
             * @param mixed $data
             *
             * @return array<string|null, int, string>
             */
            $normalizeData = static function (mixed $data, int $defaultStatusCode): array {
                if (!is_array($data)) {
                    $data = [];
                }

                $messageId = null;
                $code      = $defaultStatusCode;
                $message   = 'Unknown error';

                if (isset($data['id']) && is_string($data['id'])) {
                    $messageId = $data['id'];
                }

                if (isset($data['message']) && is_string($data['message'])) {
                    $message = $data['message'];
                }

                return [$messageId, $code, $message];
            };

            $statusCode = $response->getStatusCode();

            if (is_numeric($statusCode)) {
                $statusCode = (int)$statusCode;
            } else {
                $statusCode = 500;
            }

            $content = $response->getData();

            [$messageId, $code, $responseMessage] = $normalizeData(
                $content,
                $statusCode
            );

            if ($statusCode === 200) {
                $this->logSendOperation(
                    $message,
                    true,
                    [
                        'statusCode' => $statusCode,
                        'messageId'  => $messageId,
                    ]
                );

                return true;
            }

            $errors = [];

            if (is_array($content)) {
                if (isset($content['message']) && is_string($content['message'])) {
                    $errors[] = $content['message'];
                }
            }

            if (empty($errors)) {
                $errors[] = 'Unknown error';
            }

            foreach ($errors as $error) {
                $this->addError(
                    $code,
                    sprintf('Mailgun API error (code: %s): %s', $statusCode, $error),
                    $content
                );
            }

            $this->logSendOperation(
                $message,
                false,
                [
                    'statusCode'   => $statusCode,
                    'errorDetails' => $content,
                ]
            );

            return false;
        } catch (MailTransportException $e) {
            $this->addError($e->getCode(), $e->getMessage(), $e->getDetails());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => 'MailTransportException',
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        } catch (HttpClientException $e) {
            $this->addError(500, 'HTTP client error: ' . $e->getMessage());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => 'HttpClientException',
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        } catch (Throwable $e) {
            $this->addError(500, 'Unexpected error: ' . $e->getMessage());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    // SECTION: Helpers

    // SUBSECTION: Payload
    /**
     * Builds the request payload for the Mailgun API.
     *
     * @param Message $message the message to be sent
     *
     * @return array<mixed> the payload as an array
     */
    protected function buildPayload(Message $message): array
    {
        $payload = [
            'from'    => $this->formatSingleEmailAddressString($message->getFrom()),
            'to'      => $this->formatMultipleEmailAddressesString($message->getTo()),
            'subject' => $message->getSubject(),
        ];

        if (!empty($message->getCc())) {
            $payload['cc'] = $this->formatMultipleEmailAddressesString($message->getCc());
        }

        if (!empty($message->getBcc())) {
            $payload['bcc'] = $this->formatMultipleEmailAddressesString($message->getBcc());
        }

        if (!empty($message->getReplyTo())) {
            $payload['h:Reply-To'] = $this->formatSingleEmailAddressString($message->getReplyTo());
        }

        if ($htmlBody = $message->getHtmlBody()) {
            $payload['html'] = $htmlBody;
        }

        if ($textBody = $message->getTextBody()) {
            $payload['text'] = $textBody;
        }

        if ($headers = $message->getHeaders()) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $payload['h:' . $name] = $value;
                }
            }
        }

        if ($this->enableTracking) {
            $payload['o:tracking']        = 'yes';
            $payload['o:tracking-opens']  = 'yes';
            $payload['o:tracking-clicks'] = 'yes';
        } else {
            $payload['o:tracking']        = 'no';
            $payload['o:tracking-opens']  = 'no';
            $payload['o:tracking-clicks'] = 'no';
        }

        $result = ArrayHelper::merge(
            $payload,
            $this->getOptions(),
            $this->mailgunOptions
        );

        if ($attachments = $message->getAttachments()) {
            $result = $this->addAttachmentsToPayload($result, $attachments);
        }

        return $result;
    }

    /**
     * Creates an HTTP request for the Mailgun API.
     *
     * @param array<mixed> $payload the request payload
     *
     * @return Request the HTTP request
     */
    protected function createRequest(array $payload): Request
    {
        $baseUrl = $this->useEuRegion ? $this->euApiEndpoint : $this->apiEndpoint;
        $url     = sprintf('%s/%s/messages', $baseUrl, $this->domain);

        $request = $this->getHttpClient()
            ->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders([
                'Accept' => 'application/json',
            ])
            ->setFormat(Client::FORMAT_URLENCODED);

        $request->addOptions([
            'userAgent' => 'YiiFusion-Mailgun/1.0',
            'auth'      => ['api', $this->apiKey],
        ]);

        $request->setData($payload);

        return $request;
    }

    // SUBSECTION: Email Formatters
    /**
     * Formats a single email address from an array of addresses to a string.
     *
     * @param array<string, string> $addresses email addresses in the format [email => name]
     *
     * @return string the first email address in the required format
     */
    protected function formatSingleEmailAddressString(array $addresses): string
    {
        $email = key($addresses);
        $name  = current($addresses);

        if (empty($name)) {
            return (string)$email;
        }

        return sprintf('"%s" <%s>', (string)$name, (string)$email);
    }

    /**
     * Formats multiple email addresses to a comma-separated string.
     *
     * @param array<string, string> $addresses email addresses in the format [email => name]
     *
     * @return string formatted email addresses
     */
    protected function formatMultipleEmailAddressesString(array $addresses): string
    {
        $result = [];

        foreach ($addresses as $email => $name) {
            if (empty($name)) {
                $result[] = $email;
            } else {
                $result[] = sprintf('"%s" <%s>', $name, $email);
            }
        }

        return implode(', ', $result);
    }

    // SUBSECTION: Attachment Formatters
    /**
     * Adds attachments to the request payload.
     *
     * @param array<mixed> $payload the existing payload
     * @param array<mixed> $attachments the attachments from the message
     *
     * @return array<mixed> the modified payload
     */
    protected function addAttachmentsToPayload(array $payload, array $attachments): array
    {
        foreach ($attachments as $name => $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $content = $attachment['content'] ?? '';
            $file    = $attachment['fileName'] ?? '';

            if (!empty($content) && is_string($content) && is_string($file)) {
                $key = 'attachment[' . $file . ']';

                $payload[$key] = [
                    'fileName'    => $file,
                    'content'     => $content,
                    'contentType' => 'application/octet-stream',
                ];

                if (isset($attachment['contentType']) && is_string($attachment['contentType'])) {
                    $payload[$key]['contentType'] = $attachment['contentType'];
                }
            }
        }

        return $payload;
    }
}
